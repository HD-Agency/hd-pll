<?php
/**
 * W-A5: WooCommerce Frontend Account for Polylang.
 *
 * - Shows all orders regardless of language in "My Account → Orders".
 * - Translates product names in order details to current language.
 * - Translates payment method title in order item totals.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class FrontendAccount {

	private array $allLangsCallback;

	public function __construct() {
		$this->allLangsCallback = [ $this, 'allLanguagesQueryArg' ];

		// Show all orders in My Account (HPOS-compatible).
		add_action( 'woocommerce_account_content', [ $this, 'addLanguageFilter' ], -100000 );
		add_action( 'woocommerce_account_content', [ $this, 'removeLanguageFilter' ], 100000 );

		// Legacy WP_Query fallback for non-HPOS.
		add_action( 'parse_query', [ $this, 'parseQuery' ], 3 ); // Before Polylang.

		// Translate product names in order details.
		add_filter( 'woocommerce_order_item_name', [ $this, 'orderItemName' ], 10, 3 );

		// Translate payment method in order totals.
		add_filter( 'woocommerce_get_order_item_totals', [ $this, 'translatePaymentMethod' ], 10, 2 );
	}

	/**
	 * Add filter to show orders in all languages (HPOS path).
	 */
	public function addLanguageFilter(): void {
		add_filter( 'woocommerce_order_query_args', $this->allLangsCallback );
	}

	/**
	 * Remove the all-languages filter after account content renders.
	 */
	public function removeLanguageFilter(): void {
		remove_filter( 'woocommerce_order_query_args', $this->allLangsCallback );
	}

	/**
	 * Set lang='' to fetch orders in all languages.
	 *
	 * @param array $query Order query args.
	 *
	 * @return array
	 */
	public function allLanguagesQueryArg( array $query ): array {
		$query['lang'] = '';

		return $query;
	}

	/**
	 * Disable language filter for shop_order queries on frontend (legacy WP_Query).
	 *
	 * @param \WP_Query $query WP_Query instance.
	 */
	public function parseQuery( \WP_Query $query ): void {
		$qvars = $query->query_vars;

		if (
			! isset( $qvars['lang'] )
			&& isset( $qvars['post_type'] )
			&& (
				'shop_order' === $qvars['post_type']
				|| ( is_array( $qvars['post_type'] ) && in_array( 'shop_order', $qvars['post_type'], true ) )
			)
		) {
			$query->set( 'lang', '' );
		}
	}

	/**
	 * Translate product name in order item to current language.
	 *
	 * @param string                $item_name  Product name HTML.
	 * @param \WC_Order_Item_Product $item       Order item.
	 * @param bool                  $is_visible Whether the product link is visible.
	 *
	 * @return string
	 */
	public function orderItemName( string $item_name, $item, bool $is_visible ): string {
		$product_id = $item->get_variation_id() ?: $item->get_product_id();
		$tr_id      = \pll_get_post( $product_id );

		if ( ! $tr_id || $tr_id === $product_id ) {
			return $item_name;
		}

		$product = \wc_get_product( $tr_id );
		if ( ! $product ) {
			return $item_name;
		}

		return $is_visible
			? sprintf( '<a href="%s">%s</a>', esc_url( $product->get_permalink() ), esc_html( $product->get_name() ) )
			: esc_html( $product->get_name() );
	}

	/**
	 * Translate payment method title in order item totals.
	 *
	 * @param array     $rows  Order item totals.
	 * @param \WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function translatePaymentMethod( array $rows, \WC_Order $order ): array {
		$payment_method = $order->get_payment_method();
		$gateways       = \WC_Payment_Gateways::instance()->payment_gateways();

		if ( isset( $gateways[ $payment_method ], $rows['payment_method'] ) ) {
			$rows['payment_method']['value'] = $gateways[ $payment_method ]->get_title();
		}

		return $rows;
	}
}
