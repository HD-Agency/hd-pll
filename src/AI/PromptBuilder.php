<?php
/**
 * Translation prompt builder.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class PromptBuilder {

	/**
	 * @param TranslationUnit[] $units Translation units.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function messages( array $units, string $sourceLang, string $targetLang ): array {
		$system = (string) apply_filters(
			'hd_pll_ai_system_prompt',
			'You translate WordPress content. Return ONLY valid JSON with the key "translations" containing an array of objects. '
			. 'Each object must have "id" (copy the exact id from the input unit) and "text" (the translated text). '
			. 'Translate only the supplied human-readable text. Preserve placeholders, shortcodes, HTML tags, URLs, media paths, '
			. 'attachment IDs, filenames, product codes, SKUs, brand names, and protected tokens exactly. '
			. 'Do not invent, remove, or rewrite markup or media references.'
		);

		$glossary = apply_filters( 'hd_pll_ai_glossary_terms', [], $sourceLang, $targetLang );
		$payload  = [
			'source_language' => $sourceLang,
			'target_language' => $targetLang,
			'glossary'        => array_values( (array) $glossary ),
			'units'           => array_map( [ $this, 'promptUnit' ], $units ),
		];

		return [
			[
				'role'    => 'system',
				'content' => $system,
			],
			[
				'role'    => 'user',
				'content' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '{}',
			],
		];
	}

	/**
	 * @param array<string, string[]> $errors Validation errors by unit ID.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function repairMessages( array $errors, string $previousContent ): array {
		return [
			[
				'role'    => 'system',
				'content' => 'Repair the JSON translation response. Return only the same JSON schema with corrected translations.',
			],
			[
				'role'    => 'user',
				'content' => wp_json_encode(
					[
						'errors'            => $errors,
						'previous_response' => $previousContent,
					],
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				) ?: '{}',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function promptUnit( TranslationUnit $unit ): array {
		$payload = [
			'id'      => $unit->id,
			'source'  => $unit->source,
			'context' => $unit->context,
			'format'  => $unit->format,
		];

		if ( ! empty( $unit->protected_tokens ) ) {
			$payload['protected_tokens'] = $unit->protected_tokens;
		}

		return $payload;
	}
}
