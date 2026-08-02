<?php
/**
 * File Format Factory — central registry for import/export formats.
 *
 * Factory pattern: maps extensions/MIME types to the correct
 * Exporter/Importer strategy. Adding a new format = add one entry to FORMATS.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport;

use HDPLL\ImportExport\Format\CsvExporter;
use HDPLL\ImportExport\Format\CsvImporter;
use HDPLL\ImportExport\Format\PoExporter;
use HDPLL\ImportExport\Format\PoImporter;
use HDPLL\ImportExport\Format\XliffExporter;
use HDPLL\ImportExport\Format\XliffImporter;

defined( 'ABSPATH' ) || exit;

final class FileFormatFactory {

	/** Generator name embedded in exported files. */
	public const APP_NAME = 'Polylang';

	/**
	 * Format registry.
	 *
	 * @var array<string, array{
	 *   label: string,
	 *   extension: string,
	 *   mime_types: array<string, string>,
	 *   exporter: class-string<ExporterInterface>,
	 *   importer: class-string<ImporterInterface>,
	 *   supports: string[],
	 *   requires: string|null
	 * }>
	 */
	private const FORMATS = [
		'csv'   => [
			'label'      => 'CSV',
			'extension'  => 'csv',
			'mime_types' => [ 'csv' => 'text/csv' ],
			'exporter'   => CsvExporter::class,
			'importer'   => CsvImporter::class,
			'supports'   => [ 'strings' ],
			'requires'   => null,
		],
		'po'    => [
			'label'      => 'PO',
			'extension'  => 'po',
			'mime_types' => [ 'po' => 'text/x-po' ],
			'exporter'   => PoExporter::class,
			'importer'   => PoImporter::class,
			'supports'   => [ 'strings' ],
			'requires'   => null,
		],
		'xliff' => [
			'label'      => 'XLIFF 2.1',
			'extension'  => 'xliff',
			'mime_types' => [ 'xlf|xliff' => 'text/xml' ],
			'exporter'   => XliffExporter::class,
			'importer'   => XliffImporter::class,
			'supports'   => [ 'strings', 'posts', 'terms' ],
			'requires'   => 'libxml',
		],
	];

	/**
	 * Get formats supporting a given content type.
	 *
	 * @param string $contentType 'strings', 'posts', 'terms', or '' for all.
	 *
	 * @return array<string, array{label: string, extension: string}>
	 */
	public function getSupportedFormats( string $contentType = '' ): array {
		$result = [];

		foreach ( self::FORMATS as $key => $format ) {
			// Check PHP extension requirement.
			if ( null !== $format['requires'] && ! extension_loaded( $format['requires'] ) ) {
				continue;
			}

			// Filter by content type.
			if ( '' !== $contentType && ! in_array( $contentType, $format['supports'], true ) ) {
				continue;
			}

			$result[ $key ] = [
				'label'     => $format['label'],
				'extension' => $format['extension'],
			];
		}

		return $result;
	}

	/**
	 * Create an exporter for the given format key.
	 *
	 * @param string $formatKey   Format key (csv, po, xliff).
	 * @param string $sourceLang  Source language locale.
	 * @param string $targetLang  Target language locale.
	 *
	 * @return ExporterInterface|\WP_Error
	 */
	public function createExporter( string $formatKey, string $sourceLang, string $targetLang ): ExporterInterface|\WP_Error {
		$format = $this->resolveFormat( $formatKey );

		if ( \is_wp_error( $format ) ) {
			return $format;
		}

		$class = $format['exporter'];

		return new $class( $sourceLang, $targetLang );
	}

	/**
	 * Create an importer from a MIME type (for uploaded files).
	 *
	 * @param string $mimeType Detected MIME type of uploaded file.
	 *
	 * @return ImporterInterface|\WP_Error
	 */
	public function createImporterFromMime( string $mimeType ): ImporterInterface|\WP_Error {
		foreach ( self::FORMATS as $format ) {
			if ( null !== $format['requires'] && ! extension_loaded( $format['requires'] ) ) {
				continue;
			}

			if ( in_array( $mimeType, $format['mime_types'], true ) ) {
				$class = $format['importer'];

				return new $class();
			}
		}

		return new \WP_Error(
			'pll_import_wrong_format',
			__( 'Error: Unsupported file format. Supported: CSV, PO, XLIFF.', 'hd-pll' )
		);
	}

	/**
	 * Get all allowed MIME types for uploads.
	 *
	 * @return array<string, string>
	 */
	public function getAllowedMimeTypes(): array {
		$mimes = [];

		foreach ( self::FORMATS as $format ) {
			if ( null !== $format['requires'] && ! extension_loaded( $format['requires'] ) ) {
				continue;
			}

			$mimes = array_merge( $mimes, $format['mime_types'] );
		}

		return $mimes;
	}

	/**
	 * Resolve a format key to its config, with validation.
	 *
	 * @param string $key Format key.
	 *
	 * @return array|\WP_Error
	 */
	private function resolveFormat( string $key ): array|\WP_Error {
		if ( ! isset( self::FORMATS[ $key ] ) ) {
			return new \WP_Error(
				'pll_unknown_format',
				sprintf(
					/* translators: %s: format key */
					__( 'Error: Unknown file format "%s".', 'hd-pll' ),
					$key
				)
			);
		}

		$format = self::FORMATS[ $key ];

		if ( null !== $format['requires'] && ! extension_loaded( $format['requires'] ) ) {
			return new \WP_Error(
				'pll_format_unsupported',
				sprintf(
					/* translators: %s: PHP extension name */
					__( 'Error: PHP extension "%s" is required but not loaded.', 'hd-pll' ),
					$format['requires']
				)
			);
		}

		return $format;
	}
}
