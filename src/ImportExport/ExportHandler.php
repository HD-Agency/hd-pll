<?php
/**
 * Export Handler — orchestrates translation export.
 *
 * Supports two export flows:
 * - handle(): string translations (CSV/PO/XLIFF) from Settings page.
 * - handlePosts(): post+term content (XLIFF only) from bulk action.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport;

defined( 'ABSPATH' ) || exit;

final class ExportHandler {

	/**
	 * Process an export request.
	 *
	 * @param string   $formatKey       Format key (csv, po, xliff).
	 * @param string[] $targetLangSlugs Target language slugs.
	 * @param string   $group           Optional string group filter ('' = all).
	 *
	 * @return never|\WP_Error Sends download and exits, or returns error.
	 */
	public static function handle( string $formatKey, array $targetLangSlugs, string $group = '' ): \WP_Error {
		if ( ! function_exists( 'PLL' ) || ! class_exists( 'PLL_Admin_Strings' ) ) {
			return new \WP_Error( 'pll_export_unavailable', __( 'Polylang is not available.', 'hd-pll' ) );
		}

		$model       = \PLL()->model;
		$defaultLang = $model->get_default_language();

		if ( empty( $defaultLang ) ) {
			return new \WP_Error( 'pll_export_no_default', __( 'Error: Default language not defined.', 'hd-pll' ) );
		}

		// Get registered strings.
		$sources = \PLL_Admin_Strings::get_strings();
		if ( '' !== $group ) {
			$sources = array_filter(
				$sources,
				static fn( $s ) => $group === $s['context']
			);
		}

		if ( empty( $sources ) ) {
			return new \WP_Error( 'pll_export_no_strings', __( 'No strings found to export.', 'hd-pll' ) );
		}

		$factory   = new FileFormatFactory();
		$downloads = [];

		foreach ( $targetLangSlugs as $slug ) {
			$targetLang = $model->get_language( $slug );

			if ( ! $targetLang || $targetLang->slug === $defaultLang->slug ) {
				continue;
			}

			$exporter = $factory->createExporter(
				$formatKey,
				$defaultLang->locale,
				$targetLang->locale
			);

			if ( \is_wp_error( $exporter ) ) {
				return $exporter;
			}

			// Load existing translations for this target language.
			$mo = new \PLL_MO();
			$mo->import_from_db( $targetLang );

			foreach ( $sources as $source ) {
				$original    = $source['string'];
				$translation = $mo->translate( $original );
				$translation = ( $translation === $original ) ? '' : $translation;

				$exporter->addTranslationEntry(
					[
						'object_type' => 'string',
						'field_type'  => 'string_translation',
						'field_id'    => $source['context'] ?? '',
					],
					$original,
					$translation
				);
			}

			$downloads[] = [
				'filename' => $exporter->getFilename(),
				'content'  => $exporter->getContent(),
			];
		}

		if ( empty( $downloads ) ) {
			return new \WP_Error( 'pll_export_no_targets', __( 'No valid target languages selected.', 'hd-pll' ) );
		}

		// Send download.
		self::sendDownload( $downloads );

		// Never reaches here — sendDownload calls exit.
		return new \WP_Error();
	}



	/**
	 * Send file(s) as download response.
	 *
	 * @param array<int, array{filename: string, content: string}> $files Files to download.
	 *
	 * @return never
	 */
	private static function sendDownload( array $files ): never {
		$files = self::sanitizeDownloadFiles( $files );

		if ( 1 === count( $files ) ) {
			$file = $files[0];
			self::sendContentDispositionHeader( $file['filename'] );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: ' . strlen( $file['content'] ) );

			if ( ob_get_length() > 0 ) {
				ob_clean();
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file output
			echo $file['content'];
			exit;
		}

		// Multiple files → zip.
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive is required to download multiple files.', 'hd-pll' ) );
		}

		$uploadDir = wp_upload_dir()['path'];
		$zipName   = self::sanitizeDownloadFilename( 'pll_export_' . time() . '.zip' );
		$zipPath   = $uploadDir . '/' . $zipName;

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zipPath, \ZipArchive::CREATE ) ) {
			wp_die( esc_html__( 'Error creating zip file.', 'hd-pll' ) );
		}

		foreach ( $files as $file ) {
			$zip->addFromString( $file['filename'], $file['content'] );
		}

		$zip->close();

		$content = (string) file_get_contents( $zipPath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $zipPath );

		self::sendContentDispositionHeader( $zipName );
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . strlen( $content ) );

		if ( ob_get_length() > 0 ) {
			ob_clean();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file output
		echo $content;
		exit;
	}

	/**
	 * Sanitize all download filenames and keep ZIP entry names unique.
	 *
	 * @param array<int, array{filename: string, content: string}> $files Files to download.
	 *
	 * @return array<int, array{filename: string, content: string}>
	 */
	private static function sanitizeDownloadFiles( array $files ): array {
		$seen = [];

		foreach ( $files as $index => $file ) {
			$filename = self::sanitizeDownloadFilename( $file['filename'] );
			$filename = self::uniqueDownloadFilename( $filename, $seen );

			$files[ $index ]['filename'] = $filename;
			$seen[ $filename ]           = true;
		}

		return $files;
	}

	/**
	 * Strip header/path separators while preserving a UTF-8 display filename.
	 */
	private static function sanitizeDownloadFilename( string $filename ): string {
		$filename = str_replace( [ "\0", "\r", "\n" ], '', $filename );
		$filename = str_replace( '\\', '/', $filename );
		$filename = basename( $filename );
		$filename = preg_replace( '/[\x00-\x1F\x7F]+/', '', $filename ) ?? '';
		$filename = trim( $filename );

		return in_array( $filename, [ '', '.', '..' ], true ) ? 'download' : $filename;
	}

	/**
	 * Emit a safe Content-Disposition header with ASCII and RFC 5987 filenames.
	 */
	private static function sendContentDispositionHeader( string $filename ): void {
		$filename = self::sanitizeDownloadFilename( $filename );
		$fallback = self::asciiFilenameFallback( $filename );

		header( 'Content-Disposition: attachment; filename="' . $fallback . "\"; filename*=UTF-8''" . rawurlencode( $filename ) );
	}

	/**
	 * Build an ASCII fallback for older clients.
	 */
	private static function asciiFilenameFallback( string $filename ): string {
		$fallback = function_exists( 'remove_accents' ) ? remove_accents( $filename ) : $filename;
		$fallback = preg_replace( '/[^\x20-\x7E]+/', '', $fallback ) ?? '';
		$fallback = str_replace( [ '"', '\\', '/', ';' ], '_', $fallback );
		$fallback = trim( $fallback );

		return in_array( $fallback, [ '', '.', '..' ], true ) ? 'download' : $fallback;
	}

	/**
	 * Ensure sanitized ZIP entry names do not overwrite each other.
	 *
	 * @param array<string, bool> $seen Existing names.
	 */
	private static function uniqueDownloadFilename( string $filename, array $seen ): string {
		if ( empty( $seen[ $filename ] ) ) {
			return $filename;
		}

		$info      = pathinfo( $filename );
		$extension = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . $info['extension'] : '';
		$stem      = $info['filename'] ?? basename( $filename, $extension );
		$stem      = '' !== $stem ? $stem : 'download';

		$index = 2;
		do {
			$candidate = $stem . '-' . $index . $extension;
			++$index;
		} while ( ! empty( $seen[ $candidate ] ) );

		return $candidate;
	}
}
