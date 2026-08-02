<?php
/**
 * WooCommerce frontend fixes for Polylang integration.
 *
 * Handles: product search form translation, canonical URL fixes,
 * shop archive link fixes, hidden language field in forms,
 * home URL whitelist for widgets, layered nav shared slugs,
 * cache key language suffix, and ajax endpoint translation.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

use HD\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public function __construct() {
		if ( did_action( 'pll_language_defined' ) ) {
			$this->init();
		} else {
			add_action( 'pll_language_defined', [ $this, 'init' ], 1 );
		}
	}

	/**
	 * Setup frontend hooks after language is defined.
	 */
	public function init(): void {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		// W-B3: Reset WC country/state name cache so they display in the current locale.
		if ( ! \PLL()->options['force_lang'] ) {
			$this->resetCountriesCache();
		}

		// Product search form — apply Polylang search filter.
		if ( is_callable( [ \PLL()->filters_search ?? null, 'get_search_form' ] ) ) {
			add_filter( 'get_product_search_form', [ \PLL()->filters_search, 'get_search_form' ], 99 );
			add_filter( 'render_block_woocommerce/product-search', [ \PLL()->filters_search, 'get_search_form' ] );
		}

		if ( ! \PLL()->options['force_lang'] ) {
			// Language set from content.
			if ( ! Helper::getOption( 'permalink_structure' ) ) {
				// Plain permalinks.
				add_filter( 'pll_check_canonical_url', [ $this, 'checkCanonicalUrl' ] );
				add_filter( 'pll_translation_url', [ $this, 'translationUrl' ], 10, 2 );
			} else {
				// Pretty permalinks.
				add_filter( 'post_type_archive_link', [ $this, 'postTypeArchiveLink' ], 99, 2 );
			}

			// Hidden language field in WC forms.
			foreach ( $this->formActions() as $action ) {
				add_action( $action, [ $this, 'languageFormField' ] );
			}

			add_filter( 'woocommerce_get_remove_url', [ $this, 'addLangQueryArg' ] );
		}

		// Home URL whitelist for WC widgets.
		add_filter( 'pll_home_url_white_list', [ $this, 'homeUrlWhiteList' ] );

		// Layered nav — fix shared slug taxonomy queries.
		add_filter( 'woocommerce_product_query_tax_query', [ $this, 'productTaxQuery' ] );

		// Price filter widget fix for subdomains.
		if ( \PLL()->options['force_lang'] > 1 ) {
			add_filter( 'home_url', [ $this, 'fixWidgetPriceFilter' ], 10, 2 );
		}

		// Object cache compatibility.
		add_filter( 'woocommerce_shortcode_products_query', [ $this, 'shortcodeProductsQuery' ] );
		add_filter( 'woocommerce_get_product_subcategories_cache_key', [ $this, 'productSubcategoriesCacheKey' ] );

		// Ajax endpoint.
		add_filter( 'woocommerce_ajax_get_endpoint', [ $this, 'ajaxGetEndpoint' ], 10, 2 );

		// Coming Soon page compat.
		add_filter( 'woocommerce_is_extension_store_page', [ $this, 'isStorePage' ] );

		// Variation attributes — translate option slugs to current language.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_args', [ $this, 'translateVariationOptions' ] );
	}

	/* ---------- Callbacks ---------- */

	/**
	 * Fix canonical redirect from shop page to product archive (plain permalinks).
	 */
	public function checkCanonicalUrl( string $redirect_url ): string|false {
		return \is_post_type_archive( 'product' ) ? false : $redirect_url;
	}

	/**
	 * Fix translation URL of shop page (plain permalinks, language from content).
	 */
	public function translationUrl( string $url, string $lang ): string {
		if ( ! \is_post_type_archive( 'product' ) ) {
			return $url;
		}

		$language = \PLL()->model->get_language( $lang );
		if ( ! $language ) {
			return $url;
		}

		if ( \PLL()->options['hide_default'] && 'page' === Helper::getOption( 'show_on_front' ) && \PLL()->options['default_lang'] === $language->slug ) {
			$pages = \pll_languages_list( [ 'fields' => 'page_on_front' ] );
			if ( in_array( \wc_get_page_id( 'shop' ), $pages, true ) ) {
				return $language->get_home_url();
			}
		}

		$url = \get_post_type_archive_link( 'product' );
		$url = \PLL()->links_model->switch_language_in_link( $url, $language );

		return \PLL()->links_model->remove_paged_from_link( $url );
	}

	/**
	 * Fix shop link (pretty permalinks, language from content).
	 */
	public function postTypeArchiveLink( string $link, string $post_type ): string {
		return 'product' === $post_type ? \wc_get_page_permalink( 'shop' ) : $link;
	}

	/**
	 * Output hidden language input field.
	 */
	public function languageFormField(): void {
		printf( '<input type="hidden" name="lang" value="%s" />', esc_attr( \pll_current_language() ) );
	}

	/**
	 * Add lang query arg to URL.
	 */
	public function addLangQueryArg( string $url ): string {
		return \add_query_arg( 'lang', \pll_current_language(), $url );
	}

	/**
	 * Whitelist WC widget files for home_url filter.
	 *
	 * @param string[][] $arr Whitelist.
	 *
	 * @return string[][]
	 */
	public function homeUrlWhiteList( array $arr ): array {
		$arr[] = [ 'file' => 'abstract-wc-widget.php' ];

		if ( \PLL()->options['force_lang'] > 0 ) {
			$arr[] = [ 'file' => 'class-wc-widget-product-categories.php' ];
		}

		if ( \PLL()->options['force_lang'] > 1 ) {
			$arr[] = [ 'file' => 'class-wc-widget-price-filter.php' ];
		}

		return $arr;
	}

	/**
	 * Fix layered nav: resolve shared slugs to term_taxonomy_ids.
	 */
	public function productTaxQuery( array $tax_query ): array {
		foreach ( $tax_query as $k => $q ) {
			if ( is_array( $q ) && ! empty( $q['field'] ) && 'slug' === $q['field'] ) {
				$terms = \get_terms(
					[
						'taxonomy' => $q['taxonomy'],
						'slug'     => $q['terms'],
					]
				);
				if ( is_array( $terms ) ) {
					$tax_query[ $k ]['terms'] = wp_list_pluck( $terms, 'term_taxonomy_id' );
					$tax_query[ $k ]['field'] = 'term_taxonomy_id';
				}
			}
		}

		return $tax_query;
	}

	/**
	 * Fix price filter widget form action URL for subdomains.
	 */
	public function fixWidgetPriceFilter( string $url, string $path ): string {
		global $wp;

		if ( ! empty( $wp->request ) && \trailingslashit( $wp->request ) === $path && ! empty( \PLL()->curlang ) ) {
			$url = \PLL()->links_model->switch_language_in_link( $url, \PLL()->curlang );
		}

		return $url;
	}

	/**
	 * Add language to shortcode product queries for cache key per language.
	 */
	public function shortcodeProductsQuery( array $args ): array {
		if ( empty( \PLL()->curlang ) ) {
			return $args;
		}

		$args['tax_query']   = $args['tax_query'] ?? [];
		$args['tax_query'][] = [
			'taxonomy' => 'language',
			'field'    => 'term_taxonomy_id',
			'terms'    => \PLL()->curlang->get_tax_prop( 'language', 'term_taxonomy_id' ),
			'operator' => 'IN',
		];

		return $args;
	}

	/**
	 * Make product subcategories cache key language-dependent.
	 */
	public function productSubcategoriesCacheKey( string $cache_key ): string {
		return $cache_key . '-' . \pll_current_language();
	}

	/**
	 * Ensure ajax endpoint is in the right language.
	 */
	public function ajaxGetEndpoint( string $url, string $request ): string {
		$url = \remove_query_arg( 'wc-ajax', $url );

		if ( ! empty( \PLL()->curlang ) ) {
			$url = \PLL()->links_model->switch_language_in_link( $url, \PLL()->curlang );
		}

		return \add_query_arg( 'wc-ajax', $request, $url );
	}

	/**
	 * Tell WC Coming Soon that product/taxonomy pages are store pages.
	 */
	public function isStorePage( bool $is_store_page ): bool {
		return ( \is_product() || \is_product_taxonomy() ) ? true : $is_store_page;
	}

	/* ---------- Helpers ---------- */

	/**
	 * Translate variation attribute option slugs to current language.
	 *
	 * WC stores source-language term slugs in variation attributes.
	 * When displayed in a translated language, slugs must match the
	 * current-language terms for dropdown/swatch rendering to work.
	 *
	 * @param array $args WC dropdown args.
	 *
	 * @return array
	 */
	public function translateVariationOptions( array $args ): array {
		$options   = $args['options'] ?? [];
		$attribute = $args['attribute'] ?? '';

		if ( empty( $options ) || empty( $attribute ) || ! taxonomy_exists( $attribute ) ) {
			return $args;
		}

		// Look up terms by slug without PLL language filter ('lang' => '').
		// WC stores source-language slugs — those won't be found by
		// get_term_by() which PLL filters to current language only.
		$source_terms = get_terms(
			[
				'taxonomy'   => $attribute,
				'slug'       => $options,
				'hide_empty' => false,
				'lang'       => '', // Bypass PLL language filter.
			]
		);

		if ( ! is_array( $source_terms ) || empty( $source_terms ) ) {
			return $args;
		}

		// Build slug → term_id map for source-language terms.
		$slugToId = [];
		foreach ( $source_terms as $t ) {
			$slugToId[ $t->slug ] = $t->term_id;
		}

		$normalized = [];
		foreach ( $options as $slug ) {
			if ( ! isset( $slugToId[ $slug ] ) ) {
				$normalized[] = $slug;
				continue;
			}

			$trId = \pll_get_term( $slugToId[ $slug ] );
			if ( $trId && $trId !== $slugToId[ $slug ] ) {
				$trTerm       = get_term( $trId, $attribute );
				$normalized[] = $trTerm instanceof \WP_Term ? $trTerm->slug : $slug;
			} else {
				$normalized[] = $slug;
			}
		}

		$args['options'] = $normalized;

		return $args;
	}

	/**
	 * WC form actions that need a hidden language field.
	 *
	 * @return string[]
	 */
	private function formActions(): array {
		return [
			'woocommerce_login_form_start',
			'woocommerce_register_form_start',
			'woocommerce_before_cart_table',
			'woocommerce_before_add_to_cart_button',
			'woocommerce_lostpassword_form',
		];
	}

	/**
	 * W-B3: Replace WC_Countries instance so country/state names
	 * are read from i18n files in the current PLL locale.
	 *
	 * Only needed when language is set from content (force_lang=0),
	 * because WC may have already cached names in the wrong locale.
	 */
	private function resetCountriesCache(): void {
		if ( function_exists( 'WC' ) && \WC()->countries instanceof \WC_Countries ) {
			\WC()->countries = new \WC_Countries();
		}
	}
}
