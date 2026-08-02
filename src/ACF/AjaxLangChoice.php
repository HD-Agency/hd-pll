<?php
/**
 * ACF — AJAX Language Choice.
 *
 * When user changes language in the post metabox (classic editor),
 * refreshes ACF relationship/post_object/taxonomy fields via AJAX
 * with values translated to the new language.
 *
 * @package HDPLL\ACF
 */

declare(strict_types=1);

namespace HDPLL\ACF;

use PLL_Language;
use HDPLL\ACF\Entity\PostEntity;
use HDPLL\ACF\Strategy\CopyStrategy;

defined( 'ABSPATH' ) || exit;

final class AjaxLangChoice {

	/**
	 * Register hooks. Called from ACFIntegration::onAcfInit().
	 */
	public function onAcfInit(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_action( 'wp_ajax_acf_post_lang_choice', [ $this, 'handleAjax' ] );

		// Filter relationship/post_object queries to match current post's language.
		add_filter( 'acf/fields/relationship/query', [ $this, 'addLanguageToQuery' ], 10, 3 );
	}

	/**
	 * Enqueue JS on post editor pages for translated post types.
	 */
	public function enqueueScripts(): void {
		global $pagenow, $typenow;

		if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true )
			|| ! \PLL()->model->is_translated_post_type( $typenow )
		) {
			return;
		}

		wp_enqueue_script(
			'pll-acf-lang-choice',
			'',
			[ 'acf-input' ],
			HD_PLL_VERSION,
			true
		);

		// Inline script — listens for PLL language change event.
		wp_add_inline_script( 'pll-acf-lang-choice', $this->getInlineScript() );
	}

	/**
	 * Handle AJAX request for language change.
	 */
	public function handleAjax(): void {
		check_ajax_referer( 'pll_language', '_pll_nonce' );

		if ( ! isset( $_POST['fields'], $_POST['lang'], $_POST['post_id'] ) ) {
			wp_die( '0' );
		}

		$postId = absint( $_POST['post_id'] );
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( '-1' );
		}

		$language = \PLL()->model->get_language( sanitize_key( $_POST['lang'] ) );
		if ( ! $language instanceof PLL_Language ) {
			wp_die( '0' );
		}

		$response     = [];
		$fields       = explode( ',', sanitize_text_field( wp_unslash( $_POST['fields'] ) ) );
		$copyStrategy = new CopyStrategy();

		foreach ( $fields as $fieldKey ) {
			$fieldArray = acf_get_field( $fieldKey );
			if ( false === $fieldArray ) {
				continue;
			}

			$fromValue           = acf_get_value( $postId, $fieldArray );
			$fieldArray['value'] = $copyStrategy->execute(
				new PostEntity( $postId ),
				$fromValue,
				$fieldArray,
				[
					'target_language' => $language,
					'original_value'  => $fromValue,
				]
			);

			// Render with translated value in-memory only — no DB persistence.
			// ACF's acf_render_fields() skips acf_get_value() when $field['value'] !== null.
			ob_start();
			acf_render_fields( [ $fieldArray ] );
			$fieldWrap = ob_get_clean();

			$response[] = [
				'field_key'  => str_replace( '_', '-', $fieldKey ),
				'field_data' => false !== $fieldWrap ? $fieldWrap : '',
			];
		}

		wp_send_json( $response );
	}

	/**
	 * Add language filter to ACF relationship field queries.
	 *
	 * @param array      $args  WP_Query arguments.
	 * @param array      $field ACF field definition.
	 * @param int|string $acfId ACF post ID.
	 *
	 * @return array
	 */
	public function addLanguageToQuery( array $args, array $field, int|string $acfId ): array {
		if ( isset( $args['lang'] ) ) {
			return $args;
		}

		$decoded = acf_decode_post_id( $acfId );
		if ( 'post' !== $decoded['type'] ) {
			return $args;
		}

		$language = \PLL()->model->post->get_language( (int) $decoded['id'] );
		if ( $language instanceof PLL_Language ) {
			$args['lang'] = $language->slug;
		}

		return $args;
	}

	/**
	 * Inline JS to listen for PLL language change and refresh ACF fields.
	 */
	private function getInlineScript(): string {
		return <<<'JS'
document.addEventListener('onPostLangChoice', function(e) {
    var selectors = [
        '.acf-field-relationship',
        '.acf-field-post-object',
        '.acf-field-taxonomy'
    ];
    var fields = [];
    selectors.forEach(function(sel) {
        document.querySelectorAll(sel).forEach(function(el) {
            var key = el.getAttribute('data-key');
            if (key) fields.push(key);
        });
    });

    if (!fields.length) return;

    var postId = document.getElementById('post_ID')?.value;
    var nonce = document.querySelector('#_pll_nonce')?.value;
    if (!postId || !nonce) return;

    var data = new FormData();
    data.set('action', 'acf_post_lang_choice');
    data.set('lang', e.detail.lang.slug);
    data.set('fields', fields.join(','));
    data.set('post_id', postId);
    data.set('_pll_nonce', nonce);

    fetch(ajaxurl, { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(response) {
            response.forEach(function(res) {
                var field = document.querySelector('.acf-' + res.field_key);
                if (!field) return;
                var type = field.getAttribute('data-type');
                var parent = field.parentNode;
                field.outerHTML = res.field_data;
                var newField = parent ? parent.querySelector('.acf-' + res.field_key) : null;
                if (newField && typeof acf !== 'undefined') {
                    acf.do_action('ready_field/type=' + type, newField);
                }
            });
            if (typeof acf !== 'undefined') {
                acf.getFields({ type: 'post_object' });
            }
        });
});
JS;
	}
}
