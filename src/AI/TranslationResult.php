<?php
/**
 * Translation result DTO.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class TranslationResult {

	/**
	 * @param string[]             $errors Validation or provider errors.
	 * @param array<string, mixed> $usage  Provider usage data.
	 */
	public function __construct(
		public readonly string $unit_id,
		public readonly string $source,
		public readonly string $translated,
		public readonly string $status = 'ok',
		public readonly array $errors = [],
		public readonly array $usage = [],
		public readonly array $path = []
	) {}

	/**
	 * @param array<string, mixed> $data Raw result data.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			sanitize_key( (string) ( $data['unit_id'] ?? $data['id'] ?? '' ) ),
			(string) ( $data['source'] ?? '' ),
			(string) ( $data['translated'] ?? $data['text'] ?? '' ),
			sanitize_key( (string) ( $data['status'] ?? 'ok' ) ),
			array_values( array_map( 'strval', (array) ( $data['errors'] ?? [] ) ) ),
			is_array( $data['usage'] ?? null ) ? $data['usage'] : [],
			array_values( array_map( 'strval', (array) ( $data['path'] ?? [] ) ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'unit_id'    => $this->unit_id,
			'source'     => $this->source,
			'translated' => $this->translated,
			'status'     => $this->status,
			'errors'     => $this->errors,
			'usage'      => $this->usage,
			'path'       => $this->path,
		];
	}
}
