<?php
/**
 * PLL AI auto-translation manager.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\Jobs\BatchManager;
use HDPLL\AI\Jobs\JobRepository;
use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class AutoTranslateManager {

	public static function shouldBoot(): bool {
		return is_admin()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
	}

	public function register(): void {
		$repository = new JobRepository();
		add_action( 'init', [ $repository, 'registerPostType' ] );

		( new BatchManager( $repository ) )->register();

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
			( new RowActions() )->register();
			( new BulkActions( $repository ) )->register();
		}
	}

	public function enqueueAssets(): void {
		global $pagenow;
		if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
			return;
		}

		$settings = Plugin::getCachedOptions();
		$assetUrl = HD_PLL_URL . 'assets/';

		wp_enqueue_style( 'hd-pll-ai-auto-translate', $assetUrl . 'auto-translate.css', [], HD_PLL_VERSION );
		wp_register_script( 'hd-pll-ai-auto-translate', $assetUrl . 'auto-translate.js', [ 'wp-api-fetch' ], HD_PLL_VERSION, true );
		wp_localize_script(
			'hd-pll-ai-auto-translate',
			'hdPllAi',
			[
				'root'         => esc_url_raw( rest_url( ( defined( 'REST_NAMESPACE' ) ? REST_NAMESPACE : 'hd/v1' ) . '/pll/ai' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'adminUrl'     => esc_url_raw( admin_url() ),
				'editorAssist' => ! empty( $settings['ai_editor_assist_enabled'] ),
			]
		);

		wp_enqueue_script( 'hd-pll-ai-auto-translate' );
	}
}
