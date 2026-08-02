<?php
/**
 * W12+W13: Order language storage (HPOS-compatible).
 *
 * Stores and retrieves order language using post meta `_pll_language`.
 * Works with both traditional CPT-based orders and HPOS custom tables.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class OrderLanguage {

	public function __construct() {
		// Save order language on checkout (frontend only).
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'setOrderLanguage' ] );

		// Assign language when order is created programmatically.
		add_action( 'woocommerce_new_order', [ $this, 'newOrder' ] );

		// Update customer locale when placing an order.
		add_action( 'woocommerce_new_order', [ $this, 'updateCustomerLocale' ] );

		// Admin: display language in order meta.
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'displayOrderLanguage' ] );

		// W-B5: HPOS Order Query — filter orders by language in admin list.
		if ( is_admin() ) {
			add_filter( 'woocommerce_order_query_args', [ $this, 'filterOrderQueryByLang' ] );
		}
	}

	/**
	 * Set the order language meta on checkout.
	 *
	 * @param int $order_id Order ID.
	 */
	public function setOrderLanguage( int $order_id ): void {
		$lang = $this->getCurrentLanguageSlug();
		if ( $lang ) {
			$this->saveLanguage( $order_id, $lang );
		}
	}

	/**
	 * Set language for programmatically created orders.
	 *
	 * @param int $order_id Order ID.
	 */
	public function newOrder( int $order_id ): void {
		// Only if language not already set.
		$existing = $this->getLanguage( $order_id );
		if ( $existing ) {
			return;
		}

		$lang = $this->getCurrentLanguageSlug();
		if ( $lang ) {
			$this->saveLanguage( $order_id, $lang );
		}
	}

	/**
	 * Update customer locale when they place an order in a different language.
	 *
	 * @param int $order_id Order ID.
	 */
	public function updateCustomerLocale( int $order_id ): void {
		if ( ! ( \PLL() instanceof \PLL_Frontend ) ) {
			return;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$order_lang   = $this->getLanguage( $order_id );
		$language     = $order_lang ? \PLL()->model->get_language( $order_lang ) : null;
		$order_locale = $language ? $language->locale : '';
		$user_locale  = get_user_meta( $user_id, 'locale', true );
		$sync_locale  = (bool) apply_filters(
			'hd_pll_sync_customer_locale_from_order',
			! empty( Plugin::getCachedOptions()['sync_customer_locale_from_order'] ),
			$user_id,
			$order_id,
			$order_locale,
			$user_locale
		);

		if ( ! empty( $order_locale ) && ( '' === $user_locale || $sync_locale ) && $order_locale !== $user_locale ) {
			update_user_meta( $user_id, 'locale', $order_locale );
		}
	}

	/**
	 * Display order language in admin.
	 *
	 * @param \WC_Order $order WC Order object.
	 */
	public function displayOrderLanguage( \WC_Order $order ): void {
		$lang = $this->getLanguage( $order->get_id() );
		if ( $lang ) {
			$language = \PLL()->model->get_language( $lang );
			$name     = $language ? $language->name : strtoupper( $lang );
			printf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html__( 'Language', 'hd-pll' ),
				esc_html( $name )
			);
		}
	}

	/**
	 * W-B5: Inject language meta_query into admin order list queries.
	 *
	 * Only applies when PLL language filter is active (curlang set).
	 * Does NOT filter frontend (My Account), cron, or CLI queries.
	 *
	 * @param array $args Query args.
	 *
	 * @return array
	 */
	public function filterOrderQueryByLang( array $args ): array {
		// Skip refund queries — refunds don't carry language meta.
		$type = $args['type'] ?? 'shop_order';
		if (
			'shop_order_refund' === $type ||
			( is_array( $type ) && in_array( 'shop_order_refund', $type, true ) )
		) {
			return $args;
		}

		// Respect explicit lang arg: 'all' = no filter, specific slug = filter by it.
		if ( isset( $args['pll_lang'] ) ) {
			if ( 'all' === $args['pll_lang'] || '' === $args['pll_lang'] ) {
				return $args;
			}
			$lang = $args['pll_lang'];
		} elseif ( ! empty( \PLL()->curlang ) ) {
			$lang = \PLL()->curlang->slug;
		} else {
			return $args;
		}

		$args['meta_query']   = $args['meta_query'] ?? [];
		$args['meta_query'][] = [
			'key'     => '_pll_language',
			'value'   => sanitize_key( $lang ),
			'compare' => '=',
		];

		return $args;
	}
	/* ---------- Data Access ---------- */

	/**
	 * Get the language slug of an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string|false Language slug or false.
	 */
	public static function getLanguage( int $order_id ): string|false {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$lang = $order->get_meta( '_pll_language', true );

		return ! empty( $lang ) ? (string) $lang : false;
	}

	/**
	 * Save the language for an order (HPOS-compatible via WC meta API).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $lang     Language slug.
	 */
	public static function saveLanguage( int $order_id, string $lang ): void {
		$order = \wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( '_pll_language', sanitize_key( $lang ) );
			$order->save();
		}
	}

	/**
	 * Get the PLL_Language object for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return \PLL_Language|null
	 */
	public static function getLanguageObject( int $order_id ): ?\PLL_Language {
		$slug = self::getLanguage( $order_id );
		if ( ! $slug ) {
			return null;
		}

		return \PLL()->model->get_language( $slug ) ?: null;
	}

	/* ---------- Helpers ---------- */

	/**
	 * Get current language slug.
	 */
	private function getCurrentLanguageSlug(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			return (string) \pll_current_language( 'slug' );
		}

		return '';
	}
}
