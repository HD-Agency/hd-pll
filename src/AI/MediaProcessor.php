<?php
/**
 * Media placeholder processor for AI translation pipeline.
 *
 * Strips <figure> and <img> tags from translation units, replacing
 * them with {{IMG_n}} placeholders. After translation, restores
 * original markup.
 *
 * @package HDPLL\AI\Pipeline
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\TranslationUnit;

defined( 'ABSPATH' ) || exit;

final class MediaProcessor {

	/**
	 * Strip <figure>…</figure> blocks and standalone <img> tags from unit sources,
	 * replacing them with {{IMG_n}} placeholders. The placeholder format is
	 * intentionally chosen to match the protected_tokens `{…}` pattern so the
	 * validator ensures AI preserves them.
	 *
	 * @param TranslationUnit[]                  $units    Units to process.
	 * @param array<string, array<string, string>> &$mediaMap Populated with unit-ID → [placeholder → original markup].
	 *
	 * @return TranslationUnit[] Units with images replaced by placeholders.
	 */
	public function strip( array $units, array &$mediaMap ): array {
		$result = [];
		foreach ( $units as $unit ) {
			$placeholders = [];
			$counter      = 0;
			$source       = $unit->source;

			// Replace <figure …>…</figure> blocks (greedy-safe via non-greedy match).
			$source = preg_replace_callback(
				'#<figure\b[^>]*>[\s\S]*?</figure>#i',
				static function ( array $m ) use ( &$placeholders, &$counter ): string {
					$ph                  = '{{IMG_' . $counter . '}}';
					$placeholders[ $ph ] = $m[0];
					++$counter;
					return $ph;
				},
				$source
			) ?? $source;

			// Replace standalone <img …> tags not already inside a <figure>.
			$source = preg_replace_callback(
				'#<img\b[^>]*/?>\s*#i',
				static function ( array $m ) use ( &$placeholders, &$counter ): string {
					$ph                  = '{{IMG_' . $counter . '}}';
					$placeholders[ $ph ] = $m[0];
					++$counter;
					return $ph;
				},
				$source
			) ?? $source;

			if ( ! empty( $placeholders ) ) {
				$mediaMap[ $unit->id ] = $placeholders;

				// Rebuild unit with stripped source and re-extracted protected tokens.
				$protectedTokens = array_merge(
					TranslationUnit::extractProtectedTokens( $source ),
					array_keys( $placeholders )
				);
				$unit            = new TranslationUnit(
					$unit->id,
					$source,
					$unit->context,
					$unit->format,
					array_values( array_unique( $protectedTokens ) ),
					$unit->path
				);
			}

			$result[] = $unit;
		}

		return $result;
	}

	/**
	 * Restore original image markup in translated text by replacing placeholders.
	 *
	 * @param array<string, string>               $translations Translated text by unit ID.
	 * @param array<string, array<string, string>> $mediaMap     Placeholder → original markup by unit ID.
	 *
	 * @return array<string, string>
	 */
	public function restore( array $translations, array $mediaMap ): array {
		foreach ( $mediaMap as $unitId => $placeholders ) {
			if ( ! isset( $translations[ $unitId ] ) ) {
				continue;
			}

			$translations[ $unitId ] = strtr( $translations[ $unitId ], $placeholders );
		}

		return $translations;
	}
}
