<?php
/**
 * Contract for translation file writers.
 *
 * Strategy pattern: each format (CSV, PO, XLIFF) implements this to produce
 * a downloadable file containing source + target translation pairs.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport;

defined( 'ABSPATH' ) || exit;

interface ExporterInterface {

	/**
	 * Add a translation entry (source string + optional target).
	 *
	 * @param array  $ref    Reference data (object_type, field_type, object_id, field_id).
	 * @param string $source Source text in original language.
	 * @param string $target Translated text (empty if not yet translated).
	 */
	public function addTranslationEntry( array $ref, string $source, string $target = '' ): void;

	/**
	 * Get the file content as string.
	 */
	public function getContent(): string;

	/**
	 * Get the download filename.
	 */
	public function getFilename(): string;
}
