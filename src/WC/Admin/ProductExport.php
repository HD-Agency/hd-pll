<?php
/**
 * W-B2: Product CSV Export — adds language & translation group columns.
 *
 * Hooks into WooCommerce's built-in Products > Export CSV to include
 * Polylang language slug and translation group name per product row.
 *
 * @package HDPLL\WC\Admin
 */

declare(strict_types=1);

namespace HDPLL\WC\Admin;

defined( 'ABSPATH' ) || exit;

final class ProductExport {

	public function __construct() {
		add_filter( 'woocommerce_product_export_product_default_columns', [ $this, 'defaultColumns' ] );
		add_filter( 'woocommerce_product_export_row_data', [ $this, 'rowData' ], 10, 2 );
	}

	/**
	 * Add language and translation group to default export columns.
	 *
	 * @param string[] $columns Default columns.
	 *
	 * @return string[]
	 */
	public function defaultColumns( array $columns ): array {
		$columns['language']          = __( 'Language', 'hd-pll' );
		$columns['translation_group'] = __( 'Translation group', 'hd-pll' );

		return $columns;
	}

	/**
	 * Populate language and translation group values for each exported row.
	 *
	 * @param array       $row     Row data.
	 * @param \WC_Product $product Product object.
	 *
	 * @return array
	 */
	public function rowData( array $row, \WC_Product $product ): array {
		$id = $product->get_id();

		if ( isset( $row['language'] ) ) {
			$row['language'] = pll_get_post_language( $id ) ?: '';
		}

		if ( isset( $row['translation_group'] ) ) {
			$row['translation_group'] = $this->getTranslationGroupName( $id );
		}

		return $row;
	}

	/**
	 * Get a stable translation group name for a product.
	 *
	 * Uses the `post_translations` taxonomy term name that Polylang assigns
	 * to link translations together. Returns empty string if none found.
	 *
	 * @param int $postId Product ID.
	 *
	 * @return string
	 */
	private function getTranslationGroupName( int $postId ): string {
		$terms = get_the_terms( $postId, 'post_translations' );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		return $terms[0]->name;
	}
}
