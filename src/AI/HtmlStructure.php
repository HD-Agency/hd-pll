<?php
/**
 * HTML and shortcode structure helpers for AI translation.
 *
 * @package HDPLL\AI\Content
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class HtmlStructure {

	private const TOKEN_PATTERN = '~\[([A-Za-z0-9_-]+)(?:\s[^\]]*)?\][\s\S]*?\[/\1\]|<[^>]+>|\[/?[A-Za-z0-9_-]+(?:\s[^\]]*)?\]~';

	private const TRANSLATABLE_MEDIA_ATTRIBUTES = [ 'alt', 'title' ];

	private const TRANSLATABLE_GENERIC_ATTRIBUTES = [ 'title', 'aria-label' ];

	/**
	 * @return string[]
	 */
	public function split( string $html ): array {
		if ( '' === $html ) {
			return [];
		}

		preg_match_all( self::TOKEN_PATTERN, $html, $matches, PREG_OFFSET_CAPTURE );
		$parts  = [];
		$offset = 0;

		foreach ( $matches[0] ?? [] as $match ) {
			$token = (string) $match[0];
			$start = (int) $match[1];

			if ( $start > $offset ) {
				$parts[] = substr( $html, $offset, $start - $offset );
			}

			$parts[] = $token;
			$offset  = $start + strlen( $token );
		}

		if ( $offset < strlen( $html ) ) {
			$parts[] = substr( $html, $offset );
		}

		return $parts;
	}

	public function isTag( string $part ): bool {
		return str_starts_with( ltrim( $part ), '<' );
	}

	public function isShortcodeToken( string $part ): bool {
		return 1 === preg_match( '/^\[\/?[A-Za-z0-9_-]+(?:\s[^\]]*)?\](?:[\s\S]*\[\/[A-Za-z0-9_-]+\])?$/', trim( $part ) );
	}

	public function isShortcodeOnly( string $text ): bool {
		$source = trim( wp_strip_all_tags( $text ) );

		return 1 === preg_match( '/^\[\/?[A-Za-z0-9_-]+(?:\s[^\]]*)?\](?:[\s\S]*\[\/[A-Za-z0-9_-]+\])?$/', $source );
	}

	/**
	 * @return array<string, string>
	 */
	public function translatableAttributes( string $tag ): array {
		$name = $this->tagName( $tag );
		if ( '' === $name || str_starts_with( ltrim( $tag ), '</' ) || str_starts_with( ltrim( $tag ), '<!' ) ) {
			return [];
		}

		$allowed = 'img' === $name ? self::TRANSLATABLE_MEDIA_ATTRIBUTES : self::TRANSLATABLE_GENERIC_ATTRIBUTES;
		$attrs   = [];

		foreach ( $this->attributes( $tag ) as $attr => $value ) {
			if ( in_array( $attr, $allowed, true ) && '' !== trim( $value ) ) {
				$attrs[ $attr ] = $value;
			}
		}

		return $attrs;
	}

	public function replaceAttribute( string $tag, string $attribute, string $value ): string {
		$pattern = '/(\s' . preg_quote( $attribute, '/' ) . '\s*=\s*)(["\'])(.*?)\2/i';

		return preg_replace_callback(
			$pattern,
			static fn( array $m ): string => $m[1] . $m[2] . esc_attr( $value ) . $m[2],
			$tag,
			1
		) ?? $tag;
	}

	/**
	 * @return string[]
	 */
	public function immutableSignature( string $html, bool $allowHrefChanges = false ): array {
		$signature = [];

		foreach ( $this->split( $html ) as $part ) {
			if ( $this->isShortcodeToken( $part ) ) {
				$signature[] = 'shortcode:' . trim( $part );
				continue;
			}

			if ( ! $this->isTag( $part ) ) {
				continue;
			}

			$signature[] = 'tag:' . wp_json_encode( $this->tagSignature( $part, $allowHrefChanges ), JSON_UNESCAPED_SLASHES );
		}

		return $signature;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function tagSignature( string $tag, bool $allowHrefChanges = false ): array {
		$trimmed = trim( $tag );
		$name    = $this->tagName( $tag );
		$attrs   = $this->attributes( $tag );

		foreach ( [ ...self::TRANSLATABLE_MEDIA_ATTRIBUTES, ...self::TRANSLATABLE_GENERIC_ATTRIBUTES ] as $attr ) {
			unset( $attrs[ $attr ] );
		}
		if ( $allowHrefChanges ) {
			unset( $attrs['href'] );
		}

		ksort( $attrs );

		return [
			'name'    => $name,
			'closing' => str_starts_with( $trimmed, '</' ),
			'special' => str_starts_with( $trimmed, '<!' ) || str_starts_with( $trimmed, '<?' ),
			'attrs'   => $attrs,
		];
	}

	private function tagName( string $tag ): string {
		if ( ! preg_match( '/^<\s*\/?\s*([A-Za-z0-9:-]+)/', $tag, $m ) ) {
			return '';
		}

		return strtolower( $m[1] );
	}

	/**
	 * @return array<string, string>
	 */
	private function attributes( string $tag ): array {
		$name = $this->tagName( $tag );
		if ( '' === $name ) {
			return [];
		}

		$inside = preg_replace( '/^<\s*\/?\s*' . preg_quote( $name, '/' ) . '\s*/i', '', trim( $tag ) );
		$inside = preg_replace( '/\/?>$/', '', (string) $inside );

		preg_match_all( '/([A-Za-z_:][-A-Za-z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>`]+)))?/', $inside, $matches, PREG_SET_ORDER );

		$attrs = [];
		foreach ( $matches as $m ) {
			$attr = strtolower( (string) $m[1] );
			if ( '' === $attr ) {
				continue;
			}

			$attrs[ $attr ] = (string) ( $m[2] ?? $m[3] ?? $m[4] ?? '' );
		}

		return $attrs;
	}
}
