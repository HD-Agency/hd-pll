<?php
/**
 * Translation unit DTO.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class TranslationUnit {

	/**
	 * Pattern → validation error code mapping.
	 * Single source of truth for extractProtectedTokens() and TranslationValidator.
	 *
	 * @var array<string, string>
	 */
	public const PROTECTED_PATTERNS = [
		'/%(?:\d+\$)?[bcdeEfFgGosuxX]/'    => 'placeholder_mismatch',
		'/\{[A-Za-z0-9_.-]+\}/'            => 'named_placeholder_mismatch',
		'/\[[A-Za-z0-9_-]+(?:\s[^\]]*)?]/' => 'shortcode_mismatch',
		'~https?://[^\s<>"\']+~'           => 'url_mismatch',
	];

	/**
	 * @param string[] $protected_tokens Tokens that must remain unchanged.
	 * @param string[] $path             Object/content path metadata.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $source,
		public readonly string $context = '',
		public readonly string $format = 'text',
		public readonly array $protected_tokens = [],
		public readonly array $path = []
	) {}

	/**
	 * Extract protected tokens from a text segment.
	 *
	 * Canonical implementation — used by ContentExtractor and UnitChunker.
	 *
	 * @return string[]
	 */
	public static function extractProtectedTokens( string $text ): array {
		$tokens = [];
		foreach ( array_keys( self::PROTECTED_PATTERNS ) as $pattern ) {
			preg_match_all( $pattern, $text, $matches );
			$tokens = array_merge( $tokens, $matches[0] ?? [] );
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * @param array<string, mixed> $data Raw unit data.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			sanitize_key( (string) ( $data['id'] ?? '' ) ),
			(string) ( $data['source'] ?? '' ),
			(string) ( $data['context'] ?? '' ),
			sanitize_key( (string) ( $data['format'] ?? 'text' ) ),
			array_values( array_map( 'strval', (array) ( $data['protected_tokens'] ?? [] ) ) ),
			array_values( array_map( 'strval', (array) ( $data['path'] ?? [] ) ) )
		);
	}
}
