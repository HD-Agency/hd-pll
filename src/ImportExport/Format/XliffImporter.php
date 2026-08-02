<?php
/**
 * XLIFF 2.1 Importer — parses XLIFF files and yields translation entries.
 *
 * Supports XLIFF 2.1 format (OASIS standard). Reads translation units
 * from the file and yields them one by one for processing.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport\Format;

use HDPLL\ImportExport\ImporterInterface;

defined( 'ABSPATH' ) || exit;

final class XliffImporter implements ImporterInterface {

	/** @var array<int, array{type: string, id: int|null, fields: array<string, string>}> */
	private array $entries = [];

	private int $cursor        = 0;
	private string $targetLang = '';
	private string $siteRef    = '';
	private string $generator  = '';

	public function importFromFile( string $filepath ): bool|\WP_Error {
		if ( ! extension_loaded( 'libxml' ) ) {
			return new \WP_Error( 'pll_xliff_no_libxml', __( 'Error: PHP libxml extension is required.', 'hd-pll' ) );
		}

		$prev = libxml_use_internal_errors( true );

		$content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			libxml_use_internal_errors( $prev );

			return new \WP_Error( 'pll_xliff_read_error', __( 'Error: Could not read XLIFF file.', 'hd-pll' ) );
		}

		$dom = new \DOMDocument();
		if ( ! $dom->loadXML( $content, LIBXML_NONET ) ) {
			libxml_use_internal_errors( $prev );

			return new \WP_Error( 'pll_xliff_parse_error', __( 'Error: Invalid XML in XLIFF file.', 'hd-pll' ) );
		}

		libxml_use_internal_errors( $prev );

		return $this->parseDocument( $dom );
	}

	public function getTargetLanguage(): string|false {
		return '' !== $this->targetLang ? $this->targetLang : false;
	}

	public function getSiteReference(): string|false {
		return '' !== $this->siteRef ? $this->siteRef : false;
	}

	public function getGeneratorName(): string {
		return $this->generator;
	}

	public function getNextEntry(): array {
		if ( $this->cursor >= count( $this->entries ) ) {
			return [];
		}

		$entry = $this->entries[ $this->cursor ];
		++$this->cursor;

		return $entry;
	}

	/**
	 * Parse XLIFF 2.1 document into translation entries.
	 *
	 * @param \DOMDocument $dom Parsed XML document.
	 *
	 * @return bool|\WP_Error
	 */
	private function parseDocument( \DOMDocument $dom ): bool|\WP_Error {
		$xliff = $dom->documentElement;

		if ( null === $xliff || 'xliff' !== $xliff->localName ) {
			return new \WP_Error( 'pll_xliff_invalid', __( 'Error: Not a valid XLIFF document.', 'hd-pll' ) );
		}

		$this->targetLang = $xliff->getAttribute( 'trgLang' ) ?: '';

		// Parse <file> elements.
		$files = $xliff->getElementsByTagName( 'file' );

		foreach ( $files as $file ) {
			/** @var \DOMElement $file */
			$this->siteRef = $file->getAttribute( 'original' ) ?: '';

			// Check generator from <notes>.
			$this->generator = $this->extractNoteValue( $file, 'app' );

			// Parse <unit> elements.
			$this->parseUnits( $file );
		}

		if ( empty( $this->entries ) ) {
			return new \WP_Error( 'pll_xliff_empty', __( 'Error: No translation units found in XLIFF file.', 'hd-pll' ) );
		}

		return true;
	}

	/**
	 * Parse <unit> elements from a <file> element.
	 *
	 * @param \DOMElement $file The <file> element.
	 */
	private function parseUnits( \DOMElement $file ): void {
		$units = $file->getElementsByTagName( 'unit' );

		// Collect string translations.
		$stringFields = [];

		foreach ( $units as $unit ) {
			/** @var \DOMElement $unit */
			$unitId = $unit->getAttribute( 'id' );

			$segment = $unit->getElementsByTagName( 'segment' )->item( 0 );
			if ( ! $segment instanceof \DOMElement ) {
				continue;
			}

			$source = $this->getElementText( $segment, 'source' );
			$target = $this->getElementText( $segment, 'target' );

			if ( '' === $source ) {
				continue;
			}

			// String translation.
			$stringFields[ $unitId ] = [
				'source' => $source,
				'target' => $target,
			];
		}

		// Yield string translations as a single batch.
		if ( ! empty( $stringFields ) ) {
			$this->entries[] = [
				'type' => 'strings-translations',
				'id'   => null,
				'data' => $stringFields,
			];
		}
	}

	/**
	 * Extract a <note> value by category from an element.
	 *
	 * @param \DOMElement $node     Parent element.
	 * @param string      $category Note category to find.
	 *
	 * @return string Note value, or empty string if not found.
	 */
	private function extractNoteValue( \DOMElement $node, string $category ): string {
		$notes = $node->getElementsByTagName( 'note' );

		foreach ( $notes as $note ) {
			/** @var \DOMElement $note */
			if ( $note->getAttribute( 'category' ) === $category ) {
				return trim( $note->textContent );
			}
		}

		return '';
	}

	/**
	 * Get text content from a named child element.
	 *
	 * @param \DOMElement $node    Parent element.
	 * @param string      $tagName Child element tag name.
	 *
	 * @return string Text content or empty string.
	 */
	private function getElementText( \DOMElement $node, string $tagName ): string {
		$el = $node->getElementsByTagName( $tagName )->item( 0 );

		return $el instanceof \DOMElement ? trim( $el->textContent ) : '';
	}
}
