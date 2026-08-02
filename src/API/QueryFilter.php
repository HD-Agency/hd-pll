<?php
/**
 * REST collection query filtering via `?lang=` parameter.
 *
 * Follows the Polylang Pro pattern:
 * - Capture the active WP_REST_Request via `rest_dispatch_request`.
 * - Register the `lang` collection param on translatable post types and taxonomies.
 * - Filter post REST queries through `rest_{$post_type}_query` (dynamic per post type).
 * - Filter term REST queries through `rest_{$taxonomy}_query` (dynamic per taxonomy).
 * - Empty `lang` or `lang=all` → no filter (return all languages).
 * - Do NOT inject raw `tax_query` clauses as the primary implementation.
 *
 * @package HDPLL\API
 */

declare(strict_types=1);

namespace HDPLL\API;

defined( 'ABSPATH' ) || exit;

final class QueryFilter {

	public function __construct() {
		// Register per-post-type and per-taxonomy query filters and collection
		// params inside rest_api_init so all types/taxonomies are registered.
		add_action( 'rest_api_init', [ $this, 'registerDynamicHooks' ] );
	}

	/* ---------- Dynamic Hooks (registered inside rest_api_init) ---- */

	/**
	 * Register per-post-type and per-taxonomy query filters and collection params.
	 *
	 * WP core uses `rest_{$post_type}_query` (dynamic) for post collection queries.
	 * WP core uses `rest_{$taxonomy}_query` (dynamic) for term collection queries.
	 *
	 * @perf Registering N-hooks is acceptable at the current scale. If the site grows
	 *       beyond 50+ Custom Post Types/Taxonomies, consider switching to `rest_request_before_callbacks`
	 *       or `rest_pre_dispatch` for a single unified global hook.
	 */
	public function registerDynamicHooks(): void {
		// Post types.
		if ( function_exists( 'pll_is_translated_post_type' ) ) {
			foreach ( \get_post_types( [ 'show_in_rest' => true ] ) as $post_type ) {
				if ( ! \pll_is_translated_post_type( $post_type ) ) {
					continue;
				}

				add_filter(
					"rest_{$post_type}_query",
					[ $this, 'filterPostQuery' ],
					10,
					2
				);

				add_filter(
					"rest_{$post_type}_collection_params",
					[ $this, 'registerCollectionParam' ],
					10,
					1
				);
			}
		}

		// Taxonomies.
		if ( function_exists( 'pll_is_translated_taxonomy' ) ) {
			foreach ( \get_taxonomies( [ 'show_in_rest' => true ] ) as $taxonomy ) {
				if ( ! \pll_is_translated_taxonomy( $taxonomy ) ) {
					continue;
				}

				add_filter(
					"rest_{$taxonomy}_query",
					[ $this, 'filterTermQuery' ],
					10,
					2
				);

				add_filter(
					"rest_{$taxonomy}_collection_params",
					[ $this, 'registerCollectionParam' ],
					10,
					1
				);
			}
		}
	}

	/**
	 * Add `lang` collection query parameter.
	 *
	 * Shared callback for both post type and taxonomy collection params,
	 * hooked dynamically via `rest_{$type}_collection_params`.
	 *
	 * @param array $params Existing collection params.
	 *
	 * @return array
	 */
	public function registerCollectionParam( array $params ): array {
		$params['lang'] = [
			'description'       => __( 'Filter by Polylang language slug. Use "all" or omit to return all languages.', 'hd-pll' ),
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => [ $this, 'validateLangParam' ],
		];

		return $params;
	}

	/* ---------- Query Hooks ----------------------------------------- */

	/**
	 * Inject lang into WP_Query args for post REST queries.
	 *
	 * @param array           $args    WP_Query args.
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return array
	 */
	public function filterPostQuery( array $args, \WP_REST_Request $request ): array {
		$lang = $this->resolveLangFromRequest( $request );
		if ( null !== $lang ) {
			$args['lang'] = $lang;
		}

		return $args;
	}

	/**
	 * Inject lang into get_terms args for term REST queries.
	 *
	 * Hooked dynamically via `rest_{$taxonomy}_query`.
	 *
	 * @param array           $args    get_terms args.
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return array
	 */
	public function filterTermQuery( array $args, \WP_REST_Request $request ): array {
		$lang = $this->resolveLangFromRequest( $request );
		if ( null !== $lang ) {
			$args['lang'] = $lang;
		}

		return $args;
	}

	/* ---------- Validation ------------------------------------------ */

	/**
	 * Validate the `lang` query param.
	 * Accepts any registered slug plus `all` and empty string.
	 *
	 * @param string $value Raw `lang` value.
	 *
	 * @return bool|\WP_Error
	 */
	public function validateLangParam( string $value ): bool|\WP_Error {
		$value = sanitize_key( $value );

		if ( '' === $value || 'all' === $value ) {
			return true;
		}

		if ( ! RestLanguageResolver::isValidLanguage( $value ) ) {
			return new \WP_Error(
				'pll_rest_invalid_lang',
				sprintf( __( 'Invalid Polylang language slug: %s', 'hd-pll' ), $value ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/* ---------- Helpers --------------------------------------------- */

	/**
	 * Extract and normalise the `lang` param from a REST request.
	 *
	 * Returns null when no filter should be applied (empty or 'all').
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return string|null Language slug to filter by, or null for no filter.
	 */
	private function resolveLangFromRequest( \WP_REST_Request $request ): ?string {
		$lang = (string) ( $request->get_param( 'lang' ) ?? '' );
		$lang = sanitize_key( $lang );

		if ( '' === $lang || 'all' === $lang ) {
			return null;
		}

		return RestLanguageResolver::isValidLanguage( $lang ) ? $lang : null;
	}
}
