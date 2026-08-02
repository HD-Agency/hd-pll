<?php
/**
 * P-B4: Content Sync — Translate IDs in blocks, shortcodes, and HTML
 * when duplicating content to another language.
 *
 * Parses duplicated content and translates all embedded IDs
 * (posts, terms, attachments) to the target language.
 *
 * Uses a registry pattern for block rules — extensible for WC blocks
 * or any custom block without modifying core logic.
 *
 * @internal This class is intended to be called on-demand by other features
 *           (e.g., DuplicateContent or AI TranslationEngine). It does not
 *           register its own hooks or implement PllFeatureInterface.
 *
 * @package HDPLL\Models
 */

declare(strict_types=1);

namespace HDPLL\Models;

use HD\Core\DB;

defined( 'ABSPATH' ) || exit;

final class SyncContent {

	/**
	 * Attachment translation cache for one request.
	 *
	 * @var array<string, int>
	 */
	private array $attachmentTranslations = [];

	/**
	 * Block translation rules registry.
	 * Format: blockName => [ attrName => type ]
	 *
	 * Types:
	 *  - 'post'         — single post/page ID
	 *  - 'term'         — single term ID
	 *  - 'attachment'   — single attachment ID (auto-creates translation if missing)
	 *  - 'post[]'       — array of post IDs
	 *  - 'term[]'       — array of term IDs
	 *  - 'attachment[]' — array of attachment IDs
	 */
	private const BLOCK_RULES = [
		// Core blocks.
		'core/image'                       => [ 'id' => 'attachment' ],
		'core/cover'                       => [ 'id' => 'attachment' ],
		'core/media-text'                  => [ 'mediaId' => 'attachment' ],
		'core/audio'                       => [ 'id' => 'attachment' ],
		'core/video'                       => [ 'id' => 'attachment' ],
		'core/file'                        => [ 'id' => 'attachment' ],
		'core/block'                       => [ 'ref' => 'post' ],

		// WC blocks (pre-registered for future use).
		'woocommerce/handpicked-products'  => [ 'products' => 'post[]' ],
		'woocommerce/featured-product'     => [ 'productId' => 'post' ],
		'woocommerce/featured-category'    => [ 'categoryId' => 'term' ],
		'woocommerce/reviews-by-product'   => [ 'productId' => 'post' ],
		'woocommerce/reviews-by-category'  => [ 'categoryIds' => 'term[]' ],
		'woocommerce/product-tag'          => [ 'tags' => 'term[]' ],
		'woocommerce/product-category'     => [ 'categories' => 'term[]' ],
		'woocommerce/product-best-sellers' => [ 'categories' => 'term[]' ],
		'woocommerce/product-new'          => [ 'categories' => 'term[]' ],
		'woocommerce/product-top-rated'    => [ 'categories' => 'term[]' ],
		'woocommerce/product-on-sale'      => [ 'categories' => 'term[]' ],
	];

	/**
	 * Translate copied content and featured image for a target post.
	 */
	public function translatePostContent( int $targetId, string $targetLang, int $sourceId = 0 ): void {
		$freshPost = get_post( $targetId );
		if ( ! $freshPost ) {
			return;
		}

		$content = $this->translateContent( $freshPost->post_content, $targetLang );
		$excerpt = $this->translateShortcodes( $freshPost->post_excerpt, $targetLang );
		$excerpt = $this->translateHtmlImages( $excerpt, $targetLang );

		if ( $content !== $freshPost->post_content || $excerpt !== $freshPost->post_excerpt ) {
			DB::db()->update(
				DB::db()->posts,
				[
					'post_content' => $content,
					'post_excerpt' => $excerpt,
				],
				[ 'ID' => $targetId ]
			);
		}

		if ( 0 < $sourceId ) {
			$sourceThumb = get_post_thumbnail_id( $sourceId );
			if ( $sourceThumb ) {
				$translatedThumb = $this->translateOrCreateAttachment( $sourceThumb, $targetLang );
				if ( $translatedThumb && $translatedThumb !== $sourceThumb ) {
					set_post_thumbnail( $targetId, $translatedThumb );
				}
			}
		}

		clean_post_cache( $targetId );
		$GLOBALS['post'] = get_post( $targetId );
	}

