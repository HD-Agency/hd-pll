<?php
/**
 * Taxonomy term translator — AI text processing only.
 *
 * Extracts translatable text from terms, sends to AI, returns preview
 * or delegates commit to TranslationTermModel.
 *
 * @package HDPLL\AI\Translator
 */

declare(strict_types=1);

namespace HDPLL\AI\Translator;

use HDPLL\AI\TranslationUnit;
use HDPLL\AI\TranslationEngine;
use HDPLL\Models\TranslationTermModel;

defined( 'ABSPATH' ) || exit;

final class TaxonomyTranslator {

	public function __construct( private readonly TranslationEngine $engine = new TranslationEngine() ) {}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function translate( int $termId, string $targetLang, array $options = [] ): array|\WP_Error {
		$term = get_term( $termId );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'hd_pll_ai_term_not_found', __( 'Source term not found.', 'hd-pll' ) );
		}

		$sourceLang = \pll_get_term_language( $termId );
		if ( ! $sourceLang || ! \PLL()->model->get_language( $targetLang ) ) {
			return new \WP_Error( 'hd_pll_ai_invalid_language', __( 'Invalid source or target language.', 'hd-pll' ) );
		}

		$units = [
			new TranslationUnit( 'term_name', $term->name, 'term name', 'text' ),
		];
		if ( '' !== trim( $term->description ) ) {
			$units[] = new TranslationUnit( 'term_description', $term->description, 'term description', 'html' );
		}

		$results = $this->engine->translateUnits( $units, $sourceLang, $targetLang );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$fields = [];
		foreach ( $results as $result ) {
			$fields[ $result->unit_id ] = $result->translated;
		}

		$targetId = \pll_get_term( $termId, $targetLang ) ?: 0;
		$preview  = [
			'source_id'   => $termId,
			'target_id'   => $targetId,
			'source_lang' => $sourceLang,
			'target_lang' => $targetLang,
			'fields'      => $fields,
		];

		if ( empty( $options['commit'] ) ) {
			return [ 'preview' => $preview ];
		}

		$model    = new TranslationTermModel();
		$targetId = $model->duplicate(
			$termId,
			$targetLang,
			$fields,
			[
				'overwrite'      => $targetId > 0,
				'translate_slug' => ! empty( $options['translate_slug'] ),
			]
		);

		if ( is_wp_error( $targetId ) ) {
			return $targetId;
		}

		return [
			'preview' => [
				...$preview,
				'target_id' => $targetId,
			],
			'item'    => [ 'id' => $targetId ],
		];
	}
}
