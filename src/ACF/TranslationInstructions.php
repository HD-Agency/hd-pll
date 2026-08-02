<?php
/**
 * ACF — Translation Instructions.
 *
 * Adds a "Translations Settings" tab to field group editor
 * with a toggle to display translation hints (translated/ignored)
 * below each field's label in the post editor.
 *
 * @package HDPLL\ACF
 */

declare(strict_types=1);

namespace HDPLL\ACF;

use HDPLL\ACF\Strategy\CopyStrategy;

defined( 'ABSPATH' ) || exit;

final class TranslationInstructions {

	private const TAB_NAME    = 'pll-instructions';
	private const SETTING_KEY = 'pll_display_field_instructions';

	/**
	 * Register hooks. Called from ACFIntegration::onAcfInit().
	 */
	public function onAcfInit(): void {
		add_filter( 'acf/field_group/additional_group_settings_tabs', [ $this, 'addSettingTab' ] );
		add_action( 'acf/field_group/render_group_settings_tab/' . self::TAB_NAME, [ $this, 'renderSetting' ] );
		add_filter( 'acf/pre_render_fields', [ self::class, 'appendInstructions' ] );
	}

	/**
	 * Add "Translations Settings" tab.
	 *
	 * @param array $tabs Existing tabs.
	 *
	 * @return array Modified tabs.
	 */
	public function addSettingTab( array $tabs ): array {
		$tabs[ self::TAB_NAME ] = __( 'Translations Settings', 'hd-pll' );

		return $tabs;
	}

	/**
	 * Render the toggle setting in the tab.
	 *
	 * @param array $fieldGroup Field group definition.
	 */
	public function renderSetting( array $fieldGroup ): void {
		if ( LocationLanguage::hasLanguageLocationRule( $fieldGroup ) ) {
			$fieldGroup[ self::SETTING_KEY ] = 0;

			acf_render_field_instructions(
				[
					'id'           => 'no_translations_settings',
					'required'     => 0,
					'label'        => esc_html__( 'No translations settings', 'hd-pll' ),
					'instructions' => esc_html__( 'No translations settings are available for field group with language location rules.', 'hd-pll' ),
				]
			);

			return;
		}

		acf_render_field_wrap(
			[
				'label'        => esc_html__( 'Display translation field instructions', 'hd-pll' ),
				'instructions' => esc_html__( 'When enabled, the translation field instructions will be displayed below the field label.', 'hd-pll' ),
				'type'         => 'true_false',
				'name'         => self::SETTING_KEY,
				'prefix'       => 'acf_field_group',
				'value'        => $fieldGroup[ self::SETTING_KEY ] ?? 1,
				'ui'           => 1,
			]
		);
	}

	/**
	 * Hook into field rendering to append translation instructions.
	 *
	 * @param array $fields Fields being rendered.
	 *
	 * @return array Unmodified fields.
	 */
	public static function appendInstructions( array $fields ): array {
		// Use array callable instead of first-class callable to allow WP to deduplicate the hook.
		add_filter( 'acf/prepare_field', [ self::class, 'getFieldInstructions' ] );

		return $fields;
	}

	/**
	 * Append translation instruction text to a field's instructions.
	 *
	 * @param array|false $field Field array or false.
	 *
	 * @return array|false
	 */
	public static function getFieldInstructions( array|false $field ): array|false {
		if ( ! is_array( $field ) ) {
			return $field;
		}

		$fieldGroup = acf_get_field_group( $field['parent'] );
		if ( ! $fieldGroup || empty( $fieldGroup[ self::SETTING_KEY ] ) ) {
			return $field;
		}

		$instruction = '<span style="font-size: 1.2em; vertical-align: middle;" class="dashicons dashicons-translation"></span> '
			. self::getInstruction( $field );

		$field['instructions'] = ! empty( $field['instructions'] )
			? $field['instructions'] . '<br>' . $instruction
			: $instruction;

		return $field;
	}

	/**
	 * Get the instruction text for a field based on its translation setting.
	 *
	 * @param array $field ACF field definition.
	 */
	public static function getInstruction( array $field ): string {
		if ( empty( $field ) ) {
			return '';
		}

		if ( empty( $field['translations'] ) ) {
			// Layout fields (group, repeater, etc.) have no direct setting —
			// infer from their child fields.
			if ( in_array( $field['type'], [ 'group', 'repeater', 'clone', 'flexible_content' ], true ) ) {
				static $strategy;
				$strategy ??= new CopyStrategy();

				if ( $strategy->canExecute( $field ) ) {
					return __( 'This field is translated.', 'hd-pll' );
				}
			}

			return __( 'This field is ignored.', 'hd-pll' );
		}

		return 'translate' === $field['translations']
			? __( 'This field is translated.', 'hd-pll' )
			: __( 'This field is ignored.', 'hd-pll' );
	}
}
