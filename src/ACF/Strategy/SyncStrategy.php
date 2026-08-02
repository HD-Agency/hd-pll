<?php
/**
 * ACF Strategy — Synchronize.
 *
 * Real-time sync strategy. Extends CopyStrategy to handle
 * synchronization on field updates.
 *
 * Propagates structural changes (repeater rows, group/flexible layouts) to
 * translations whose child fields are marked `translate`, while leaving each
 * translated leaf value to be edited independently.
 *
 * @package HDPLL\ACF\Strategy
 */

declare(strict_types=1);

namespace HDPLL\ACF\Strategy;

use PLL_Language;
use HDPLL\ACF\Entity\AbstractEntity;

defined( 'ABSPATH' ) || exit;

final class SyncStrategy extends CopyStrategy {

	private CopyStrategy $copy;

	public function __construct( CopyStrategy $copy ) {
		$this->copy = $copy;
	}

	/**
	 * Execute sync strategy.
	 *
	 * For taxonomy fields: also assigns terms to the target object.
	 * For other fields: delegates to parent (Copy) strategy.
	 */
	public function execute( AbstractEntity $entity, mixed $value, array $field, array $args = [] ): mixed {
		$args = wp_parse_args( $args, [ 'original_value' => null ] );

		if ( ! $this->canExecute( $field ) ) {
			return $args['original_value'];
		}

		if ( 'taxonomy' !== $field['type'] ) {
			return parent::execute( $entity, $value, $field, $args );
		}

		$targetLang = $args['target_language'] ?? null;
		if ( ! $targetLang instanceof PLL_Language ) {
			return $value;
		}

		$taxonomy = $field['taxonomy'] ?? '';
		if ( ! pll_is_translated_taxonomy( $taxonomy )
			|| ! $this->objectSupportsTaxonomy( $entity, $taxonomy, $args )
		) {
			return $value;
		}

		$value = $this->translateTerm( $value, $targetLang );

		// Assign terms to target object (ACF's save_post only fires for source).
		if ( ! empty( $field['save_terms'] ) && isset( $args['target_id'] ) ) {
			wp_set_object_terms( $args['target_id'], $value, $taxonomy );
		}

		return $value;
	}

	/**
	 * Check the concrete target/source post type instead of the generic PLL model type.
	 */
	private function objectSupportsTaxonomy( AbstractEntity $entity, string $taxonomy, array $args ): bool {
		$object_id = isset( $args['target_id'] ) ? (int) $args['target_id'] : $entity->getId();
		$post_type = 0 < $object_id ? get_post_type( $object_id ) : false;

		if ( is_string( $post_type ) && '' !== $post_type ) {
			return in_array( $taxonomy, get_object_taxonomies( $post_type ), true );
		}

		return in_array( $taxonomy, get_object_taxonomies( $entity->getType() ), true );
	}

	/**
	 * Structural fields can be synced when a child field is translatable —
	 * this keeps repeater rows and group/flexible layouts aligned across
	 * translations while their `translate` leaves are edited independently.
	 * Leaf fields never sync on their own (there is no `sync` setting).
	 */
	protected function canExecuteRecursive( array $field ): bool {
		return match ( $field['type'] ) {
			'clone', 'group', 'repeater' => $this->canExecuteWithTranslatableChildren( $field['sub_fields'] ?? [] ),
			'flexible_content'           => $this->canExecuteWithTranslatableLayouts( $field['layouts'] ?? [] ),
			default                      => false,
		};
	}

	/**
	 * Check if any sub_field has 'translate' or is syncable.
	 */
	private function canExecuteWithTranslatableChildren( array $subFields ): bool {
		foreach ( $subFields as $subField ) {
			if ( isset( $subField['translations'] ) && 'translate' === $subField['translations'] ) {
				return true;
			}

			if ( $this->canExecute( $subField ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check flexible_content layouts for translatable children.
	 */
	private function canExecuteWithTranslatableLayouts( array $layouts ): bool {
		foreach ( $layouts as $layout ) {
			if ( $this->canExecuteWithTranslatableChildren( $layout['sub_fields'] ?? [] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply on repeater rows with existing/new row detection.
	 *
	 * Existing rows (prefixed `row-{N}`) → use Sync strategy.
	 * New rows → use Copy strategy (fresh copy).
	 */
	protected function applyOnRows( AbstractEntity $entity, array $values, array $field, array $args = [] ): array {
		if ( empty( $field['sub_fields'] ) ) {
			return $values;
		}

		foreach ( $field['sub_fields'] as $subfield ) {
			foreach ( $values as $row => $subvalues ) {
				if ( preg_match( '/^row-(?P<incr>.+)$/', (string) $row, $matches ) ) {
					// Existing row — update with sync.
					if ( ! is_array( $subvalues ) ) {
						continue;
					}

					$i                   = $matches['incr'];
					$parent              = $this->getFieldKey( $field );
					$subfield['pll_key'] = $parent . '_' . $i . '_' . $subfield['key'];
					$values[ $row ]      = $this->applyOnSubfield(
						$entity,
						$subvalues,
						$subfield,
						[
							'target_language' => $args['target_language'],
							'original_value'  => $args['original_value'][ $i ] ?? null,
						]
					);

					continue;
				}

				// New row — copy with Copy strategy.
				$parent              = $this->getFieldKey( $field );
				$subfield['pll_key'] = $parent . '_' . $row . '_' . $subfield['key'];
				$values[ $row ]      = $this->copy->applyOnSubfield(
					$entity,
					$subvalues,
					$subfield,
					[
						'target_language' => $args['target_language'],
						'original_value'  => null,
					]
				);
			}
		}

		return $values;
	}
}
