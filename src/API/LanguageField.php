<?php
/**
 * REST API language fields: `lang` and `translations`.
 *
 * Registers read-only (Step 2) and write (Step 3) REST fields for all
 * Polylang-translatable post types and taxonomies that are exposed via
 * show_in_rest.
 *
 * Field shape mirrors Polylang Pro:
 * - `lang`         → string (language slug)
 * - `translations` → object (lang_slug => object_id)
 *
 * @package HDPLL\API
 */

declare(strict_types=1);

namespace HDPLL\API;

defined( 'ABSPATH' ) || exit;

final class LanguageField {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'registerFields' ] );
	}

	/* ---------- Field Registration ---------------------------------- */

	/**
	 * Register `lang` and `translations` REST fields for all translatable
	 * post types and taxonomies that have show_in_rest enabled.
	 */
	public function registerFields(): void {
		// Post types.
		foreach ( $this->getTranslatablePostTypes() as $post_type ) {
			\register_rest_field(
				$post_type,
				'lang',
				[
					'get_callback'    => [ $this, 'getPostLang' ],
					'update_callback' => [ $this, 'updatePostLang' ],
					'schema'          => [
						'description' => __( 'Polylang language slug.', 'hd-pll' ),
						'type'        => 'string',
						'context'     => [ 'view', 'edit' ],
					],
				]
			);

			\register_rest_field(
				$post_type,
				'translations',
				[
					'get_callback'    => [ $this, 'getPostTranslations' ],
					'update_callback' => [ $this, 'updatePostTranslations' ],
					'schema'          => [
						'description'          => __( 'Map of Polylang language slug to translated post ID.', 'hd-pll' ),
						'type'                 => 'object',
						'additionalProperties' => [ 'type' => 'integer' ],
						'context'              => [ 'view', 'edit' ],
					],
				]
			);
		}

		// Taxonomies.
		// register_rest_field() resolves additional fields via get_object_type(),
		// which returns the schema title. WP_REST_Terms_Controller maps
		// 'post_tag' → 'tag'; all others use the taxonomy slug directly.
		foreach ( $this->getTranslatableTaxonomies() as $taxonomy ) {
			$field_type = $this->getTaxonomySchemaTitle( $taxonomy );

			\register_rest_field(
				$field_type,
				'lang',
				[
					'get_callback'    => [ $this, 'getTermLang' ],
					'update_callback' => [ $this, 'updateTermLang' ],
					'schema'          => [
						'description' => __( 'Polylang language slug.', 'hd-pll' ),
						'type'        => 'string',
						'context'     => [ 'view', 'edit' ],
					],
				]
			);

			\register_rest_field(
				$field_type,
				'translations',
				[
					'get_callback'    => [ $this, 'getTermTranslations' ],
					'update_callback' => [ $this, 'updateTermTranslations' ],
					'schema'          => [
						'description'          => __( 'Map of Polylang language slug to translated term ID.', 'hd-pll' ),
						'type'                 => 'object',
						'additionalProperties' => [ 'type' => 'integer' ],
						'context'              => [ 'view', 'edit' ],
					],
				]
			);
		}
	}

	/* ---------- Post Read Callbacks --------------------------------- */

	/**
	 * @param array $post_data Prepared post data.
	 *
	 * @return string
	 */
	public function getPostLang( array $post_data ): string {
		return RestLanguageResolver::getPostLanguage( (int) $post_data['id'] );
	}

	/**
	 * @param array $post_data Prepared post data.
	 *
	 * @return array<string, int>
	 */
	public function getPostTranslations( array $post_data ): array {
		return RestLanguageResolver::getPostTranslations( (int) $post_data['id'] );
	}

	/* ---------- Term Read Callbacks --------------------------------- */

	/**
	 * @param array $term_data Prepared term data.
	 *
	 * @return string
	 */
	public function getTermLang( array $term_data ): string {
		return RestLanguageResolver::getTermLanguage( (int) $term_data['id'] );
	}

	/**
	 * @param array $term_data Prepared term data.
	 *
	 * @return array<string, int>
	 */
	public function getTermTranslations( array $term_data ): array {
		return RestLanguageResolver::getTermTranslations( (int) $term_data['id'] );
	}

	/* ---------- Post Write Callbacks -------------------------------- */

	/**
	 * Update the language assignment for a post.
	 *
	 * @param mixed    $value New `lang` value from the request.
	 * @param \WP_Post $post  Post object.
	 *
	 * @return bool|\WP_Error
	 */
	public function updatePostLang( mixed $value, \WP_Post $post ): bool|\WP_Error {
		$lang = sanitize_key( (string) $value );

		$valid = RestLanguageResolver::validatePostWrite( $post->ID, $lang, $post->post_type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		\pll_set_post_language( $post->ID, $lang );

		return true;
	}

	/**
	 * Update the translation map for a post.
	 *
	 * @param mixed    $value New `translations` value (assoc array).
	 * @param \WP_Post $post  Post object.
	 *
	 * @return bool|\WP_Error
	 */
	public function updatePostTranslations( mixed $value, \WP_Post $post ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'pll_rest_invalid_translations',
				__( 'The translations field must be an object (lang_slug => post_id).', 'hd-pll' ),
				[ 'status' => 400 ]
			);
		}

		$translations = array_map( 'absint', $value );

		$valid = RestLanguageResolver::validatePostTranslationsMap( $translations, $post->post_type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Ensure the source post itself is included in the map.
		$current_lang = RestLanguageResolver::getPostLanguage( $post->ID );
		if ( $current_lang ) {
			$translations[ $current_lang ] = $post->ID;
		}

		\pll_save_post_translations( $translations );

		return true;
	}

	/* ---------- Term Write Callbacks -------------------------------- */

	/**
	 * Update the language assignment for a term.
	 *
	 * @param mixed    $value New `lang` value from the request.
	 * @param \WP_Term $term  Term object.
	 *
	 * @return bool|\WP_Error
	 */
	public function updateTermLang( mixed $value, \WP_Term $term ): bool|\WP_Error {
		$lang = sanitize_key( (string) $value );

		$valid = RestLanguageResolver::validateTermWrite( $term->term_id, $lang, $term->taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		\pll_set_term_language( $term->term_id, $lang );

		return true;
	}

	/**
	 * Update the translation map for a term.
	 *
	 * @param mixed    $value New `translations` value (assoc array).
	 * @param \WP_Term $term  Term object.
	 *
	 * @return bool|\WP_Error
	 */
	public function updateTermTranslations( mixed $value, \WP_Term $term ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'pll_rest_invalid_translations',
				__( 'The translations field must be an object (lang_slug => term_id).', 'hd-pll' ),
				[ 'status' => 400 ]
			);
		}

		$translations = array_map( 'absint', $value );

		$valid = RestLanguageResolver::validateTermTranslationsMap( $translations, $term->taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Ensure the source term itself is included in the map.
		$current_lang = RestLanguageResolver::getTermLanguage( $term->term_id );
		if ( $current_lang ) {
			$translations[ $current_lang ] = $term->term_id;
		}

		\pll_save_term_translations( $translations );

		return true;
	}

	/* ---------- Helpers --------------------------------------------- */

	/**
	 * Return all translatable post types that expose a REST API.
	 *
	 * @return string[]
	 */
	private function getTranslatablePostTypes(): array {
		if ( ! function_exists( 'pll_is_translated_post_type' ) ) {
			return [];
		}

		$post_types = [];
		foreach ( \get_post_types( [ 'show_in_rest' => true ] ) as $pt ) {
			if ( \pll_is_translated_post_type( $pt ) ) {
				$post_types[] = $pt;
			}
		}

		return $post_types;
	}

	/**
	 * Return all translatable taxonomies that expose a REST API.
	 *
	 * @return string[]
	 */
	private function getTranslatableTaxonomies(): array {
		if ( ! function_exists( 'pll_is_translated_taxonomy' ) ) {
			return [];
		}

		$taxonomies = [];
		foreach ( \get_taxonomies( [ 'show_in_rest' => true ] ) as $tax ) {
			if ( \pll_is_translated_taxonomy( $tax ) ) {
				$taxonomies[] = $tax;
			}
		}

		return $taxonomies;
	}

	/**
	 * Map taxonomy slug to the schema title used by WP_REST_Terms_Controller.
	 *
	 * WP core maps 'post_tag' → 'tag' in get_item_schema(); all other taxonomies
	 * use the taxonomy slug directly. register_rest_field() resolves fields via
	 * this schema title, so we must match it exactly.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return string Schema title used as the REST field object type.
	 */
	private function getTaxonomySchemaTitle( string $taxonomy ): string {
		return 'post_tag' === $taxonomy ? 'tag' : $taxonomy;
	}
}
