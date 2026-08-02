<?php
/**
 * Product meta sync for Polylang "Duplicate content to this language".
 *
 * Ensures WC product data (price, SKU, stock, gallery, attributes, variations)
 * is copied when using Polylang Pro's content duplication for translations.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

defined( 'ABSPATH' ) || exit;

final class ProductSync {

	/**
	 * WC product meta keys → WC property names.
	 *
	 * @var array<string, string>
	 */
	private const PRODUCT_METAS = [
		'_backorders'            => 'backorders',
		'_children'              => 'children',
		'_crosssell_ids'         => 'cross_sell_ids',
		'_default_attributes'    => 'default_attributes',
		'_download_expiry'       => 'download_expiry',
		'_download_limit'        => 'download_limit',
		'_downloadable'          => 'downloadable',
		'_downloadable_files'    => 'downloads',
		'_featured'              => 'featured',
		'_height'                => 'height',
		'_length'                => 'length',
		'_low_stock_amount'      => 'low_stock_amount',
		'_manage_stock'          => 'manage_stock',
		'_price'                 => 'price',
		'_product_attributes'    => 'attributes',
		'_product_image_gallery' => 'gallery_image_ids',
		'_regular_price'         => 'regular_price',
		'_sale_price'            => 'sale_price',
		'_sale_price_dates_from' => 'date_on_sale_from',
		'_sale_price_dates_to'   => 'date_on_sale_to',
		'_sku'                   => 'sku',
		'_global_unique_id'      => 'global_unique_id',
		'_sold_individually'     => 'sold_individually',
		'_stock'                 => 'stock_quantity',
		'_stock_status'          => 'stock_status',
		'_tax_class'             => 'tax_class',
		'_tax_status'            => 'tax_status',
		'_thumbnail_id'          => 'image_id',
		'_upsell_ids'            => 'upsell_ids',
		'_virtual'               => 'virtual',
		'_weight'                => 'weight',
		'_width'                 => 'width',
		'_button_text'           => 'button_text',
		'_product_url'           => 'product_url',
		'_purchase_note'         => 'purchase_note',
		'_variation_description' => 'description',
	];

	/**
	 * Meta keys containing translatable text (excluded from copy unless sync).
	 *
	 * @var string[]
	 */
	private const TEXT_METAS = [
		'_button_text',
		'_product_url',
		'_purchase_note',
		'_variation_description',
	];

	/**
	 * Properties that hold post IDs (need translation to target language).
	 *
	 * @var string[]
	 */
	private const ID_PROPERTIES = [
		'image_id',
		'gallery_image_ids',
		'upsell_ids',
		'cross_sell_ids',
	];

	public function __construct() {
		// Tell Polylang which WC metas to copy during "Duplicate content".
		add_filter( 'pll_copy_post_metas', [ $this, 'copyPostMetas' ], 5, 4 );

		// Translate meta values (IDs, attributes) to target language.
		add_filter( 'pll_translate_post_meta', [ $this, 'translatePostMeta' ], 5, 4 );

		// Ensure variations are not filtered by language.
		add_filter( 'woocommerce_variable_children_args', [ $this, 'variableChildrenArgs' ] );

		// Duplicate variations when a product is duplicated via TranslationPostModel.
		add_action( 'hd_pll_post_duplicated', [ $this, 'onPostDuplicated' ], 10, 3 );
	}

	/**
	 * Add WC product meta keys to the list Polylang copies during content duplication.
	 *
	 * @param string[] $metas List of meta keys to copy.
	 * @param bool     $sync  True for synchronization, false for copy.
	 * @param int      $from  Source product ID.
	 * @param int      $to    Target product ID.
	 *
	 * @return string[]
	 */
	public function copyPostMetas( array $metas, bool $sync, int $from, int $to ): array {
		if ( ! in_array( get_post_type( $from ), [ 'product', 'product_variation' ], true ) ) {
			return $metas;
		}

		// During sync, exclude variation attribute metas (PLL handles these automatically).
		if ( $sync ) {
			$metas = array_values( preg_grep( '/^attribute_/', $metas, PREG_GREP_INVERT ) ?: [] );
		}

		$to_copy = array_keys( self::PRODUCT_METAS );

		// Exclude text-based metas when target already has content (avoid overwriting translations).
		if ( $to && get_post_field( 'post_modified', $to ) !== get_post_field( 'post_date', $to ) ) {
			$to_copy = array_diff( $to_copy, self::TEXT_METAS );
		}

		return array_unique( array_merge( $metas, $to_copy ) );
	}

	/**
	 * Translate meta values to the target language before copying.
	 *
	 * Handles: thumbnail_id, gallery IDs, upsell/cross-sell IDs, variation attributes.
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Meta key.
	 * @param string $lang  Target language slug.
	 * @param int    $from  Source product ID.
	 *
	 * @return mixed
	 */
	public function translatePostMeta( mixed $value, string $key, string $lang, int $from ): mixed {
		if ( ! in_array( get_post_type( $from ), [ 'product', 'product_variation' ], true ) ) {
			return $value;
		}

		// Translate variation attribute values (e.g., attribute_pa_color).
		if ( str_starts_with( $key, 'attribute_' ) ) {
			return $this->translateAttribute( $value, substr( $key, 10 ), $lang );
		}

		// Translate ID-based properties.
		$property = self::PRODUCT_METAS[ $key ] ?? '';

		if ( ! $property || ! in_array( $property, self::ID_PROPERTIES, true ) ) {
			return $value;
		}

		return match ( $property ) {
			'image_id'          => $this->translatePostId( $value, $lang ),
			'gallery_image_ids' => $this->translateIdList( $value, $lang ),
			'upsell_ids',
			'cross_sell_ids'    => $this->translateIdList( $value, $lang ),
			default             => $value,
		};
	}

	/**
	 * Disable language filter for variable product children queries.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array
	 */
	public function variableChildrenArgs( array $args ): array {
		$args['lang'] = '';

		return $args;
	}

	/**
	 * Translate a taxonomy attribute value to the target language.
	 *
	 * @param mixed  $value Attribute slug.
	 * @param string $tax   Taxonomy name (without 'attribute_' prefix).
	 * @param string $lang  Target language.
	 *
	 * @return mixed
	 */
	private function translateAttribute( mixed $value, string $tax, string $lang ): mixed {
		if ( empty( $tax ) || empty( $value ) || ! is_string( $value ) || ! taxonomy_exists( $tax ) ) {
			return $value;
		}

		$term = get_term_by( 'slug', $value, $tax );

		if ( ! $term instanceof \WP_Term ) {
			return $value;
		}

		$tr_id = \pll_get_term( $term->term_id, $lang );

		if ( ! $tr_id || $tr_id === $term->term_id ) {
			return $value;
		}

		$tr_term = get_term( $tr_id, $tax );

		return $tr_term instanceof \WP_Term ? $tr_term->slug : $value;
	}

	/**
	 * Translate a single post/attachment ID to the target language.
	 *
	 * @param mixed  $value Post ID.
	 * @param string $lang  Target language.
	 *
	 * @return mixed
	 */
	private function translatePostId( mixed $value, string $lang ): mixed {
		if ( ! is_numeric( $value ) || empty( $value ) ) {
			return $value;
		}

		$tr_id = \pll_get_post( (int) $value, $lang );

		return $tr_id ?: $value;
	}

	/**
	 * Translate a list of post IDs (serialized or array) to the target language.
	 *
	 * @param mixed  $value Serialized string or array of IDs.
	 * @param string $lang  Target language.
	 *
	 * @return mixed
	 */
	private function translateIdList( mixed $value, string $lang ): mixed {
		$ids = is_string( $value ) ? maybe_unserialize( $value ) : $value;

		if ( ! is_array( $ids ) ) {
			return $this->translatePostId( $value, $lang );
		}

		$translated = array_map(
			fn( $id ) => is_numeric( $id ) ? ( \pll_get_post( (int) $id, $lang ) ?: $id ) : $id,
			$ids
		);

		return is_string( $value ) ? maybe_serialize( $translated ) : $translated;
	}

	/* ---------- Variation Duplication via TranslationPostModel Hook ---------- */

	/**
	 * Duplicate variations when a product is duplicated via TranslationPostModel.
	 *
	 * Listens to `hd_pll_post_duplicated` action, checks if source is a variable
	 * product, then clones its variations for the target product.
	 *
	 * @param int    $sourceId   Source product ID.
	 * @param int    $targetId   Target product ID.
	 * @param string $targetLang Target language slug.
	 */
	public function onPostDuplicated( int $sourceId, int $targetId, string $targetLang ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$source = \wc_get_product( $sourceId );

		if ( ! $source || ! $source->is_type( 'variable' ) ) {
			return;
		}

		// Check if variations already exist for this translation (avoid double-run).
		$existing = get_posts(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $targetId,
				'numberposts' => 1,
				'fields'      => 'ids',
				'lang'        => '',
			]
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		foreach ( $source->get_children() as $child_id ) {
			$child = \wc_get_product( $child_id );
			if ( ! $child instanceof \WC_Product_Variation ) {
				continue;
			}

			$this->cloneVariation( $child, $targetId, $targetLang );
		}

		// Force WC to re-sync parent lookup tables.
		\WC_Product_Variable::sync( $targetId );
	}

	/**
	 * Clone a single variation to a new parent, translating attribute values.
	 *
	 * @param \WC_Product_Variation $source    Source variation.
	 * @param int                   $parent_id Target parent product ID.
	 * @param string                $lang      Target language slug.
	 */
	private function cloneVariation( \WC_Product_Variation $source, int $parent_id, string $lang ): void {
		$clone = clone $source;
		$clone->set_id( 0 );
		$clone->set_parent_id( $parent_id );
		$clone->set_date_created( null );
		$clone->set_slug( '' );

		// Translate attribute values to target language.
		$attributes = $source->get_attributes( 'edit' );
		$translated = [];

		foreach ( $attributes as $taxonomy => $value ) {
			$translated[ $taxonomy ] = $this->translateAttribute( $value, $taxonomy, $lang );
		}

		$clone->set_attributes( $translated );

		// Clear unique identifiers.
		if ( method_exists( $clone, 'set_global_unique_id' ) ) {
			$clone->set_global_unique_id( '' );
		}

		// SKU is kept as-is. Products::uniqueSku() filter allows shared SKUs
		// between translations, so a single save() is sufficient.
		$clone->save();
		\pll_set_post_language( $clone->get_id(), $lang );

		// Link source variation ↔ cloned variation as translations.
		$tr_ids = \pll_get_post_translations( $source->get_id() );

		if ( empty( $tr_ids ) ) {
			$source_lang = \pll_get_post_language( $source->get_id() );
			$tr_ids      = $source_lang ? [ $source_lang => $source->get_id() ] : [];
		}

		$tr_ids[ $lang ] = $clone->get_id();
		\pll_save_post_translations( $tr_ids );
	}
}