	/* ---------- Content Translation ---------- */

	/**
	 * Translate a piece of content (blocks + shortcodes + HTML).
	 *
	 * @param string $content Content to translate.
	 * @param string $lang    Target language slug.
	 *
	 * @return string
	 */
	private function translateContent( string $content, string $lang ): string {
		if ( has_blocks( $content ) ) {
			$blocks  = parse_blocks( $content );
			$blocks  = $this->translateBlocks( $blocks, $lang );
			$content = serialize_blocks( $blocks );
		}

		$content = $this->translateShortcodes( $content, $lang );
		$content = $this->translateHtmlImages( $content, $lang );

		return $content;
	}

	/* ---------- Block Translation ---------- */

	/**
	 * Recursively translate block attributes containing IDs.
	 *
	 * @param array  $blocks Parsed blocks array.
	 * @param string $lang   Target language slug.
	 *
	 * @return array
	 */
	private function translateBlocks( array $blocks, string $lang ): array {
		// Allow extending block translation rules.
		// Filter: hd_pll_sync_block_rules (param array $rules).
		$rules = apply_filters( 'hd_pll_sync_block_rules', self::BLOCK_RULES );

		foreach ( $blocks as $k => $block ) {
			// Translate inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $k ]['innerBlocks'] = $this->translateBlocks( $block['innerBlocks'], $lang );
			}

			if ( empty( $block['blockName'] ) || ! isset( $rules[ $block['blockName'] ] ) ) {
				continue;
			}

			$blockRules = $rules[ $block['blockName'] ];

			foreach ( $blockRules as $attr => $type ) {
				if ( ! isset( $block['attrs'][ $attr ] ) ) {
					continue;
				}

				$blocks[ $k ]['attrs'][ $attr ] = $this->translateValue(
					$block['attrs'][ $attr ],
					$type,
					$lang
				);
			}
		}

