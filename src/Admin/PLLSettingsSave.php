<?php
/**
 * PLL Settings Form Save Handler.
 *
 * Extracted from PLLSettings to own the form submission lifecycle:
 * Pro features, admin locale, AI fields, translation scanner settings,
 * import/export dispatch.
 *
 * @package HDPLL\Admin
 */

declare(strict_types=1);

namespace HDPLL\Admin;

use HD\Core\Helper;
use HDPLL\Plugin;
use HDPLL\ImportExport\ExportHandler;
use HDPLL\Translation\Scanner;

defined( 'ABSPATH' ) || exit;

final class PLLSettingsSave {

	private const NONCE_ACTION = 'hd_pll_settings_save';
	private const NONCE_FIELD  = '_hd_pll_nonce';

	/**
	 * Handle form submission (called from admin_init).
	 */
	public static function handle(): void {
		$isExport = ! empty( $_POST['hd_pll_export'] );
		if ( ( empty( $_POST['hd_pll_save'] ) && ! $isExport ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $_POST[ self::NONCE_FIELD ] ?? '', self::NONCE_ACTION ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$redirect_url = admin_url( 'admin.php?page=' . sanitize_key( $_GET['page'] ?? 'mlang' ) );

		// ── Handle export (skip settings save) ──
		if ( $isExport ) {
			self::handleExport( $redirect_url );
			return; // handleExport() always exits; guard against future changes.
		}

		// ── Save Pro features ──
		$features   = $_POST['hd_pll_features'] ?? [];
		$pro_slugs  = array_keys( PLLSettings::getProFeatureLabels() );
		$pll_option = [];

		foreach ( $pro_slugs as $slug ) {
			$pll_option[ $slug ] = ! empty( $features[ $slug ] );
		}

		// T-B1: Admin force locale.
		$pll_option['admin_force_locale'] = sanitize_key( $_POST['hd_pll_admin_force_locale'] ?? '' );

		// Delegate AI fields.
		AiSettingsSection::saveFields( $pll_option, $_POST );

		Helper::updateOption( Plugin::optionKey(), $pll_option );
		Plugin::resetCache();

		// ── Save Translation settings ──
		self::saveTranslationSettings();

		// ── Handle import (moved to REST: ImportExportAPI) ──
		// File upload now goes through /wp-json/hd/v1/pll/import to bypass hosting WAF.

		$redirect_url = add_query_arg( 'hd_pll_msg', 'saved', $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle export request (terminates on success).
	 *
	 * @param string $redirect_url Redirect URL on failure.
	 */
	private static function handleExport( string $redirect_url ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle().
		$exportFormat = sanitize_key( $_POST['hd_pll_export_format'] ?? 'csv' );
		$exportLangs  = array_map( 'sanitize_key', $_POST['hd_pll_export_langs'] ?? [] );
		$exportGroup  = sanitize_text_field( $_POST['hd_pll_export_group'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! empty( $exportLangs ) ) {
			$result = ExportHandler::handle( $exportFormat, $exportLangs, $exportGroup );

			if ( \is_wp_error( $result ) && $result->has_errors() ) {
				$redirect_url = add_query_arg(
					[
						'hd_pll_msg'   => 'export_error',
						'hd_pll_error' => $result->get_error_message(),
					],
					$redirect_url
				);
				wp_safe_redirect( $redirect_url );
				exit;
			}
			// On success, ExportHandler sends file and exits.
		}

		// No languages selected — redirect with error.
		$redirect_url = add_query_arg( 'hd_pll_msg', 'export_error', $redirect_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Save translation scanner settings from POST data.
	 */
	private static function saveTranslationSettings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle().
		$translation = $_POST['hd_pll_translation'] ?? [];
		$save_data   = [
			'themes'             => array_map( 'sanitize_text_field', $translation['themes'] ?? [] ),
			'plugins'            => array_map( 'sanitize_text_field', $translation['plugins'] ?? [] ),
			'domains'            => [ 'default' ],
			'additional_domains' => [],
		];

		// Auto-detect text domains for selected themes.
		foreach ( $save_data['themes'] as $theme_name ) {
			$theme = wp_get_theme( $theme_name );
			if ( $theme->exists() ) {
				$textdomain = $theme->get( 'TextDomain' );
				if ( $textdomain && $textdomain !== $theme_name ) {
					$save_data['additional_domains'][] = sanitize_text_field( $textdomain );
				}
			}
		}

		// Auto-detect text domains for selected plugins.
		$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];
		foreach ( $save_data['plugins'] as $plugin_name ) {
			foreach ( $all_plugins as $key => $info ) {
				if ( pathinfo( $key, PATHINFO_FILENAME ) === $plugin_name ) {
					$textdomain = $info['TextDomain'] ?? '';
					if ( $textdomain && $textdomain !== $plugin_name ) {
						$save_data['additional_domains'][] = sanitize_text_field( $textdomain );
					}
					break;
				}
			}
		}

		$save_data['additional_domains'] = array_unique( $save_data['additional_domains'] );
		Scanner::saveSettings( $save_data );
	}
}
