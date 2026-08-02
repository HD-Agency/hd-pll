<?php
/**
 * Polylang Integration Module — Orchestrator.
 *
 * Replaces polylang-pro + polylang-wc + theme-translation-for-polylang plugins.
 *
 * Architecture mirrors WooCommerceModule:
 * - FEATURES const -> declarative, zero-footprint conditional loading.
 * - PllFeatureInterface -> slug() static check + register().
 * - HasAPI -> auto-register REST routes.
 *
 * Coexistence rules:
 * - polylang-pro active -> skip Pro features (translate-slugs, duplicate, fallback).
 * - polylang-wc active -> skip WC features entirely.
 * - Translation always available (replaces a free plugin).
 *
 * @package HDPLL
 */

declare(strict_types=1);

namespace HDPLL;

use HDPLL\Contracts\HasAPI;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static array $optionCache = [];

	public static function slug(): string {
		return 'pll';
	}

	public static function optionKey(): string {
		return 'hd_pll';
	}

	public static function getOptions(): array {
		return array_merge( self::defaults(), get_option( self::optionKey(), [] ) );
	}

	public static function getCachedOptions(): array {
		if ( empty( self::$optionCache ) ) {
			self::$optionCache = self::getOptions();
		}

		return self::$optionCache;
	}

	public static function resetCache(): void {
		self::$optionCache = [];
	}

	public static function isWoocommerceActive(): bool {
		return function_exists( 'WC' ) || class_exists( 'WooCommerce' );
	}

	public static function isAcfActive(): bool {
		return defined( 'ACF_PRO' ) || class_exists( 'acf_pro' ) || function_exists( 'get_field' );
	}

	/**
	 * Active when Polylang (free or pro) is loaded.
	 */
	public static function isActive(): bool {
		return defined( 'POLYLANG_VERSION' );
	}

	/* ---------- Coexistence Guards ------------------------------- */

	/**
	 * Whether Polylang Pro is active → skip Pro features.
	 */
	public static function isProActive(): bool {
		return defined( 'POLYLANG_PRO' );
	}

	/**
	 * Whether Polylang for WooCommerce plugin is active → skip WC features.
	 */
	public static function isWCActive(): bool {
		return defined( 'PLLWC_VERSION' );
	}

	/**
	 * Whether Theme Translation for Polylang plugin is active → skip Translation features.
	 */
	public static function isTTfPActive(): bool {
		return class_exists( 'Polylang_Theme_Translation', false );
	}

	/* ---------- Sub-features (settings-gated) ------------------- */

	/**
	 * Pro features — skipped if polylang-pro active.
	 *
	 * @var array<class-string<Contracts\PllFeatureInterface>>
	 */
	private const PRO_FEATURES = [
		Pro\TranslateSlugs::class,
		Pro\DuplicateContent::class,
		Pro\ShareSlugs::class,
		Pro\LocaleFallback::class,
	];

	/* ---------- Defaults ---------------------------------------- */

	public static function defaults(): array {
		$defaults = [
			'admin_force_locale'              => 'content',
			'sync_customer_locale_from_order' => false,
			'ai_translation_enabled'          => false,
			'ai_consumer_token'               => '',
			'ai_default_target_languages'     => [],
			'ai_default_commit_mode'          => 'draft',
			'ai_default_post_status'          => 'draft',
			'ai_content_types'                => [ 'post', 'page' ],
			'ai_translate_title'              => true,
			'ai_translate_content'            => true,
			'ai_translate_excerpt'            => true,
			'ai_translate_slug'               => false,
			'ai_translate_meta_keys'          => [],
			'ai_seo_meta_presets'             => [ 'rank_math', 'yoast' ],
			'ai_glossary_terms'               => [],
			'ai_max_units_per_request'        => 25,
			'ai_max_chars_per_request'        => 12000,
			'ai_editor_assist_enabled'        => false,
		];

		foreach ( self::PRO_FEATURES as $featureClass ) {
			$defaults[ $featureClass::slug() ] = false;
		}

		return $defaults;
	}

	/* ---------- Boot -------------------------------------------- */

	public function boot(): void {

		// Load plugin textdomain for translations.
		add_action( 'init', static fn() => load_plugin_textdomain( 'hd-pll', false, dirname( HD_PLL_PLUGIN_BASENAME ) . '/languages' ) );

		// ── Admin settings tab (always available under Languages > Settings) ──
		if ( \is_admin() ) {
			Admin\PLLSettings::init();

			// T-B1: Force admin locale.
			$locale_mode = self::getCachedOptions()['admin_force_locale'] ?? '';

			// 'profile' → let WP use get_user_locale() natively (no filter needed).
			// 'content' / 'default' → override WP locale.
			if ( '' !== $locale_mode && 'profile' !== $locale_mode && function_exists( 'PLL' ) && ! wp_doing_ajax() ) {

				// Resolve target PLL_Language based on mode.
				$target_lang = match ( $locale_mode ) {
					'default' => \PLL()->model->get_default_language(),
					'content' => \PLL()->curlang, // Whatever PLL determined for current content.
					default   => null,
				};

				if ( $target_lang instanceof \PLL_Language ) {
					$target_locale = $target_lang->locale;

					// Force WP to reload ALL textdomains with the target locale.
					// NOTE: Do NOT set PLL()->curlang here — that would cause
					// Polylang to filter post listings by this language,
					// breaking "All languages" view in edit.php.
					// MUST run BEFORE the determine_locale filter — otherwise
					// switch_to_locale() sees the locale already matches and early-returns.
					switch_to_locale( $target_locale );

					// Lock determine_locale() to the target — WP_Locale_Switcher hooks
					// at priority 10, this at 20 ensures no later filter can override.
					add_filter( 'determine_locale', static fn() => $target_locale, 20 );
				}
			}
		}

		// ── WC features: auto-boot if WooCommerce active AND polylang-wc NOT active ──
		if ( self::isWoocommerceActive() && ! self::isWCActive() ) {
			$this->hookPllInit( [ $this, 'initWC' ] );
		}

		// ── ACF features: auto-boot if ACF active AND polylang-pro's ACF integration NOT loaded ──
		if ( self::isAcfActive()
			&& ! class_exists( 'WP_Syntex\Polylang_Pro\Integrations\ACF\Main', false )
		) {
			$this->hookPllInit(
				static function () {
					$acf = new ACF\ACFIntegration();
					$acf->register();
				}
			);
		}

		$settings = self::getCachedOptions();

		if ( ! empty( $settings['ai_translation_enabled'] ) ) {
			// REST routes must be registered unconditionally — REST_REQUEST is
			// not defined at after_setup_theme, so shouldBoot() would block them.
			self::collectApiClass( AI\AutoTranslateAPI::class );

			if ( AI\AutoTranslateManager::shouldBoot() ) {
				$this->hookPllInit(
					static function (): void {
						( new AI\AutoTranslateManager() )->register();
					}
				);
			}
		}

		// ── Pro features: declarative loop, skip if polylang-pro active ──
		if ( ! self::isProActive() ) {
			$this->bootFeatures( self::PRO_FEATURES, $settings );
		}

		// ── Translation features: always-on when TTfP plugin is not active ──
		// Not gated by feature toggle — scanner runs based on selected themes/plugins.
		if ( ! self::isTTfPActive() ) {
			$this->hookPllInit(
				static function () {
					( new Translation\Scanner() )->register();
				}
			);

			// Import REST endpoint — bypasses hosting WAF that blocks multipart POST to admin.php.
			self::collectApiClass( ImportExport\ImportExportAPI::class );
		}

		// ── REST API: skip when Polylang Pro REST handlers are already active ──
		if ( ! self::isProActive() ) {
			$this->hookPllInit(
				static function () {
					new API\LanguageField();
					new API\QueryFilter();
				}
			);
		}

		// ── WooCommerce REST: only when WC active and polylang-wc NOT active ──
		if ( self::isWoocommerceActive() && ! self::isWCActive() ) {
			$this->hookPllInit(
				static function () {
					new API\WCQueryFilter();
				}
			);
		}
	}

	/**
	 * Boot features from a declarative list.
	 * Zero footprint: disabled features are never instantiated.
	 *
	 * @param array<class-string<Contracts\PllFeatureInterface>> $features Feature classes.
	 * @param array<string, mixed>                               $settings Module settings.
	 */
	private function bootFeatures( array $features, array $settings ): void {
		foreach ( $features as $featureClass ) {
			$slug = $featureClass::slug();

			if ( empty( $settings[ $slug ] ) ) {
				continue; // Zero footprint — no new, no register
			}

			$this->hookPllInit(
				static function () use ( $featureClass ) {
					$feature = new $featureClass();
					$feature->register();

					// Auto-register API endpoints via named batch hook.
					if ( $feature instanceof HasAPI ) {
						foreach ( $feature::apiClasses() as $apiClass ) {
							Plugin::collectApiClass( $apiClass );
						}
					}
				}
			);
		}
	}

	/** @var list<class-string> PLL API controller classes collected during boot. */
	private static array $pllApiClasses = [];

	/**
	 * Collect an API class for deferred batch registration.
	 *
	 * @param class-string $apiClass API controller class.
	 */
	public static function collectApiClass( string $apiClass ): void {
		if ( empty( self::$pllApiClasses ) ) {
			add_action( 'rest_api_init', [ self::class, 'registerApiRoutes' ] );
		}
		self::$pllApiClasses[] = $apiClass;
	}

	/**
	 * Register REST API routes for PLL features.
	 * Hooked to `rest_api_init` — named method for removability.
	 */
	public static function registerApiRoutes(): void {
		foreach ( self::$pllApiClasses as $apiClass ) {
			( new $apiClass() )->register_routes();
		}
	}

	/**
	 * Hook a callback to pll_init, or call immediately if already fired.
	 *
	 * pll_init fires at plugins_loaded (priority 1), but Modules boot
	 * at after_setup_theme (priority 11) — so pll_init is already done.
	 */
	private function hookPllInit( callable $callback ): void {
		if ( did_action( 'pll_init' ) ) {
			$callback();
		} else {
			add_action( 'pll_init', $callback );
		}
	}


	/* ---------- WC Integration (always-on when conditions met) --- */

	/**
	 * Initialize WooCommerce integration features.
	 * Called on `pll_init` — PLL() is available.
	 * Only runs when polylang-wc plugin is NOT active.
	 */
	public function initWC(): void {
		// W-A4: Post types & taxonomy registration (foundational — must be first).
		new WC\PostTypes();

		// W1: Translate WC page IDs.
		// Frontend: defer to pll_language_defined (matching polylang-wc)
		// so pll_get_post filter is NOT active during rewrite rule generation.
		if ( did_action( 'pll_language_defined' ) ) {
			WC\WCPages::init();
		} else {
			add_action( 'pll_language_defined', [ WC\WCPages::class, 'init' ], 1 );
		}

		// Admin: post state labels (no pll_get_post filter needed).
		if ( \is_admin() ) {
			WC\WCPages::initAdmin();
		}

		// W2+W10: Shop rewrite rules + translation URLs
		new WC\ShopRewrite();
		new WC\Links();

		// W3: WC string translations
		new WC\Strings();

		// W4: Email language switching
		new WC\Emails();

		// W5: Cart language persistence
		new WC\Cart();

		// W6+W7+W8: Product/stock/SKU sync
		new WC\Products();

		// Product meta copy/translate for PLL "Duplicate content to this language".
		new WC\ProductSync();

		// W11: Coupon translation
		new WC\Coupons();

		// W12+W13: Order language (HPOS)
		new WC\OrderLanguage();

		// W-B6: Cross-domain Data (Xdata) transfer for WooCommerce.
		if ( (int) ( \PLL()->options['force_lang'] ?? 0 ) >= 2 ) {
			new WC\Xdata();
		}

		// Frontend-only features.
		if ( \PLL() instanceof \PLL_Frontend ) {
			new WC\Frontend();

			// W-A5: My Account — all-language orders + item name translation.
			new WC\FrontendAccount();
		}

		// Admin-only features (product/taxonomy/order management).
		if ( \PLL() instanceof \PLL_Admin ) {
			new WC\Admin\AdminProducts();
			new WC\Admin\AdminTaxonomies();
			new WC\Admin\AdminOrders();
			new WC\Admin\AdminProductDuplicate();

			// W-B2: Product CSV Import/Export with language support.
			new WC\Admin\ProductExport();
			new WC\Admin\ProductImport();
		}
	}
}
