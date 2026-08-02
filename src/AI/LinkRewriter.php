<?php
/**
 * Rewrite internal links to translated objects.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class LinkRewriter {

	private const CACHE_MAX_ITEMS = 500;

	/** @var array<string, string> */
	private static array $cache = [];

	public function rewrite( string $html, string $targetLang ): string {
		if ( '' === trim( $html ) || ! str_contains( $html, 'href=' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/href=(["\'])(.*?)\1/i',
			fn( array $matches ): string => 'href=' . $matches[1] . esc_url( $this->rewriteUrl( $matches[2], $targetLang ) ) . $matches[1],
			$html
		) ?? $html;
	}

	private function rewriteUrl( string $url, string $targetLang ): string {
		$key = $url . '|' . $targetLang;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		if ( $this->shouldSkip( $url ) ) {
			return $this->remember( $key, $url );
		}

		$postId = url_to_postid( $url );
		if ( $postId ) {
			$translated = \pll_get_post( $postId, $targetLang );
			if ( $translated ) {
				return $this->remember( $key, get_permalink( $translated ) ?: $url );
			}
		}

		$home = home_url( '/' );
		if ( trailingslashit( $url ) === trailingslashit( $home ) ) {
			return $this->remember( $key, \pll_home_url( $targetLang ) );
		}

		return $this->remember( $key, $url );
	}

	private function remember( string $key, string $url ): string {
		if ( ! isset( self::$cache[ $key ] ) && count( self::$cache ) >= self::CACHE_MAX_ITEMS ) {
			unset( self::$cache[ (string) array_key_first( self::$cache ) ] );
		}

		self::$cache[ $key ] = $url;

		return $url;
	}

	private function shouldSkip( string $url ): bool {
		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return true;
		}

		foreach ( [ 'mailto:', 'tel:', '/wp-content/', '/wp-includes/', '/wp-admin/', '/wp-json/' ] as $needle ) {
			if ( str_contains( $url, $needle ) ) {
				return true;
			}
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST );
	}
}
