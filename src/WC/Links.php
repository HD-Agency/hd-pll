<?php
/**
 * W10: WooCommerce translation URLs for language switcher.
 *
 * Handles translating the current URL when switching languages,
 * including shop pages, endpoints, breadcrumbs, and permalinks.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class Links {

	public function __construct() {
		// Translation of the current URL.
		add_filter( 'pll_translation_url', [ $this, 'translationUrl' ], 10, 2 );

		// Breadcrumb home URL.
		add_filter( 'woocommerce_breadcrumb_home_url', 'pll_home_url', 10, 0 );

		// Shop permalink base on frontend.
		if ( \PLL() instanceof \PLL_Frontend ) {
			add_filter( 'option_woocommerce_permalinks', [ $this, 'filterWoocommercePermalinks' ] );
		}

		// Order received URL in correct language.
		add_filter( 'woocommerce_get_checkout_order_received_url', [ $this, 'checkoutOrderReceivedUrl' ], 10, 2 );

		// Download URLs for subdomains/multi-domains.
		if ( \PLL()->options['force_lang'] > 1 ) {
			add_filter( 'woocommerce_customer_available_downloads', [ $this, 'filterDownloadUrls' ] );
			add_filter( 'woocommerce_get_item_downloads', [ $this, 'filterDownloadUrls' ] );
			add_filter( 'woocommerce_product_file_download_path', [ $this, 'filterDownloadUrl' ] );
		}
	}

	/**
	 * Returns the translation URL for WC pages.
	 *
	 * @param string|null $url  Translation URL (null when no translation exists).
	 * @param string      $lang Language slug.
	 *
	 * @return string|null
	 */
	public function translationUrl( ?string $url, string $lang ): ?string {
		global $wp;

		// Shop page.
		if ( \is_shop() && ! \is_search() ) {
			$url        = null;
			$tr_shop_id = \pll_get_post( \wc_get_page_id( 'shop' ), $lang );

			if ( $tr_shop_id ) {
				$url = \get_permalink( $tr_shop_id );

				// Layered nav attributes (values are slugs, not IDs).
				foreach ( \wc_get_attribute_taxonomies() as $tax ) {
					$name = 'filter_' . $tax->attribute_name;

					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( empty( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) {
						continue;
					}

					$taxonomy = \wc_attribute_taxonomy_name( $tax->attribute_name );
					$slugs    = explode( ',', sanitize_text_field( $_GET[ $name ] ) );
					$tr_slugs = [];

					foreach ( $slugs as $slug ) {
						$term = get_term_by( 'slug', trim( $slug ), $taxonomy );
						if ( $term instanceof \WP_Term ) {
							$tr_term_id = \pll_get_term( $term->term_id, $lang );
							$tr_term    = $tr_term_id ? get_term( $tr_term_id, $taxonomy ) : null;
							$tr_slugs[] = $tr_term instanceof \WP_Term ? $tr_term->slug : $slug;
						} else {
							$tr_slugs[] = $slug;
						}
					}

					if ( ! empty( $tr_slugs ) ) {
						$url = \add_query_arg( [ $name => implode( ',', $tr_slugs ) ], $url );
					}
				}
			}
		}

		if ( null === $url ) {
			return null;
		}

		// WC endpoints (my-account, checkout endpoints).
		$endpoint = \WC()->query->get_current_endpoint();
		if ( $endpoint ) {
			$value = \wc_edit_address_i18n( $wp->query_vars[ $endpoint ], true );
			$url   = \wc_get_endpoint_url( $endpoint, $value, $url );

			if ( \PLL()->links_model->using_permalinks ) {
				$url = \trailingslashit( $url );
			}

			if ( 'order-received' === $endpoint ) {
				$order = \wc_get_order( $value );
				if ( $order instanceof \WC_Order ) {
					$url = \add_query_arg( 'key', $order->get_order_key(), $url );
				}
			}

			if ( 'order-pay' === $endpoint ) {
				$order = \wc_get_order( $value );
				if ( $order instanceof \WC_Order ) {
					$url = \add_query_arg(
						[
							'pay_for_order' => 'true',
							'key'           => $order->get_order_key(),
						],
						$url
					);
				}
			}
		}

		return $url;
	}

	/**
	 * Shop breadcrumb permalink base to match current language.
	 *
	 * @param string[] $permalinks WC permalinks options.
	 *
	 * @return string[]
	 */
	public function filterWoocommercePermalinks( array $permalinks ): array {
		if ( ! isset( $permalinks['product_base'] ) || ! did_action( 'pll_language_defined' ) ) {
			return $permalinks;
		}

		$slugs = WCPages::getShopPageSlugs();
		$lang  = \pll_current_language();

		if ( count( $slugs ) > 1 && ! empty( $slugs[ $lang ] ) ) {
			$pattern                    = '#(' . implode( '|', $slugs ) . ')#';
			$permalinks['product_base'] = (string) preg_replace( $pattern, $slugs[ $lang ], $permalinks['product_base'] );
		}

		return $permalinks;
	}

	/**
	 * Ensure order-received URL matches the order language.
	 *
	 * @param string    $url   Order received URL.
	 * @param \WC_Order $order WC Order object.
	 *
	 * @return string
	 */
	public function checkoutOrderReceivedUrl( string $url, \WC_Order $order ): string {
		static $avoid_recursion = false;

		if ( $avoid_recursion ) {
			return $url;
		}

		$lang = $order->get_meta( '_pll_language' );
		if ( ! $lang ) {
			return $url;
		}

		$language = \PLL()->model->get_language( $lang );
		if ( ! $language ) {
			return $url;
		}

		$avoid_recursion = true;
		$saved_curlang   = \PLL()->curlang;
		\PLL()->curlang  = $language;

		add_filter( 'option_woocommerce_checkout_page_id', 'pll_get_post' );
		$url = $order->get_checkout_order_received_url();
		remove_filter( 'option_woocommerce_checkout_page_id', 'pll_get_post' );

		$avoid_recursion = false;
		\PLL()->curlang  = $saved_curlang;

		return $url;
	}

	/**
	 * Filter download URLs for subdomains/multi-domains.
	 *
	 * @param array $downloads List of downloads.
	 *
	 * @return array
	 */
	public function filterDownloadUrls( array $downloads ): array {
		if ( ! \PLL()->curlang instanceof \PLL_Language ) {
			return $downloads;
		}

		foreach ( $downloads as $key => $download ) {
			if ( empty( $download['download_url'] ) ) {
				continue;
			}

			$downloads[ $key ]['download_url'] = \PLL()->links_model->switch_language_in_link(
				$download['download_url'],
				\PLL()->curlang
			);
		}

		return $downloads;
	}

	/**
	 * Filter single download URL for subdomains/multi-domains.
	 *
	 * @param string $download_url File download URL.
	 *
	 * @return string
	 */
	public function filterDownloadUrl( string $download_url ): string {
		if ( empty( $download_url ) || ! \PLL()->curlang instanceof \PLL_Language ) {
			return $download_url;
		}

		return \PLL()->links_model->switch_language_in_link( $download_url, \PLL()->curlang );
	}
}
