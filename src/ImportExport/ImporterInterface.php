<?php
/**
 * Contract for translation file readers.
 *
 * Strategy pattern: each format (CSV, PO, XLIFF) implements this to parse
 * an uploaded file and yield translation entries one by one.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport;

defined( 'ABSPATH' ) || exit;

interface ImporterInterface {

	/**
	 * Parse a file from disk.
	 *
	 * @param string $filepath Absolute path to the uploaded file.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function importFromFile( string $filepath ): bool|\WP_Error;

	/**
	 * Get the target language identifier from file metadata.
	 *
	 * @return string|false Language locale/slug, or false if missing.
	 */
	public function getTargetLanguage(): string|false;

	/**
	 * Get the site reference URL from file metadata.
	 *
	 * @return string|false Site URL, or false if missing.
	 */
	public function getSiteReference(): string|false;

	/**
	 * Get the generator application name from file metadata.
	 */
	public function getGeneratorName(): string;

	/**
	 * Yield the next translation entry from the parsed file.
	 *
	 * @return array{type: string, id: int|null, data: mixed, fields?: array}
	 *               Empty array when no more entries.
	 */
	public function getNextEntry(): array;
}
