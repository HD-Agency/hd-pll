<?php
/**
 * AI Translation Settings Section — Render + AJAX + Save.
 *
 * Extracted from PLLSettings to own the AI translation configuration
 * lifecycle: rendering the admin form section, handling AJAX connection
 * tests, and persisting AI-specific option fields.
 *
 * @package HDPLL\Admin
 */

declare(strict_types=1);

namespace HDPLL\Admin;

use HD\Core\Encryptor;
use HD\Core\Helper;
use HDPLL\AI\AiClient;
use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class AiSettingsSection {

	private const AJAX_NONCE_ACTION = 'hd_pll_test_hdat_conn';

	/**
	 * Register AJAX hook.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_hd_pll_test_hdat_connection', [ self::class, 'ajaxTestConnection' ] );
	}

	/**
	 * Render AI translation settings.
	 *
	 * @param array<string, mixed> $settings PLL settings.
	 */
	public static function render( array $settings ): void {
		$languages    = function_exists( 'PLL' ) ? \PLL()->model->get_languages_list() : [];
		$post_types   = get_post_types( [ 'public' => true ], 'objects' );
		$target_langs = (array) ( $settings['ai_default_target_languages'] ?? [] );
		$contentTypes = (array) ( $settings['ai_content_types'] ?? [] );

		?>
		<hr>

		<h3>
			<?php esc_html_e( 'AI Translation', 'hd-pll' ); ?>
			<b style="font-size:14px;color:<?php echo AiClient::isAvailable() ? '#00a32a' : '#d63638'; ?>">
				<?php echo AiClient::isAvailable() ? esc_html__( 'HDAT route available', 'hd-pll' ) : esc_html__( 'HDAT route missing', 'hd-pll' ); ?>
			</b>
		</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable AI translation', 'hd-pll' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="hd_pll_ai_enabled" value="1" <?php checked( ! empty( $settings['ai_translation_enabled'] ) ); ?>>
						<?php esc_html_e( 'Enable', 'hd-pll' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'HDAT consumer token', 'hd-pll' ); ?></th>
				<td>
					<?php

					$token    = (string) ( $settings['ai_consumer_token'] ?? '' );
					$hasToken = '' !== $token;
					?>
					<div style="display:flex;gap:6px;align-items:center;">
						<input type="password" class="regular-text" id="hd-pll-token-input" name="hd_pll_ai_consumer_token" value="" placeholder="<?php echo $hasToken ? esc_attr__( 'Token saved (●●●●●●●●)', 'hd-pll' ) : ''; ?>" autocomplete="new-password" spellcheck="false">
						<button type="button" id="hd-pll-test-conn" class="button button-secondary"<?php echo $hasToken ? ' disabled' : ''; ?>>
							<?php echo $hasToken ? esc_html__( 'Update', 'hd-pll' ) : esc_html__( 'Test Connection', 'hd-pll' ); ?>
						</button>
						<span id="hd-pll-conn-status" style="font-weight:600;"></span>
					</div>
					<p class="description">
						<?php

						echo $hasToken
							? esc_html__( 'Enter a new token to replace the current one.', 'hd-pll' )
							: esc_html__( 'Paste your HDAT consumer token to connect.', 'hd-pll' );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Target languages', 'hd-pll' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Target languages', 'hd-pll' ); ?></span></legend>
						<?php foreach ( (array) $languages as $language ) : ?>
							<?php if ( is_object( $language ) ) : ?>
							<label style="display:inline-block;margin-right:12px;margin-bottom:8px;">
								<input type="checkbox" name="hd_pll_ai_target_languages[]" value="<?php echo esc_attr( $language->slug ); ?>" <?php checked( in_array( $language->slug, $target_langs, true ) ); ?>>
								<?php echo esc_html( $language->name ); ?>
							</label>
							<?php endif; ?>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Select the languages to generate translations for when performing bulk actions.', 'hd-pll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bulk draft status', 'hd-pll' ); ?></th>
				<td>
					<select name="hd_pll_ai_post_status">
						<option value="draft" <?php selected( $settings['ai_default_post_status'] ?? 'draft', 'draft' ); ?>><?php esc_html_e( 'Draft', 'hd-pll' ); ?></option>
						<option value="pending" <?php selected( $settings['ai_default_post_status'] ?? 'draft', 'pending' ); ?>><?php esc_html_e( 'Pending review', 'hd-pll' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Bulk AI translation always creates translated posts in the selected status. Editor assist remains preview-only inside the editor.', 'hd-pll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Content types', 'hd-pll' ); ?></th>
				<td>
					<?php foreach ( $post_types as $type => $object ) : ?>
						<label style="display:inline-block;margin-right:12px;">
							<input type="checkbox" name="hd_pll_ai_content_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $contentTypes, true ) ); ?>>
							<?php echo esc_html( $object->labels->name ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fields', 'hd-pll' ); ?></th>
				<td>
					<label><input type="checkbox" name="hd_pll_ai_translate_title" value="1" <?php checked( ! empty( $settings['ai_translate_title'] ) ); ?>> <?php esc_html_e( 'Title', 'hd-pll' ); ?></label>
					<label><input type="checkbox" name="hd_pll_ai_translate_content" value="1" <?php checked( ! empty( $settings['ai_translate_content'] ) ); ?>> <?php esc_html_e( 'Content', 'hd-pll' ); ?></label>
					<label><input type="checkbox" name="hd_pll_ai_translate_excerpt" value="1" <?php checked( ! empty( $settings['ai_translate_excerpt'] ) ); ?>> <?php esc_html_e( 'Excerpt', 'hd-pll' ); ?></label>
					<label><input type="checkbox" name="hd_pll_ai_translate_slug" value="1" <?php checked( ! empty( $settings['ai_translate_slug'] ) ); ?>> <?php esc_html_e( 'Slug', 'hd-pll' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Meta keys', 'hd-pll' ); ?></th>
				<td>
					<textarea name="hd_pll_ai_meta_keys" rows="3" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $settings['ai_translate_meta_keys'] ?? [] ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One key per line. List of custom field keys to translate (e.g., _yoast_wpseo_title, rank_math_description). Do not add fields storing IDs, media, or arrays.', 'hd-pll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Limits', 'hd-pll' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Limits', 'hd-pll' ); ?></span></legend>
						<label style="display: block; margin-bottom: 8px;">
							<input type="number" min="1" step="1" name="hd_pll_ai_max_units" value="<?php echo esc_attr( (string) ( $settings['ai_max_units_per_request'] ?? 25 ) ); ?>" class="small-text">
							<?php esc_html_e( 'Maximum translation units per API request', 'hd-pll' ); ?>
						</label>
						<label style="display: block; margin-bottom: 8px;">
							<input type="number" min="1000" step="100" name="hd_pll_ai_max_chars" value="<?php echo esc_attr( (string) ( $settings['ai_max_chars_per_request'] ?? 12000 ) ); ?>" style="width: 80px;">
							<?php esc_html_e( 'Maximum characters per API request', 'hd-pll' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Controls chunking. Long posts will be split into smaller API requests to prevent timeouts or token limits.', 'hd-pll' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Glossary', 'hd-pll' ); ?></th>
				<td>
					<textarea name="hd_pll_ai_glossary_terms" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) ( $settings['ai_glossary_terms'] ?? [] ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One term per line. AI will be forced to preserve these terms (e.g. brand names, SKUs) without translating them.', 'hd-pll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Editor assist', 'hd-pll' ); ?></th>
				<td>
					<label><input type="checkbox" name="hd_pll_ai_editor_assist" value="1" <?php checked( ! empty( $settings['ai_editor_assist_enabled'] ) ); ?>> <?php esc_html_e( 'Enable editor-time assist', 'hd-pll' ); ?></label>
					<p class="description"><?php esc_html_e( 'When creating a new translation in the Editor, automatically run AI in the background to pre-fill the title and content. You can review before publishing.', 'hd-pll' ); ?></p>
				</td>
			</tr>
		</table>
		<script>
		(function(){
			var input  = document.getElementById('hd-pll-token-input');
			var btn    = document.getElementById('hd-pll-test-conn');
			var status = document.getElementById('hd-pll-conn-status');
			var nonce  = '<?php echo esc_js( wp_create_nonce( self::AJAX_NONCE_ACTION ) ); ?>';
			var saved  = <?php echo $hasToken ? 'true' : 'false'; ?>;

			if (!input || !btn || !status) return;

			function doTest(token) {
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Testing…', 'hd-pll' ) ); ?>';
				status.textContent = '<?php echo esc_js( __( 'Testing…', 'hd-pll' ) ); ?>';
				status.style.color = '#646970';

				var fd = new FormData();
				fd.append('action', 'hd_pll_test_hdat_connection');
				fd.append('nonce', nonce);
				fd.append('token', token || '');

				fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(r){
						if (r.success) {
							status.textContent = '✓ ' + (r.data.message || 'OK');
							status.style.color = '#00a32a';
							if (token) {
								input.value = '';
								input.placeholder = '<?php echo esc_js( __( 'Token saved (●●●●●●●●)', 'hd-pll' ) ); ?>';
								saved = true;
							}
							btn.textContent = '<?php echo esc_js( __( 'Update', 'hd-pll' ) ); ?>';
							btn.disabled = true;
						} else {
							status.textContent = r.data.message || '<?php echo esc_js( __( 'Connection failed.', 'hd-pll' ) ); ?>';
							status.style.color = '#d63638';
							btn.textContent = saved ? '<?php echo esc_js( __( 'Update', 'hd-pll' ) ); ?>' : '<?php echo esc_js( __( 'Test Connection', 'hd-pll' ) ); ?>';
							btn.disabled = !input.value.trim() && !saved;
						}
					})
					.catch(function(){
						status.textContent = '<?php echo esc_js( __( 'Network error.', 'hd-pll' ) ); ?>';
						status.style.color = '#d63638';
						btn.textContent = saved ? '<?php echo esc_js( __( 'Update', 'hd-pll' ) ); ?>' : '<?php echo esc_js( __( 'Test Connection', 'hd-pll' ) ); ?>';
						btn.disabled = false;
					});
			}

			// Enable/disable button based on input.
			input.addEventListener('input', function(){
				btn.disabled = !input.value.trim();
			});

			btn.addEventListener('click', function(){
				var token = input.value.trim();
				if (!token && !saved) return;
				doTest(token);
			});

			// Auto-test on page load when token is saved.
			if (saved) {
				doTest('');
			}
		})();
		</script>
		<?php
	}

	/**
	 * Save AI-specific fields from the form submission.
	 *
	 * @param array<string, mixed> $option Mutable option array.
	 * @param array<string, mixed> $post   $_POST data.
	 */
	public static function saveFields( array &$option, array $post ): void {
		$option['ai_translation_enabled'] = ! empty( $post['hd_pll_ai_enabled'] );
		$option['ai_consumer_token']      = sanitize_text_field( wp_unslash( $post['hd_pll_ai_consumer_token'] ?? '' ) );

		// Preserve existing token when the masked UI sends an empty field.
		if ( '' === $option['ai_consumer_token'] ) {
			$existing                    = Plugin::getCachedOptions();
			$option['ai_consumer_token'] = $existing['ai_consumer_token'] ?? '';
		} else {
			$option['ai_consumer_token'] = Encryptor::encode( $option['ai_consumer_token'] ) ?? $option['ai_consumer_token'];
		}

		$option['ai_default_target_languages'] = array_map( 'sanitize_key', (array) ( $post['hd_pll_ai_target_languages'] ?? [] ) );
		$option['ai_default_commit_mode']      = 'draft';
		$option['ai_default_post_status']      = sanitize_key( $post['hd_pll_ai_post_status'] ?? 'draft' );
		$option['ai_content_types']            = array_map( 'sanitize_key', (array) ( $post['hd_pll_ai_content_types'] ?? [] ) );
		$option['ai_translate_title']          = ! empty( $post['hd_pll_ai_translate_title'] );
		$option['ai_translate_content']        = ! empty( $post['hd_pll_ai_translate_content'] );
		$option['ai_translate_excerpt']        = ! empty( $post['hd_pll_ai_translate_excerpt'] );
		$option['ai_translate_slug']           = ! empty( $post['hd_pll_ai_translate_slug'] );
		$option['ai_translate_meta_keys']      = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/\r\n|\r|\n/', (string) ( $post['hd_pll_ai_meta_keys'] ?? '' ) ) ?: [] ) ) );
		$option['ai_glossary_terms']           = array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) ( $post['hd_pll_ai_glossary_terms'] ?? '' ) ) ?: [] ) ) ) );
		$option['ai_max_units_per_request']    = max( 1, absint( $post['hd_pll_ai_max_units'] ?? 25 ) );
		$option['ai_max_chars_per_request']    = max( 1000, absint( $post['hd_pll_ai_max_chars'] ?? 12000 ) );
		$option['ai_editor_assist_enabled']    = ! empty( $post['hd_pll_ai_editor_assist'] );
	}

	/**
	 * AJAX: Test HDAT connection (optionally save a new token first).
	 */
	public static function ajaxTestConnection(): void {
		if ( ! check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'hd-pll' ) ], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'hd-pll' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$newToken = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$settings = Plugin::getCachedOptions();
		$token    = $newToken;

		// If a new token was provided, encrypt and save it.
		if ( '' !== $newToken ) {
			$stored                      = Helper::getOption( Plugin::optionKey(), [] );
			$stored['ai_consumer_token'] = Encryptor::encode( $newToken ) ?? $newToken;
			Helper::updateOption( Plugin::optionKey(), $stored );
			Plugin::resetCache();
			$token = $newToken;
		} else {
			// Decrypt stored token; fall back to raw value for legacy plain-text tokens.
			$stored = (string) ( $settings['ai_consumer_token'] ?? '' );
			$token  = '' !== $stored ? ( Encryptor::decode( $stored ) ?? $stored ) : '';
		}

		if ( '' === $token ) {
			wp_send_json_error( [ 'message' => __( 'Consumer token is empty.', 'hd-pll' ) ] );
		}

		// Test connection via internal REST request to HDAT models endpoint.
		$request = new \WP_REST_Request( 'GET', '/hdat/v1/models' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );

		$response = class_exists( \HDAT\Auth\InternalRequestContext::class )
			? \HDAT\Auth\InternalRequestContext::run( static fn() => rest_do_request( $request ) )
			: rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		if ( $response->is_error() || $response->get_status() >= 400 ) {
			$data = $response->get_data();
			$msg  = $data['error']['message'] ?? $data['message'] ?? __( 'Connection failed.', 'hd-pll' );
			wp_send_json_error( [ 'message' => $msg ] );
		}

		wp_send_json_success( [ 'message' => __( 'Connection successful!', 'hd-pll' ) ] );
	}
}
