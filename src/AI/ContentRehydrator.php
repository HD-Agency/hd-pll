<?php
/**
 * Rehydrate translated units into content fields.
 *
 * @package HDPLL\AI\Content
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\TranslationResult;

defined( 'ABSPATH' ) || exit;

final class ContentRehydrator {

	public function __construct( private readonly HtmlStructure $html = new HtmlStructure() ) {}

	/**
	 * @param TranslationResult[] $results Translation results.
	 *
	 * @return array<string, string>
	 */
	public function postFields( array $results, ?\WP_Post $source = null ): array {
		$fields       = [];
		$blockResults = [];
		$htmlResults  = [];
		foreach ( $results as $result ) {
			$field = match ( $result->unit_id ) {
				'post_title'   => 'post_title',
				'post_excerpt' => 'post_excerpt',
				'post_content' => 'post_content',
				default        => '',
			};

			if ( '' !== $field ) {
				$fields[ $field ] = $result->translated;
				continue;
			}

			if ( [ 'post_content' ] === array_slice( $result->path, 0, 1 ) ) {
				$htmlResults[] = $result;
				continue;
			}

			if ( [ 'blocks' ] === array_slice( $result->path, 0, 1 ) ) {
				$blockResults[] = $result;
			}
		}

		if ( $source instanceof \WP_Post && ! empty( $htmlResults ) ) {
			$fields['post_content'] = $this->applyHtmlResults( $source->post_content, $htmlResults, [ 'post_content' ] );
		}

		if ( $source instanceof \WP_Post && ! empty( $blockResults ) && has_blocks( $source->post_content ) ) {
			$blocks = parse_blocks( $source->post_content );
			foreach ( $blockResults as $result ) {
				$this->applyBlockResult( $blocks, $result );
			}
			$fields['post_content'] = serialize_blocks( $blocks );
		}

		return $fields;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function applyBlockResult( array &$blocks, TranslationResult $result ): void {
		$path = $result->path;
		if ( 'blocks' !== array_shift( $path ) ) {
			return;
		}

		$this->applyBlockPath( $blocks, $path, $result );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string[]                        $path   Block-relative path.
	 */
	private function applyBlockPath( array &$blocks, array $path, TranslationResult $result ): void {
		$index = array_shift( $path );
		if ( null === $index || ! is_numeric( $index ) || ! isset( $blocks[ (int) $index ] ) ) {
			return;
		}

		$section = array_shift( $path );
		if ( 'innerBlocks' === $section ) {
			if ( ! isset( $blocks[ (int) $index ]['innerBlocks'] ) || ! is_array( $blocks[ (int) $index ]['innerBlocks'] ) ) {
				return;
			}

			$this->applyBlockPath( $blocks[ (int) $index ]['innerBlocks'], $path, $result );
			return;
		}

		if ( 'attrs' === $section ) {
			$attr = array_shift( $path );
			if ( null !== $attr && isset( $blocks[ (int) $index ]['attrs'] ) && is_array( $blocks[ (int) $index ]['attrs'] ) ) {
				$blocks[ (int) $index ]['attrs'][ $attr ] = $result->translated;
			}
			return;
		}

		if ( 'innerHTML' === $section ) {
			$basePath = ! empty( $path ) ? array_slice( $result->path, 0, -count( $path ) ) : $result->path;
			$html     = is_string( $blocks[ (int) $index ]['innerHTML'] ?? null ) ? $blocks[ (int) $index ]['innerHTML'] : '';

			$blocks[ (int) $index ]['innerHTML']    = $this->applyHtmlResults( $html, [ $result ], $basePath );
			$blocks[ (int) $index ]['innerContent'] = [ $blocks[ (int) $index ]['innerHTML'] ];
		}
	}

	/**
	 * @param TranslationResult[] $results  HTML translation results.
	 * @param string[]            $basePath Expected path prefix.
	 */
	private function applyHtmlResults( string $html, array $results, array $basePath ): string {
		$segments = [];
		$attrs    = [];

		foreach ( $results as $result ) {
			if ( $basePath !== array_slice( $result->path, 0, count( $basePath ) ) ) {
				continue;
			}

			$relative = array_slice( $result->path, count( $basePath ) );
			$type     = $relative[0] ?? '';
			$index    = isset( $relative[1] ) ? (int) $relative[1] : -1;

			if ( 'segments' === $type && $index >= 0 ) {
				$segments[ $index ] = $result->translated;
				continue;
			}

			if ( 'tag_attrs' === $type && $index >= 0 && isset( $relative[2] ) ) {
				$attrs[ $index ][ (string) $relative[2] ] = $result->translated;
			}
		}

		$parts = $this->html->split( $html );
		foreach ( $parts as $index => $part ) {
			if ( isset( $segments[ $index ] ) ) {
				$parts[ $index ] = $segments[ $index ];
				continue;
			}

			if ( ! isset( $attrs[ $index ] ) || ! $this->html->isTag( $part ) ) {
				continue;
			}

			foreach ( $attrs[ $index ] as $attr => $value ) {
				$part = $this->html->replaceAttribute( $part, $attr, $value );
			}

			$parts[ $index ] = $part;
		}

		return implode( '', $parts );
	}
}
