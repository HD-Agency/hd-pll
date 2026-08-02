<?php
/**
 * ACF Entity — Abstract base for translatable ACF objects.
 *
 * Handles Post, Term, and Media objects. Provides core methods for
 * applying translation strategies, rendering pre-filled fields on
 * new translation pages, and real-time sync on field updates.
 *
 * Port of polylang-pro's Entity\Abstract_Object with PHP 8.1+ patterns.
 *
 * @package HDPLL\ACF\Entity
 */

declare(strict_types=1);

namespace HDPLL\ACF\Entity;

use PLL_Language;
use HDPLL\ACF\Dispatcher;
use HDPLL\ACF\Strategy\AbstractStrategy;
use HDPLL\ACF\Strategy\CopyStrategy;
use HDPLL\ACF\Strategy\SyncStrategy;

defined( 'ABSPATH' ) || exit;

abstract class AbstractEntity {

	/**
	 * Tracks updated fields to prevent reverse synchronization (A→B→A loop).
	 *
	 * @var string[]
	 */
	private static array $updated = [];

	/**
	 * Object ID (source or target).
	 */
	private int $id;

	public function __construct( int $id = 0 ) {
		$this->id = $id;
	}

	/* ---------- Public API ---------------------------------------- */

	/**
	 * Pre-fill field value on new translation page.
	 *
	 * Triggered by `acf/pre_render_field`. Copies field value from the
	 * source post and translates IDs (images, relationships, etc.).
	 *
	 * @param array $field ACF field definition.
	 *
	 * @return array Modified field with pre-filled value.
	 */
	public function renderField( array $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['new_lang'] ) ) {
			return $field;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang = \PLL()->model->get_language( sanitize_key( $_GET['new_lang'] ) );
		if ( empty( $lang ) ) {
			return $field;
		}

		$fromId = $this->getFromIdInRequest();
		if ( empty( $fromId ) || $fromId === $this->id ) {
			return $field;
		}

		$fromValue      = acf_get_value( static::acfId( $fromId ), $field );
		$originalValue  = $field['value'] ?? ( $field['default_value'] ?? null );
		$field['value'] = ( new CopyStrategy() )->execute(
			$this,
			$fromValue,
			$field,
			[
				'target_language' => $lang,
				'source_language' => \PLL()->model->{$this->getType()}->get_language( $fromId ),
				'original_value'  => $originalValue,
			]
		);

		return $field;
	}

	/**
	 * Sync field value to other translations on save.
	 *
	 * Triggered by `acf/update_value`. Applies SyncStrategy to all
	 * translations of the current object.
	 *
	 * @param mixed $value Field value being saved.
	 * @param array $field ACF field definition.
	 *
	 * @return mixed Pass-through value (unchanged).
	 */
	public function update( mixed $value, array $field ): mixed {
		// Anti-reverse-sync: skip if this field was already synced to this object.
		$storageKey = $this->getStorageKey( $this->id, $field['key'] );
		if ( in_array( $storageKey, self::$updated, true ) ) {
			return $value;
		}

		$strategy = new SyncStrategy( new CopyStrategy() );

		if ( ! $strategy->canExecute( $field ) ) {
			return $value;
		}

		$translations = \PLL()->model->{$this->getType()}->get_translations( $this->id );

		foreach ( $translations as $lang => $trId ) {
			if ( $this->id === $trId ) {
				continue;
			}

			$lang = \PLL()->model->get_language( $lang );
			if ( ! $lang instanceof PLL_Language ) {
				continue;
			}

			// Mark as updated before syncing to prevent reverse loop.
			self::$updated[] = $this->getStorageKey( $trId, $field['key'] );

			$acfId   = static::acfId( $trId );
			$trValue = acf_get_value( $acfId, $field );
			$trValue = $strategy->execute(
				$this,
				$value,
				$field,
				[
					'target_language' => $lang,
					'original_value'  => $trValue,
					'target_id'       => $trId,
				]
			);

			// Don't update if sub_fields were stripped (nothing to sync).
			if ( ! empty( $field['sub_fields'] ) && is_array( $trValue ) && empty( $trValue ) ) {
				continue;
			}

			acf_update_value( $trValue, $acfId, $field );
		}

		return $value;
	}

	/**
	 * Apply a strategy to all fields of this entity.
	 *
	 * Used during post synchronization (copy/sync mode).
	 *
	 * @param AbstractStrategy $strategy Strategy to execute.
	 * @param int              $toId     Target object ID.
	 * @param array            $args     Strategy arguments (target_language, etc.).
	 */
	public function applyToAllFields( AbstractStrategy $strategy, int $toId = 0, array $args = [] ): void {
		// Temporarily remove update hook to avoid double-processing.
		remove_filter( 'acf/update_value', [ Dispatcher::class, 'update' ], 5 );

		$fields = get_field_objects( static::acfId( $this->id ), false );

		if ( empty( $fields ) ) {
			$fields = [];
		}

		$args['update'] = ! isset( $args['update'] ) || (bool) $args['update'];

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( 'value', $field ) || null === $field['value'] ) {
				continue;
			}

			$args['original_value'] = acf_get_value( static::acfId( $toId ), $field );
			if ( null === $args['original_value'] ) {
				$args['original_value'] = $field['default_value'] ?? null;
			}

			$trValue = $strategy->execute(
				$this,
				$field['value'],
				$field,
				$args
			);

			if ( 0 < $toId && ! empty( $args['update'] ) ) {
				acf_update_value( $trValue, static::acfId( $toId ), $field );
			}
		}

		// Restore update hook.
		add_filter( 'acf/update_value', [ Dispatcher::class, 'update' ], 5, 3 );
	}

	/**
	 * Remove ACF metas from PLL's sync list.
	 *
	 * ACF fields are handled by our integration, not PLL's meta copy.
	 *
	 * @param string[] $metas List of meta keys.
	 * @param bool     $sync  Whether synchronizing or copying.
	 * @param int      $from  Source object ID.
	 * @param int      $to    Target object ID.
	 *
	 * @return string[] Filtered meta keys.
	 */
	public static function removeAcfMetasFromPllSync( array $metas, bool $sync, int|string $from, int|string $to ): array {
		$fromAcfId = static::acfId( (int) $from );
		$toAcfId   = static::acfId( (int) $to );

		$acfMetas = array_keys(
			array_merge(
				(array) acf_get_meta( $fromAcfId ),
				(array) acf_get_meta( $toAcfId )
			)
		);

		return array_diff( $metas, $acfMetas );
	}

	/* ---------- Accessors ---------------------------------------- */

	public function getId(): int {
		return $this->id;
	}

	/* ---------- Protected helpers -------------------------------- */

	/**
	 * Build a storage key for anti-reverse-sync tracking.
	 */
	protected function getStorageKey( int $id, string $key ): string {
		return static::acfId( $id ) . '|' . $key;
	}

	/* ---------- Abstract ----------------------------------------- */

	/**
	 * Transform object ID to ACF post ID.
	 *
	 * @return int|string ACF-compatible post ID.
	 */
	abstract protected static function acfId( int $id ): int|string;

	/**
	 * Get source object ID from the current HTTP request.
	 */
	abstract protected function getFromIdInRequest(): int;

	/**
	 * Get object type ('post' or 'term').
	 * Must match PLL()->model->{type} property name.
	 */
	abstract public function getType(): string;
}
