<?php
/**
 * W-A4: WooCommerce Post Types & Taxonomies Registration for Polylang.
 *
 * - Disables WC's built-in i18n slugs for product_cat/product_tag (conflicts with PLL).
 * - Registers WC taxonomies (product_cat, product_tag, pa_* attributes) into Polylang.
 * - Adds taxonomies to copy list when creating translations.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

use HD\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class PostTypes {

	public function __construct() {
		$permalinks = Helper::getOption( 'woocommerce_permalinks', [] );

		// Disable WC's built-in i18n slug translation (breaks PLL).
		add_filter(
			'woocommerce_taxonomy_args_product_cat',
			static function ( array $args ) use ( $permalinks ): array {
				$args['rewrite']['slug'] = ! empty( $permalinks['category_base'] )
					? $permalinks['category_base']
					: 'product-category';

				return $args;
			}
		);

		add_filter(
			'woocommerce_taxonomy_args_product_tag',
			static function ( array $args ) use ( $permalinks ): array {
				$args['rewrite']['slug'] = ! empty( $permalinks['tag_base'] )
					? $permalinks['tag_base']
					: 'product-tag';

				return $args;
			}
		);

		// Register WC taxonomies into Polylang.
		add_filter( 'pll_get_taxonomies', [ $this, 'translateTaxonomies' ], 10, 2 );

		// Register WC post types into Polylang.
		add_filter( 'pll_get_post_types', [ $this, 'translatePostTypes' ], 10, 2 );

		// Copy WC taxonomies when creating a new translation.
		add_filter( 'pll_copy_taxonomies', [ $this, 'copyTaxonomies' ] );
	}

	/**
	 * Register WC taxonomies into Polylang for language/translation management.
	 * Hide them from Polylang settings (auto-managed, not user-togglable).
	 *
	 * @param string[] $taxonomies PLL-managed taxonomies.
	 * @param bool     $hide       True when listing in Polylang settings UI.
	 *
	 * @return string[]
	 */
	public function translateTaxonomies( array $taxonomies, bool $hide ): array {
		// product_shipping_class is untranslated but hidden from PLL settings.
		unset( $taxonomies['product_shipping_class'] );

		$wc_taxonomies = self::getTranslatedTaxonomies();

		return $hide
			? array_diff( $taxonomies, $wc_taxonomies )
			: array_merge( $taxonomies, $wc_taxonomies );
	}

	/**
	 * Register WC post types into Polylang for language/translation management.
	 * Hide them from Polylang settings (auto-managed, not user-togglable).
	 *
	 * @param string[] $types PLL-managed post types.
	 * @param bool     $hide  True when listing in Polylang settings UI.
	 *
	 * @return string[]
	 */
	public function translatePostTypes( array $types, bool $hide ): array {
		$wc_types = [ 'product', 'product_variation' ];

		return $hide
			? array_diff( $types, $wc_types )
			: array_merge( $types, $wc_types );
	}

	/**
	 * Add WC taxonomies to the list of taxonomies to copy when creating a translation.
	 *
	 * @param string[] $taxonomies Taxonomies to copy/sync.
	 *
	 * @return string[]
	 */
	public function copyTaxonomies( array $taxonomies ): array {
		return array_merge(
			$taxonomies,
			[ 'product_type', 'product_shipping_class', 'product_visibility', 'product_cat', 'product_tag' ]
		);
	}

	/**
	 * Get all WC taxonomies that should be translated by Polylang.
	 *
	 * @return string[]
	 */
	private static function getTranslatedTaxonomies(): array {
		$woo_taxonomies = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' );

		foreach ( $woo_taxonomies as $key => $tax ) {
			$woo_taxonomies[ $key ] = 'pa_' . $tax;
		}

		return array_merge( [ 'product_cat', 'product_tag', 'product_brand' ], $woo_taxonomies );
	}
}
