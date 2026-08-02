<?php
/**
 * Durable translation job DTO.
 *
 * @package HDPLL\AI\Jobs
 */

declare(strict_types=1);

namespace HDPLL\AI\Jobs;

defined( 'ABSPATH' ) || exit;

final class TranslationJob {

	/**
	 * @param array<string, mixed> $options Translation options.
	 * @param array<string, mixed> $usage   Provider usage.
	 * @param array<int, mixed>    $results Item results.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly int $source_id,
		public readonly string $source_lang,
		public readonly string $target_lang,
		public readonly string $status = 'pending',
		public readonly array $options = [],
		public readonly int $attempts = 0,
		public readonly string $last_error = '',
		public readonly int $started_at = 0,
		public readonly int $finished_at = 0,
		public readonly array $usage = [],
		public readonly array $results = []
	) {}

	public static function fromPost( \WP_Post $post ): self {
		$payload = json_decode( (string) $post->post_content, true );
		$payload = is_array( $payload ) ? $payload : [];

		return new self(
			$post->ID,
			sanitize_key( (string) ( $payload['type'] ?? 'post' ) ),
			absint( $payload['source_id'] ?? 0 ),
			sanitize_key( (string) ( $payload['source_lang'] ?? '' ) ),
			sanitize_key( (string) ( $payload['target_lang'] ?? '' ) ),
			( JobStatus::fromRaw( $payload['status'] ?? $post->post_status ) ?? JobStatus::default() )->value,
			is_array( $payload['options'] ?? null ) ? $payload['options'] : [],
			absint( $payload['attempts'] ?? 0 ),
			(string) ( $payload['last_error'] ?? '' ),
			absint( $payload['started_at'] ?? 0 ),
			absint( $payload['finished_at'] ?? 0 ),
			is_array( $payload['usage'] ?? null ) ? $payload['usage'] : [],
			is_array( $payload['results'] ?? null ) ? $payload['results'] : []
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'          => $this->id,
			'type'        => $this->type,
			'source_id'   => $this->source_id,
			'source_lang' => $this->source_lang,
			'target_lang' => $this->target_lang,
			'status'      => $this->status,
			'options'     => $this->options,
			'attempts'    => $this->attempts,
			'last_error'  => $this->last_error,
			'started_at'  => $this->started_at,
			'finished_at' => $this->finished_at,
			'usage'       => $this->usage,
			'results'     => $this->results,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		$data = $this->toArray();
		unset( $data['id'] );

		return $data;
	}
}
