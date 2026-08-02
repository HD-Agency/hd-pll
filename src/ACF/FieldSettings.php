<?php
/**
 * ACF — Per-Field Translation Settings.
 *
 * Adds a "Translations" dropdown to each non-layout ACF field with two
 * strategies: Translate (default — seed + keep references linked) and
 * Ignore (independent per language).
 *
 * @package HDPLL\ACF
 */

declare(strict_types=1);

namespace HDPLL\ACF;

defined( 'ABSPATH' ) || exit;

final class FieldSettings {

	/**
	 * Register the settings hook for each non-layout field type.
	 * Called from ACFIntegration::onAcfInit().
	 */
	public function onAcfInit(): void {
		foreach ( acf_get_field_types() as $type ) {
			if ( 'layout' !== $type->category ) {
				add_action( "acf/render_field_settings/type={$type->name}", [ $this, 'renderFieldSettings' ] );
			}
		}
	}

	/**
	 * Render the "Translations" dropdown setting for a field.
	 *
	 * @param array $field ACF field definition.
	 */
	public function renderFieldSettings( array $field ): void {
		// Hide when field group uses Language location rule.
		$fieldGroup = LocationLanguage::getFieldGroupFromField( $field );
		if ( ! empty( $fieldGroup ) && LocationLanguage::hasLanguageLocationRule( $fieldGroup ) ) {
			return;
		}

		$instructions =
			'<details>' .
				'<summary style="cursor: pointer; outline: none; margin-bottom: 5px;">' . esc_html__( 'View translation rules', 'hd-pll' ) . '</summary>' .
				'<div style="line-height: 1.6;">' .
				'<strong>' . esc_html__( 'Translate:', 'hd-pll' ) . '</strong> ' . esc_html__( 'Default. The value is seeded into new translations as a starting point — reference IDs and Repeater rows stay linked — then translated independently.', 'hd-pll' ) . '<br>' .
				'<strong>' . esc_html__( 'Ignore:', 'hd-pll' ) . '</strong> ' . esc_html__( 'For fields that should not be translated (e.g. slug, ID). The field stays fully independent per language with no copy, sync, or linking — you can still enter a value manually in each language.', 'hd-pll' ) .
				'</div>' .
			'</details>';

		acf_render_field_setting(
			$field,
			[
				'label'         => __( 'Translations', 'hd-pll' ),
				'instructions'  => $instructions,
				'name'          => 'translations',
				'type'          => 'select',
				'choices'       => [
					'translate' => __( 'Translate', 'hd-pll' ),
					'ignore'    => __( 'Ignore', 'hd-pll' ),
				],
				'default_value' => 'translate',
			],
			false
		);
	}
}
