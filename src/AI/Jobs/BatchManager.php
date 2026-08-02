<?php
/**
 * Translation job runner.
 *
 * @package HDPLL\AI\Jobs
 */

declare(strict_types=1);

namespace HDPLL\AI\Jobs;

use HDPLL\AI\TranslationCoordinator;

defined( 'ABSPATH' ) || exit;

final class BatchManager {

	private const CRON_HOOK         = 'hd_pll_ai_process_jobs';
	private const LOCK_KEY          = 'hd_pll_ai_job_lock';
	private const LOCK_TTL          = MINUTE_IN_SECONDS * 5;
	private const RUNNING_STALE_TTL = HOUR_IN_SECONDS;

	/**
	 * @var callable|null
	 */
	private mixed $runner;

	public function __construct( private readonly JobRepository $repository = new JobRepository(), ?callable $runner = null ) {
		$this->runner = $runner;
	}

	public function register(): void {
		add_filter(
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Translation jobs need near-real-time background processing.
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['minute'] = [
					'interval' => MINUTE_IN_SECONDS,
					'display'  => __( 'Every minute', 'hd-pll' ),
				];

				return $schedules;
			}
		);
		add_action( self::CRON_HOOK, [ $this, 'process' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'minute', self::CRON_HOOK );
		}
	}

	public function process( int $limit = 1 ): void {
		$token = $this->acquireLock();
		if ( null === $token ) {
			return;
		}

		try {
			$this->failStaleRunningJobs();

			foreach ( $this->repository->list( max( 1, $limit ), 'pending' ) as $job ) {
				$this->runJob( $job );
			}
		} finally {
			$this->releaseLock( $token );
		}
	}

	public function runJob( TranslationJob $job ): bool|\WP_Error {
		try {
			$running = $this->repository->update(
				$job->id,
				[
					'status'      => 'running',
					'attempts'    => $job->attempts + 1,
					'last_error'  => '',
					'started_at'  => time(),
					'finished_at' => 0,
				]
			);

			if ( is_wp_error( $running ) ) {
				return $running;
			}

			$options           = $job->options;
			$options['commit'] = $options['commit'] ?? true;

			$result = $this->runTranslation( $job->type, $job->source_id, $job->target_lang, $options );

			if ( is_wp_error( $result ) ) {
				$this->markJobFailed( $job->id, $result->get_error_message() );
				return $result;
			}

			return $this->repository->update(
				$job->id,
				[
					'status'      => empty( $options['commit'] ) ? 'preview' : 'completed',
					'finished_at' => time(),
					'results'     => $result,
				]
			);
		} catch ( \Throwable $e ) {
			$message = $e->getMessage() ?: __( 'Unexpected translation job failure.', 'hd-pll' );
			$this->markJobFailed( $job->id, $message );

			return new \WP_Error( 'hd_pll_ai_job_exception', $message );
		}
	}

	private function acquireLock(): ?string {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'hd_pll_ai_job_lock_', true );
		$lock  = [
			'token'      => $token,
			'created_at' => time(),
		];

		if ( add_option( self::LOCK_KEY, $lock, '', false ) ) {
			return $token;
		}

		$current = get_option( self::LOCK_KEY, [] );
		$created = is_array( $current ) ? absint( $current['created_at'] ?? 0 ) : absint( $current );
		if ( $created > 0 && time() - $created < self::LOCK_TTL ) {
			return null;
		}

		delete_option( self::LOCK_KEY );

		return add_option( self::LOCK_KEY, $lock, '', false ) ? $token : null;
	}

	private function releaseLock( string $token ): void {
		$current = get_option( self::LOCK_KEY, [] );
		if ( ! is_array( $current ) || ( $current['token'] ?? '' ) !== $token ) {
			return;
		}

		delete_option( self::LOCK_KEY );
	}

	/**
	 * @param array<string, mixed> $options Translation options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function runTranslation( string $type, int $sourceId, string $targetLang, array $options ): array|\WP_Error {
		if ( is_callable( $this->runner ) ) {
			$runner = $this->runner;

			return $runner( $type, $sourceId, $targetLang, $options );
		}

		return ( new TranslationCoordinator() )->run( $type, $sourceId, $targetLang, $options );
	}

	private function markJobFailed( int $jobId, string $message ): void {
		try {
			$this->repository->update(
				$jobId,
				[
					'status'      => 'failed',
					'last_error'  => $message,
					'finished_at' => time(),
				]
			);
		} catch ( \Throwable $e ) {
			// Do not rethrow from failure persistence; runJob already returns the original error.
			unset( $e );
		}
	}

	private function failStaleRunningJobs(): void {
		$ttl    = max( MINUTE_IN_SECONDS, absint( apply_filters( 'hd_pll_ai_running_job_stale_ttl', self::RUNNING_STALE_TTL ) ) );
		$cutoff = time() - $ttl;

		foreach ( $this->repository->list( 100, 'running' ) as $job ) {
			if ( $job->started_at <= 0 || $job->started_at > $cutoff ) {
				continue;
			}

			$this->markJobFailed(
				$job->id,
				sprintf(
					/* translators: %d: stale running timeout in seconds. */
					__( 'Translation job exceeded the running timeout (%d seconds).', 'hd-pll' ),
					$ttl
				)
			);
		}
	}
}
