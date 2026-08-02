<?php
/**
 * ACF Options Pages — Slide-over Panel view.
 *
 * Rendered in admin_footer. Shell is hidden by default,
 * shown via JS when a "Translate" button is clicked.
 *
 * @var string $defaultLangName Default language display name.
 *
 * @package HDPLL\ACF\Options
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="pll-options-slideover" class="pll-slideover">

	<!-- Backdrop -->
	<div class="pll-slideover__backdrop"></div>

	<!-- Panel -->
	<div class="pll-slideover__panel">

		<!-- Header -->
		<div class="pll-slideover__header">
			<h2 class="pll-slideover__title"></h2>
			<button type="button" class="pll-slideover__close" aria-label="<?php esc_attr_e( 'Close', 'hd-pll' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>

		<!-- Toolbar -->
		<div class="pll-slideover__toolbar">
			<button type="button" class="button pll-copy-default-btn" title="<?php esc_attr_e( 'Copy content from the default language to save time.', 'hd-pll' ); ?>">
				<span class="dashicons dashicons-admin-page"></span>
				<?php

				printf(
					/* translators: %s: default language name */
					wp_kses_post( __( 'Copy from <strong>%s</strong>', 'hd-pll' ) ),
					esc_html( $defaultLangName )
				);
				?>
			</button>
			<button type="button" class="button button-link-delete pll-remove-translation-btn" style="display: none;">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Remove Translation', 'hd-pll' ); ?>
			</button>
			<span class="spinner pll-slideover__spinner"></span>
		</div>

		<!-- Content (AJAX-populated) -->
		<div class="pll-slideover__content">
			<div class="pll-slideover__loading" style="text-align: center; padding: 40px;">
				<span class="spinner is-active" style="float: none;"></span>
			</div>
		</div>

		<!-- Footer -->
		<div class="pll-slideover__footer">
			<button type="button" class="button pll-slideover__cancel">
				<?php esc_html_e( 'Cancel', 'hd-pll' ); ?>
			</button>
			<button type="button" class="button button-primary pll-slideover__save">
				<span class="dashicons dashicons-saved" style="vertical-align: text-bottom; margin-right: 2px;"></span>
				<?php esc_html_e( 'Save', 'hd-pll' ); ?>
			</button>
		</div>
	</div>
</div>

<!-- wp.template: Loading spinner -->
<script type="text/html" id="tmpl-pll-options-loading">
	<div style="text-align: center; padding: 40px;">
		<span class="spinner is-active" style="float: none;"></span>
	</div>
</script>

<!-- wp.template: Error notice -->
<script type="text/html" id="tmpl-pll-options-error">
	<p class="notice notice-error" style="margin: 20px;">{{ data.message }}</p>
</script>
