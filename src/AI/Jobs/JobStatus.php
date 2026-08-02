<?php
/**
 * Durable PLL AI job statuses.
 *
 * @package HDPLL\AI\Enum
 */

declare(strict_types=1);

namespace HDPLL\AI\Jobs;

defined( 'ABSPATH' ) || exit;

enum JobStatus: string {
	case Pending   = 'pending';
	case Running   = 'running';
	case Completed = 'completed';
	case Preview   = 'preview';
	case Failed    = 'failed';
	case Cancelled = 'cancelled';
	case Dead      = 'dead';

	public static function fromRaw( mixed $status ): ?self {
		return self::tryFrom( sanitize_key( (string) $status ) );
	}

	public static function default(): self {
		return self::Pending;
	}

	public function isTerminal(): bool {
		return in_array( $this, [ self::Completed, self::Preview, self::Failed, self::Cancelled, self::Dead ], true );
	}

	/**
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $status ): string => $status->value, self::cases() );
	}
}
