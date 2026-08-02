<?php
/**
 * XLIFF 2.1 Exporter — exports translations as industry-standard XLIFF.
 *
 * Produces XLIFF 2.1 (OASIS standard) compatible with CAT tools
 * like SDL Trados, memoQ, Memsource, etc.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport\Format;

use HDPLL\ImportExport\ExporterInterface;
use HDPLL\ImportExport\FileFormatFactory;

defined( 'ABSPATH' ) || exit;

final class XliffExporter implements ExporterInterface {

	private const XLIFF_NS      = 'urn:oasis:names:tc:xliff:document:2.1';
	private const XLIFF_VERSION = '2.1';

	private string $sourceLang;
	private string $targetLang;

	/** @var array<int, array{ref: array, source: string, target: string}> */
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
			'ref'    => $ref,
			'source' => $source,
			'target' => $target,
		];
	}

	public function getContent(): string {
		$dom               = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;

		$xliff = $dom->createElementNS( self::XLIFF_NS, 'xliff' );
		$xliff->setAttribute( 'version', self::XLIFF_VERSION );
		$xliff->setAttribute( 'srcLang', $this->sourceLang );
		$xliff->setAttribute( 'trgLang', $this->targetLang );
		$dom->appendChild( $xliff );

		$file = $dom->createElement( 'file' );
		$file->setAttribute( 'id', 'strings' );
		$file->setAttribute( 'original', get_site_url() );
		$xliff->appendChild( $file );

		// Generator note.
		$notes = $dom->createElement( 'notes' );
		$note  = $dom->createElement( 'note', FileFormatFactory::APP_NAME );
		$note->setAttribute( 'category', 'app' );
		$notes->appendChild( $note );
		$file->appendChild( $notes );

		// Translation units.
		foreach ( $this->entries as $index => $entry ) {
			$unitId = $entry['ref']['name'] ?? 'unit_' . ( $index + 1 );

			$unit = $dom->createElement( 'unit' );
			$unit->setAttribute( 'id', $unitId );

			// Add context note if present.
			if ( ! empty( $entry['context'] ) ) {
				$unitNotes = $dom->createElement( 'notes' );
				$ctxNote   = $dom->createElement( 'note', $entry['context'] );
				$ctxNote->setAttribute( 'category', 'context' );
				$unitNotes->appendChild( $ctxNote );
				$unit->appendChild( $unitNotes );
			}

			$segment = $dom->createElement( 'segment' );

			$sourceEl = $dom->createElement( 'source' );
			$sourceEl->appendChild( $dom->createCDATASection( $entry['source'] ) );
			$segment->appendChild( $sourceEl );

			$targetEl = $dom->createElement( 'target' );
			if ( '' !== $entry['target'] ) {
				$targetEl->appendChild( $dom->createCDATASection( $entry['target'] ) );
			}
			$segment->appendChild( $targetEl );

			$unit->appendChild( $segment );
			$file->appendChild( $unit );
		}

		return $dom->saveXML() ?: '';
	}

	public function getFilename(): string {
		return sprintf(
			'pll_strings_%s_%s_%s.xliff',
			$this->sourceLang,
			$this->targetLang,
			gmdate( 'Y-m-d' )
		);
	}
}
