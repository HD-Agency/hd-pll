<?php
/**
 * CSV Exporter — exports string translations via OpenSpout.
 *
 * Format: String | Source | Language1 (locale) | Language2 (locale) | ...
 * Compatible with the old CsvProcessor export format.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport\Format;

use HDPLL\ImportExport\ExporterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;

defined( 'ABSPATH' ) || exit;

final class CsvExporter implements ExporterInterface {

	private string $sourceLang;
	private string $targetLang;

	/** @var array<int, array{source: string, target: string, context: string}> */
	private array $entries = [];

	public function __construct( string $sourceLang, string $targetLang ) {
		$this->sourceLang = $sourceLang;
		$this->targetLang = $targetLang;
	}

	public function addTranslationEntry( array $ref, string $source, string $target = '' ): void {
		if ( '' === $source ) {
			return;
		}

		$this->entries[] = [
			'source'  => $source,
			'target'  => $target,
			'context' => $ref['field_id'] ?? '',
		];
	}

	public function getContent(): string {
		$writer = new Writer();

		$tmpFile = wp_tempnam( 'pll_csv_export' );
		$writer->openToFile( $tmpFile );

		// Header.
		$writer->addRow(
			Row::fromValues(
				[
					'String',
					'Source',
					$this->sourceLang,
					$this->targetLang,
				]
			)
		);

		// Data rows.
		foreach ( $this->entries as $entry ) {
			$writer->addRow(
				Row::fromValues(
					[
						$entry['source'],
						$entry['context'],
						$entry['source'],
						$entry['target'],
					]
				)
			);
		}

		$writer->close();

		$content = (string) file_get_contents( $tmpFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmpFile );

		return $content;
	}

	public function getFilename(): string {
		return sprintf(
			'pll_strings_%s_%s_%s.csv',
			$this->sourceLang,
			$this->targetLang,
			gmdate( 'Y-m-d' )
		);
	}
}
