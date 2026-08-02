<?php
/**
 * W-A2: WooCommerce Taxonomy Admin for Polylang.
 *
 * - Copies term metas (display_type, thumbnail_id, order) when creating translations.
 * - Fixes term hierarchy language filter for product_cat.
 * - Translates thumbnail_id to target language media.
 * - Fixes product_cat thumbnail language on save.
 * - Sets language for attribute terms created from product metabox.
 * - Pre-populates category fields when creating a translation.
 *
 * @package HDPLL\WC\Admin
 */

declare(strict_types=1);

namespace HDPLL\WC\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminTaxonomies {

	public function __construct() {
		add_action( 'init', [ $this, 'init' ], 11 ); // After WooCommerce.
	}

	/**
	 * Setup hooks after WC taxonomies are registered.
	 */
	public function init(): void {
		// Copy term metas when creating translation.
		add_filter( 'pll_copy_term_metas', [ $this, 'getMetasToCopy' ], 10, 3 );

		// Fix term hierarchy language filter for WC ordering.
		add_filter( 'get_terms_args', [ $this, 'getTermsArgs' ], 5 ); // Before Polylang.

		// Media-related: translate thumbnail, fix language.
		if ( \PLL()->options['media_support'] ?? false ) {
			add_filter( 'pll_translate_term_meta', [ $this, 'translateMeta' ], 10, 3 );
			add_action( 'created_product_cat', [ $this, 'fixTermThumbnail' ], 999 );
			add_action( 'edited_product_cat', [ $this, 'fixTermThumbnail' ], 999 );
		}

		// Set language for attribute terms created from product metabox.
		add_action( 'create_term', [ $this, 'createAttributeTerm' ], 10, 3 );

		// Pre-populate category fields when creating translation.
		if ( function_exists( 'pll_remove_anonymous_object_filter' ) ) {
			\pll_remove_anonymous_object_filter( 'product_cat_add_form_fields', [ 'WC_Admin_Taxonomies', 'add_category_fields' ] );
			add_action( 'product_cat_add_form_fields', [ $this, 'addCategoryFields' ] );
		}
	}

	/**
	 * Add WC term metas to the list of metas to copy when creating a translation.
	 *
	 * @param string[] $to_copy Meta keys to copy.
	 * @param bool     $sync    True for sync, false for copy.
	 * @param int      $from    Source term ID.
	 *
	 * @return string[]
	 */
	public function getMetasToCopy( array $to_copy, bool $sync, int $from ): array {
		$term = get_term( $from );

		if ( ! $term instanceof \WP_Term ) {
			return $to_copy;
		}

		// product_cat metas.
		if ( 'product_cat' === $term->taxonomy ) {
			$to_copy[] = 'display_type';
			$to_copy[] = 'thumbnail_id';

			if ( ! $sync ) {
				$to_copy[] = 'order';
			}
		}

		// pa_* attribute order metas.
		if ( ! $sync && str_starts_with( $term->taxonomy, 'pa_' ) ) {
			$metas = get_term_meta( $from );

			if ( is_array( $metas ) ) {
				foreach ( array_keys( $metas ) as $key ) {
					if ( str_starts_with( (string) $key, 'order_' ) ) {
						$to_copy[] = $key;
					}
				}
			}
		}

		return $to_copy;
	}

	/**
	 * Suppress PLL language filter in _get_term_hierarchy() for product_cat.
	 * WC modifies orderby to meta_value_num which conflicts with PLL's term filter.
	 *
	 * @param array $args WP_Term_Query arguments.
	 *
	 * @return array
	 */
	public function getTermsArgs( array $args ): array {
		if (
			'all' === ( $args['get'] ?? '' )
			&& 'meta_value_num' === ( $args['orderby'] ?? '' )
			&& 'id=>parent' === ( $args['fields'] ?? '' )
		) {
			$args['lang'] = '';
		}

		return $args;
	}

	/**
	 * Translate thumbnail_id to the target language media when copying term meta.
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Meta key.
	 * @param string $lang  Target language slug.
	 *
	 * @return mixed
	 */
	public function translateMeta( mixed $value, string $key, string $lang ): mixed {
		if ( 'thumbnail_id' === $key && is_numeric( $value ) && ! empty( $value ) ) {
			$tr_value = \pll_get_post( (int) $value, $lang );

			return $tr_value ?: $value;
		}

		return $value;
	}

	/**
	 * Fix product_cat thumbnail language after save.
	 * If thumbnail was just uploaded, it may have wrong language (preferred vs current).
	 *
	 * @param int $term_id Term ID.
	 */
	public function fixTermThumbnail( int $term_id ): void {
		$thumbnail_id = get_term_meta( $term_id, 'thumbnail_id', true );
		$thumbnail_id = is_numeric( $thumbnail_id ) ? (int) $thumbnail_id : 0;

		$lang = \pll_get_term_language( $term_id );

		if ( ! $thumbnail_id || ! $lang || \pll_get_post_language( $thumbnail_id ) === $lang ) {
			return;
		}

		$translations = \pll_get_post_translations( $thumbnail_id );

		if ( ! empty( $translations[ $lang ] ) ) {
			update_term_meta( $term_id, 'thumbnail_id', $translations[ $lang ] );
		} else {
			\pll_set_post_language( $thumbnail_id, $lang );
		}
	}

	/**
	 * Set language for attribute terms created from product metabox ajax.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function createAttributeTerm( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! doing_action( 'wp_ajax_woocommerce_add_new_attribute' ) || ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return;
		}

		if ( ! isset( $_POST['pll_post_id'], $_REQUEST['security'] ) ) {
			return;
		}

		$nonce = sanitize_key( wp_unslash( $_REQUEST['security'] ) );
		if ( ! wp_verify_nonce( $nonce, 'add-attribute' ) ) {
			return;
		}

		$lang = \pll_get_post_language( absint( $_POST['pll_post_id'] ) );

		if ( $lang ) {
			\pll_set_term_language( $term_id, $lang );
		}
	}

	/**
	 * Pre-populate category fields from source term when creating a translation.
	 * Replaces WC_Admin_Taxonomies::add_category_fields().
	 */
	public function addCategoryFields(): void {
		$term = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['taxonomy'], $_GET['from_tag'], $_GET['new_lang'] ) ) {
			$term = get_term( absint( $_GET['from_tag'] ), 'product_cat' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$wc_admin = \WC_Admin_Taxonomies::get_instance();

		if ( $term instanceof \WP_Term ) {
			$wc_admin->edit_category_fields( $term );
		} else {
			$wc_admin->add_category_fields();
		}
	}
}
