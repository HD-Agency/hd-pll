<?php
/**
 * ACF Strategy — Abstract base for translation strategies.
 *
 * Dispatches by ACF field type, handling nested structures:
 * flexible_content, repeater, clone, group → recursive sub_fields.
 *
 * Port of polylang-pro's Strategy\Abstract_Strategy with PHP 8.1+ patterns.
 *
 * @package HDPLL\ACF\Strategy
 */

declare(strict_types=1);

namespace HDPLL\ACF\Strategy;

use HDPLL\ACF\Entity\AbstractEntity;

defined( 'ABSPATH' ) || exit;

abstract class AbstractStrategy {

	/**
	 * Cached can_execute results per field key.
	 *
	 * @var array<string, bool>
	 */
	private array $canExecuteCache = [];

	/* ---------- Public API ---------------------------------------- */

	/**
	 * Check if strategy can execute on a field.
	 */
	public function canExecute( array $field ): bool {
		$key = $field['key'];

		return $this->canExecuteCache[ $key ] ??= $this->canExecuteRecursive( $field );
	}

	/**
	 * Execute the strategy on a field.
	 *
	 * Dispatches to type-specific handlers for nested structures.
	 *
	 * @param AbstractEntity $entity Source entity.
	 * @param mixed          $value  Field value from source.
	 * @param array          $field  ACF field definition.
	 * @param array          $args   Strategy arguments.
	 *
	 * @return mixed Translated/copied value.
	 */
	public function execute( AbstractEntity $entity, mixed $value, array $field, array $args = [] ): mixed {
		$args = wp_parse_args( $args, [ 'original_value' => null ] );

		if ( ! $this->canExecute( $field ) ) {
			return $args['original_value'];
		}

		if ( empty( $value ) ) {
			return $value;
		}

		return match ( $field['type'] ) {
			'flexible_content' => is_array( $value )
				? $this->applyOnLayouts( $entity, $value, $field, array_merge( $args, [ 'original_value' => is_array( $args['original_value'] ) ? $args['original_value'] : [] ] ) )
				: $value,
			'repeater' => is_array( $value )
				? $this->applyOnRows( $entity, $value, $field, array_merge( $args, [ 'original_value' => is_array( $args['original_value'] ) ? $args['original_value'] : [] ] ) )
				: $value,
			'clone', 'group' => is_array( $value )
				? $this->applyOnGroup( $entity, $value, $field, array_merge( $args, [ 'original_value' => is_array( $args['original_value'] ) ? $args['original_value'] : [] ] ) )
				: $value,
			default => $this->apply( $entity, $value, $field, $args ),
		};
	}

	/* ---------- Abstract ----------------------------------------- */

	/**
	 * Apply strategy on a single (non-nested) field.
	 *
	 * @return mixed Translated value.
	 */
	abstract protected function apply( AbstractEntity $entity, mixed $value, array $field, array $args = [] ): mixed;

	/* ---------- Recursive field type handlers -------------------- */

	/**
	 * Recursively check if strategy can execute on nested fields.
	 */
	protected function canExecuteRecursive( array $field ): bool {
		return match ( $field['type'] ) {
			'flexible_content' => $this->canExecuteInLayouts( $field ),
			'clone', 'group', 'repeater' => $this->canExecuteInSubFields( $field ),
			default => false,
		};
	}

	private function canExecuteInLayouts( array $field ): bool {
		foreach ( $field['layouts'] ?? [] as $layout ) {
			foreach ( $layout['sub_fields'] ?? [] as $subField ) {
				if ( $this->canExecute( $subField ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function canExecuteInSubFields( array $field ): bool {
		foreach ( $field['sub_fields'] ?? [] as $subField ) {
			if ( $this->canExecute( $subField ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply strategy to repeater rows.
	 */
	protected function applyOnRows( AbstractEntity $entity, array $values, array $field, array $args = [] ): array {
		if ( empty( $field['sub_fields'] ) ) {
			return $values;
		}

		$originalValue = $args['original_value'];

		foreach ( $field['sub_fields'] as $subfield ) {
			foreach ( $values as $row => $subvalues ) {
				if ( ! is_array( $subvalues ) ) {
					continue;
				}

				$subfield['pll_key']    = $this->getFieldKey( $field ) . '_' . $row . '_' . $subfield['key'];
				$args['original_value'] = $originalValue[ $row ] ?? null;
				$values[ $row ]         = $this->applyOnSubfield(
					$entity,
					$subvalues,
					$subfield,
					$args
				);
			}
		}

		return $values;
	}

	/**
	 * Apply strategy to flexible content layouts.
	 */
	protected function applyOnLayouts( AbstractEntity $entity, array $values, array $field, array $args = [] ): array {
		foreach ( $field['layouts'] ?? [] as $layout ) {
			if ( ! empty( $layout['sub_fields'] ) ) {
				$values = $this->applyOnRows( $entity, $values, $layout, $args );
			}
		}

		return $values;
	}

	/**
	 * Apply strategy to group/clone sub_fields.
	 */
	protected function applyOnGroup( AbstractEntity $entity, array $values, array $field, array $args = [] ): array {
		foreach ( $field['sub_fields'] ?? [] as $subfield ) {
			$subfield['pll_key'] = $this->getFieldKey( $field ) . '_' . $subfield['key'];
			$values              = $this->applyOnSubfield( $entity, $values, $subfield, $args );
		}

		return $values;
	}

	/**
	 * Apply strategy to a single subfield within a parent.
	 */
	protected function applyOnSubfield( AbstractEntity $entity, array $subvalues, array $subfield, array $args = [] ): array {
		if ( empty( $subfield['parent'] ) ) {
			return $subvalues;
		}

		if ( ! $this->canExecute( $subfield ) ) {
			if ( isset( $args['original_value'][ $subfield['key'] ] ) ) {
				$subvalues[ $subfield['key'] ] = $args['original_value'][ $subfield['key'] ];
			} elseif ( isset( $args['original_value'][ $subfield['name'] ] ) ) {
				$subvalues[ $subfield['name'] ] = $args['original_value'][ $subfield['name'] ];
			} else {
				unset( $subvalues[ $subfield['name'] ], $subvalues[ $subfield['key'] ] );
			}

			return $subvalues;
		}

		$selector = null;
		if ( isset( $subvalues[ $subfield['name'] ] ) ) {
			$selector = $subfield['name'];
		} elseif ( isset( $subvalues[ $subfield['key'] ] ) ) {
			$selector = $subfield['key'];
		}

		if ( null === $selector ) {
			return $subvalues;
		}

		$args['original_value'] = $args['original_value'][ $selector ] ?? null;

		$subvalues[ $selector ] = $this->execute(
			$entity,
			$subvalues[ $selector ],
			$subfield,
			$args
		);

		return $subvalues;
	}

	/**
	 * Get the effective field key for nested field tracking.
	 */
	protected function getFieldKey( array $field ): string {
		return $field['pll_key'] ?? $field['__key'] ?? $field['key'];
	}
}
