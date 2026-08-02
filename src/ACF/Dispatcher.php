<?php
/**
 * ACF × Polylang — Dispatcher.
 *
 * Central hook router for ACF field translation.
 * Routes ACF events (render, update, sync) to the appropriate Entity
 * based on the ACF post ID type (post, term, attachment).
 *
 * @package HDPLL\ACF
 */

declare(strict_types=1);

namespace HDPLL\ACF;

use WP_Term;
use PLL_Language;
use HDPLL\ACF\Entity\AbstractEntity;
use HDPLL\ACF\Entity\PostEntity;
use HDPLL\ACF\Entity\TermEntity;
use HDPLL\ACF\Entity\MediaEntity;
use HDPLL\ACF\Strategy\CopyStrategy;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {

	/**
	 * Register all hooks. Called from ACFIntegration::onAcfInit().
	 */
	public static function register(): void {

		// ── Remove ACF metas from PLL's own sync (we handle them ourselves) ──
		add_filter( 'pll_copy_post_metas', [ PostEntity::class, 'removeAcfMetasFromPllSync' ], 10, 4 );
		add_filter( 'pll_copy_term_metas', [ TermEntity::class, 'removeAcfMetasFromPllSync' ], 10, 4 );

		// ── Copy/sync on translation creation ──
		add_action( 'pll_post_synchronized', [ self::class, 'onPostSynchronized' ], 10, 4 );
		add_action( 'pll_duplicate_term', [ self::class, 'onDuplicateTerm' ], 10, 3 );

		// ── Real-time sync on field save ──
		add_filter( 'acf/update_value', [ self::class, 'update' ], 5, 3 );

		// ── Pre-fill fields on new translation page ──
		add_filter( 'acf/pre_render_field', [ self::class, 'renderField' ], 10, 2 );

		// ── Media field copy ──
		if ( \PLL()->options['media_support'] ) {
			add_action( 'pll_translate_media', [ self::class, 'copyMediaFields' ], 10, 3 );
		}
	}

	/* ---------- Hook callbacks ----------------------------------- */

	/**
	 * Pre-fill field value on new translation page.
	 *
	 * @param array      $field  ACF field definition.
	 * @param int|string $acfId  ACF post ID.
	 *
	 * @return array Modified field.
	 */
	public static function renderField( array $field, int|string $acfId ): array {
		$entity = self::getByAcfId( $acfId );

		return empty( $entity ) ? $field : $entity->renderField( $field );
	}

	/**
	 * Sync field value on save.
	 *
	 * @param mixed      $value  Field value being saved.
	 * @param int|string $acfId  ACF post ID.
	 * @param array      $field  ACF field definition.
	 *
	 * @return mixed Pass-through value.
	 */
	public static function update( mixed $value, int|string $acfId, array $field ): mixed {
		$entity = self::getByAcfId( $acfId );

		return empty( $entity ) ? $value : $entity->update( $value, $field );
	}

	/**
	 * Copy/sync ACF fields when PLL synchronizes a post.
	 *
	 * @param int    $postId   Source post ID.
	 * @param int    $trPostId Target post ID.
	 * @param string $lang     Target language slug.
	 * @param string $mode     'sync' or 'copy'.
	 */
	public static function onPostSynchronized( int $postId, int $trPostId, string $lang, string $mode ): void {
		( new PostEntity( $postId ) )->onPostSynchronized( $trPostId, $lang, $mode );
	}

	/**
	 * Copy ACF fields when PLL duplicates a term.
	 *
	 * @param int    $from Term ID of the source.
	 * @param int    $to   Term ID of the new translation.
	 * @param string $lang Language code.
	 */
	public static function onDuplicateTerm( int $from, int $to, string $lang ): void {
		$lang = \PLL()->model->get_language( $lang );
		if ( $lang instanceof PLL_Language ) {
			( new TermEntity( $from ) )->applyToAllFields(
				new CopyStrategy(),
				$to,
				[ 'target_language' => $lang ]
			);
		}
	}

	/**
	 * Copy ACF fields when PLL creates a media translation.
	 *
	 * @param int          $fromId         Source media ID.
	 * @param int          $toId           Target media ID.
	 * @param PLL_Language $targetLanguage Target language.
	 */
	public static function copyMediaFields( int $fromId, int $toId, PLL_Language $targetLanguage ): void {
		( new MediaEntity( $fromId ) )->copyFields( $toId, $targetLanguage );
	}

	/* ---------- Entity routing ----------------------------------- */

	/**
	 * Resolve ACF post ID to an Entity instance.
	 *
	 * @param int|string $acfId ACF post ID (e.g., 123, "term_5", "attachment_10").
	 *
	 * @return AbstractEntity|null Entity or null if not a translatable type.
	 */
	protected static function getByAcfId( int|string $acfId ): ?AbstractEntity {
		$decoded = acf_decode_post_id( $acfId );
		$id      = (int) $decoded['id'];

		return match ( $decoded['type'] ) {
			'post' => self::resolvePostEntity( $id ),
			'term' => self::resolveTermEntity( $id ),
			default => null, // 'option' type → handled by Phase 3 (Options module)
		};
	}

	/**
	 * Resolve a post ID to PostEntity or MediaEntity.
	 */
	private static function resolvePostEntity( int $id ): ?AbstractEntity {
		$postType = (string) get_post_type( $id );

		if ( \PLL()->options['media_support'] && 'attachment' === $postType ) {
			return new MediaEntity( $id );
		}

		if ( pll_is_translated_post_type( $postType ) ) {
			return new PostEntity( $id );
		}

		return null;
	}

	/**
	 * Resolve a term ID to TermEntity.
	 */
	private static function resolveTermEntity( int $id ): ?AbstractEntity {
		$term = get_term( $id );
		if ( $term instanceof WP_Term && pll_is_translated_taxonomy( $term->taxonomy ) ) {
			return new TermEntity( $id );
		}

		// New term creation: term_0 with new_lang and taxonomy in request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === $id && ! empty( $_GET['new_lang'] ) && ! empty( $_GET['taxonomy'] )
			&& pll_is_translated_taxonomy( sanitize_key( $_GET['taxonomy'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return new TermEntity( $id );
		}

		return null;
	}
}
