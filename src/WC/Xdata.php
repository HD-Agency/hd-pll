<?php
/**
 * W-B6: Cross-domain Data (Xdata) for WooCommerce.
 *
 * Manages cookie transfer when switching from one domain (or subdomain) to another.
 * Prevents loss of WC Session, Cart Hash, and Recently Viewed cookies when users
 * navigate between language subdomains (e.g. vi.shop.com -> en.shop.com).
 *
 * Includes embedded session manager for temporary cross-domain data storage.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class Xdata {

	public function __construct() {
		add_filter( 'pll_get_xdata', [ $this, 'getXdata' ] );
		add_action( 'pll_set_xdata', [ $this, 'setXdata' ] );
		add_filter( 'pll_xdata_session_manager', fn() => self::class );

		// If using completely different domains (force_lang === 3), make cookies cross-domain compatible.
		if ( 3 === (int) ( \PLL()->options['force_lang'] ?? 0 ) ) {
			add_filter( 'woocommerce_set_cookie_options', [ $this, 'setCookieOptions' ], 10, 2 );
		}
	}

	/**
	 * Get the WooCommerce cookies to transfer.
	 *
	 * @param array $data Existing data to transfer.
	 *
	 * @return array
	 */
	public function getXdata( array $data ): array {
		if ( isset( $_COOKIE['woocommerce_cart_hash'], $_COOKIE['woocommerce_items_in_cart'] ) ) {
			$data['wc'] = [
				'hash'  => sanitize_key( $_COOKIE['woocommerce_cart_hash'] ),
				'items' => (int) $_COOKIE['woocommerce_items_in_cart'],
			];
		}

		if ( isset( $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] ) ) {
			$data['wc']['session'] = sanitize_text_field( wp_unslash( $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] ) );
		}

		if ( isset( $_COOKIE['woocommerce_recently_viewed'] ) ) {
			$data['wc']['views'] = sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) );
		}

		return $data;
	}

	/**
	 * Set the transferred WooCommerce cookies on the new domain.
	 *
	 * @param array $data Transferred data payload.
	 */
	public function setXdata( array $data ): void {
		if ( empty( $data['wc'] ) ) {
			return;
		}

		$wcData = $data['wc'];

		if ( isset( $wcData['session'] ) ) {
			$expiration = time() + (int) apply_filters( 'wc_session_expiration', 48 * HOUR_IN_SECONDS );
			$secure     = apply_filters( 'wc_session_use_secure_cookie', false );
			wc_setcookie( 'wp_woocommerce_session_' . COOKIEHASH, $wcData['session'], $expiration, $secure );
		}

		if ( isset( $wcData['hash'], $wcData['items'] ) ) {
			wc_setcookie( 'woocommerce_cart_hash', $wcData['hash'] );
			wc_setcookie( 'woocommerce_items_in_cart', $wcData['items'] );
		}

		if ( isset( $wcData['views'] ) ) {
			wc_setcookie( 'woocommerce_recently_viewed', $wcData['views'] );
		}

		// Re-calculate shipping packages on domain change (WC 2.6+ requirement).
		if ( function_exists( 'WC' ) && isset( WC()->shipping, WC()->cart ) ) {
			WC()->shipping()->calculate_shipping( WC()->cart->get_shipping_packages() );
		}
	}

	/**
	 * Set cross-domain compatible cookie options when using separate domains.
	 *
	 * Requires SSL (secure=true) and SameSite=None.
	 *
	 * @param array  $options Cookie options.
	 * @param string $name    Cookie name.
	 *
	 * @return array
	 */
	public function setCookieOptions( array $options, string $name ): array {
		$cookies = [
			'wp_woocommerce_session_' . COOKIEHASH,
			'woocommerce_cart_hash',
			'woocommerce_items_in_cart',
			'woocommerce_recently_viewed',
		];

		if ( is_ssl() && in_array( $name, $cookies, true ) && apply_filters( 'hd_pll_allow_cookie_xdata', true, $name ) ) {
			$options['secure']   = true;
			$options['samesite'] = 'None';
		}

		return $options;
	}

	/* ---------- Embedded Session Manager ---------- */

	/**
	 * Writes cross-domain data to the WooCommerce session table.
	 *
	 * @param string $key    A unique hash key (session_key).
	 * @param array  $data   Data to store in the session.
	 * @param int    $userId Optional user ID.
	 */
	public static function set( string $key, array $data, int $userId = 0 ): void {
		global $wpdb;

		if ( empty( $userId ) ) {
			$userId = get_current_user_id();
		}

		if ( ! empty( $userId ) ) {
			$data['user_id'] = $userId;
		}

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_sessions',
			[
				'session_key'    => $key,
				'session_value'  => wp_json_encode( $data ),
				'session_expiry' => time() + ( 2 * MINUTE_IN_SECONDS ),
			],
			[ '%s', '%s', '%d' ]
		);
	}

	/**
	 * Reads cross-domain data from the session and deletes it to prevent replay.
	 *
	 * @param string $key Session key.
	 *
	 * @return array
	 */
	public static function get( string $key ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $key ) );

		if ( ! empty( $value->session_value ) && time() < (int) $value->session_expiry ) {
			$wpdb->delete( $table, [ 'session_key' => $key ] );
			$data = json_decode( $value->session_value, true );

			return is_array( $data ) ? $data : [];
		}

		// Expired or missing — clean up stale row if present.
		if ( ! empty( $value ) ) {
			$wpdb->delete( $table, [ 'session_key' => $key ] );
		}

		return [];
	}
}
