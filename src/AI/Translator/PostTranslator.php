<?php
/**
 * Post translation workflow — AI text processing only.
 *
 * Extracts translatable text, sends to AI, returns preview or
 * delegates commit to TranslationPostModel.
 *
 * @package HDPLL\AI\Translator
 */

declare(strict_types=1);

namespace HDPLL\AI\Translator;

use HDPLL\AI\ContentExtractor;
use HDPLL\AI\ContentRehydrator;
use HDPLL\AI\LinkRewriter;
use HDPLL\AI\TranslationEngine;
use HDPLL\AI\TranslationValidator;
use HDPLL\Models\TranslationPostModel;
use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class PostTranslator {

	private ?MetaTranslator $metaTranslator = null;

	public function __construct(
		protected readonly TranslationEngine $engine = new TranslationEngine(),
		protected readonly ContentExtractor $extractor = new ContentExtractor(),
		protected readonly ContentRehydrator $rehydrator = new ContentRehydrator(),
		protected readonly LinkRewriter $linkRewriter = new LinkRewriter(),
		protected readonly TranslationValidator $validator = new TranslationValidator()
	) {}

	private function metaTranslator(): MetaTranslator {
		return $this->metaTranslator ??= new MetaTranslator( $this->engine );
	}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function translate( int $sourceId, string $targetLang, array $options = [] ): array|\WP_Error {
		$source = get_post( $sourceId );
		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'hd_pll_ai_post_not_found', __( 'Source post not found.', 'hd-pll' ) );
		}

		$sourceLang = \pll_get_post_language( $sourceId );
		if ( ! $sourceLang || ! \PLL()->model->get_language( $targetLang ) ) {
			return new \WP_Error( 'hd_pll_ai_invalid_language', __( 'Invalid source or target language.', 'hd-pll' ) );
		}

		$results = $this->engine->translateUnits( $this->extractor->extractPost( $source, $options ), $sourceLang, $targetLang );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$fields          = $this->rehydrator->postFields( $results, $source );
		$structureErrors = $this->validator->validatePostFields( $source, $fields );
		if ( ! empty( $structureErrors ) ) {
			return new \WP_Error( 'hd_pll_ai_structure_validation_failed', __( 'Translated content structure validation failed.', 'hd-pll' ), [ 'errors' => $structureErrors ] );
		}

		if ( ! empty( $options['rewrite_links'] ) && ! empty( $fields['post_content'] ) ) {
			$fields['post_content'] = $this->linkRewriter->rewrite( $fields['post_content'], $targetLang );
			$structureErrors        = $this->validator->validatePostFields( $source, $fields, true );
			if ( ! empty( $structureErrors ) ) {
				return new \WP_Error( 'hd_pll_ai_structure_validation_failed', __( 'Translated content structure validation failed.', 'hd-pll' ), [ 'errors' => $structureErrors ] );
			}
		}

		$meta = [];
		if ( ! empty( $options['translate_meta'] ) ) {
			$meta = $this->metaTranslator()->previewPostMeta( $sourceId, $sourceLang, $targetLang, $this->metaKeys( $options ) );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
		}

		$targetId = \pll_get_post( $sourceId, $targetLang ) ?: 0;
		$preview  = [
			'source_id'   => $sourceId,
			'target_id'   => $targetId,
			'source_lang' => $sourceLang,
			'target_lang' => $targetLang,
			'fields'      => $fields,
			'meta'        => $meta,
			'links'       => $this->postLinks( (int) $targetId ),
		];

		if ( empty( $options['commit'] ) ) {
			return [ 'preview' => $preview ];
		}

		// Merge translated meta into fields for TranslationPostModel.
		$translatedFields = array_merge( $fields, $meta );

		$model    = new TranslationPostModel();
		$targetId = $model->duplicate(
			$sourceId,
			$targetLang,
			$translatedFields,
			[
				'overwrite'       => $targetId > 0,
				'status'          => sanitize_key( (string) ( $options['status'] ?? 'draft' ) ),
				'preserve_author' => ! empty( $options['preserve_author'] ),
				'preserve_date'   => ! empty( $options['preserve_date'] ),
				'translate_slug'  => ! empty( $options['translate_slug'] ),
			]
		);

		if ( is_wp_error( $targetId ) ) {
			return $targetId;
		}

		return [
			'preview' => [
				...$preview,
				'target_id' => $targetId,
				'links'     => $this->postLinks( (int) $targetId ),
			],
			'item'    => [ 'id' => $targetId ],
		];
	}

	/**
	 * @return array{edit:string,view:string}
	 */
	public static function postLinks( int $postId ): array {
		if ( $postId <= 0 ) {
			return [
				'edit' => '',
				'view' => '',
			];
		}

		$editLink = (string) get_edit_post_link( $postId, 'raw' );
		$viewLink = (string) get_preview_post_link( $postId );
		if ( '' === $viewLink ) {
			$permalink = (string) get_permalink( $postId );
			$viewLink  = '' !== $permalink ? add_query_arg( 'preview', 'true', $permalink ) : '';
		}

		return [
			'edit' => esc_url_raw( '' !== $editLink ? $editLink : admin_url( 'post.php?post=' . $postId . '&action=edit' ) ),
			'view' => esc_url_raw( $viewLink ),
		];
	}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return string[]
	 */
	private function metaKeys( array $options ): array {
		if ( ! empty( $options['meta_keys'] ) && is_array( $options['meta_keys'] ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $options['meta_keys'] ) ) );
		}

		$settings = Plugin::getCachedOptions();

		return array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['ai_translate_meta_keys'] ?? [] ) ) ) );
	}
}
