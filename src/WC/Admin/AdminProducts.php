<?php
/**
 * W-A1: Admin Product UI for Polylang.
 *
 * - Filters ajax product search results by language.
 * - Fixes product list table search when WC uses post__in.
 * - Syncs menu_order across translations when drag-sorting.
 *
 * @package HDPLL\WC\Admin
 */

declare(strict_types=1);

namespace HDPLL\WC\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminProducts {

	public function __construct() {
		// Filter ajax product search by language.
		add_filter( 'woocommerce_json_search_found_products', [ $this, 'searchFoundProducts' ] );
		add_filter( 'woocommerce_json_search_found_grouped_products', [ $this, 'searchFoundProducts' ] );

		// Fix product list search with post__in.
		add_filter( 'pll_filter_query_excluded_query_vars', [ $this, 'fixProductsSearch' ], 10, 2 );

		// Sync menu_order across translations.
		if ( in_array( 'menu_order', \PLL()->options['sync'] ?? [], true ) ) {
			add_action( 'woocommerce_after_product_ordering', [ $this, 'productOrdering' ], 10, 2 );
		}
	}

	/**
	 * Filter ajax product search results to show only products in the same language.
	 *
	 * @param string[] $products Product IDs as keys, names as values.
	 *
	 * @return string[]
	 */
	public function searchFoundProducts( array $products ): array {
		// Determine the target language from the post being edited, or the admin preferred language.
		$lang = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['pll_post_id'] ) ) {
			$lang = \pll_get_post_language( absint( $_REQUEST['pll_post_id'] ) );
		}

		if ( ! $lang ) {
			$lang = \pll_current_language();
		}

		if ( ! $lang ) {
			return $products;
		}

		foreach ( array_keys( $products ) as $id ) {
			if ( \pll_get_post_language( (int) $id ) !== $lang ) {
				unset( $products[ $id ] );
			}
		}

		return $products;
	}

	/**
	 * Fix product list search: WC uses post__in which PLL excludes from language filter.
	 * Re-include it for product searches so results are language-filtered.
	 *
	 * @param string[]  $excludes Query vars excluded from language filter.
	 * @param \WP_Query $query    WP_Query object.
	 *
	 * @return string[]
	 */
	public function fixProductsSearch( array $excludes, \WP_Query $query ): array {
		if ( ! empty( $query->query['product_search'] ) ) {
			$excludes = array_diff( $excludes, [ 'post__in' ] );
		}

		return $excludes;
	}

	/**
	 * Sync menu_order to translations when products are drag-sorted.
	 *
	 * @param int   $id          Product ID that was sorted.
	 * @param int[] $menu_orders Product IDs as keys, menu_order as values.
	 */
	public function productOrdering( int $id, array $menu_orders ): void {
		$language = \pll_get_post_language( $id );

		foreach ( $menu_orders as $product_id => $order ) {
			if ( \pll_get_post_language( $product_id ) !== $language ) {
				continue;
			}

			$translations = \pll_get_post_translations( $product_id );

			foreach ( $translations as $tr_id ) {
				if ( (int) $tr_id !== $product_id ) {
					wp_update_post(
						[
							'ID'         => (int) $tr_id,
							'menu_order' => (int) $order,
						]
					);
				}
			}
		}
	}
}
