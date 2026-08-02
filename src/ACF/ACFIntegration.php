<?php
/**
 * ACF × Polylang Integration — Orchestrator.
 *
 * Replaces polylang-pro's ACF integration when polylang-pro is removed.
 * Registers core field translation hooks, labels, settings, and options page translation.
 *
 * Always-on when PLL + ACF are active and PLL Pro's ACF integration is NOT loaded.
 *
 * @package HDPLL\ACF
 */

declare(strict_types=1);

namespace HDPLL\ACF;

defined( 'ABSPATH' ) || exit;

final class ACFIntegration {

	/**
	 * Register hooks.
	 * Called by Plugin only when ACF is active and PLL Pro's ACF integration is NOT loaded.
	 */
	public function register(): void {
		add_action( 'acf/init', [ $this, 'onAcfInit' ] );
	}

	/**
	 * Called on acf/init — ACF API is fully available.
	 */
	public function onAcfInit(): void {
		add_filter( 'acf/settings/current_language', static fn() => pll_current_language() );
		add_filter( 'acf/settings/default_language', static fn() => pll_default_language() );

		// ── Phase 1: Core field translation ──
		Dispatcher::register();

		// ── Phase 2: Labels, Settings & Admin UX ──
		( new FieldSettings() )->onAcfInit();
		( new TranslationInstructions() )->onAcfInit();
		( new AjaxLangChoice() )->onAcfInit();

		// Labels translation (gated by filter).
		if ( apply_filters( 'pll_enable_acf_labels_translation', true ) ) {
			( new Labels\FieldGroupLabels() )->onAcfInit();
			( new Labels\ObjectTypeLabels( 'post_type', 'ACF_Post_Type', 'Post Type' ) )->onAcfInit();
			( new Labels\ObjectTypeLabels( 'taxonomy', 'ACF_Taxonomy', 'Taxonomy' ) )->onAcfInit();
		}

		// ── Phase 3: Options Pages translation ──
		( new Options\OptionsAdmin() )->boot();

		// Boot transparent post_id rewriting only when BEA plugin is NOT active.
		// BEA (acf-options-for-polylang) hooks the same acf/validate_post_id filter.
		// Coexisting would cause double-processing with unpredictable results.
		if ( ! class_exists( 'BEA\ACF_Options_For_Polylang\Main', false ) ) {
			( new Options\OptionsPostId() )->boot();
		} else {
			_doing_it_wrong(
				__METHOD__,
				'The "ACF Options for Polylang" plugin is active. HD theme handles this natively — please deactivate the plugin to avoid conflicts.',
				'1.0.0'
			);
		}

		// Register Language location type.
		acf_register_location_type( LocationLanguage::class );

		// Prevent PLL from translating acf-field-group post type.
		add_filter(
			'pll_get_post_types',
			static fn( array $types ): array => array_diff_key( $types, [ 'acf-field-group' => 1 ] )
		);

		// Hide PLL private taxonomies from ACF.
		add_filter(
			'acf/get_taxonomies',
			static fn( array $taxonomies ): array => array_diff(
				$taxonomies,
				get_taxonomies( [ '_pll' => true ] )
			)
		);

		\PLL()->model->cache->clean( 'post_types' );
	}
}
