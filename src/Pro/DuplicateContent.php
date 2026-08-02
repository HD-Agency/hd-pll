<?php
/**
 * Duplicate Content — UI and request handling for translation duplication.
 *
 * Adds a "copy" icon in the Polylang Languages metabox. Clicking it
 * creates a new translation post with content copied from the source
 * via TranslationPostModel.
 *
 * @package HDPLL\Pro
 */

declare(strict_types=1);

namespace HDPLL\Pro;

use HDPLL\Contracts\PllFeatureInterface;
use HDPLL\Models\TranslationPostModel;

defined( 'ABSPATH' ) || exit;

final class DuplicateContent implements PllFeatureInterface {

	public static function slug(): string {
		return 'duplicate_content';
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		if ( ! \is_admin() ) {
			return;
		}

		if ( $this->isReferencePluginActive() ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'Disable Duplicate Content Addon For Polylang before enabling HD duplicate content hooks.', 'hd-pll' ),
				HD_PLL_VERSION
			);
			return;
		}

		// Guard: if translation already exists, redirect to edit it (prevents duplicate auto-drafts).
		add_action( 'load-post-new.php', [ $this, 'guardExistingTranslation' ] );

		// Copy content AFTER Polylang sync (priority 5000) creates the translation link.
		add_filter( 'use_block_editor_for_post', [ $this, 'newPostTranslation' ], 6000 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueReplaceConfirm' ] );

		// Add copy icon in the Languages metabox translations table.
		foreach ( \PLL()->model->get_languages_list() as $lang ) {
			add_action(
				'pll_before_post_translation_' . $lang->slug,
				fn( string $post_type ) => $this->renderCopyIcon( $lang->slug, $post_type ),
			);
		}

		// Icon CSS.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdminCss' ] );
	}

	/**
	 * Enqueue admin CSS for Polylang translation icons.
	 */
	public function enqueueAdminCss(): void {
		wp_register_style( 'hd-pll-duplicate-content', false, [], HD_PLL_VERSION );
		wp_enqueue_style( 'hd-pll-duplicate-content' );
		wp_add_inline_style( 'hd-pll-duplicate-content', '.pll_icon_copy:before{content:"\f105"}.pll_icon_replace:before{content:"\f463"}' );
	}

	/**
	 * Detect duplicate-content-addon-for-polylang to avoid double handling.
	 */
	private function isReferencePluginActive(): bool {
		if ( class_exists( 'duplicateContentAddon', false ) ) {
			return true;
		}

		return function_exists( 'is_plugin_active' )
			&& is_plugin_active( 'duplicate-content-addon-for-polylang/duplicate-content-addon-for-polylang.php' );
	}

	/* ---------- Guard: Prevent Duplicate Auto-Drafts ---------- */

	/**
	 * If user clicks the copy icon but a translation already exists,
	 * redirect to edit the existing translation instead of creating a new auto-draft.
	 */
	public function guardExistingTranslation(): void {
		if ( empty( $_GET['hd_duplicate'] ) || empty( $_GET['from_post'] ) || empty( $_GET['new_lang'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'new-post-translation' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$from_post_id = absint( wp_unslash( $_GET['from_post'] ) );
		$new_lang     = sanitize_key( wp_unslash( $_GET['new_lang'] ) );

		$existing = (int) \pll_get_post( $from_post_id, $new_lang );
		if ( $existing > 0 && $existing !== $from_post_id ) {
			wp_safe_redirect( get_edit_post_link( $existing, 'raw' ) );
			exit;
		}
	}

	/* ---------- Copy Icon in Metabox ---------- */

	/**
	 * Render copy icon — only when no translation exists yet.
	 */
	private function renderCopyIcon( string $lang_slug, string $post_type ): void {
		global $post;

		if ( empty( $post ) || ! $post instanceof \WP_Post ) {
			echo '<td class="pll-column-icon"></td>';

			return;
		}

		// Don't show on unsaved posts — prevents duplicate auto-draft loop.
		if ( 'auto-draft' === $post->post_status || ! \PLL()->model->is_translated_post_type( $post_type ) ) {
			echo '<td class="pll-column-icon"></td>';

			return;
		}

		// Translation already exists — output replace icon.
		$translation_id = \PLL()->model->post->get_translation( $post->ID, $lang_slug );
		if ( $translation_id && $translation_id !== $post->ID ) {
			$url = add_query_arg(
				[
					'post'       => $translation_id,
					'action'     => 'edit',
					'from_post'  => $post->ID,
					'hd_replace' => 1,
				],
				admin_url( 'post.php' )
			);
			$url = add_query_arg( '_wpnonce', wp_create_nonce( "hd-replace-translation_{$post->ID}_{$translation_id}" ), $url );

			printf(
				'<td class="pll-column-icon"><a href="%s" title="%s" class="pll_icon_replace" data-hd-pll-replace-confirm="%s"></a></td>',
				esc_url( $url ),
				esc_attr__( 'Replace this translation with source content', 'hd-pll' ),
				esc_attr__( 'Replace this translation with the source content?', 'hd-pll' )
			);

			return;
		}

		$url = add_query_arg(
			[
				'post_type'    => $post_type,
				'from_post'    => $post->ID,
				'new_lang'     => $lang_slug,
				'hd_duplicate' => 1,
			],
			admin_url( 'post-new.php' )
		);

		// PLL nonce required to create the translation link.
		$url = add_query_arg( '_wpnonce', wp_create_nonce( 'new-post-translation' ), $url );

		printf(
			'<td class="pll-column-icon"><a href="%s" title="%s" class="pll_icon_copy"></a></td>',
			esc_url( $url ),
			esc_attr__( 'Duplicate content to this language', 'hd-pll' )
		);
	}

	/**
	 * Enqueue delegated confirmation for replace links.
	 */
	public function enqueueReplaceConfirm(): void {
		wp_register_script( 'hd-pll-duplicate-content', '', [], HD_PLL_VERSION, true );
		wp_add_inline_script(
			'hd-pll-duplicate-content',
			"document.addEventListener('click',function(event){var link=event.target.closest('[data-hd-pll-replace-confirm]');if(!link){return;}if(!window.confirm(link.getAttribute('data-hd-pll-replace-confirm'))){event.preventDefault();}});"
		);
		wp_enqueue_script( 'hd-pll-duplicate-content' );
	}

	/* ---------- Content Duplication ---------- */

	/**
	 * Copy content from source post to newly created translation.
	 *
	 * Runs at priority 6000 — AFTER Polylang sync (5000) has created
	 * the translation link and set the language.
	 */
	public function newPostTranslation( bool $is_block_editor ): bool {
		global $post;
		static $done = false;

		if ( $done || empty( $post ) ) {
			return $is_block_editor;
		}

		$context = $this->resolveCopyContext( $post );
		if ( empty( $context ) ) {
			return $is_block_editor;
		}

		$done  = true;
		$model = new TranslationPostModel();

		$result = $model->duplicate(
			$context['source_id'],
			$context['target_lang'],
			[],
			[
				'target_id' => $context['target_id'],
				'overwrite' => true,
				'status'    => $context['target']->post_status,
			]
		);

		if ( is_wp_error( $result ) ) {
			_doing_it_wrong( __METHOD__, esc_html( $result->get_error_message() ), HD_PLL_VERSION );
			return $is_block_editor;
		}

		// Refresh global $post so editor loads the copied content.
		$GLOBALS['post'] = get_post( $context['target_id'] );

		return $is_block_editor;
	}

	/**
	 * Resolve duplicate or replace request context.
	 *
	 * @return array{mode: string, source_id: int, target_id: int, target_lang: string, source: \WP_Post, target: \WP_Post}|null
	 */
	private function resolveCopyContext( \WP_Post $target_post ): ?array {
		if ( ! empty( $_GET['hd_duplicate'] ) ) {
			return $this->resolveDuplicateContext( $target_post );
		}

		if ( ! empty( $_GET['hd_replace'] ) ) {
			return $this->resolveReplaceContext( $target_post );
		}

		return null;
	}

	/**
	 * Resolve new translation duplicate context.
	 *
	 * @return array{mode: string, source_id: int, target_id: int, target_lang: string, source: \WP_Post, target: \WP_Post}|null
	 */
	private function resolveDuplicateContext( \WP_Post $target_post ): ?array {
		if ( empty( $_GET['from_post'] ) || empty( $_GET['new_lang'] ) ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'new-post-translation' ) ) {
			return null;
		}

		$source_id   = absint( $_GET['from_post'] );
		$target_lang = sanitize_key( wp_unslash( $_GET['new_lang'] ) );
		$source      = get_post( $source_id );

		if ( ! $source instanceof \WP_Post || ! current_user_can( 'read_post', $source_id ) ) {
			return null;
		}

		if ( $source_id === $target_post->ID || $source->post_type !== $target_post->post_type ) {
			return null;
		}

		if ( ! \PLL()->model->get_language( $target_lang ) ) {
			return null;
		}

		$linked_target = \pll_get_post( $source_id, $target_lang );
		if ( $linked_target && (int) $linked_target !== $target_post->ID ) {
			return null;
		}

		return [
			'mode'        => 'duplicate',
			'source_id'   => $source_id,
			'target_id'   => $target_post->ID,
			'target_lang' => $target_lang,
			'source'      => $source,
			'target'      => $target_post,
		];
	}

	/**
	 * Resolve existing translation replace context.
	 *
	 * @return array{mode: string, source_id: int, target_id: int, target_lang: string, source: \WP_Post, target: \WP_Post}|null
	 */
	private function resolveReplaceContext( \WP_Post $target_post ): ?array {
		if ( empty( $_GET['from_post'] ) || empty( $_GET['post'] ) ) {
			return null;
		}

		$source_id = absint( $_GET['from_post'] );
		$target_id = absint( $_GET['post'] );
		if ( $target_id !== $target_post->ID || $source_id === $target_id ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, "hd-replace-translation_{$source_id}_{$target_id}" ) ) {
			return null;
		}

		$source      = get_post( $source_id );
		$target_lang = \pll_get_post_language( $target_id );

		if ( ! $source instanceof \WP_Post || ! $target_lang ) {
			return null;
		}

		if ( ! current_user_can( 'read_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) {
			return null;
		}

		if ( $source->post_type !== $target_post->post_type ) {
			return null;
		}

		if ( \pll_get_post( $source_id, $target_lang ) !== $target_id ) {
			return null;
		}

		return [
			'mode'        => 'replace',
			'source_id'   => $source_id,
			'target_id'   => $target_id,
			'target_lang' => $target_lang,
			'source'      => $source,
			'target'      => $target_post,
		];
	}
}
