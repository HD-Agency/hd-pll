<?php
/**
 * W-B2: Product CSV Import — reads language & translation group columns.
 *
 * Hooks into WooCommerce's built-in Products > Import CSV to:
 * - Map "Language" and "Translation group" CSV columns.
 * - Assign Polylang language to each imported product.
 * - Link products into translation groups.
 * - Ensure term lookups (categories, tags) respect language context.
 *
 * @package HDPLL\WC\Admin
 */

declare(strict_types=1);

namespace HDPLL\WC\Admin;

defined( 'ABSPATH' ) || exit;

final class ProductImport {

	private bool $importing = false;

	public function __construct() {
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns', [ $this, 'defaultColumns' ] );
		add_filter( 'woocommerce_csv_product_import_mapping_options', [ $this, 'mappingOptions' ], 1 );
		add_action( 'woocommerce_product_import_inserted_product_object', [ $this, 'insertedProduct' ], 10, 2 );

		// Set preferred language early so term lookups are language-aware.
		add_action( 'woocommerce_product_importer_before_set_parsed_data', [ $this, 'beforeSetParsedData' ], 10, 2 );
		add_action( 'woocommerce_product_import_before_process_item', [ $this, 'setLanguageFromRow' ] );

		// Override category/tag parsing to respect language context.
		add_filter( 'woocommerce_product_importer_formatting_callbacks', [ $this, 'formattingCallbacks' ], 10, 2 );

		add_filter( 'pllwc_copy_post_metas', [ $this, 'disablePllWcCopyPostMetas' ], 999 );
		add_filter( 'get_terms_args', [ $this, 'filterTermsByLang' ], 5 );
	}

	/**
	 * Map CSV column headers to internal keys.
	 *
	 * @param string[] $mappings Default column mappings.
	 *
	 * @return string[]
	 */
	public function defaultColumns( array $mappings ): array {
		$mappings[ __( 'Language', 'hd-pll' ) ]          = 'language';
		$mappings[ __( 'Translation group', 'hd-pll' ) ] = 'translations';

		return $mappings;
	}

	/**
	 * Add language and translation group to the mapping dropdown options.
	 *
	 * Inserts them before "Price" for logical UI positioning.
	 *
	 * @param string[] $options Mapping options.
	 *
	 * @return string[]
	 */
	public function mappingOptions( array $options ): array {
		$pos = array_search( 'price', array_keys( $options ), true );

		$new = [
			'language'     => __( 'Language', 'hd-pll' ),
			'translations' => __( 'Translation group', 'hd-pll' ),
		];

		if ( false !== $pos ) {
			$before  = array_slice( $options, 0, $pos, true );
			$after   = array_slice( $options, $pos, null, true );
			$options = array_merge( $before, $new, $after );
		} else {
			$options = array_merge( $options, $new );
		}

		return $options;
	}

	/**
	 * After a product is imported, assign language and link translation group.
	 *
	 * @param \WC_Product $product Imported product object.
	 * @param array       $data    Row data from CSV.
	 */
	public function insertedProduct( \WC_Product $product, array $data ): void {
		try {
			$id   = $product->get_id();
			$lang = $data['language'] ?? '';

			if ( '' === $lang || ! \PLL()->model->get_language( $lang ) ) {
				return;
			}

			// Assign language.
			pll_set_post_language( $id, $lang );

			// Link translation group.
			if ( ! empty( $data['translations'] ) ) {
				$this->setTranslationGroup( $id, $lang, $data['translations'] );
			}

			// Re-generate slug if name is present (shared slug support).
			if ( ! empty( $data['name'] ) ) {
				$product->set_slug( $data['name'] );
				$product->save();
			}
		} finally {
			$this->importing = false;
		}
	}

	/**
	 * Create or join a translation group by name.
	 *
	 * @param int    $id        Product ID.
	 * @param string $lang      Language slug.
	 * @param string $groupName Translation group name from CSV.
	 */
	private function setTranslationGroup( int $id, string $lang, string $groupName ): void {
		$taxonomy = 'post_translations';
		$term     = get_term_by( 'name', $groupName, $taxonomy );

		if ( empty( $term ) ) {
			// Create new group.
			$translations = [ $lang => $id ];

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$result = wp_insert_term( $groupName, $taxonomy, [ 'description' => serialize( $translations ) ] );

			if ( ! is_wp_error( $result ) ) {
				wp_set_object_terms( $id, $result['term_id'], $taxonomy );
			}
		} elseif ( $term instanceof \WP_Term ) {
			// Join existing group.
			$translations = maybe_unserialize( $term->description );

			if ( ! is_array( $translations ) ) {
				$translations = [];
			}

			$translations[ $lang ] = $id;
			pll_save_post_translations( $translations );
		}
	}

