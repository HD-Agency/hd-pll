<?php
/**
 * Unified post translation/duplication model.
 *
 * Consolidates all entity-creation logic for posts (manual duplicate,
 * AI translation, WooCommerce product sync) into a single source of truth.
 *
 * Inspired by polylang-pro's PLL_Translation_Post_Model.
 *
 * @package HDPLL\Models
 */

declare(strict_types=1);

namespace HDPLL\Models;

use HD\Core\DB;
use HDPLL\ACF\Entity\PostEntity;
use HDPLL\AI\Translator\MetaTranslator;
use HDPLL\Plugin;
use HDPLL\Models\SyncContent;

defined( 'ABSPATH' ) || exit;

final class TranslationPostModel {

	/** @var array<string, int> */
	private array $termTranslationCache = [];

	/**
	 * Runtime/internal meta keys to skip during copy.
	 *
	 * @var string[]
	 */
	private const SKIP_META_KEYS = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_pingme',
		'_encloseme',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pll_*',
	];

	/**
	 * Duplicate a post into a target language.
	 *
	 * If the target post already exists and `overwrite` is false, returns error.
	 * If `$translatedFields` is provided (AI mode), overlay translated text onto
	 * the duplicated post instead of copying source text verbatim.
	 *
	 * @param int                    $sourceId         Source post ID.
	 * @param string                 $targetLang       Target language slug.
	 * @param array<string, string>  $translatedFields Optional translated fields (post_title, post_content, post_excerpt, meta).
	 * @param array<string, mixed>   $options          Options: target_id, overwrite, status, preserve_date, preserve_author, translate_slug.
	 *
	 * @return int|\WP_Error Target post ID or error.
	 */
	public function duplicate( int $sourceId, string $targetLang, array $translatedFields = [], array $options = [] ): int|\WP_Error {
		$source = get_post( $sourceId );
		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'hd_pll_source_not_found', __( 'Source post not found.', 'hd-pll' ) );
		}

		$sourceLang = \pll_get_post_language( $sourceId );
		if ( ! $sourceLang || ! \PLL()->model->get_language( $targetLang ) ) {
			return new \WP_Error( 'hd_pll_invalid_language', __( 'Invalid source or target language.', 'hd-pll' ) );
		}

		$targetId = absint( $options['target_id'] ?? 0 );
		if ( $targetId > 0 ) {
			$target = get_post( $targetId );
			if ( ! $target instanceof \WP_Post || $target->post_type !== $source->post_type ) {
				return new \WP_Error( 'hd_pll_invalid_target', __( 'Invalid target post.', 'hd-pll' ) );
			}

			$targetLangCurrent = \pll_get_post_language( $targetId );
			if ( $targetLangCurrent && $targetLangCurrent !== $targetLang ) {
				return new \WP_Error( 'hd_pll_target_language_mismatch', __( 'Target post language does not match requested language.', 'hd-pll' ) );
			}

			$linkedTarget = \pll_get_post( $sourceId, $targetLang );
			if ( $linkedTarget && (int) $linkedTarget !== $targetId ) {
				return new \WP_Error( 'hd_pll_target_conflict', __( 'A different translation already exists for this language.', 'hd-pll' ) );
			}
		} else {
			$targetId = \pll_get_post( $sourceId, $targetLang ) ?: 0;
		}

		$overwrite = ! empty( $options['overwrite'] );

		// For new post: standard flow. For existing: require overwrite flag.
		if ( $targetId > 0 && ! $overwrite ) {
			return new \WP_Error( 'hd_pll_target_exists', __( 'Translation already exists. Use overwrite option.', 'hd-pll' ) );
		}

		$status = self::sanitizePostStatus( $options['status'] ?? 'draft' );

		// Determine content: use translated fields if provided, otherwise copy source.
		$title   = array_key_exists( 'post_title', $translatedFields )
			? self::sanitizeTranslatedTitle( $translatedFields['post_title'] )
			: $source->post_title;
		$content = array_key_exists( 'post_content', $translatedFields )
			? self::sanitizeTranslatedContent( $translatedFields['post_content'] )
			: $source->post_content;
		$excerpt = array_key_exists( 'post_excerpt', $translatedFields )
			? self::sanitizeTranslatedExcerpt( $translatedFields['post_excerpt'] )
			: $source->post_excerpt;

		if ( $targetId > 0 ) {
			// Overwrite existing: direct DB update to avoid PLL hook interference.
			$db = DB::db();
			$db->update(
				$db->posts,
				[
					'post_title'        => $title,
					'post_content'      => $content,
					'post_excerpt'      => $excerpt,
					'post_status'       => $status,
					'post_modified'     => current_time( 'mysql' ),
					'post_modified_gmt' => current_time( 'mysql', true ),
				],
				[ 'ID' => $targetId ]
			);

			clean_post_cache( $targetId );

			\pll_set_post_language( $targetId, $targetLang );
			$translations                = \pll_get_post_translations( $sourceId );
			$translations[ $sourceLang ] = $sourceId;
			$translations[ $targetLang ] = $targetId;
			\pll_save_post_translations( $translations );
		} else {
			// Create new post.
			$postarr = [
				'post_type'    => $source->post_type,
				'post_status'  => $status,
				'post_author'  => ! empty( $options['preserve_author'] ) ? (int) $source->post_author : get_current_user_id(),
				'post_parent'  => $this->translateParent( (int) $source->post_parent, $targetLang ),
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			];

			if ( ! empty( $options['preserve_date'] ) ) {
				$postarr['post_date']     = $source->post_date;
				$postarr['post_date_gmt'] = $source->post_date_gmt;
			}

			if ( ! empty( $options['translate_slug'] ) ) {
				$postarr['post_name'] = sanitize_title( $title ) . '-' . $targetLang;
			}

			// Temporarily unhook Polylang's save_post handler which calls
			// check_admin_referer() — incompatible with REST/CLI contexts.
			$pllCrud = \PLL()->posts ?? null;
			if ( $pllCrud && has_action( 'save_post', [ $pllCrud, 'save_post' ] ) ) {
				remove_action( 'save_post', [ $pllCrud, 'save_post' ] );
			} else {
				$pllCrud = null;
			}

			try {
				$targetId = wp_insert_post( $postarr, true );
			} finally {
				if ( $pllCrud ) {
					add_action( 'save_post', [ $pllCrud, 'save_post' ], 10, 3 );
				}
			}

			if ( is_wp_error( $targetId ) ) {
				return $targetId;
			}

			// Set language and link translations.
			\pll_set_post_language( $targetId, $targetLang );
			$translations                = \pll_get_post_translations( $sourceId );
			$translations[ $sourceLang ] = $sourceId;
			$translations[ $targetLang ] = $targetId;
			\pll_save_post_translations( $translations );
		}

		// Copy post meta via Polylang pipeline.
		$this->copyPostMeta( $sourceId, $targetId, $targetLang );

		// Copy ACF fields via ID-aware strategy.
		$this->copyAcfFields( $sourceId, $targetId, $targetLang );

		// Sync taxonomies (map source terms to translated terms).
		$this->syncTaxonomies( $sourceId, $targetId, $targetLang );

		// Translate embedded IDs in content (blocks, shortcodes, images).
		( new SyncContent() )->translatePostContent( $targetId, $targetLang, $sourceId );

		// Apply translated meta fields (AI mode).
		if ( ! empty( $translatedFields ) ) {
			$this->applyTranslatedMetaFields( $targetId, $translatedFields );
		}

		// Refresh cache.
		clean_post_cache( $targetId );

		/**
		 * Fires after a post has been duplicated/translated.
		 *
		 * WC ProductSync listens to this for variation cloning.
		 *
		 * @param int                   $sourceId  Source post ID.
		 * @param int                   $targetId  Target post ID.
		 * @param string                $targetLang Target language slug.
		 * @param array<string, mixed>  $options   Options used during duplication.
		 */
		do_action( 'hd_pll_post_duplicated', $sourceId, $targetId, $targetLang, $options );

		return $targetId;
	}

	/**
	 * Copy post meta through Polylang's pipeline.
	 *
	 * Uses `PLL()->sync->post_metas->copy()` when available, with a local
	 * fallback that mirrors `PLL_Sync_Metas::copy()` semantics.
	 */
	public function copyPostMeta( int $sourceId, int $targetId, string $targetLang ): void {
		if ( isset( \PLL()->sync->post_metas ) && is_object( \PLL()->sync->post_metas )
			&& method_exists( \PLL()->sync->post_metas, 'copy' )
		) {
			add_filter( 'pll_copy_post_metas', [ $this, 'filterMetaKeys' ], 999, 5 );
			try {
				\PLL()->sync->post_metas->copy( $sourceId, $targetId, $targetLang, false );
			} finally {
				remove_filter( 'pll_copy_post_metas', [ $this, 'filterMetaKeys' ], 999 );
			}
			return;
		}

		$this->copyPostMetaFallback( $sourceId, $targetId, $targetLang );
	}

	/**
	 * Apply HD duplicate meta copy policy.
	 *
	 * Public because it's used as a filter callback.
	 *
	 * @param string[] $keys Meta keys selected by Polylang.
	 *
	 * @return string[]
	 */
	public function filterMetaKeys( array $keys, bool $sync, int|string $sourceId, int|string $targetId, string $targetLang = '' ): array {
		$keys = array_values(
			array_unique(
				array_merge(
					$keys,
					$this->getPostMetaKeys( (int) $sourceId, (int) $targetId )
				)
			)
		);

		$skip_keys = apply_filters(
			'hd_pll_duplicate_skip_meta_keys',
			self::SKIP_META_KEYS,
			(int) $sourceId,
			(int) $targetId,
			$targetLang
		);
		$acf_meta  = $this->getAcfMetaKeys( (int) $sourceId, (int) $targetId );

		$keys = array_values(
			array_filter(
				$keys,
				fn( string $key ): bool => ! in_array( $key, $acf_meta, true )
					&& ! $this->matchesSkippedMetaKey( $key, (array) $skip_keys )
			)
		);

		$keys = apply_filters(
			'hd_pll_duplicate_copy_meta_keys',
			$keys,
			(int) $sourceId,
			(int) $targetId,
			$targetLang
		);

		return array_values( array_unique( (array) $keys ) );
	}

	/**
	 * Sync taxonomies from source to target, mapping to translated terms.
	 */
	public function syncTaxonomies( int $sourceId, int $targetId, string $targetLang ): void {
		foreach ( get_object_taxonomies( get_post_type( $sourceId ) ?: 'post' ) as $taxonomy ) {
			$taxonomyObject = get_taxonomy( $taxonomy );
			if ( ! $taxonomyObject || ! empty( $taxonomyObject->_pll ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $sourceId, $taxonomy, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$termIds = array_map( 'intval', $terms );
			if ( function_exists( 'pll_is_translated_taxonomy' ) && \pll_is_translated_taxonomy( $taxonomy ) ) {
				$termIds = array_filter(
					array_map(
						fn( int $termId ): int => $this->translatedTermId( $termId, $targetLang ),
						$termIds
					)
				);
			}

			wp_set_object_terms( $targetId, $termIds, $taxonomy );
		}
	}

	/* ---------- Private Helpers ---------- */

	private function translatedTermId( int $termId, string $targetLang ): int {
		$key = $termId . '|' . $targetLang;

		if ( ! array_key_exists( $key, $this->termTranslationCache ) ) {
			$this->termTranslationCache[ $key ] = (int) ( \pll_get_term( $termId, $targetLang ) ?: 0 );
		}

		return $this->termTranslationCache[ $key ];
	}

	/**
	 * Translate post parent ID for hierarchical post types.
	 * Falls back to source parent if no translation exists.
	 */
	private function translateParent( int $parentId, string $targetLang ): int {
		if ( $parentId <= 0 ) {
			return 0;
		}

		return (int) ( \pll_get_post( $parentId, $targetLang ) ?: $parentId );
	}

	/**
	 * Normalize translated field input without producing array/object casts.
	 */
	private static function normalizeTranslatedString( mixed $value ): string {
		return is_scalar( $value ) || null === $value ? (string) $value : '';
	}

	/**
	 * Sanitize a translated post title.
	 */
	private static function sanitizeTranslatedTitle( mixed $value ): string {
		return sanitize_text_field( self::normalizeTranslatedString( $value ) );
	}

	/**
	 * Sanitize translated block/content HTML while preserving valid block markup.
	 */
	private static function sanitizeTranslatedContent( mixed $value ): string {
		$content = self::normalizeTranslatedString( $value );

		if ( function_exists( 'filter_block_content' ) ) {
			return filter_block_content( $content, 'post' );
		}

		return wp_kses_post( $content );
	}

	/**
	 * Sanitize a translated post excerpt.
	 */
	private static function sanitizeTranslatedExcerpt( mixed $value ): string {
		return sanitize_textarea_field( self::normalizeTranslatedString( $value ) );
	}

	/**
	 * Restrict duplicate status changes to expected WordPress post statuses.
	 */
	private static function sanitizePostStatus( mixed $status ): string {
		$status          = sanitize_key( self::normalizeTranslatedString( $status ) );
		$allowedStatuses = apply_filters(
			'hd_pll_duplicate_allowed_post_statuses',
			[
				'publish',
				'future',
				'draft',
				'pending',
				'private',
			]
		);
		$allowedStatuses = array_filter( (array) $allowedStatuses, 'is_string' );

		return in_array( $status, $allowedStatuses, true ) ? $status : 'draft';
	}

	/**
	 * Apply sanitized translated meta fields from AI mode.
	 *
	 * @param array<string, mixed> $translatedFields Translated field payload.
	 */
	private function applyTranslatedMetaFields( int $targetId, array $translatedFields ): void {
		foreach ( self::sanitizeTranslatedMetaFields( $translatedFields, $this->allowedTranslatedMetaKeys() ) as $key => $value ) {
			update_post_meta( $targetId, $key, $value );
		}
	}

	/**
	 * Resolve the meta keys AI translation is allowed to overwrite.
	 *
	 * @return string[]
	 */
	private function allowedTranslatedMetaKeys(): array {
		$settings       = Plugin::getCachedOptions();
		$configuredKeys = self::normalizeMetaKeys( (array) ( $settings['ai_translate_meta_keys'] ?? [] ) );
		$keys           = ! empty( $configuredKeys ) ? $configuredKeys : MetaTranslator::DEFAULT_KEYS;
		$keys           = apply_filters( 'hd_pll_ai_translatable_meta_keys', $keys );

		return self::normalizeMetaKeys( (array) $keys );
	}

	/**
	 * Build a sanitized translated-meta payload from the AI field payload.
	 *
	 * @param array<string, mixed> $translatedFields Translated field payload.
	 * @param string[]             $allowedKeys      Allowed meta keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitizeTranslatedMetaFields( array $translatedFields, array $allowedKeys ): array {
		$allowed = array_fill_keys( self::normalizeMetaKeys( $allowedKeys ), true );
		$meta    = [];

		foreach ( $translatedFields as $rawKey => $value ) {
			$key = sanitize_key( self::normalizeTranslatedString( $rawKey ) );

			if ( '' === $key
				|| in_array( $key, [ 'post_title', 'post_content', 'post_excerpt' ], true )
				|| empty( $allowed[ $key ] )
				|| self::isProtectedTranslatedMetaKey( $key )
			) {
				continue;
			}

			$meta[ $key ] = self::sanitizeTranslatedMetaValue( $key, $value );
		}

		return $meta;
	}

	/**
	 * Normalize configured meta keys to their persisted key form.
	 *
	 * @param string[] $keys Raw meta keys.
	 *
	 * @return string[]
	 */
	private static function normalizeMetaKeys( array $keys ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $key ): string => sanitize_key( self::normalizeTranslatedString( $key ) ),
						$keys
					)
				)
			)
		);
	}

	/**
	 * Block runtime/protected WordPress meta keys even when supplied by AI output.
	 */
	private static function isProtectedTranslatedMetaKey( string $key ): bool {
		return '_thumbnail_id' === $key
			|| str_starts_with( $key, '_wp_' )
			|| str_starts_with( $key, '_edit_' )
			|| str_starts_with( $key, '_pll_' );
	}

	/**
	 * Sanitize translated meta values according to the key's likely value type.
	 */
	private static function sanitizeTranslatedMetaValue( string $key, mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map(
				static fn( mixed $item ): mixed => self::sanitizeTranslatedMetaValue( $key, $item ),
				$value
			);
		}

		$value = self::normalizeTranslatedString( $value );

		if ( str_contains( $key, 'url' ) || str_contains( $key, 'link' ) ) {
			return esc_url_raw( $value );
		}

		if ( str_contains( $key, 'content' ) || str_contains( $key, 'html' ) ) {
			return wp_kses_post( $value );
		}

		if ( str_contains( $key, 'description' ) || str_contains( $key, 'metadesc' ) || str_contains( $key, 'excerpt' ) ) {
			return sanitize_textarea_field( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Local fallback mirroring the important parts of PLL_Sync_Metas::copy().
	 */
	private function copyPostMetaFallback( int $sourceId, int $targetId, string $targetLang ): void {
		$source_meta = get_post_meta( $sourceId );
		$target_meta = get_post_meta( $targetId );
		$keys        = array_unique( array_merge( array_keys( $source_meta ), array_keys( $target_meta ) ) );
		$keys        = $this->filterMetaKeys( $keys, false, $sourceId, $targetId, $targetLang );

		foreach ( $keys as $key ) {
			if ( empty( $source_meta[ $key ] ) ) {
				if ( ! empty( $target_meta[ $key ] ) ) {
					delete_post_meta( $targetId, $key );
				}
				continue;
			}

			delete_post_meta( $targetId, $key );
			foreach ( $source_meta[ $key ] as $value ) {
				$value = maybe_unserialize( $value );
				$value = apply_filters( 'pll_translate_post_meta', $value, $key, $targetLang, $sourceId, $targetId );
				add_post_meta( $targetId, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Copy ACF fields through the module's ID-aware strategy.
	 */
	private function copyAcfFields( int $sourceId, int $targetId, string $targetLang ): void {
		if ( ! class_exists( PostEntity::class ) || ! function_exists( 'acf_get_store' ) ) {
			return;
		}

		( new PostEntity( $sourceId ) )->onPostSynchronized( $targetId, $targetLang, 'copy' );
	}

	/**
	 * Resolve ACF-owned meta keys so generic meta copy does not duplicate them.
	 *
	 * @return string[]
	 */
	private function getAcfMetaKeys( int $sourceId, int $targetId ): array {
		if ( ! function_exists( 'acf_get_meta' ) ) {
			return [];
		}

		return array_keys(
			array_merge(
				(array) acf_get_meta( $sourceId ),
				(array) acf_get_meta( $targetId )
			)
		);
	}

	/**
	 * Query meta keys without hydrating stored meta values.
	 *
	 * @return string[]
	 */
	private function getPostMetaKeys( int ...$postIds ): array {
		$postIds = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $postIds )
				)
			)
		);

		if ( empty( $postIds ) ) {
			return [];
		}

		$db           = DB::db();
		$placeholders = implode( ', ', array_fill( 0, count( $postIds ), '%d' ) );
		$sql          = "SELECT DISTINCT meta_key FROM {$db->postmeta} WHERE post_id IN ({$placeholders})";
		$prepared     = $db->prepare( $sql, $postIds );

		if ( ! $prepared ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map( 'strval', (array) $db->get_col( $prepared ) )
			)
		);
	}

	/**
	 * Match exact keys and simple trailing-wildcard patterns.
	 *
	 * @param string[] $skipKeys Keys or patterns such as `_pll_*`.
	 */
	private function matchesSkippedMetaKey( string $key, array $skipKeys ): bool {
		foreach ( $skipKeys as $skipKey ) {
			if ( ! is_string( $skipKey ) || '' === $skipKey ) {
				continue;
			}

			if ( str_ends_with( $skipKey, '*' ) ) {
				if ( str_starts_with( $key, rtrim( $skipKey, '*' ) ) ) {
					return true;
				}
				continue;
			}

			if ( $key === $skipKey ) {
				return true;
			}
		}

		return false;
	}
}
