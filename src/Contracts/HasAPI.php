<?php
/**
 * HasAPI — optional contract for PLL features with REST API endpoints.
 *
 * Orchestrator auto-registers routes on rest_api_init for booted features.
 *
 * @package HDPLL\Contracts
 */

declare(strict_types=1);

namespace HDPLL\Contracts;

defined( 'ABSPATH' ) || exit;

interface HasAPI {
	/** @return list<class-string<\WP_REST_Controller>> */
	public static function apiClasses(): array;
}