		return $blocks;
	}

	/**
	 * Translate a single value or array of values based on type.
	 *
	 * @param mixed  $value Original value.
	 * @param string $type  Type identifier (post, term, attachment, post[], etc).
	 * @param string $lang  Target language slug.
	 *
	 * @return mixed
	 */
	private function translateValue( mixed $value, string $type, string $lang ): mixed {
		$isArray  = str_ends_with( $type, '[]' );
		$baseType = $isArray ? substr( $type, 0, -2 ) : $type;

		if ( $isArray && is_array( $value ) ) {
			return array_map( fn( $id ) => $this->translateId( $id, $baseType, $lang ), $value );
		}

		return $this->translateId( $value, $baseType, $lang );
	}

	/**
	 * Translate a single ID to the target language.
	 *
	 * @param mixed  $id   Original ID.
	 * @param string $type Object type (post, term, attachment).
	 * @param string $lang Target language slug.
	 *
	 * @return mixed
	 */
	private function translateId( mixed $id, string $type, string $lang ): mixed {
		if ( ! is_numeric( $id ) || empty( $id ) ) {
			return $id;
		}

		$id = (int) $id;

		return match ( $type ) {
			'post'       => \pll_get_post( $id, $lang ) ?: $id,
			'attachment' => $this->translateOrCreateAttachment( $id, $lang ),
			'term'       => \pll_get_term( $id, $lang ) ?: $id,
			default      => $id,
		};
	}

	/**
	 * Translate an attachment ID, creating the media translation when needed.
	 */
	private function translateOrCreateAttachment( mixed $id, string $lang ): mixed {
		if ( ! is_numeric( $id ) || empty( $id ) ) {
			return $id;
		}

		$id = (int) $id;
		if ( empty( \PLL()->options['media_support'] ) ) {
			return $id;
		}

		$cacheKey = $id . '|' . $lang;
		if ( isset( $this->attachmentTranslations[ $cacheKey ] ) ) {
			return $this->attachmentTranslations[ $cacheKey ];
		}

		$translatedId = \pll_get_post( $id, $lang );
		if ( ! $translatedId && method_exists( \PLL()->model->post, 'create_media_translation' ) ) {
			$translatedId = \PLL()->model->post->create_media_translation( $id, $lang );
		}

		$this->attachmentTranslations[ $cacheKey ] = $translatedId ? (int) $translatedId : $id;

		return $this->attachmentTranslations[ $cacheKey ];
	}

	/* ---------- Shortcode Translation ---------- */

	/**
	 * Translate shortcode IDs ([gallery], [caption], [playlist]).
	 *
	 * @param string $content Content with shortcodes.
	 * @param string $lang    Target language slug.
	 *
	 * @return string
	 */
	private function translateShortcodes( string $content, string $lang ): string {
		if ( empty( $content ) || ! str_contains( $content, '[' ) ) {
			return $content;
		}

		// Translate gallery and playlist shortcodes (e.g. ids attr).
		$content = preg_replace_callback(
			'/\[(gallery|playlist)\s([^\]]*)\]/i',
			fn( array $m ) => $this->translateIdsShortcode( $m, $lang ),
			$content
		) ?? $content;

		// Translate caption shortcodes (e.g. attachment id).
		$content = preg_replace_callback(
			'/\[(caption|wp_caption)\s([^\]]*)\]/i',
			fn( array $m ) => $this->translateCaptionShortcode( $m, $lang ),
			$content
		) ?? $content;

		return $content;
	}

	/**
	 * Translate IDs in gallery/playlist shortcode attributes.
	 *
	 * @param array  $matches Regex matches.
	 * @param string $lang    Target language slug.
	 *
	 * @return string
	 */
	private function translateIdsShortcode( array $matches, string $lang ): string {
		$tag   = $matches[1];
		$attrs = $matches[2];

		$attrs = preg_replace_callback(
			'/\bids=(["\'])([^"\']+)\1/i',
			function ( array $m ) use ( $lang ): string {
				$quote  = $m[1];
				$ids    = array_map( 'intval', explode( ',', $m[2] ) );
				$tr_ids = array_map(
					fn( int $id ) => $this->translateOrCreateAttachment( $id, $lang ),
					$ids
				);

				return 'ids=' . $quote . implode( ',', $tr_ids ) . $quote;
			},
			$attrs
		) ?? $attrs;

		return "[{$tag} {$attrs}]";
	}

	/**
	 * Translate ID in caption shortcode.
	 *
	 * @param array  $matches Regex matches.
	 * @param string $lang    Target language slug.
	 *
	 * @return string
	 */
	private function translateCaptionShortcode( array $matches, string $lang ): string {
		$tag   = $matches[1];
		$attrs = $matches[2];

		$attrs = preg_replace_callback(
			'/\bid=(["\'])attachment_(\d+)\1/i',
			function ( array $m ) use ( $lang ): string {
				$quote = $m[1];
				$tr_id = $this->translateOrCreateAttachment( (int) $m[2], $lang );

				return 'id=' . $quote . 'attachment_' . $tr_id . $quote;
			},
			$attrs
		) ?? $attrs;

		return "[{$tag} {$attrs}]";
	}

	/* ---------- HTML Image Translation ---------- */

	/**
	 * Translate image IDs in HTML (wp-image-{ID} class, data-id attribute).
	 *
	 * @param string $content HTML content.
	 * @param string $lang    Target language slug.
	 *
	 * @return string
	 */
	private function translateHtmlImages( string $content, string $lang ): string {
		if ( empty( $content ) ) {
			return $content;
		}

		// Translate wp-image-{ID} class.
		$content = preg_replace_callback(
			'/(?<![A-Za-z0-9_-])wp-image-(\d+)(?![A-Za-z0-9_-])/',
			fn( array $m ) => 'wp-image-' . $this->translateOrCreateAttachment( (int) $m[1], $lang ),
			$content
		) ?? $content;

		// Translate data-id="{ID}" attribute.
		$content = preg_replace_callback(
			'/\bdata-id=(["\'])(\d+)\1/i',
			fn( array $m ) => 'data-id=' . $m[1] . $this->translateOrCreateAttachment( (int) $m[2], $lang ) . $m[1],
			$content
		) ?? $content;

		return $content;
	}
}
