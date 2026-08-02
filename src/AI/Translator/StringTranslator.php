<?php
/**
 * Polylang string translator.
 *
 * @package HDPLL\AI\Translator
 */

declare(strict_types=1);

namespace HDPLL\AI\Translator;

use HDPLL\AI\TranslationUnit;
use HDPLL\AI\TranslationEngine;

defined( 'ABSPATH' ) || exit;

final class StringTranslator {

	public function __construct( private readonly TranslationEngine $engine = new TranslationEngine() ) {}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function translateBatch( string $targetLang, array $options = [] ): array|\WP_Error {
		if ( ! class_exists( 'PLL_Admin_Strings' ) ) {
			return new \WP_Error( 'hd_pll_ai_strings_unavailable', __( 'Polylang string admin API is unavailable.', 'hd-pll' ) );
		}

		$language = \PLL()->model->get_language( $targetLang );
		$default  = \PLL()->model->get_default_language();
		if ( ! $language || ! $default ) {
			return new \WP_Error( 'hd_pll_ai_invalid_language', __( 'Invalid target language.', 'hd-pll' ) );
		}

		$group     = sanitize_text_field( (string) ( $options['group'] ?? '' ) );
		$batchSize = min( 100, max( 1, absint( $options['batch_size'] ?? 20 ) ) );
		$strings   = $this->loadStrings( $group );
		if ( ! array_key_exists( 'missing_only', $options ) || ! empty( $options['missing_only'] ) ) {
			$strings = $this->filterMissingStrings( $strings, $language );
		}
		$strings = array_slice( $strings, 0, $batchSize );
		$units   = [];

		foreach ( $strings as $index => $string ) {
			$value = $this->stringValue( $string );
			if ( '' !== $value ) {
				$units[] = new TranslationUnit( 'string_' . $index, $value, 'Polylang string', 'text' );
			}
		}

		$results = $this->engine->translateUnits( $units, $default->slug, $targetLang );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$items = array_map( static fn( $result ): array => $result->toArray(), $results );

		if ( ! empty( $options['commit'] ) ) {
			$mo = new \PLL_MO();
			$mo->import_from_db( $language );
			foreach ( $results as $result ) {
				$index = (int) substr( $result->unit_id, 7 );
				$value = isset( $strings[ $index ] ) ? $this->stringValue( $strings[ $index ] ) : '';
				if ( '' !== $value ) {
					$mo->add_entry( $mo->make_entry( $value, $result->translated ) );
				}
			}
			$mo->export_to_db( $language );
		}

		return [ 'items' => $items ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadStrings( string $group = '' ): array {
		$strings = \PLL_Admin_Strings::get_strings();
		if ( '' === $group ) {
			return (array) $strings;
		}

		return array_values(
			array_filter(
				(array) $strings,
				fn( mixed $item ): bool => $this->stringContext( $item ) === $group
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $strings Source strings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filterMissingStrings( array $strings, \PLL_Language $language ): array {
		$mo = new \PLL_MO();
		$mo->import_from_db( $language );

		return array_values(
			array_filter(
				$strings,
				fn( mixed $item ): bool => '' === $mo->translate_if_any( $this->stringValue( $item ) )
			)
		);
	}

	private function stringValue( mixed $item ): string {
		if ( is_array( $item ) ) {
			return (string) ( $item['string'] ?? $item['name'] ?? '' );
		}

		return is_object( $item ) ? (string) ( $item->string ?? $item->name ?? '' ) : '';
	}

	private function stringContext( mixed $item ): string {
		if ( is_array( $item ) ) {
			return (string) ( $item['context'] ?? '' );
		}

		return is_object( $item ) ? (string) ( $item->context ?? '' ) : '';
	}
}
