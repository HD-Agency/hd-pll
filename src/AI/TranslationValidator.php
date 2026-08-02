<?php
/**
 * Translation validation.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class TranslationValidator {

	public function __construct( private readonly HtmlStructure $html = new HtmlStructure() ) {}

	/**
	 * @param TranslationUnit[]     $units        Source units.
	 * @param array<string,string> $translations Translated text by unit ID.
	 *
	 * @return array<string, string[]> Errors by unit ID.
	 */
	public function validate( array $units, array $translations ): array {
		$errors = [];
		$ids    = array_map( static fn( TranslationUnit $unit ): string => $unit->id, $units );

		foreach ( array_diff( $ids, array_keys( $translations ) ) as $missingId ) {
			$errors[ $missingId ][] = 'missing_translation';
		}

		foreach ( $units as $unit ) {
			if ( ! isset( $translations[ $unit->id ] ) ) {
				continue;
			}

			$errors[ $unit->id ] ??= [];
			$translated            = $translations[ $unit->id ];
			if ( '' !== trim( $unit->source ) && '' === trim( $translated ) ) {
				$errors[ $unit->id ][] = 'empty_translation';
			}

			foreach ( $unit->protected_tokens as $token ) {
				if ( '' !== $token && ! str_contains( $translated, $token ) ) {
					$errors[ $unit->id ][] = 'missing_protected_token:' . $token;
				}
			}

			foreach ( TranslationUnit::PROTECTED_PATTERNS as $pattern => $code ) {
				$this->comparePattern( $unit->source, $translated, $pattern, $code, $errors[ $unit->id ] );
			}

			if ( in_array( $unit->format, [ 'html', 'block_attribute' ], true ) && ! $this->hasBalancedHtml( $translated ) ) {
				$errors[ $unit->id ][] = 'html_balance_failed';
			}

			if ( empty( $errors[ $unit->id ] ) ) {
				unset( $errors[ $unit->id ] );
			}
		}

		return $errors;
	}

	/**
	 * @param array<string, string> $fields Translated post fields.
	 *
	 * @return string[]
	 */
	public function validatePostFields( \WP_Post $source, array $fields, bool $allowHrefChanges = false ): array {
		if ( empty( $fields['post_content'] ) || ! is_string( $fields['post_content'] ) ) {
			return [];
		}

		$sourceSignature = $this->html->immutableSignature( $source->post_content, $allowHrefChanges );
		$targetSignature = $this->html->immutableSignature( $fields['post_content'], $allowHrefChanges );

		return $sourceSignature === $targetSignature ? [] : [ 'content_structure_mismatch' ];
	}

	/**
	 * @param string[] $errors Unit errors.
	 */
	private function comparePattern( string $source, string $translated, string $pattern, string $code, array &$errors ): void {
		preg_match_all( $pattern, $source, $sourceMatches );
		preg_match_all( $pattern, $translated, $translatedMatches );

		$sourceTokens     = $sourceMatches[0] ?? [];
		$translatedTokens = $translatedMatches[0] ?? [];
		sort( $sourceTokens );
		sort( $translatedTokens );

		if ( $sourceTokens !== $translatedTokens ) {
			$errors[] = $code;
		}
	}

	private function hasBalancedHtml( string $html ): bool {
		if ( '' === trim( $html ) || ! str_contains( $html, '<' ) ) {
			return true;
		}

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$result   = $document->loadHTML( '<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $result && empty( $errors );
	}
}
