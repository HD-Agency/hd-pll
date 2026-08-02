<?php
/**
 * Plugin Name: HD PLL Integration
 * Plugin URI: https://webhd.vn
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: HD
 * Author URI: https://webhd.vn
 * Description: Standalone, independent Polylang integration replacing Polylang Pro/WC/Theme Translation.
 * License: MIT
 */

use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

// Prevent double loading.
if ( defined( 'HD_PLL_VERSION' ) ) {
	return;
}

// ── Constants ───────────────────────────────────

const HD_PLL_VERSION = '1.0.0';

define( 'HD_PLL_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . DIRECTORY_SEPARATOR );
define( 'HD_PLL_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) . '/' );
define( 'HD_PLL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ── Guards ──────────────────────────────────────

// PHP version guard.
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'HD PLL Integration requires PHP 8.1 or newer. Please upgrade your PHP version.' )
		)
	);
	return;
}

// Autoload guard.
$hd_pll_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_file( $hd_pll_autoload ) ) {
	require_once $hd_pll_autoload;
}

// Load theme autoloader to make HD\Core\Helper etc. available during plugins_loaded.
if ( function_exists( 'get_template_directory' ) ) {
	$theme_autoload = get_template_directory() . '/vendor/autoload.php';
	if ( is_file( $theme_autoload ) ) {
		require_once $theme_autoload;
	}
}

if ( ! class_exists( Plugin::class ) ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'HD PLL Integration: missing vendor directory. Please run composer install.' )
		)
	);
	return;
}

// ── Bootstrap ───────────────────────────────────

add_action(
	'plugins_loaded',
	static function () {
		( new Plugin() )->boot();
	},
	15
);
