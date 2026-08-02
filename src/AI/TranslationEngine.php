<?php
/**
 * Shared translation engine.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class TranslationEngine {

	public function __construct(
		private readonly AiClient $client = new AiClient(),
		private readonly PromptBuilder $promptBuilder = new PromptBuilder(),
		private readonly TranslationValidator $validator = new TranslationValidator(),
		private readonly UnitChunker $chunker = new UnitChunker(),
		private readonly MediaProcessor $mediaProcessor = new MediaProcessor()
	) {}

	/**
	 * @param array<int, TranslationUnit|array<string, mixed>> $units Translation units.
	 *
	 * @return TranslationResult[]|\WP_Error
	 */
	public function translateUnits( array $units, string $sourceLang, string $targetLang ): array|\WP_Error {
		$units = array_values(
			array_map(
				static fn( TranslationUnit|array $unit ): TranslationUnit => $unit instanceof TranslationUnit ? $unit : TranslationUnit::fromArray( $unit ),
				$units
			)
		);

		if ( empty( $units ) ) {
			return [];
		}

		$results = [];
		foreach ( $this->chunker->chunk( $units ) as $chunk ) {
			$chunkResults = $this->translateChunk( $chunk, $sourceLang, $targetLang );
			if ( is_wp_error( $chunkResults ) ) {
				return $chunkResults;
			}

			array_push( $results, ...$chunkResults );
		}

		return $results;
	}

	/**
	 * @param TranslationUnit[] $units Translation units.
	 *
	 * @return TranslationResult[]|\WP_Error
	 */
	private function translateChunk( array $units, string $sourceLang, string $targetLang ): array|\WP_Error {
		$settings = Plugin::getCachedOptions();
		$maxChars = max( 1000, absint( $settings['ai_max_chars_per_request'] ?? 12000 ) );

		// Expand oversized units into sub-units.
		$expandedUnits = [];
		$splitMap      = []; // Maps original ID to sub-unit IDs.
		foreach ( $units as $unit ) {
			if ( strlen( $unit->source ) > $maxChars ) {
				$subUnits = $this->chunker->splitLargeUnit( $unit, $maxChars );
				if ( count( $subUnits ) > 1 ) {
					$splitMap[ $unit->id ] = array_map( static fn( TranslationUnit $u ): string => $u->id, $subUnits );
					array_push( $expandedUnits, ...$subUnits );
					continue;
				}
			}

			$expandedUnits[] = $unit;
		}

		// Strip images from sources before sending to AI; restore after.
		$mediaMap      = [];
		$strippedUnits = $this->mediaProcessor->strip( $expandedUnits, $mediaMap );

		$messages     = $this->promptBuilder->messages( $strippedUnits, $sourceLang, $targetLang );
		$translations = null;
		$lastError    = null;
		$bestContent  = '';

		// Retry on JSON parse / empty-content errors. The HDAT proxy may route
		// to a different provider on each attempt, improving success odds.
		// Partial results (some IDs missing) are accepted — missing IDs flow
		// into the validator → repair path below.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = $this->requestTranslations( $messages );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$content = AiClient::assistantContent( $response );
			if ( '' === $content ) {
				$lastError = new \WP_Error(
					'hd_pll_ai_empty_content',
					__( 'AI returned an empty response. Retrying…', 'hd-pll' )
				);
				continue;
			}

			$parsed = $this->parseTranslations( $content );
			if ( is_wp_error( $parsed ) ) {
				$lastError = $parsed;
				continue;
			}

			// Keep the best partial result across retries.
			if ( null === $translations || count( $parsed ) > count( $translations ) ) {
				$translations = $parsed;
				$bestContent  = $content;
			}

			// All unit IDs present — no need to retry.
			if ( count( $translations ) >= count( $strippedUnits ) ) {
				break;
			}
		}

		if ( null === $translations || empty( $translations ) ) {
			return $lastError ?? new \WP_Error( 'hd_pll_ai_json_parse_failed', __( 'AI response is not valid JSON.', 'hd-pll' ) );
		}

		// Restore original image markup in translated text.
		$translations = $this->mediaProcessor->restore( $translations, $mediaMap );

		$errors = $this->validator->validate( $expandedUnits, $translations );
		if ( ! empty( $errors ) ) {
			$repair = $this->requestTranslations( $this->promptBuilder->repairMessages( $errors, $bestContent ) );
			if ( is_wp_error( $repair ) ) {
				return $repair;
			}

			$translations = $this->parseTranslations( AiClient::assistantContent( $repair ) );
			if ( is_wp_error( $translations ) ) {
				return $translations;
			}

			$translations = $this->mediaProcessor->restore( $translations, $mediaMap );

			$errors = $this->validator->validate( $expandedUnits, $translations );
			if ( ! empty( $errors ) ) {
				return new \WP_Error( 'hd_pll_ai_validation_failed', __( 'Translation validation failed.', 'hd-pll' ), [ 'errors' => $errors ] );
			}
		}

		// Merge sub-unit translations back into original unit IDs.
		if ( ! empty( $splitMap ) ) {
			foreach ( $splitMap as $originalId => $subIds ) {
				$merged = '';
				foreach ( $subIds as $subId ) {
					$merged .= $translations[ $subId ] ?? '';
					unset( $translations[ $subId ] );
				}

				$translations[ $originalId ] = $merged;
			}
		}

		$usage = is_array( $response['usage'] ?? null ) ? $response['usage'] : [];

		return array_map(
			static fn( TranslationUnit $unit ): TranslationResult => new TranslationResult( $unit->id, $unit->source, $translations[ $unit->id ] ?? '', 'ok', [], $usage, $unit->path ),
			$units
		);
	}

	/**
	 * @param array<int, array<string, string>> $messages Chat messages.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function requestTranslations( array $messages ): array|\WP_Error {
		// Inject a per-call nonce into the user message content so that the HDAT
		// response cache produces a unique hash for every request. Without this,
		// a poisoned cache entry (malformed AI output) would be served on retries
		// and repeated button clicks for the full TTL window (24 h).
		// The nonce lives inside the message content string — it is never sent as
		// a top-level API body key (which would cause 400 errors on strict
		// providers like OpenAI and Anthropic).
		$last = array_key_last( $messages );
		if ( null !== $last && isset( $messages[ $last ]['content'] ) ) {
			$messages[ $last ]['content'] .= "\n{\"_nonce\":\"" . uniqid( '', true ) . '"}';
		}

		$payload = [
			'messages'    => $messages,
			'temperature' => 0.2,
			'max_tokens'  => $this->estimateMaxTokens( $messages ),
		];

		return $this->client->chat( $payload );
	}

	/**
	 * Parse AI response JSON into a translations map.
	 *
	 * Returns whatever valid translations the AI provided, even if incomplete.
	 * Missing unit IDs are handled downstream by the validator → repair path.
	 *
	 * @return array<string, string>|\WP_Error Translations map (id → text), or error if JSON is unparseable.
	 */
	private function parseTranslations( string $content ): array|\WP_Error {
		$content = $this->stripCodeFences( $content );
		$decoded = json_decode( trim( $content ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'hd_pll_ai_json_parse_failed', __( 'AI response is not valid JSON.', 'hd-pll' ) );
		}

		$items = $decoded['translations'] ?? $decoded;
		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'hd_pll_ai_missing_translations', __( 'AI response is missing translations.', 'hd-pll' ) );
		}

		$translations = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || ! array_key_exists( 'text', $item ) ) {
				continue;
			}

			$translations[ strtolower( trim( (string) $item['id'] ) ) ] = (string) $item['text'];
		}

		return $translations;
	}

	/**
	 * Estimate max_tokens for the completion based on message content length.
	 *
	 * Translation output is roughly the same length as input, plus JSON
	 * structural overhead. The default HDAT limit (2048) is too low for
	 * articles longer than ~600 words.
	 *
	 * @param array<int, array<string, string>> $messages Chat messages.
	 */
	private function estimateMaxTokens( array $messages ): int {
		$chars = 0;
		foreach ( $messages as $msg ) {
			$chars += strlen( (string) ( $msg['content'] ?? '' ) );
		}

		// ~1 token per 3 chars; double for translated output + JSON wrapper.
		$estimated = (int) ceil( $chars / 3 * 2 );

		return max( 2048, min( 32768, $estimated ) );
	}

	/**
	 * Strip markdown code fences that some models wrap around JSON output.
	 *
	 * Models without native json_object support often return:
	 *   ```json\n{...}\n```
	 */
	private function stripCodeFences( string $content ): string {
		$content = trim( $content );

		if ( preg_match( '/^```(?:json)?\s*\n(.+?)\n\s*```$/s', $content, $matches ) ) {
			return $matches[1];
		}

		return $content;
	}
}
