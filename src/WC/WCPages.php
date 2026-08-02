<?php
/**
 * W1: Translate WooCommerce page IDs.
 *
 * Ensures WC pages (shop, cart, checkout, myaccount, terms) return
 * the translated page ID based on current Polylang language.
 *
 * Also adds post state labels ("— Shop Page", etc.) for translated pages
 * in admin page list, matching polylang-wc behavior.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class WCPages {

	/**
	 * WC page slugs whose option_woocommerce_{slug}_page_id needs translation.
	 */
	private const TRANSLATED_PAGES = [
		'myaccount',
		'shop',
		'cart',
		'checkout',
		'terms',
	];

	/**
	 * Hook filters to translate WC page IDs (frontend only).
	 *
	 * Uses option filter (not woocommerce_get_{page}_page_id) because
	 * some themes retrieve the option directly.
	 *
	 * Matching polylang-wc: pll_get_post filter is only added on frontend
	 * (pll_language_defined) to avoid breaking admin functions that rely
	 * on raw wc_get_page_id().
	 */
	public static function init(): void {
		static $initialized = false;

		if ( $initialized ) {
			return;
		}

		$initialized = true;

		foreach ( self::TRANSLATED_PAGES as $page ) {
			add_filter( "option_woocommerce_{$page}_page_id", 'pll_get_post' );
		}
	}

	/**
	 * Register admin-only hooks for WC pages.
	 * Called separately — does NOT add pll_get_post filter.
	 */
	public static function initAdmin(): void {
		add_filter( 'display_post_states', [ self::class, 'displayPostStates' ], 10, 2 );
	}

	/**
	 * Add post state labels for translations of WC special pages.
	 *
	 * WooCommerce core only checks exact ID match via wc_get_page_id(),
	 * which misses translated pages. We use pll_get_post_translations()
	 * to cover all languages.
	 *
	 * @param string[] $post_states Existing post display states.
	 * @param \WP_Post $post        Current post object.
	 *
	 * @return string[]
	 */
	public static function displayPostStates( array $post_states, \WP_Post $post ): array {
		$translated_labels = [
			'myaccount' => \__( 'My Account Page', 'hd-pll' ),
			'shop'      => \__( 'Shop Page', 'hd-pll' ),
			'cart'      => \__( 'Cart Page', 'hd-pll' ),
			'checkout'  => \__( 'Checkout Page', 'hd-pll' ),
			'terms'     => \__( 'Terms and Conditions Page', 'hd-pll' ),
		];

		foreach ( $translated_labels as $page => $label ) {
			$key = "wc_page_for_{$page}";

			// Skip if WooCommerce core already added this state.
			if ( isset( $post_states[ $key ] ) ) {
				continue;
			}

			// pll_get_post filter is NOT active in admin, so
			// wc_get_page_id() returns the raw (default lang) ID.
			$page_id = \wc_get_page_id( $page );
			if ( $page_id < 1 ) {
				continue;
			}

			$translations = array_map( 'intval', \pll_get_post_translations( $page_id ) );
			if ( \in_array( $post->ID, $translations, true ) ) {
				$post_states[ $key ] = $label;
			}
		}

		return $post_states;
	}

	/**
	 * Get all shop page slugs across languages.
	 *
	 * Shared by ShopRewrite (rewrite rules) and Links (URL translation).
	 *
	 * @return array<string, string> lang_slug => page_uri
	 */
	public static function getShopPageSlugs(): array {
		$slugs = [];
		$id    = \wc_get_page_id( 'shop' );
		$ids   = \pll_get_post_translations( $id );

		\_prime_post_caches( $ids );

		foreach ( $ids as $lang => $page_id ) {
			$slugs[ $lang ] = urldecode( (string) \get_page_uri( $page_id ) );
		}

		return array_filter( $slugs );
	}
}
