<?php
/**
 * PLL AI auto-translation REST API.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\Jobs\BatchManager;
use HDPLL\AI\Jobs\JobRepository;
use HDPLL\AI\Translator\PostTranslator;

defined( 'ABSPATH' ) || exit;

final class AutoTranslateAPI extends \WP_REST_Controller {

	private const BASE = '/pll/ai';

	public function register_routes(): void {
		$routes = [
			[ '/translate/post', 'translatePost' ],
			[ '/translate/term', 'translateTerm' ],
			[ '/translate/string-batch', 'translateStringBatch' ],
			[ '/jobs', 'createJob' ],
			[ '/jobs/(?P<id>\d+)/run', 'runJob' ],
			[ '/jobs/(?P<id>\d+)/cancel', 'cancelJob' ],
		];

		$ns = defined( 'REST_NAMESPACE' ) ? REST_NAMESPACE : 'hd/v1';

		foreach ( $routes as [ $route, $method ] ) {
			register_rest_route(
				$ns,
				self::BASE . $route,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $method ],
					'permission_callback' => [ $this, 'permission' ],
				]
			);
		}

		register_rest_route(
			$ns,
			self::BASE . '/jobs/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'getJob' ],
				'permission_callback' => [ $this, 'permission' ],
			]
		);

		register_rest_route(
			$ns,
			self::BASE . '/jobs',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'listJobs' ],
				'permission_callback' => [ $this, 'permission' ],
			]
		);
	}

	public function permission(): bool|\WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'hd_pll_ai_forbidden', __( 'Insufficient permissions.', 'hd-pll' ), [ 'status' => 403 ] );
	}

	public function translatePost( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->browserNonceCheck( $request );
		if ( $nonce ) {
			return $nonce;
		}

		$id   = absint( $request->get_param( 'source_id' ) );
		$lang = $this->targetLang( $request );
		if ( is_wp_error( $lang ) ) {
			return $this->resultResponse( $lang );
		}

		$result = ( new TranslationCoordinator() )->run( 'post', $id, $lang, $this->options( $request ) );

		return $this->resultResponse( $result );
	}

	public function translateTerm( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->browserNonceCheck( $request );
		if ( $nonce ) {
			return $nonce;
		}

		$lang = $this->targetLang( $request );
		if ( is_wp_error( $lang ) ) {
			return $this->resultResponse( $lang );
		}

		return $this->resultResponse( ( new TranslationCoordinator() )->run( 'term', absint( $request->get_param( 'source_id' ) ), $lang, $this->options( $request ) ) );
	}

	public function translateStringBatch( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->browserNonceCheck( $request );
		if ( $nonce ) {
			return $nonce;
		}

		$lang = $this->targetLang( $request );
		if ( is_wp_error( $lang ) ) {
			return $this->resultResponse( $lang );
		}

		return $this->resultResponse( ( new TranslationCoordinator() )->run( 'string', 0, $lang, $this->options( $request ) ) );
	}

	public function createJob( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->browserNonceCheck( $request );
		if ( $nonce ) {
			return $nonce;
		}

		$type     = sanitize_key( (string) $request->get_param( 'type' ) ) ?: 'post';
		$sourceId = absint( $request->get_param( 'source_id' ) );

		// For post-like jobs, verify the source post type is registered with Polylang.
		if ( ! in_array( $type, [ 'term', 'string' ], true ) ) {
			$source = get_post( $sourceId );
			if ( ! $source instanceof \WP_Post ) {
				return $this->resultResponse( new \WP_Error( 'hd_pll_ai_post_not_found', __( 'Source post not found.', 'hd-pll' ), [ 'status' => 404 ] ) );
			}

			if ( function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( $source->post_type ) ) {
				return $this->resultResponse( new \WP_Error( 'hd_pll_ai_not_translatable', sprintf( __( 'Post type "%s" is not registered for translation in Polylang.', 'hd-pll' ), esc_html( $source->post_type ) ), [ 'status' => 403 ] ) );
			}
		}

		$sourceLang = sanitize_key( (string) ( $request->get_param( 'source_lang' ) ?: '' ) );
		$targetLang = $this->targetLang( $request );
		if ( is_wp_error( $targetLang ) ) {
			return $this->resultResponse( $targetLang );
		}

		$id = ( new JobRepository() )->create(
			[
				'type'        => $type,
				'source_id'   => $sourceId,
				'source_lang' => $sourceLang,
				'target_lang' => $targetLang,
				'status'      => 'pending',
				'options'     => $this->options( $request ),
				'attempts'    => 0,
				'last_error'  => '',
				'usage'       => [],
				'results'     => [],
			]
		);

		return $this->resultResponse( is_wp_error( $id ) ? $id : [ 'job' => [ 'id' => $id ] ] );
	}

	public function runJob( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->browserNonceCheck( $request );
		if ( $nonce ) {
			return $nonce;
		}

		$repository = new JobRepository();
		$job        = $repository->get( absint( $request['id'] ) );
		if ( ! $job ) {
			return $this->resultResponse( new \WP_Error( 'hd_pll_ai_job_not_found', __( 'Translation job not found.', 'hd-pll' ), [ 'status' => 404 ] ) );
		}

		$result = ( new BatchManager( $repository ) )->runJob( $job );

		if ( is_wp_error( $result ) ) {
			return $this->resultResponse( $result );
		}

		$payload = $this->jobResponsePayload( $repository->get( $job->id ) );

		return $this->resultResponse(
			[
				'job'       => $payload['job'],
				'target_id' => $payload['target_id'],
				'links'     => $payload['links'],
			]
		);
	}

	public function cancelJob( \WP_REST_Request $request ): \WP_REST_Response {
		$nonce = $this->browserNonceCheck( $request );
		if ( $nonce ) {
			return $nonce;
		}

		$result = ( new JobRepository() )->update( absint( $request['id'] ), [ 'status' => 'cancelled' ] );

		return $this->resultResponse(
			is_wp_error( $result ) ? $result : [
				'job' => [
					'id'     => absint( $request['id'] ),
					'status' => 'cancelled',
				],
			]
		);
	}

	public function getJob( \WP_REST_Request $request ): \WP_REST_Response {
		$job = ( new JobRepository() )->get( absint( $request['id'] ) );

		if ( ! $job ) {
			return $this->resultResponse( new \WP_Error( 'hd_pll_ai_job_not_found', __( 'Translation job not found.', 'hd-pll' ), [ 'status' => 404 ] ) );
		}

		$payload = $this->jobResponsePayload( $job );

		return $this->resultResponse(
			[
				'job'       => $payload['job'],
				'target_id' => $payload['target_id'],
				'links'     => $payload['links'],
			]
		);
	}

	public function listJobs( \WP_REST_Request $request ): \WP_REST_Response {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$jobs   = array_map( static fn( $job ): array => $job->toArray(), ( new JobRepository() )->list( 50, $status ) );

		return $this->sendResponse( [], 200, [ 'items' => $jobs ] );
	}

	private function browserNonceCheck( \WP_REST_Request $request ): ?\WP_REST_Response {
		return $this->verifyNonce( $request );
	}

	/**
	 * @return array{job:array<string,mixed>|null,target_id:int,links:array{edit:string,view:string}}
	 */
	private function jobResponsePayload( ?Jobs\TranslationJob $job ): array {
		$targetId = $this->jobTargetId( $job );
		$links    = PostTranslator::postLinks( $targetId );

		return [
			'job'       => $job?->toArray(),
			'target_id' => $targetId,
			'links'     => $links,
		];
	}

	private function jobTargetId( ?Jobs\TranslationJob $job ): int {
		if ( ! $job ) {
			return 0;
		}

		$targetId = absint( $job->results['preview']['target_id'] ?? $job->results['item']['id'] ?? 0 );
		if ( $targetId > 0 ) {
			return $targetId;
		}

		if ( 'term' !== $job->type && 'string' !== $job->type && function_exists( 'pll_get_post' ) ) {
			return absint( pll_get_post( $job->source_id, $job->target_lang ) );
		}

		return 0;
	}

	private function targetLang( \WP_REST_Request $request ): string|\WP_Error {
		$lang = sanitize_key( (string) $request->get_param( 'target_lang' ) );
		$list = function_exists( 'pll_languages_list' ) ? pll_languages_list( [ 'fields' => 'slug' ] ) : [];

		if ( ! in_array( $lang, (array) $list, true ) ) {
			return new \WP_Error( 'hd_pll_ai_invalid_target_language', __( 'Invalid target language.', 'hd-pll' ), [ 'status' => 400 ] );
		}

		return $lang;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function options( \WP_REST_Request $request ): array {
		$options = $request->get_param( 'options' );
		$options = is_array( $options ) ? $options : [];

		foreach ( [ 'commit', 'overwrite', 'preserve_date', 'preserve_author', 'translate_slug', 'translate_meta', 'rewrite_links', 'missing_only' ] as $flag ) {
			$options[ $flag ] = ! empty( $options[ $flag ] );
		}
		$options['status']    = sanitize_key( (string) ( $options['status'] ?? 'draft' ) );
		$options['meta_keys'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $options['meta_keys'] ?? [] ) ) ) );

		return $options;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result Result payload.
	 */
	private function resultResponse( array|\WP_Error $result ): \WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return $this->sendResponse(
				[
					'success' => false,
					'message' => $result->get_error_message(),
				],
				(int) ( is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 400 ),
				is_array( $data ) ? $data : []
			);
		}

		return $this->sendResponse(
			[],
			200,
			$result + [
				'items'  => [],
				'errors' => [],
				'usage'  => [],
			]
		);
	}

	/**
	 * Verify nonce from request header.
	 */
	protected function verifyNonce( \WP_REST_Request $request ): ?\WP_REST_Response {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) )
			? null
			: $this->sendResponse(
				[
					'success' => false,
					'message' => 'Invalid CSRF token.',
				],
				403
			);
	}

	/**
	 * Send standardized REST response.
	 */
	public function sendResponse( array $result = [], int $status = 200, array $data = [] ): \WP_REST_Response {
		$result = [
			'success'   => $status < 400,
			'status'    => $status,
			'errorCode' => 0,
			...$result,
		];

		if ( $data ) {
			$result['data'] = $data;
		}

		$response = rest_ensure_response( $result );
		$response->set_status( $status );

		return $response;
	}
}
