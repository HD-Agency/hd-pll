<?php
/**
 * Shared AI translation orchestration.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\Translator\PostTranslator;
use HDPLL\AI\Translator\StringTranslator;
use HDPLL\AI\Translator\TaxonomyTranslator;

defined( 'ABSPATH' ) || exit;

final class TranslationCoordinator {

	public function __construct(
		private readonly TermDependencyResolver $dependencies = new TermDependencyResolver(),
		private readonly PostTranslator $postTranslator = new PostTranslator(),
		private readonly TaxonomyTranslator $taxonomyTranslator = new TaxonomyTranslator(),
		private readonly StringTranslator $stringTranslator = new StringTranslator()
	) {}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run( string $type, int $sourceId, string $targetLang, array $options = [] ): array|\WP_Error {
		$type = sanitize_key( $type );

		return match ( $type ) {
			'term'   => $this->taxonomyTranslator->translate( $sourceId, $targetLang, $options ),
			'string' => $this->stringTranslator->translateBatch( $targetLang, $options ),
			default  => $this->translatePostWithDependencies( $sourceId, $targetLang, $options ),
		};
	}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function translatePostWithDependencies( int $sourceId, string $targetLang, array $options ): array|\WP_Error {
		if ( ! empty( $options['commit'] ) ) {
			$result = $this->translateMissingTerms( $sourceId, $targetLang, $options );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->postTranslator->translate( $sourceId, $targetLang, $options );
	}

	/**
	 * @param array<string, mixed> $options Translation options.
	 */
	private function translateMissingTerms( int $sourceId, string $targetLang, array $options ): bool|\WP_Error {
		$terms = $this->dependencies->missingForPost( $sourceId, $targetLang );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $term ) {
			$result = $this->taxonomyTranslator->translate(
				(int) $term['term_id'],
				$targetLang,
				[
					...$options,
					'commit' => true,
				]
			);

			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'hd_pll_ai_term_dependency_failed',
					$result->get_error_message(),
					[
						'status'      => 400,
						'source_id'   => $sourceId,
						'term_id'     => (int) $term['term_id'],
						'taxonomy'    => (string) $term['taxonomy'],
						'target_lang' => $targetLang,
						'error_code'  => $result->get_error_code(),
					]
				);
			}
		}

		return true;
	}
}
