<?php
/**
 * W11: Coupon translation.
 *
 * Translates product/category restrictions in coupons
 * to match the current language's translations.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class Coupons {

	public function __construct() {
		add_action( 'woocommerce_coupon_loaded', [ $this, 'couponLoaded' ] );
	}

	/**
	 * Translate product and category restrictions to current language.
	 *
	 * @param \WC_Coupon $coupon Coupon data object.
	 */
	public function couponLoaded( \WC_Coupon $coupon ): void {
		if ( ! \pll_current_language() ) {
			return;
		}

		$coupon->set_product_ids(
			array_map( [ $this, 'maybeGetTranslatedProduct' ], $coupon->get_product_ids() )
		);

		$coupon->set_excluded_product_ids(
			array_map( [ $this, 'maybeGetTranslatedProduct' ], $coupon->get_excluded_product_ids() )
		);

		$coupon->set_product_categories(
			array_map( [ $this, 'maybeGetTranslatedTerm' ], $coupon->get_product_categories() )
		);

		$coupon->set_excluded_product_categories(
			array_map( [ $this, 'maybeGetTranslatedTerm' ], $coupon->get_excluded_product_categories() )
		);
	}

	/**
	 * Get translated product ID or fallback to original.
	 */
	private function maybeGetTranslatedProduct( int $id ): int {
		$tr_id = \pll_get_post( $id );

		return $tr_id ?: $id;
	}

	/**
	 * Get translated term ID or fallback to original.
	 */
	private function maybeGetTranslatedTerm( int $id ): int {
		$tr_id = \pll_get_term( $id );

		return $tr_id ?: $id;
	}
}
