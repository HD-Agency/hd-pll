<?php
/**
 * W-A6: Product Duplicate for Polylang.
 *
 * When WC duplicates a product: set language, duplicate translations, link group, handle variations.
 *
 * @package HDPLL\WC\Admin
 */

declare(strict_types=1);

namespace HDPLL\WC\Admin;

use HD\Core\DB;

defined( 'ABSPATH' ) || exit;

final class AdminProductDuplicate {

	public function __construct() {
		add_filter( 'woocommerce_duplicate_product_exclude_children', '__return_true' );
		add_action( 'admin_action_duplicate_product', [ $this, 'beforeDuplicate' ], 5 );
		add_action( 'woocommerce_product_duplicate', [ $this, 'productDuplicate' ], 10, 2 );
	}

	/**
	 * Remove PLL term language check during duplicate (WC assigns terms freely).
	 */
	public function beforeDuplicate(): void {
		if ( isset( \PLL()->posts ) && is_callable( [ \PLL()->posts, 'set_object_terms' ] ) ) {
			remove_action( 'set_object_terms', [ \PLL()->posts, 'set_object_terms' ] );
		}
	}

	/**
	 * Handle translation duplication after WC duplicates the main product.
	 *
	 * @param \WC_Product $duplicate Duplicated product (by WC).
	 * @param \WC_Product $product   Original product.
	 */
	public function productDuplicate( \WC_Product $duplicate, \WC_Product $product ): void {
		$tr_ids = \pll_get_post_translations( $product->get_id() );

		if ( empty( $tr_ids ) ) {
			// No translations — just set language for the duplicate.
			$lang = \pll_get_post_language( $product->get_id() );
			if ( $lang ) {
				\pll_set_post_language( $duplicate->get_id(), $lang );
			}

			return;
		}

		$meta_to_exclude = (array) array_filter(
			apply_filters(
				'woocommerce_duplicate_product_exclude_meta',
				[],
				array_map( static fn( $datum ) => $datum->key, $product->get_meta_data() )
			)
		);

		// Set language for the WC-duplicated product.
		$lang                = \pll_get_post_language( $product->get_id() );
		$new_tr_ids          = [];
		$new_tr_ids[ $lang ] = $duplicate->get_id();
		\pll_set_post_language( $duplicate->get_id(), $lang );

		// Duplicate each translation.
		$sku_updates = [];
		$sku         = $duplicate->get_sku( 'edit' );

		foreach ( $tr_ids as $tr_lang => $tr_id ) {
			if ( (int) $tr_id === $product->get_id() ) {
				continue;
			}

			$tr_product = \wc_get_product( $tr_id );
			if ( ! $tr_product ) {
				continue;
			}

			$tr_duplicate = clone $tr_product;
			$tr_duplicate->set_id( 0 );
			/* translators: %s: product name */
			$tr_duplicate->set_name( sprintf( __( '%s (Copy)', 'flavor' ), $tr_duplicate->get_name() ) );
			$tr_duplicate->set_total_sales( 0 );
			$tr_duplicate->set_status( 'draft' );
			$tr_duplicate->set_date_created( null );
			$tr_duplicate->set_slug( '' );
			$tr_duplicate->set_rating_counts( [] );
			$tr_duplicate->set_average_rating( 0 );
			$tr_duplicate->set_review_count( 0 );
			if ( '' !== $tr_duplicate->get_sku( 'edit' ) ) {
				$tr_duplicate->set_sku( '' );
			}

			foreach ( $meta_to_exclude as $meta_key ) {
				$tr_duplicate->delete_meta_data( $meta_key );
			}

			if ( method_exists( $tr_duplicate, 'set_global_unique_id' ) ) {
				$tr_duplicate->set_global_unique_id( '' );
			}

			do_action( 'woocommerce_product_duplicate_before_save', $tr_duplicate, $tr_product );

			$tr_duplicate->save();
			$new_tr_ids[ $tr_lang ] = $tr_duplicate->get_id();
			\pll_set_post_language( $tr_duplicate->get_id(), $tr_lang );
			$sku_updates[] = $tr_duplicate;
		}

		// Link all duplicated translations together.
		\pll_save_post_translations( $new_tr_ids );

		foreach ( $sku_updates as $tr_duplicate ) {
			if ( '' !== $sku ) {
				$tr_duplicate->set_sku( $sku );
				$tr_duplicate->save();
			}
		}

		// Handle variations for variable products.
		if ( $product->is_type( 'variable' ) ) {
			$this->duplicateVariations( $product, $duplicate, $new_tr_ids, $meta_to_exclude );
		}
	}

