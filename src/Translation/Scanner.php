<?php
/**
 * Theme/Plugin String Scanner.
 *
 * Scans theme and plugin files for translatable strings (pll_e, pll__,
 * __(), _e(), _x(), esc_html__, esc_attr__, _n) and registers them
 * with Polylang's string translation system.
 *
 * Replaces the "Theme Translation for Polylang" plugin.
 *
 * @package HDPLL\Translation
 */

declare(strict_types=1);

namespace HDPLL\Translation;

use HD\Support\FileSystem;
use HD\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	private const CONTEXT_PREFIX = 'TTfP: ';
	private const CACHE_PREFIX   = 'hd_pll_strings:';

	/**
	 * File extensions to scan.
	 *
	 * @var string[]
	 */
	private const EXTENSIONS = [ 'php', 'inc', 'twig' ];

	/**
	 * Directory names to skip during recursive scans.
	 *
	 * @var string[]
	 */
	private const EXCLUDE_DIRS = [ 'node_modules', 'vendor', '.git', 'dist', 'build', 'tests' ];

	/**
	 * Plugins to always exclude from scanning.
	 *
	 * @var string[]
	 */
	private const EXCLUDE_PLUGINS = [
		'polylang',
		'polylang-pro',
		'theme-translation-for-polylang',
		'polylang-theme-translation',
	];

	/**
	 * Register scanner + translator hooks.
	 */
	public function register(): void {
		// Run scanner only on Polylang string management pages.
		add_action( 'init', [ $this, 'maybeScan' ] );

		// Translator hooks — always active when language is defined.
		add_action( 'pll_language_defined', [ $this, 'initTranslator' ], 99, 2 );
	}

	/**
	 * Run scanner on Polylang admin pages.
	 */
	public function maybeScan(): void {
		if ( ! \is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only scan on Polylang pages (strings list, settings).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( ! str_starts_with( $page, 'mlang' ) ) {
			return;
		}

		$settings = self::getSettings();

		$this->scanThemes( $settings['themes'] );
		$this->scanPlugins( $settings['plugins'] );
	}

	/**
	 * Initialize the translator when language is defined.
	 *
	 * @param string        $slug Deprecated.
	 * @param \PLL_Language $lang Language object.
	 */
	public function initTranslator( string $slug, \PLL_Language $lang ): void {
		new Translator( $lang );
	}

	/* ---------- Scanning ---------- */

	/**
	 * Scan selected themes for translatable strings.
	 *
	 * @param string[] $theme_names Theme directory names to scan.
	 */
	private function scanThemes( array $theme_names ): void {
		$themes = wp_get_themes();

		foreach ( $themes as $name => $theme ) {
			if ( in_array( $name, $theme_names, true ) ) {
				$dir = $theme->get_theme_root() . DIRECTORY_SEPARATOR . $name;
				$this->registerStringsFromDir( $dir, $name );
			}
		}
	}

	/**
	 * Scan selected plugins for translatable strings.
	 *
	 * @param string[] $plugin_names Plugin directory names to scan.
	 */
	private function scanPlugins( array $plugin_names ): void {
		$plugins = wp_get_active_and_valid_plugins();

		if ( \is_multisite() ) {
			$plugins = array_merge( $plugins, wp_get_active_network_plugins() );
		}

		foreach ( $plugins as $plugin ) {
			$plugin_dir  = dirname( $plugin );
			$plugin_name = pathinfo( $plugin, PATHINFO_FILENAME );

			if ( in_array( $plugin_name, $plugin_names, true )
				&& ! in_array( $plugin_name, self::EXCLUDE_PLUGINS, true )
				&& $plugin_dir !== WP_PLUGIN_DIR
			) {
				$this->registerStringsFromDir( $plugin_dir, $plugin_name );
			}
		}
	}

	/**
	 * Register strings from a directory (with transient cache).
	 */
	private function registerStringsFromDir( string $path, string $name ): void {
		$strings = self::extractFromDir( $path, $name );

		foreach ( $strings as $string ) {
			\pll_register_string( $string, $string, self::CONTEXT_PREFIX . $name );
		}
	}

	/**
	 * Extract translatable strings from a directory (with transient cache).
	 *
	 * Public static for reuse by ImportExport subsystem.
	 *
	 * @param string $path Directory path.
	 * @param string $name Cache identifier (theme/plugin name).
	 *
	 * @return string[] Unique translatable strings.
	 */
	public static function extractFromDir( string $path, string $name ): array {
		$cache_key = self::CACHE_PREFIX . $name . ':' . md5( $path );
		$strings   = get_transient( $cache_key );

		if ( is_array( $strings ) ) {
			return $strings;
		}

		$files   = self::collectFiles( $path );
		$strings = self::extractStrings( $files );
		set_transient( $cache_key, $strings, DAY_IN_SECONDS );

		return $strings;
	}

	/**
	 * Clear all scanner transient caches.
	 *
	 * Call when translation settings change (themes/plugins selection)
	 * or after code deployment to force a re-scan.
	 */
	public static function clearCache(): void {
		$settings = self::getSettings();

		// Collect all known cache keys and delete via transient API
		// (works correctly with both DB and persistent object cache).
		foreach ( wp_get_themes() as $name => $theme ) {
			if ( in_array( $name, $settings['themes'], true ) ) {
				$dir = $theme->get_theme_root() . DIRECTORY_SEPARATOR . $name;
				delete_transient( self::CACHE_PREFIX . $name . ':' . md5( $dir ) );
			}
		}

		$plugins = wp_get_active_and_valid_plugins();

		if ( \is_multisite() ) {
			$plugins = array_merge( $plugins, wp_get_active_network_plugins() );
		}

		foreach ( $plugins as $plugin ) {
			$plugin_name = pathinfo( $plugin, PATHINFO_FILENAME );

			if ( in_array( $plugin_name, $settings['plugins'], true ) ) {
				delete_transient( self::CACHE_PREFIX . $plugin_name . ':' . md5( dirname( $plugin ) ) );
			}
		}
	}

	/**
	 * Recursively collect files with matching extensions.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return string[] File paths.
	 */
	public static function collectFiles( string $dir ): array {
		$results = [];

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- graceful fallback for unreadable dirs.
		$items = @scandir( $dir );

		if ( ! is_array( $items ) ) {
			return $results;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = realpath( $dir . DIRECTORY_SEPARATOR . $item );
			if ( false === $path ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				if ( in_array( basename( $path ), self::EXCLUDE_DIRS, true ) ) {
					continue;
				}

				$results = array_merge( $results, self::collectFiles( $path ) );
			} else {
				$ext = pathinfo( $path, PATHINFO_EXTENSION );
				if ( in_array( $ext, self::EXTENSIONS, true ) ) {
					$results[] = $path;
				}
			}
		}

		return $results;
	}

	/**
	 * Extract translatable strings from files using regex patterns.
	 *
	 * Matches: pll_e(), pll__(), __(), _e(), _x(), esc_html__(), esc_attr__(),
	 * esc_html_e(), esc_attr_e(), _n() (singular + plural).
	 *
	 * @param string[] $files File paths.
	 *
	 * @return string[] Unique translatable strings.
	 */
	public static function extractStrings( array $files ): array {
		$strings = [];

		foreach ( $files as $file ) {
			$content = FileSystem::fileRead( $file );
			if ( null === $content || '' === $content ) {
				continue;
			}

			// Normalize escaped quotes.
			$content = str_replace( "\\'", '[__SQ__]', $content );
			$content = str_replace( '\\"', '[__DQ__]', $content );

			// pll_e / pll__ patterns.
			preg_match_all( '/[\s=(\.]+pll_[_e]\s*\(\s*[\'\"](.*?)[\'\"][\s]*\)/s', $content, $m );
			if ( ! empty( $m[1] ) ) {
				$strings = array_merge( $strings, $m[1] );
			}

			// __, _e, _x — single quotes.
			preg_match_all( '/[\s=(\.]+_[_ex]\s*\(\s*\'(.*?)\'\s*[,]*\s*\'*(.*?)\'*\s*\)/s', $content, $m );
			if ( ! empty( $m[1] ) ) {
				$strings = array_merge( $strings, $m[1] );
			}

			// __, _e, _x — double quotes.
			preg_match_all( '/[\s=(\.]+_[_ex]\s*\(\s*"(.*?)"\s*[,]*\s*"*(.*?)"*\s*\)/s', $content, $m );
			if ( ! empty( $m[1] ) ) {
				$strings = array_merge( $strings, $m[1] );
			}

			// esc_html__ / esc_html_e / esc_attr__ / esc_attr_e — single quotes.
			preg_match_all( '/[\s=(\.]+(?:esc_html|esc_attr)(?:__|_e)\s*\(\s*\'(.*?)\'\s*[,]*\s*\'*(.*?)\'*\s*\)/s', $content, $m );
			if ( ! empty( $m[1] ) ) {
				$strings = array_merge( $strings, $m[1] );
			}

			// esc_html__ / esc_html_e / esc_attr__ / esc_attr_e — double quotes.
			preg_match_all( '/[\s=(\.]+(?:esc_html|esc_attr)(?:__|_e)\s*\(\s*"(.*?)"\s*[,]*\s*"*(.*?)"*\s*\)/s', $content, $m );
			if ( ! empty( $m[1] ) ) {
				$strings = array_merge( $strings, $m[1] );
			}

			// _n singular + plural.
			preg_match_all( '/[\s=(\.]+_n\s*\(\s*[\'\"](.*?)[\'\"][\s]*,[\s]*[\'\"](.*?)[\'\"][\s]*,(.*?)\)/s', $content, $m );
			if ( ! empty( $m[1] ) ) {
				$strings = array_merge( $strings, $m[1] );
				$strings = array_merge( $strings, $m[2] );
			}
		}

		// Restore quotes and deduplicate.
		$strings = array_map(
			static function ( string $s ): string {
				$s = str_replace( '[__SQ__]', "'", $s );

				return str_replace( '[__DQ__]', '"', $s );
			},
			$strings
		);

		return array_unique( $strings );
	}

	/* ---------- Scan Settings ---------- */

	private const SETTINGS_KEY = 'hd_pll_translation_settings';

	/** @var array|null In-memory cache. */
	private static ?array $settingsCache = null;

	/**
	 * Get scan settings with defaults.
	 *
	 * @return array{themes: string[], plugins: string[], domains: string[], additional_domains: string[]}
	 */
	public static function getSettings(): array {
		if ( null !== self::$settingsCache ) {
			return self::$settingsCache;
		}

		$settings = Helper::getOption( self::SETTINGS_KEY, [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		self::$settingsCache = wp_parse_args(
			$settings,
			[
				'themes'             => [],
				'plugins'            => [],
				'domains'            => [ 'default' ],
				'additional_domains' => [],
			]
		);

		return self::$settingsCache;
	}

	/**
	 * Save scan settings.
	 *
	 * @param array $data Raw settings data.
	 */
	public static function saveSettings( array $data ): void {
		$settings = [
			'themes'             => array_map( 'sanitize_text_field', $data['themes'] ?? [] ),
			'plugins'            => array_map( 'sanitize_text_field', $data['plugins'] ?? [] ),
			'domains'            => array_map( 'sanitize_text_field', $data['domains'] ?? [ 'default' ] ),
			'additional_domains' => array_map( 'sanitize_text_field', $data['additional_domains'] ?? [] ),
		];

		Helper::updateOption( self::SETTINGS_KEY, $settings );
		self::$settingsCache = null;

		// Bust scanner transient cache so next admin load re-scans with new selections.
		self::clearCache();
	}

	/**
	 * Get settings option key.
	 */
	public static function settingsKey(): string {
		return self::SETTINGS_KEY;
	}
}
