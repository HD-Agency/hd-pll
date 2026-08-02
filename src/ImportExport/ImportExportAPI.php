<?php
/**
 * Import/Export REST API — handles translation file import via REST.
 *
 * Bypasses hosting WAF restrictions on multipart POST by accepting
 * raw file bytes with Content-Type: application/octet-stream.
 * The filename is passed via the X-Import-Filename header.
 *
 * @package HDPLL\ImportExport
 */

declare(strict_types=1);

namespace HDPLL\ImportExport;

defined( 'ABSPATH' ) || exit;

final class ImportExportAPI extends \WP_REST_Controller {

	private const BASE = '/pll';

	/** Max upload size: 5 MB. */
	private const MAX_FILE_SIZE = 5 * 1024 * 1024;

	/** Allowed file extensions. */
	private const ALLOWED_EXTENSIONS = [ 'po', 'csv', 'xliff', 'xlf' ];

	public function register_routes(): void {
		$ns = defined( 'REST_NAMESPACE' ) ? REST_NAMESPACE : 'hd/v1';

		register_rest_route(
			$ns,
			self::BASE . '/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'importTranslations' ],
				'permission_callback' => [ $this, 'permission' ],
			]
		);
	}

	/**
	 * Permission check — requires manage_options.
	 */
	public function permission(): bool|\WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'hd_pll_import_forbidden', __( 'Insufficient permissions.', 'hd-pll' ), [ 'status' => 403 ] );
	}

	/**
	 * Handle translation file import via raw body upload.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function importTranslations( \WP_REST_Request $request ): \WP_REST_Response {
		$nonceCheck = $this->verifyNonce( $request );
		if ( null !== $nonceCheck ) {
			return $nonceCheck;
		}

		// Read filename from custom header.
		$filename = sanitize_file_name( $request->get_header( 'x_import_filename' ) ?? '' );

		if ( empty( $filename ) ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'Missing X-Import-Filename header.', 'hd-pll' ),
				],
				400
			);
		}

		// Validate file extension.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! \in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => sprintf(
						/* translators: %s: comma-separated list of allowed extensions */
						__( 'Invalid file type. Allowed: %s', 'hd-pll' ),
						implode( ', ', self::ALLOWED_EXTENSIONS )
					),
				],
				400
			);
		}

		// Read raw body.
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'Empty request body. No file data received.', 'hd-pll' ),
				],
				400
			);
		}

		$size = \strlen( $body );

		if ( $size > self::MAX_FILE_SIZE ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'File too large. Maximum size is 5 MB.', 'hd-pll' ),
				],
				400
			);
		}

		// Bump limits for large imports if the functions are available.
		if ( function_exists( 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Large PO files need more memory.
			ini_set( 'memory_limit', '512M' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		// Use native tempnam() instead of wp_tempnam() — the WP version calls
		// wp_upload_dir() which runs DB queries and allocates memory, causing
		// OOM on shared hosting when baseline memory is already ~100MB.
		$tmp_file = tempnam( sys_get_temp_dir(), 'pll_import_' );
		if ( ! $tmp_file ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'Could not create temporary file.', 'hd-pll' ),
				],
				500
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing uploaded bytes to temp file for import.
		file_put_contents( $tmp_file, $body );

		// Free memory before processing.
		unset( $body );

		$result = ImportHandler::handleFromPath( $tmp_file, $filename );

		// Clean up temp file (handleFromPath also deletes after parsing, but guard for errors).
		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}

		if ( \is_wp_error( $result ) ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => $result->get_error_message(),
				],
				422
			);
		}

		return $this->sendResponse(
			[
				'success'  => true,
				'message'  => sprintf(
					/* translators: %d: number of imported items */
					__( 'Translations imported: %d items.', 'hd-pll' ),
					$result['imported'] ?? 0
				),
				'imported' => $result['imported'] ?? 0,
				'type'     => $result['type'] ?? '',
				'warnings' => $result['warnings'] ?? [],
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
