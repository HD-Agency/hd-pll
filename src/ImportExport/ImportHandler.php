<?php
/**
 * Import Handler — orchestrates translation file import.
 *
 * Handles file upload, format detection, validation, and delegates
 * to the appropriate Importer strategy for processing.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport;

use HDPLL\ImportExport\Format\CsvImporter;
use HDPLL\ImportExport\Format\PoImporter;
use HDPLL\ImportExport\Format\XliffImporter;

defined( 'ABSPATH' ) || exit;

final class ImportHandler {
	private const STRING_IMPORT_CHUNK_SIZE = 200;

	/**
	 * Process an uploaded translation file via $_FILES (legacy form POST).
	 *
	 * @param array $file $_FILES entry (tmp_name, type, size, etc.).
	 *
	 * @return array{imported: int, type: string, warnings: string[]}|\WP_Error
	 */
	public static function handle( array $file ): array|\WP_Error {
		if ( ! function_exists( 'PLL' ) ) {
			return new \WP_Error( 'pll_import_unavailable', __( 'Polylang is not available.', 'hd-pll' ) );
		}

		// Ensure admin file functions are available (not loaded in REST context).
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// ── Upload ──
		$factory = new FileFormatFactory();

		// Temporarily allow translation file MIME types.
		$allowMimes = static fn( array $mimes ): array => array_merge( $mimes, $factory->getAllowedMimeTypes() );
		add_filter( 'upload_mimes', $allowMimes ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes

		$upload = wp_handle_sideload( $file, [ 'test_form' => false ] );

		remove_filter( 'upload_mimes', $allowMimes );

		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'pll_import_upload_failed', $upload['error'] );
		}

		if ( empty( $upload['type'] ) || empty( $upload['file'] ) ) {
			return new \WP_Error( 'pll_import_upload_failed', __( 'Upload failed.', 'hd-pll' ) );
		}

		return self::processFile( $upload['file'], $upload['type'] );
	}

	/**
	 * Process a translation file directly from a file path (REST API path).
	 *
	 * Skips wp_handle_sideload — the file is already written to disk by the
	 * REST controller. Format is detected from file extension.
	 *
	 * @param string $filePath Absolute path to the temp file.
	 * @param string $filename Original filename (for extension detection).
	 *
	 * @return array{imported: int, type: string, warnings: string[]}|\WP_Error
	 */
	public static function handleFromPath( string $filePath, string $filename ): array|\WP_Error {
		if ( ! function_exists( 'PLL' ) ) {
			return new \WP_Error( 'pll_import_unavailable', __( 'Polylang is not available.', 'hd-pll' ) );
		}

		if ( ! file_exists( $filePath ) || ! is_readable( $filePath ) ) {
			return new \WP_Error( 'pll_import_file_missing', __( 'Import file not found or not readable.', 'hd-pll' ) );
		}

		$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime = match ( $ext ) {
			'po'           => 'text/x-po',
			'csv'          => 'text/csv',
			'xliff', 'xlf' => 'text/xml',
			default        => '',
		};

		if ( '' === $mime ) {
			return new \WP_Error(
				'pll_import_wrong_format',
				__( 'Error: Unsupported file format. Supported: CSV, PO, XLIFF.', 'hd-pll' )
			);
		}

		return self::processFile( $filePath, $mime );
	}

	/**
	 * Common processing: detect format, validate, import.
	 *
	 * @param string $filePath Absolute path to file.
	 * @param string $mimeType Detected MIME type.
	 *
	 * @return array{imported: int, type: string, warnings: string[]}|\WP_Error
	 */
	private static function processFile( string $filePath, string $mimeType ): array|\WP_Error {
		$factory  = new FileFormatFactory();
		$importer = $factory->createImporterFromMime( $mimeType );

		// Fallback: try extension-based detection for CSV (MIME might be text/plain).
		if ( \is_wp_error( $importer ) ) {
			$ext      = pathinfo( $filePath, PATHINFO_EXTENSION );
			$importer = match ( strtolower( $ext ) ) {
				'csv'           => new CsvImporter(),
				'po'            => new PoImporter(),
				'xliff', 'xlf'  => new XliffImporter(),
				default         => $importer,
			};
		}

		if ( \is_wp_error( $importer ) ) {
			wp_delete_file( $filePath );

			return $importer;
		}

		$parseResult = $importer->importFromFile( $filePath );
		wp_delete_file( $filePath );

		if ( \is_wp_error( $parseResult ) ) {
			return $parseResult;
		}

		// ── Validate metadata ──
		$validationError = self::validate( $importer );
		if ( \is_wp_error( $validationError ) ) {
			return $validationError;
		}

		// ── Resolve target language ──
		$targetLocale = $importer->getTargetLanguage();
		if ( false === $targetLocale ) {
			return new \WP_Error( 'pll_import_no_target', __( 'Error: No target language found in the file.', 'hd-pll' ) );
		}

		$targetLang = \PLL()->model->get_language( $targetLocale );
		if ( ! $targetLang ) {
			return new \WP_Error(
				'pll_import_invalid_target',
				__( "Error: The target language in the file doesn't exist on this site.", 'hd-pll' )
			);
		}

		// ── Process entries ──
		return self::processEntries( $importer, $targetLang );
	}

	/**
	 * Validate importer metadata (site reference, generator).
	 *
	 * @param ImporterInterface $importer The importer instance.
	 *
	 * @return bool|\WP_Error
	 */
	private static function validate( ImporterInterface $importer ): bool|\WP_Error {
		// Validate site reference.
		$siteRef = $importer->getSiteReference();
		if ( false !== $siteRef && $siteRef !== get_site_url() ) {
			return new \WP_Error(
				'pll_import_site_mismatch',
				sprintf(
					/* translators: %1$s: file site URL, %2$s: current site URL */
					__( 'Error: Site mismatch. File: %1$s, Current: %2$s.', 'hd-pll' ),
					$siteRef,
					get_site_url()
				)
			);
		}

		// Validate generator.
		$generator = $importer->getGeneratorName();
		if ( '' !== $generator && FileFormatFactory::APP_NAME !== $generator ) {
			return new \WP_Error(
				'pll_import_wrong_generator',
				sprintf(
					/* translators: %s: generator name */
					__( 'Error: This file was not generated by %s.', 'hd-pll' ),
					FileFormatFactory::APP_NAME
				)
			);
		}

		return true;
	}

	/**
	 * Process translation entries from the importer.
	 *
	 * @param ImporterInterface $importer   The importer instance.
	 * @param \PLL_Language    $targetLang Target language object.
	 *
	 * @return array{imported: int, type: string, warnings: string[]}
	 */
	private static function processEntries( ImporterInterface $importer, \PLL_Language $targetLang ): array {
		$warnings    = [];
		$stringBatch = [];
		$imported    = 0;

		$entry = $importer->getNextEntry();

		while ( ! empty( $entry ) ) {
			$entryType = $entry['type'] ?? '';

			if ( 'strings-translations' === $entryType ) {
				$stringBatch[] = $entry['data'];

				if ( count( $stringBatch ) >= self::STRING_IMPORT_CHUNK_SIZE ) {
					$imported   += self::importStrings( $stringBatch, $targetLang );
					$stringBatch = [];
				}
			}

			$entry = $importer->getNextEntry();
		}

		// ── Strings ──
		if ( ! empty( $stringBatch ) ) {
			$imported += self::importStrings( $stringBatch, $targetLang );
		}

		return [
			'imported' => $imported,
			'type'     => 'strings',
			'warnings' => $warnings,
		];
	}

	/**
	 * Import string translations into PLL MO.
	 *
	 * Handles 3 data formats (batched):
	 * - PO object (from PoImporter)
	 * - XLIFF array of {source, target} (from XliffImporter)
	 * - CSV array of {string, translations} (from CsvImporter)
	 *
	 * @param array         $dataBatch  Array of translation data batches.
	 * @param \PLL_Language $targetLang Target language.
	 *
	 * @return int Number of strings imported.
	 */
	private static function importStrings( array $dataBatch, \PLL_Language $targetLang ): int {
		if ( ! class_exists( 'PLL_MO' ) || empty( $dataBatch ) ) {
			return 0;
		}

		$mo = new \PLL_MO();
		$mo->import_from_db( $targetLang );
		$count = 0;

		foreach ( $dataBatch as $data ) {
			// PO object (from PoImporter) — batch of Translation_Entry.
			if ( $data instanceof \Translations ) {
				foreach ( $data->entries as $entry ) {
					/** @var \Translation_Entry $entry */
					$translation = $entry->translations[0] ?? '';

					if ( '' !== $translation && '' !== $entry->singular ) {
						$mo->add_entry( $mo->make_entry( $entry->singular, wp_kses_post( $translation ) ) );
						++$count;
					}
				}

				continue;
			}

			if ( ! is_array( $data ) ) {
				continue;
			}

			// CSV entry: {string, translations: {locale => value}}.
			if ( isset( $data['string'], $data['translations'] ) ) {
				$translation = $data['translations'][ $targetLang->locale ] ?? '';

				if ( '' !== $translation && '' !== $data['string'] ) {
					$mo->add_entry( $mo->make_entry( $data['string'], wp_kses_post( $translation ) ) );
					++$count;
				}

				continue;
			}

			// XLIFF map: field_id => {source, target}.
			foreach ( $data as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$source = $field['source'] ?? '';
				$target = $field['target'] ?? '';

				if ( '' !== $source && '' !== $target ) {
					$mo->add_entry( $mo->make_entry( $source, wp_kses_post( $target ) ) );
					++$count;
				}
			}
		}

		if ( $count > 0 ) {
			$mo->export_to_db( $targetLang );
		}

		return $count;
	}
}
