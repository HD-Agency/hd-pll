<?php
/**
 * W5: Cart language persistence.
 *
 * Translates cart items (product IDs, variation IDs, attributes)
 * when the user switches language, preserving the cart contents.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class Cart {

	public function __construct() {
		// Translate cart items when language switches.
		add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'translateCartContents' ], 20 );

		// Ensure add-to-cart uses the correct translation.
		add_filter( 'woocommerce_add_to_cart_product_id', [ $this, 'addToCartProductId' ] );
	}

	/**
	 * Translate cart contents when loaded from session.
	 *
	 * Updates product/variation IDs and data objects IN-PLACE,
	 * preserving the original cart_item_key for 3rd-party plugin compatibility
	 * (subscriptions, bundles, add-ons, etc.).
	 */
	public function translateCartContents(): void {
		if ( ! function_exists( 'pll_current_language' ) || ! \WC()->cart ) {
			return;
		}

		$lang = \pll_current_language();
		if ( ! $lang || ! is_string( $lang ) ) {
			return;
		}

		$changed = false;

		foreach ( array_keys( \WC()->cart->cart_contents ) as $key ) {
			if ( $this->translateCartItem( \WC()->cart->cart_contents[ $key ], $lang ) ) {
				$changed = true;
			}
		}

		if ( $changed ) {
			\WC()->cart->set_session();
		}
	}

	/**
	 * Translate a single cart item to the target language (in-place).
	 *
	 * @param array  $item Cart item data (by reference).
	 * @param string $lang Target language slug.
	 *
	 * @return bool True if item was translated.
	 */
	private function translateCartItem( array &$item, string $lang ): bool {
		$product_id   = $item['product_id'];
		$variation_id = $item['variation_id'] ?? 0;
		$changed      = false;

		// Translate product ID.
		$tr_product_id = \pll_get_post( $product_id, $lang );
		if ( $tr_product_id && $tr_product_id !== $product_id ) {
			$tr_product = \wc_get_product( $tr_product_id );
			if ( $tr_product ) {
				$item['product_id'] = $tr_product_id;
				$item['data']       = $tr_product;
				$changed            = true;
			}
		}

		// Translate variation ID.
		if ( $variation_id ) {
			$tr_variation_id = \pll_get_post( $variation_id, $lang );
			if ( $tr_variation_id && $tr_variation_id !== $variation_id ) {
				$tr_variation = \wc_get_product( $tr_variation_id );
				if ( $tr_variation ) {
					$item['variation_id'] = $tr_variation_id;
					$item['data']         = $tr_variation;
					$item['variation']    = $this->translateAttributes( $item['variation'] ?? [], $lang );
					$changed              = true;
				}
			}
		}

		return $changed;
	}

	/**
	 * Translate variation attributes to target language.
	 *
	 * @param array  $attributes Variation attributes (attribute_pa_color => blue).
	 * @param string $lang       Target language slug.
	 *
	 * @return array Translated attributes.
	 */
	private function translateAttributes( array $attributes, string $lang ): array {
		foreach ( $attributes as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$taxonomy = \wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $name ) ) );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'slug'       => $value,
					'hide_empty' => false,
				]
			);

			if ( is_wp_error( $terms ) || empty( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			$term    = reset( $terms );
			$term_id = \pll_get_term( $term->term_id, $lang );

			if ( ! $term_id ) {
				continue;
			}

			$tr_term = get_term( $term_id, $taxonomy );
			if ( $tr_term instanceof \WP_Term ) {
				$attributes[ $name ] = $tr_term->slug;
			}
		}

		return $attributes;
	}

	/**
	 * Ensure add-to-cart resolves to the product in the current language.
	 *
	 * @param int $product_id Product ID being added.
	 *
	 * @return int Translated product ID.
	 */
	public function addToCartProductId( int $product_id ): int {
		if ( ! function_exists( 'pll_get_post' ) ) {
			return $product_id;
		}

		$tr_id = \pll_get_post( $product_id );

		return $tr_id ?: $product_id;
	}
}
