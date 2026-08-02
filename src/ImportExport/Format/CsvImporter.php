<?php
/**
 * CSV Importer — imports string translations from CSV via OpenSpout.
 *
 * Expects format: String | Source | SourceLang | TargetLang
 * Also backward-compatible with old multi-language format:
 *   String | Source | Lang1 (locale1) | Lang2 (locale2) | ...
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport\Format;

use HDPLL\ImportExport\ImporterInterface;
use HDPLL\ImportExport\FileFormatFactory;
use OpenSpout\Reader\CSV\Reader;

defined( 'ABSPATH' ) || exit;

final class CsvImporter implements ImporterInterface {

	/** @var array<int, array{string: string, translations: array<string, string>}> */
	private array $entries = [];

	/** @var string[] Column-index-to-locale map (col 2+). */
	private array $langMap = [];

	private int $cursor = 0;

	public function importFromFile( string $filepath ): bool|\WP_Error {
		$reader = new Reader();

		try {
			$reader->open( $filepath );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'pll_csv_read_error', $e->getMessage() );
		}

		$rowIndex = 0;

		foreach ( $reader->getSheetIterator() as $sheet ) {
			foreach ( $sheet->getRowIterator() as $rowEntity ) {
				$row = $rowEntity->toArray();

				if ( 0 === $rowIndex ) {
					$this->parseHeader( $row );
					++$rowIndex;
					continue;
				}

				$string       = (string) ( $row[0] ?? '' );
				$translations = [];

				foreach ( $this->langMap as $col => $locale ) {
					$value = (string) ( $row[ $col ] ?? '' );
					if ( '' !== $value && $value !== $string ) {
						$translations[ $locale ] = $value;
					}
				}

				if ( '' !== $string && ! empty( $translations ) ) {
					$this->entries[] = [
						'string'       => $string,
						'translations' => $translations,
					];
				}

				++$rowIndex;
			}
		}

		$reader->close();

		if ( empty( $this->entries ) ) {
			return new \WP_Error( 'pll_csv_empty', __( 'No translatable entries found in CSV file.', 'hd-pll' ) );
		}

		return true;
	}

	public function getTargetLanguage(): string|false {
		// Need at least source + target columns.
		$values = array_values( $this->langMap );
		if ( count( $values ) < 2 ) {
			return false;
		}

		return $values[1];
	}

	public function getSiteReference(): string|false {
		// CSV has no site reference metadata — skip validation.
		return get_site_url();
	}

	public function getGeneratorName(): string {
		// CSV has no generator metadata — accept all CSVs.
		return FileFormatFactory::APP_NAME;
	}

	public function getNextEntry(): array {
		if ( $this->cursor >= count( $this->entries ) ) {
			return [];
		}

		$entry = $this->entries[ $this->cursor ];
		++$this->cursor;

		return [
			'type' => 'strings-translations',
			'id'   => null,
			'data' => $entry,
		];
	}

	/**
	 * Parse header row to build column-to-locale map.
	 * Expects: String | Source | LangName (locale) | LangName (locale) | ...
	 *
	 * @param array $row Header row values.
	 */
	private function parseHeader( array $row ): void {
		$languages = function_exists( 'PLL' ) ? \PLL()->model->get_languages_list() : [];

		for ( $col = 2, $max = count( $row ); $col < $max; $col++ ) {
			$header = (string) $row[ $col ];

			foreach ( $languages as $lang ) {
				if ( str_contains( $header, $lang->locale ) || str_contains( $header, $lang->slug ) ) {
					$this->langMap[ $col ] = $lang->locale;
					break;
				}
			}
		}
	}
}
