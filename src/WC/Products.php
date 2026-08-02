<?php
/**
 * W6+W7+W8: Product synchronization across translations.
 *
 * Syncs: stock (shared across translations), SKU (unique per language),
 * and product properties (price, weight, dimensions) on save.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

use HD\Core\DB;

defined( 'ABSPATH' ) || exit;

final class Products {

	public function __construct() {
		// Sync stock across translations (SQL-level for atomicity).
		add_filter( 'woocommerce_update_product_stock_query', [ $this, 'updateProductStockQuery' ], 10, 2 );
		add_action( 'woocommerce_updated_product_stock', [ $this, 'updatedProductStock' ] );
		add_filter( 'woocommerce_query_for_reserved_stock', [ $this, 'queryForReservedStock' ], 10, 2 );

		// Allow same SKU across translations.
		add_filter( 'wc_product_has_unique_sku', [ $this, 'uniqueSku' ], 10, 3 );

		// Sync product data on save.
		add_action( 'woocommerce_update_product', [ $this, 'syncProductData' ] );

		// Translate upsell/cross-sell IDs.
		if ( \PLL() instanceof \PLL_Frontend ) {
			add_filter( 'woocommerce_product_get_upsell_ids', [ $this, 'translateProductIds' ] );
			add_filter( 'woocommerce_product_get_cross_sell_ids', [ $this, 'translateProductIds' ] );
		}
	}

	/* ---------- Stock Sync (W7) ---------- */

	/**
	 * Modify stock update SQL to apply to ALL translations simultaneously.
	 *
	 * @param string $sql        SQL query.
	 * @param int    $product_id Product ID.
	 *
	 * @return string Modified SQL.
	 */
	public function updateProductStockQuery( string $sql, int $product_id ): string {
		$tr_ids = $this->getTranslationIds( $product_id );

		if ( count( $tr_ids ) <= 1 ) {
			return $sql;
		}

		$db          = DB::db();
		$placeholder = $db->prepare( 'post_id = %d', $product_id );
		$in_clause   = sprintf( 'post_id IN ( %s )', implode( ',', array_map( 'absint', $tr_ids ) ) );

		// Try both quoted and unquoted variants WC might use.
		$replaced = str_replace( $placeholder, $in_clause, $sql );

		if ( $replaced === $sql ) {
			// Fallback: try backtick-quoted column name.
			$placeholder_bt = $db->prepare( '`post_id` = %d', $product_id );
			$in_clause_bt   = sprintf( '`post_id` IN ( %s )', implode( ',', array_map( 'absint', $tr_ids ) ) );
			$replaced       = str_replace( $placeholder_bt, $in_clause_bt, $sql );
		}

		return $replaced;
	}

	/**
	 * After stock is updated, clear cache + update lookup table for translations.
	 *
	 * @param int $id Product ID.
	 */
	public function updatedProductStock( int $id ): void {
		$tr_ids = $this->getTranslationIds( $id );

		if ( count( $tr_ids ) <= 1 ) {
			return;
		}

		// Get source stock values for lookup table sync.
		$source = \wc_get_product( $id );
		if ( ! $source ) {
			return;
		}

		$db             = DB::db();
		$lookup_table   = $db->prefix . 'wc_product_meta_lookup';
		$stock_quantity = $source->get_stock_quantity();
		$stock_status   = $source->get_stock_status();

		foreach ( $tr_ids as $tr_id ) {
			if ( $tr_id === $id ) {
				continue;
			}

			$product = \wc_get_product( $tr_id );
			if ( ! $product ) {
				continue;
			}

			// Clear post meta cache so WC reads fresh values.
			$managed_by = $product->get_stock_managed_by_id();
			wp_cache_delete( $managed_by, 'post_meta' );
			wp_cache_delete( $tr_id, 'post_meta' );

			// Sync wc_product_meta_lookup so catalog filtering reflects correct stock.
			$db->update(
				$lookup_table,
				[
					'stock_quantity' => $stock_quantity,
					'stock_status'   => $stock_status,
				],
				[ 'product_id' => $tr_id ],
				[ '%f', '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Include translations in reserved stock query for accurate checkout validation.
	 */
	public function queryForReservedStock( string $query, int $product_id ): string {
		$tr_ids = $this->getTranslationIds( $product_id );

		if ( count( $tr_ids ) <= 1 ) {
			return $query;
		}

		$db = DB::db();

		return str_replace(
			$db->prepare( 'AND stock_table.`product_id` = %d', $product_id ),
			$db->prepare(
				sprintf(
					'AND stock_table.`product_id` IN ( %s )',
					implode( ', ', array_fill( 0, count( $tr_ids ), '%d' ) )
				),
				...array_map( 'intval', $tr_ids )
			),
			$query
		);
	}

	/* ---------- Unique SKU (W8) ---------- */

	/**
	 * Allow same SKU across translations of the same product.
	 *
	 * WC passes $sku_found = true when another product with same SKU exists.
	 * Return false to indicate "no conflict" when that product is a translation.
	 *
	 * @param bool   $sku_found  True if duplicate SKU found.
	 * @param int    $product_id Product ID being checked.
	 * @param string $sku        The SKU value.
	 *
	 * @return bool
	 */
	public function uniqueSku( bool $sku_found, int $product_id, string $sku ): bool {
		if ( ! $sku_found ) {
			return false; // No duplicate → SKU is unique, nothing to do.
		}

		// Find the product that has this SKU.
		$existing_id = \wc_get_product_id_by_sku( $sku );
		if ( ! $existing_id ) {
			return false;
		}

		// If the existing product is a translation of $product_id → no conflict.
		$tr_ids = $this->getTranslationIds( $product_id );

		return ! in_array( $existing_id, $tr_ids, true );
	}

	/* ---------- Product Data Sync (W6) ---------- */

	/**
	 * Sync key product properties to translations on save.
	 *
	 * @param int $product_id Product ID that was saved.
	 */
	public function syncProductData( int $product_id ): void {
		static $syncing = false;

		if ( $syncing ) {
			return;
		}

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$tr_ids = $this->getTranslationIds( $product_id );

		if ( count( $tr_ids ) <= 1 ) {
			return;
		}

		$syncing = true;

		try {
			foreach ( $tr_ids as $tr_id ) {
				if ( $tr_id === $product_id ) {
					continue;
				}

				$tr_product = \wc_get_product( $tr_id );
				if ( ! $tr_product ) {
					continue;
				}

				// Sync stock config props (actual stock values synced by SQL hooks).
				$tr_product->set_manage_stock( $product->get_manage_stock() );
				$tr_product->set_backorders( $product->get_backorders() );
				$tr_product->set_low_stock_amount( $product->get_low_stock_amount() );

				// Sync price props.
				$tr_product->set_regular_price( $product->get_regular_price() );
				$tr_product->set_sale_price( $product->get_sale_price() );
				$tr_product->set_date_on_sale_from( $product->get_date_on_sale_from() );
				$tr_product->set_date_on_sale_to( $product->get_date_on_sale_to() );

				// Sync physical props.
				$tr_product->set_weight( $product->get_weight() );
				$tr_product->set_length( $product->get_length() );
				$tr_product->set_width( $product->get_width() );
				$tr_product->set_height( $product->get_height() );

				// Sync catalog visibility.
				$tr_product->set_catalog_visibility( $product->get_catalog_visibility() );
				$tr_product->set_featured( $product->get_featured() );

				// Only save if there are actual changes.
				if ( ! empty( $tr_product->get_changes() ) ) {
					$tr_product->save();
				}
			}
		} finally {
			$syncing = false;
		}
	}

	/* ---------- Frontend Translation ---------- */

	/**
	 * Translate product IDs (upsell/cross-sell) to current language.
	 *
	 * @param int[] $ids Product IDs.
	 *
	 * @return int[]
	 */
	public function translateProductIds( array $ids ): array {
		return array_map(
			static function ( int $id ): int {
				$tr_id = \pll_get_post( $id );

				return $tr_id ?: $id;
			},
			$ids
		);
	}

	/* ---------- Helpers ---------- */

	/**
	 * Get all translation IDs for a product (including itself).
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return int[]
	 */
	private function getTranslationIds( int $product_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return [ $product_id ];
		}

		$translations = \pll_get_post_translations( $product_id );

		return ! empty( $translations ) ? array_values( array_map( 'intval', $translations ) ) : [ $product_id ];
	}
}
