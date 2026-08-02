<?php
/**
 * W2: Shop page rewrite rules for multilingual WooCommerce.
 *
 * Modifies product archive rewrite rules to support translated shop page slugs
 * across all languages (e.g., /shop/ and /cua-hang/).
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class ShopRewrite {

	public function __construct() {
		add_action( 'pll_prepare_rewrite_rules', [ $this, 'prepareRewriteRules' ], 5 );
		add_filter( 'pll_modify_rewrite_rule', [ $this, 'fixRewriteRules' ], 10, 4 );
	}

	/**
	 * Register rewrite rules filters for the shop page.
	 */
	public function prepareRewriteRules(): void {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;
		add_filter( 'rewrite_rules_array', [ $this, 'rewriteShopRules' ], 5 );
		add_filter( 'rewrite_rules_array', [ $this, 'rewriteShopSubpagesRules' ], 20 );
	}

	/**
	 * Modify product archive rewrite rules to support translated shop slugs.
	 *
	 * @param string[] $rules Rewrite rules.
	 *
	 * @return string[]
	 */
	public function rewriteShopRules( array $rules ): array {
		$new_rules = [];
		$id        = \wc_get_page_id( 'shop' );

		if ( ! $id ) {
			return $rules;
		}

		$uri          = urldecode( (string) \get_page_uri( $id ) ) . '/';
		$translations = WCPages::getShopPageSlugs();

		if ( count( $translations ) <= 1 ) {
			return $rules;
		}

		if ( \PLL()->options['force_lang'] > 0 ) {
			// Language from directory/subdomain/domain.
			$translations = array_unique( $translations );
			$new_uri      = '(' . implode( '|', $translations ) . ')/';

			foreach ( $rules as $key => $rule ) {
				if ( str_starts_with( $key, $uri ) ) {
					$new_rules[ str_replace( $uri, $new_uri, $key ) ] = str_replace(
						[ '[8]', '[7]', '[6]', '[5]', '[4]', '[3]', '[2]', '[1]' ],
						[ '[9]', '[8]', '[7]', '[6]', '[5]', '[4]', '[3]', '[2]' ],
						$rule
					);

					unset( $rules[ $key ] );
				}
			}
		} else {
			// Language from content — explicit rules per language.
			foreach ( $rules as $key => $rule ) {
				if ( str_starts_with( $key, $uri ) && str_contains( $rule, 'post_type=product' ) ) {
					foreach ( $translations as $lang => $new_uri ) {
						$new_rules[ str_replace( $uri, $new_uri . '/', $key ) ] = str_replace( '?', "?lang={$lang}&", $rule );
					}

					unset( $rules[ $key ] );
				}
			}
		}

		return $new_rules + $rules;
	}

	/**
	 * Add rewrite rules for shop subpages (e.g., children of shop page).
	 *
	 * @param string[] $rules Rewrite rules.
	 *
	 * @return string[]
	 */
	public function rewriteShopSubpagesRules( array $rules ): array {
		global $wp_rewrite;

		$permalinks         = \wc_get_permalink_structure();
		$page_rewrite_rules = [];

		if ( empty( $permalinks['use_verbose_page_rules'] ) ) {
			return $rules;
		}

		$id = \wc_get_page_id( 'shop' );
		if ( ! $id ) {
			return $rules;
		}

		foreach ( \pll_get_post_translations( $id ) as $lang => $shop_page_id ) {
			$subpages = \wc_get_page_children( $shop_page_id );

			foreach ( $subpages as $subpage ) {
				$uri = urldecode( (string) \get_page_uri( $subpage ) );

				// Remove WC-added rules — easier to add our own.
				foreach ( $rules as $key => $rule ) {
					if ( str_contains( $rule, "pagename={$uri}" ) ) {
						unset( $rules[ $key ] );
					}
				}

				if ( \PLL()->options['hide_default'] && \PLL()->options['default_lang'] === $lang ) {
					$slug = $uri;
				} else {
					$slug = $lang . '/' . $uri;
				}

				$page_rewrite_rules[ $slug . '/?$' ] = 'index.php?pagename=' . $uri;

				$wp_generated = $wp_rewrite->generate_rewrite_rules( $slug, EP_PAGES, true, true, false, false );
				foreach ( $wp_generated as $key => $value ) {
					$wp_generated[ $key ] = $value . '&pagename=' . $uri;
				}

				$page_rewrite_rules = array_merge( $page_rewrite_rules, $wp_generated );
			}
		}

		return $page_rewrite_rules + $rules;
	}

	/**
	 * Prevent Polylang from modifying certain WC rewrite rules.
	 *
	 * @param bool        $modify  Whether to modify the rule.
	 * @param string[]    $rule    Original rewrite rule.
	 * @param string      $filter  Current rules filter.
	 * @param string|bool $archive CPT archive name or false.
	 *
	 * @return bool
	 */
	public function fixRewriteRules( bool $modify, array $rule, string $filter, string|bool $archive ): bool {
		// Don't modify wc-api rules.
		if ( 'root' === $filter && str_contains( reset( $rule ), 'wc-api=$matches[2]' ) ) {
			return false;
		}

		// Don't modify product rules when language is set from content.
		if ( ! \PLL()->options['force_lang'] && 'rewrite_rules_array' === $filter && 'product' === $archive ) {
			return false;
		}

		return $modify;
	}
}
