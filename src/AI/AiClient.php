<?php
/**
 * HDAT chat-completions adapter for PLL AI translation.
 *
 * Internal-REST-only dispatch via InternalRequestContext, matching
 * HDAC's proven pattern. Consumer token is read from PLL settings
 * and decrypted via HD\Core\Encryptor.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HD\Core\Encryptor;
use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class AiClient {

	private const ROUTE = '/hdat/v1/chat/completions';

	/**
	 * Check route availability from the registered REST route table.
	 */
	public static function isAvailable(): bool {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}

		$routes = rest_get_server()->get_routes();

		return isset( $routes[ self::ROUTE ] );
	}

	/**
	 * Send an OpenAI-compatible chat-completions request via internal REST dispatch.
	 *
	 * @param array<string, mixed> $payload OpenAI-compatible payload.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function chat( array $payload ): array|\WP_Error {
		if ( ! self::isAvailable() ) {
			return new \WP_Error( 'hd_pll_ai_hdat_unavailable', __( 'HDAT chat completions route is unavailable.', 'hd-pll' ) );
		}

		$request = new \WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) ?: '{}' );

		$token = $this->consumerToken();

		if ( '' !== $token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $token );
		}

		return $this->normalizeResponse( $this->dispatchInternal( $request ) );
	}

	/**
	 * Extract the first assistant message content.
	 *
	 * @param array<string, mixed> $response OpenAI-compatible response.
	 */
	public static function assistantContent( array $response ): string {
		$choice = $response['choices'][0] ?? null;
		if ( ! is_array( $choice ) ) {
			return '';
		}

		$message = $choice['message'] ?? [];

		return is_array( $message ) ? (string) ( $message['content'] ?? '' ) : '';
	}

	/**
	 * Read and decrypt the HDAT consumer token from PLL settings.
	 *
	 * Supports legacy plain-text tokens: if Encryptor::decode() returns null
	 * (not encrypted or decryption fails), the raw stored value is used as-is.
	 */
	private function consumerToken(): string {
		$stored = (string) ( Plugin::getCachedOptions()['ai_consumer_token'] ?? '' );

		if ( '' === $stored ) {
			return '';
		}

		$decrypted = Encryptor::decode( $stored );

		return $decrypted ?? $stored;
	}

	/**
	 * Dispatch an in-process REST request with the internal request marker.
	 */
	private function dispatchInternal( \WP_REST_Request $request ): mixed {
		if ( class_exists( \HDAT\Auth\InternalRequestContext::class ) ) {
			return \HDAT\Auth\InternalRequestContext::run(
				static fn() => rest_do_request( $request )
			);
		}

		return rest_do_request( $request );
	}

	/**
	 * Normalize transport response, aligned with HDAC's pattern.
	 *
	 * Extracts error code from both error.code and root code fields,
	 * and includes response data in WP_Error metadata.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function normalizeResponse( mixed $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof \WP_REST_Response ) {
			$data = $response->get_data();
			if ( $response->get_status() >= 400 ) {
				$message = __( 'AI request failed.', 'hd-pll' );
				if ( is_array( $data ) ) {
					$message = (string) ( $data['error']['message'] ?? $data['message'] ?? $message );
				}

				return new \WP_Error(
					is_array( $data ) ? (string) ( $data['error']['code'] ?? $data['code'] ?? 'hd_pll_ai_rest_error' ) : 'hd_pll_ai_rest_error',
					$message,
					[
						'status' => $response->get_status(),
						'data'   => is_array( $data ) ? $data : [],
					]
				);
			}

			return is_array( $data ) ? $data : [];
		}

		return is_array( $response )
			? $response
			: new \WP_Error( 'hd_pll_ai_invalid_response', __( 'AI response must be an array.', 'hd-pll' ) );
	}
}
