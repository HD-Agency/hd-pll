<?php
/**
 * ACF Custom Location — Language.
 *
 * Registers a "Language" location type for ACF field groups,
 * allowing field groups to be shown only for specific languages.
 *
 * @package HDPLL\ACF
 */

declare(strict_types=1);

namespace HDPLL\ACF;

use ACF_Location;

defined( 'ABSPATH' ) || exit;

class LocationLanguage extends ACF_Location {

	/**
	 * Initialize location type properties.
	 */
	public function initialize(): void {
		$this->name     = 'language';
		$this->label    = __( 'Language', 'hd-pll' );
		$this->category = $this->label;
	}

	/**
	 * Match rule against current language.
	 *
	 * @param array $rule        Location rule.
	 * @param array $screen      Screen arguments.
	 * @param array $field_group Field group settings.
	 *
	 * @return bool
	 */
	public function match( $rule, $screen, $field_group ): bool {
		$language = pll_current_language();

		return empty( $language ) || $this->compare_to_rule( $language, $rule );
	}

	/**
	 * Return list of available languages as values for the rule.
	 *
	 * @param array $rule Location rule.
	 *
	 * @return array Language slug => name.
	 */
	public function get_values( $rule ): array {
		return array_combine(
			pll_languages_list(),
			pll_languages_list( [ 'fields' => 'name' ] )
		);
	}

	/**
	 * Check if a field group has a language location rule.
	 *
	 * @param array $fieldGroup Field group definition.
	 */
	public static function hasLanguageLocationRule( array $fieldGroup ): bool {
		if ( empty( $fieldGroup['location'] ) ) {
			return false;
		}

		foreach ( $fieldGroup['location'] as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			foreach ( $location as $rule ) {
				if ( is_array( $rule ) && isset( $rule['param'] ) && 'language' === $rule['param'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve the field group for a given field (including nested fields).
	 *
	 * @param array $field ACF field definition.
	 *
	 * @return array Field group array, or empty array on failure.
	 */
	public static function getFieldGroupFromField( array $field ): array {
		if ( 0 === $field['ID'] ) {
			$fieldGroup = acf_get_field_group( 0 );

			return ! empty( $fieldGroup ) ? $fieldGroup : [];
		}

		if ( empty( $field['parent'] ) ) {
			return [];
		}

		$fieldGroup = acf_get_field_group( $field['parent'] );
		if ( ! empty( $fieldGroup ) ) {
			return $fieldGroup;
		}

		$parentField = acf_get_field( $field['parent'] );

		return $parentField ? self::getFieldGroupFromField( $parentField ) : [];
	}
}
