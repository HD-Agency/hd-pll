<?php
/**
 * Translation unit chunker for AI translation pipeline.
 *
 * Splits translation units into sized chunks that fit within
 * configured limits per API request.
 *
 * @package HDPLL\AI\Pipeline
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\TranslationUnit;
use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class UnitChunker {

	/**
	 * Split units into sized chunks based on configured limits.
	 *
	 * @param TranslationUnit[] $units Translation units.
	 *
	 * @return array<int, TranslationUnit[]>
	 */
	public function chunk( array $units ): array {
		$settings     = Plugin::getCachedOptions();
		$maxUnits     = max( 1, absint( $settings['ai_max_units_per_request'] ?? 25 ) );
		$maxChars     = max( 1000, absint( $settings['ai_max_chars_per_request'] ?? 12000 ) );
		$chunks       = [];
		$current      = [];
		$currentChars = 0;

		foreach ( $units as $unit ) {
			$unitChars   = $this->unitLength( $unit );
			$limitByUnit = count( $current ) >= $maxUnits;
			$limitByChar = ! empty( $current ) && ( $currentChars + $unitChars ) > $maxChars;

			if ( $limitByUnit || $limitByChar ) {
				$chunks[]     = $current;
				$current      = [];
				$currentChars = 0;
			}

			$current[]     = $unit;
			$currentChars += $unitChars;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Calculate the estimated character length of a translation unit.
	 */
	public function unitLength( TranslationUnit $unit ): int {
		return strlen( $unit->source )
			+ strlen( $unit->context )
			+ strlen( $unit->format )
			+ strlen( implode( '', $unit->protected_tokens ) );
	}

	/**
	 * Split an oversized unit into smaller sub-units at HTML block boundaries.
	 *
	 * Splits at closing tags (</p>, </h2>, </h3>, </li>, </div>, </section>,
	 * </blockquote>, </figure>) or double newlines. Each sub-unit keeps the
	 * original context, format, and path.
	 *
	 * @param TranslationUnit $unit     Unit to split.
	 * @param int             $maxChars Maximum characters per sub-unit.
	 *
	 * @return TranslationUnit[]
	 */
	public function splitLargeUnit( TranslationUnit $unit, int $maxChars ): array {
		// Split at HTML block boundaries.
		$pattern  = '#(</(?:p|h[1-6]|li|div|section|blockquote|figure|tr|table|ul|ol|dl)>\s*)#i';
		$segments = preg_split( $pattern, $unit->source, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $segments || count( $segments ) <= 1 ) {
			// Fallback: split on double newlines.
			$segments = preg_split( '/\n{2,}/', $unit->source );
		}

		if ( false === $segments || count( $segments ) <= 1 ) {
			return [ $unit ];
		}

		// Rejoin segments+delimiters and group into sub-units within maxChars.
		$subUnits = [];
		$buffer   = '';
		$partNum  = 0;

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( '' !== $buffer && strlen( $buffer ) + strlen( $segment ) > $maxChars ) {
				$subUnits[] = new TranslationUnit(
					$unit->id . '__part_' . $partNum,
					$buffer,
					$unit->context,
					$unit->format,
					TranslationUnit::extractProtectedTokens( $buffer ),
					$unit->path
				);
				$buffer     = '';
				++$partNum;
			}

			$buffer .= $segment;
		}

		if ( '' !== $buffer ) {
			$subUnits[] = new TranslationUnit(
				$unit->id . '__part_' . $partNum,
				$buffer,
				$unit->context,
				$unit->format,
				TranslationUnit::extractProtectedTokens( $buffer ),
				$unit->path
			);
		}

		return count( $subUnits ) > 1 ? $subUnits : [ $unit ];
	}
}