	/**
	 * Duplicate variation translations for variable products.
	 *
	 * @param \WC_Product $product         Original product.
	 * @param \WC_Product $duplicate       Duplicated main product.
	 * @param array       $parent_tr_ids   Parent translation IDs [lang => id].
	 * @param array       $meta_to_exclude Meta keys to exclude.
	 */
	private function duplicateVariations( \WC_Product $product, \WC_Product $duplicate, array $parent_tr_ids, array $meta_to_exclude ): void {
		foreach ( $product->get_children() as $child_id ) {
			$child_tr_ids = \pll_get_post_translations( $child_id );

			if ( empty( $child_tr_ids ) ) {
				continue;
			}

			$child = \wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}

			$new_child_tr_ids = [];
			$sku              = \wc_product_generate_unique_sku( 0, $child->get_sku( 'edit' ) );
			$has_sku          = '' !== $child->get_sku( 'edit' );

			// First pass: clone all variation translations.
			$clones = [];
			foreach ( $child_tr_ids as $tr_lang => $tr_id ) {
				$tr_child = \wc_get_product( $tr_id );
				if ( ! $tr_child instanceof \WC_Product ) {
					continue;
				}

				$tr_child->read_meta_data();
				$clone = clone $tr_child;
				$clone->set_parent_id( \pll_get_post( $duplicate->get_id(), $tr_lang ) ?: $duplicate->get_id() );
				$clone->set_id( 0 );
				$clone->set_date_created( null );
				if ( $has_sku ) {
					$clone->set_sku( '' );
				}

				if ( method_exists( $clone, 'set_global_unique_id' ) ) {
					$clone->set_global_unique_id( '' );
				}

				$this->generateUniqueSlug( $clone );

				foreach ( $meta_to_exclude as $meta_key ) {
					$clone->delete_meta_data( $meta_key );
				}

				do_action( 'woocommerce_product_duplicate_before_save', $clone, $tr_child );
				$clones[ $tr_lang ] = $clone;
			}

			// Second pass: save and set languages.
			foreach ( $clones as $tr_lang => $clone ) {
				$clone->save();
				$new_child_tr_ids[ $tr_lang ] = $clone->get_id();
				\pll_set_post_language( $clone->get_id(), $tr_lang );
			}

			if ( ! empty( $new_child_tr_ids ) ) {
				\pll_save_post_translations( $new_child_tr_ids );
			}

			if ( $has_sku ) {
				foreach ( $clones as $clone ) {
					$clone->set_sku( $sku );
					$clone->save();
				}
			}
		}
	}

	/**
	 * Generate a unique slug for a variation to avoid slow wp_unique_post_slug queries.
	 *
	 * @param \WC_Product $product Product to generate slug for.
	 */
	private function generateUniqueSlug( \WC_Product $product ): void {
		$db        = DB::db();
		$root_slug = preg_replace( '/-[0-9]+$/', '', $product->get_slug() );

		$results = $db->get_results(
			$db->prepare(
				"SELECT post_name FROM {$db->posts} WHERE post_name LIKE %s AND post_type IN ('product','product_variation')",
				$root_slug . '%'
			)
		);

		if ( empty( $results ) ) {
			return;
		}

		$max_suffix = 1;
		foreach ( $results as $result ) {
			$suffix = (int) substr( $result->post_name, strrpos( $result->post_name, '-' ) + 1 );
			if ( $suffix > $max_suffix ) {
				$max_suffix = $suffix;
			}
		}

		$product->set_slug( $root_slug . '-' . ( $max_suffix + 1 ) );
	}
}
