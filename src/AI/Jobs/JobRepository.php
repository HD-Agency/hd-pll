<?php
/**
 * Translation job repository.
 *
 * @package HDPLL\AI\Jobs
 */

declare(strict_types=1);

namespace HDPLL\AI\Jobs;

defined( 'ABSPATH' ) || exit;

final class JobRepository {

	public const POST_TYPE              = 'hd_pll_ai_job';
	private const STATUS_META           = '_hd_pll_ai_status';
	private const MAX_RESULT_LIST_ITEMS = 100;
	private const MAX_RESULT_DEPTH      = 8;
	private const MAX_RESULT_STRING_LEN = 131072;

	public function registerPostType(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'        => __( 'PLL AI Jobs', 'hd-pll' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => [ 'title', 'editor' ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function create( array $payload ): int|\WP_Error {
		$status = JobStatus::fromRaw( $payload['status'] ?? JobStatus::default()->value );
		if ( null === $status ) {
			return self::invalidStatusError( $payload['status'] ?? '' );
		}

		$payload['status'] = $status->value;
		$payload           = $this->normalizePayload( $payload );

		$id = wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => sanitize_text_field( (string) ( $payload['type'] ?? 'translation' ) ) . ' #' . absint( $payload['source_id'] ?? 0 ),
				'post_content' => wp_json_encode( $payload ) ?: '{}',
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( (int) $id, self::STATUS_META, $status->value );

		return (int) $id;
	}

	public function get( int $id ): ?TranslationJob {
		$post = get_post( $id );

		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? TranslationJob::fromPost( $post ) : null;
	}

	/**
	 * @return TranslationJob[]
	 */
	public function list( int $limit = 20, string $status = '' ): array {
		$statusValue = '';
		if ( '' !== $status ) {
			$statusEnum = JobStatus::fromRaw( $status );
			if ( null === $statusEnum ) {
				return [];
			}
			$statusValue = $statusEnum->value;
		}

		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => min( 100, max( 1, $limit ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( '' !== $statusValue ) {
			$args['meta_query'] = [
				[
					'key'   => self::STATUS_META,
					'value' => $statusValue,
				],
			];
		}

		$query = new \WP_Query( $args );

		$jobs = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$job = TranslationJob::fromPost( $post );
				if ( '' === $statusValue || $job->status === $statusValue ) {
					$jobs[] = $job;
				}
			}
		}

		if ( '' !== $statusValue && count( $jobs ) < min( 100, max( 1, $limit ) ) ) {
			$jobs = $this->appendLegacyStatusMatches( $jobs, $statusValue, $limit );
		}

		return $jobs;
	}

	/**
	 * @param array<string, mixed> $changes Changes to merge.
	 */
	public function update( int $id, array $changes ): bool|\WP_Error {
		$job = $this->get( $id );
		if ( ! $job ) {
			return new \WP_Error( 'hd_pll_ai_job_not_found', __( 'Translation job not found.', 'hd-pll' ) );
		}

		if ( array_key_exists( 'status', $changes ) ) {
			$status = JobStatus::fromRaw( $changes['status'] );
			if ( null === $status ) {
				return self::invalidStatusError( $changes['status'] );
			}
			$changes['status'] = $status->value;
		}

		$payload = $this->normalizePayload( [ ...$job->payload(), ...$changes ] );
		$result  = wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_json_encode( $payload ) ?: '{}',
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $id, self::STATUS_META, (string) ( $payload['status'] ?? $job->status ) );

		return true;
	}

	/**
	 * @param array<string, mixed> $payload Job payload.
	 *
	 * @return array<string, mixed>
	 */
	private function normalizePayload( array $payload ): array {
		if ( isset( $payload['results'] ) && is_array( $payload['results'] ) ) {
			$payload['results'] = $this->boundResultPayload( $payload['results'] );
		}

		return $payload;
	}

	private static function invalidStatusError( mixed $status ): \WP_Error {
		return new \WP_Error(
			'hd_pll_ai_invalid_job_status',
			sprintf(
				/* translators: %s: invalid job status. */
				__( 'Invalid translation job status: %s', 'hd-pll' ),
				esc_html( (string) $status )
			)
		);
	}

	private function boundResultPayload( mixed $value, int $depth = 0 ): mixed {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) <= self::MAX_RESULT_STRING_LEN ) {
				return $value;
			}

			return substr( $value, 0, self::MAX_RESULT_STRING_LEN ) . "\n...[truncated]";
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( $depth >= self::MAX_RESULT_DEPTH ) {
			return [ '_truncated' => true ];
		}

		$isList = array_is_list( $value );
		$output = [];
		$index  = 0;

		foreach ( $value as $key => $item ) {
			if ( $isList && $index >= self::MAX_RESULT_LIST_ITEMS ) {
				$output[] = [
					'_truncated_count' => count( $value ) - self::MAX_RESULT_LIST_ITEMS,
				];
				break;
			}

			$output[ $key ] = $this->boundResultPayload( $item, $depth + 1 );
			++$index;
		}

		return $output;
	}

	/**
	 * Include jobs created before status meta existed.
	 *
	 * @param TranslationJob[] $jobs Current jobs.
	 *
	 * @return TranslationJob[]
	 */
	private function appendLegacyStatusMatches( array $jobs, string $status, int $limit ): array {
		$limit = min( 100, max( 1, $limit ) );
		$seen  = array_fill_keys( array_map( static fn( TranslationJob $job ): int => $job->id, $jobs ), true );
		$query = new \WP_Query(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || isset( $seen[ $post->ID ] ) ) {
				continue;
			}

			$job = TranslationJob::fromPost( $post );
			update_post_meta( $job->id, self::STATUS_META, $job->status );
			if ( $job->status !== $status ) {
				continue;
			}

			$jobs[] = $job;
			if ( count( $jobs ) >= $limit ) {
				break;
			}
		}

		return $jobs;
	}
}
