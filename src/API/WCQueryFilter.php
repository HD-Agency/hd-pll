<?php
/**
 * WooCommerce REST API language integration.
 *
 * Only boots when:
 * - WooCommerce is active.
 * - Polylang for WooCommerce (pllwc) is NOT active.
 *
 * Step 5: Collection filtering via `?lang=` for products, variations, coupons, orders.
 * Step 6: `lang` + `translations` response fields for products, variations, coupons.
 *         `lang` only for orders (via _pll_language meta).
 *
 * Products, variations, coupons: use the WP content-language path (lang param on WP_Query).
 * Orders: map request `lang` to `_pll_language` meta via woocommerce_rest_orders_prepare_object_query.
 *
 * @package HDPLL\API
 */

declare(strict_types=1);

namespace HDPLL\API;

defined( 'ABSPATH' ) || exit;

final class WCQueryFilter {

	public function __construct() {
		// Step 5: collection filtering.
		add_filter( 'woocommerce_rest_product_object_query', [ $this, 'filterProductQuery' ], 10, 2 );
		add_filter( 'woocommerce_rest_product_variation_object_query', [ $this, 'filterProductQuery' ], 10, 2 );
		add_filter( 'woocommerce_rest_coupon_object_query', [ $this, 'filterProductQuery' ], 10, 2 );
		add_filter( 'woocommerce_rest_orders_prepare_object_query', [ $this, 'filterOrderQuery' ], 10, 2 );

		// Step 6: response fields.
		add_filter( 'woocommerce_rest_prepare_product_object', [ $this, 'addProductFields' ], 10, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', [ $this, 'addProductFields' ], 10, 3 );
		add_filter( 'woocommerce_rest_prepare_coupon_object', [ $this, 'addProductFields' ], 10, 3 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', [ $this, 'addOrderFields' ], 10, 3 );

		// Register `lang` as a valid query param on Woo endpoints.
		add_filter( 'woocommerce_rest_products_params', [ $this, 'registerLangParam' ] );
		add_filter( 'woocommerce_rest_product_variations_params', [ $this, 'registerLangParam' ] );
		add_filter( 'woocommerce_rest_coupons_params', [ $this, 'registerLangParam' ] );
		add_filter( 'woocommerce_rest_orders_params', [ $this, 'registerLangParam' ] );
	}

	/* ---------- Collection Param Registration ----------------------- */

	/**
	 * Register `lang` as a valid WooCommerce REST collection param.
	 *
	 * @param array $params Existing params.
	 *
	 * @return array
	 */
	public function registerLangParam( array $params ): array {
		$params['lang'] = [
			'description'       => __( 'Filter by Polylang language slug. Use "all" or omit to return all languages.', 'hd-pll' ),
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_key',
		];

		return $params;
	}

	/* ---------- Step 5: Collection Filtering ----------------------- */

	/**
	 * Inject `lang` into product, variation, and coupon WP_Query args.
	 *
	 * @param array            $args    WP_Query args built by WooCommerce.
	 * @param \WP_REST_Request  $request Full REST request.
	 *
	 * @return array
	 */
	public function filterProductQuery( array $args, \WP_REST_Request $request ): array {
		$lang = $this->getLangFromRequest( $request );
		if ( null !== $lang ) {
			$args['lang'] = $lang;
		}

		return $args;
	}

	/**
	 * Inject `_pll_language` meta_query into order WP_Query / HPOS query args.
	 *
	 * WooCommerce HPOS: args go into WC_Order_Query, which supports meta_query.
	 *
	 * @param array            $args    Query args built by WooCommerce.
	 * @param \WP_REST_Request  $request Full REST request.
	 *
	 * @return array
	 */
	public function filterOrderQuery( array $args, \WP_REST_Request $request ): array {
		$lang = $this->getLangFromRequest( $request );
		if ( null === $lang ) {
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

	/* ---------- Step 6: Response Fields ----------------------------- */

	/**
	 * Add `lang` and `translations` to product, variation, and coupon responses.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param \WC_Product|\WC_Coupon $item     WC item.
	 *
	 * @return \WP_REST_Response
	 */
	public function addProductFields( \WP_REST_Response $response, mixed $item ): \WP_REST_Response {
		$post_id = (int) ( method_exists( $item, 'get_id' ) ? $item->get_id() : 0 );
		if ( ! $post_id ) {
			return $response;
		}

		$data                 = $response->get_data();
		$data['lang']         = RestLanguageResolver::getPostLanguage( $post_id );
		$data['translations'] = RestLanguageResolver::getPostTranslations( $post_id );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Add `lang` (only) to order responses.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param \WC_Order         $order    WC order object.
	 *
	 * @return \WP_REST_Response
	 */
	public function addOrderFields( \WP_REST_Response $response, \WC_Order $order ): \WP_REST_Response {
		$lang = $order->get_meta( '_pll_language', true );

		$data         = $response->get_data();
		$data['lang'] = $lang ? (string) $lang : '';
		$response->set_data( $data );

		return $response;
	}

	/* ---------- Helpers --------------------------------------------- */

	/**
	 * Extract and normalise the `lang` param from a REST request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return string|null Language slug, or null for no filter.
	 */
	private function getLangFromRequest( \WP_REST_Request $request ): ?string {
		$lang = sanitize_key( (string) ( $request->get_param( 'lang' ) ?? '' ) );

		if ( '' === $lang || 'all' === $lang ) {
			return null;
		}

		return RestLanguageResolver::isValidLanguage( $lang ) ? $lang : null;
	}
}