	/**
	 * Set PLL preferred language before data is parsed.
	 *
	 * This ensures term lookups (get_term_by slug/name) resolve to the correct language.
	 * Also adds a filter to make get_terms language-aware during import.
	 *
	 * @param array    $row         Raw CSV row values.
	 * @param string[] $mapped_keys Mapped column keys.
	 */
	public function beforeSetParsedData( array $row, array $mapped_keys ): void {
		$this->importing = false;

		// Find and set preferred language.
		$col = array_search( 'language', $mapped_keys, true );

		if ( false !== $col && ! empty( $row[ $col ] ) ) {
			$language = \PLL()->model->get_language( $row[ $col ] );

			if ( $language ) {
				\PLL()->pref_lang = $language;
				$this->importing  = true;
			}
		}
	}

	/**
	 * Set preferred language from row data during import processing.
	 *
	 * @param array $data Parsed row data.
	 */
	public function setLanguageFromRow( array $data ): void {
		$this->importing = false;

		if ( ! empty( $data['language'] ) ) {
			$language = \PLL()->model->get_language( $data['language'] );

			if ( $language ) {
				\PLL()->pref_lang = $language;
				$this->importing  = true;
			}
		}
	}

	/**
	 * Prevent Polylang for WooCommerce from copying product meta during imports.
	 *
	 * @param array $metas Product meta keys.
	 *
	 * @return array
	 */
	public function disablePllWcCopyPostMetas( array $metas ): array {
		return $this->importing ? [] : $metas;
	}

	/**
	 * Filter get_terms to respect the current import language.
	 *
	 * @param array $args WP_Term_Query arguments.
	 *
	 * @return array
	 */
	public function filterTermsByLang( array $args ): array {
		if ( $this->importing && ! isset( $args['lang'] ) && ! empty( \PLL()->pref_lang ) ) {
			$args['lang'] = \PLL()->pref_lang->slug;
		}

		return $args;
	}

	/**
	 * Override category/tag parsing callbacks to inject language context.
	 *
	 * When WC creates categories/tags during import, Polylang needs to know
	 * which language to assign them to. This injects the language via $_POST.
	 *
	 * @param callable[]                $callbacks Parsing callbacks array.
	 * @param \WC_Product_CSV_Importer $importer  Importer instance.
	 *
	 * @return callable[]
	 */
	public function formattingCallbacks( array $callbacks, \WC_Product_CSV_Importer $importer ): array {
		$keys = $importer->get_mapped_keys();

		$catKey = array_search( 'category_ids', $keys, true );
		if ( false !== $catKey ) {
			$callbacks[ $catKey ] = fn( string $value ) => $this->parseWithLangContext( $value, [ $importer, 'parse_categories_field' ] );
		}

		$tagKey = array_search( 'tag_ids', $keys, true );
		if ( false !== $tagKey ) {
			$callbacks[ $tagKey ] = fn( string $value ) => $this->parseWithLangContext( $value, [ $importer, 'parse_tags_field' ] );
		}

		return $callbacks;
	}

	/**
	 * Wrap a parse callback with PLL language context injection.
	 *
	 * @param string   $value    Field value.
	 * @param callable $callback Original parse callback.
	 *
	 * @return mixed
	 */
	private function parseWithLangContext( string $value, callable $callback ): mixed {
		$saved_post    = $_POST['term_lang_choice'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$saved_request = $_REQUEST['_pll_nonce'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! empty( \PLL()->pref_lang ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$_POST['term_lang_choice'] = \PLL()->pref_lang->slug;
			$_REQUEST['_pll_nonce']    = wp_create_nonce( 'pll_language' );
		}

		$result = $callback( $value );

		// Restore superglobals.
		if ( null === $saved_post ) {
			unset( $_POST['term_lang_choice'] );
		} else {
			$_POST['term_lang_choice'] = $saved_post; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( null === $saved_request ) {
			unset( $_REQUEST['_pll_nonce'] );
		} else {
			$_REQUEST['_pll_nonce'] = $saved_request;
		}

		return $result;
	}
}
