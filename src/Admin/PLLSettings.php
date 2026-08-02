<?php
/**
 * PLL Module Settings — Admin tab under Polylang settings.
 *
 * Adds a "HD Polylang" tab to Languages > Settings with:
 * - Pro feature toggles (TranslateSlugs, DuplicateContent, ShareSlugs, LocaleFallback).
 * - Translation scanner settings (theme/plugin/domain selection).
 * - Translation Import/Export (CSV, PO, XLIFF 2.1).
 *
 * @package HDPLL\Admin
 */

declare(strict_types=1);

namespace HDPLL\Admin;

use HDPLL\Plugin;
use HDPLL\ImportExport\FileFormatFactory;
use HDPLL\Translation\Scanner as TranslationSettings;

defined( 'ABSPATH' ) || exit;

final class PLLSettings {

	private const NONCE_ACTION = 'hd_pll_settings_save';
	private const NONCE_FIELD  = '_hd_pll_nonce';
	private const TAB_SLUG     = 'hd_pll';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'pll_settings_tabs', [ self::class, 'addTab' ] );
		add_action( 'pll_settings_active_tab_' . self::TAB_SLUG, [ self::class, 'renderTab' ] );
		add_action( 'admin_init', [ PLLSettingsSave::class, 'handle' ] );

		AiSettingsSection::init();

		// Override Polylang Free's preview modules when HD Pro features are active.
		add_filter( 'pll_settings_modules', [ self::class, 'overridePreviewModules' ], 20 );
	}

	/**
	 * Replace Polylang Free's "preview" settings modules with activated
	 * versions when HD PLL Pro features are enabled.
	 *
	 * Polylang Free registers `PLL_Settings_Preview_Share_Slug` and
	 * `PLL_Settings_Preview_Translate_Slugs` with `active_option = 'preview'`,
	 * which renders them as "Deactivated" with an upgrade notice.
	 * When HD provides the equivalent feature, we swap the class for a
	 * thin wrapper that sets `active_option = 'none'` → "Activated" status.
	 *
	 * @param string[] $modules Settings module class names.
	 *
	 * @return string[]
	 */
	public static function overridePreviewModules( array $modules ): array {
		if ( Plugin::isProActive() ) {
			return $modules; // Polylang Pro handles its own modules.
		}

		$settings     = Plugin::getCachedOptions();
		$replacements = [];

		if ( ! empty( $settings['share_slugs'] ) ) {
			$replacements['PLL_Settings_Preview_Share_Slug'] = HD_PLL_Settings_Share_Slug::class;
		}

		if ( ! empty( $settings['translate_slugs'] ) ) {
			$replacements['PLL_Settings_Preview_Translate_Slugs'] = HD_PLL_Settings_Translate_Slugs::class;
		}

		if ( empty( $replacements ) ) {
			return $modules;
		}

		foreach ( $modules as &$class ) {
			if ( isset( $replacements[ $class ] ) ) {
				$class = $replacements[ $class ];
			}
		}

		return $modules;
	}

	/**
	 * Add "HD Polylang" tab to Polylang Settings.
	 *
	 * @param array<string, string> $tabs Existing tabs.
	 *
	 * @return array<string, string>
	 */
	public static function addTab( array $tabs ): array {
		$tabs[ self::TAB_SLUG ] = __( 'HD Polylang', 'hd-pll' );

		return $tabs;
	}

	/**
	 * Render the settings tab content.
	 */
	public static function renderTab(): void {
		$pll_settings   = Plugin::getCachedOptions();
		$trans_settings = TranslationSettings::getSettings();
		$pro_features   = self::getProFeatureLabels();
		$themes         = self::getAvailableThemes();
		$plugins        = self::getAvailablePlugins();
		$show_pro       = ! Plugin::isProActive();
		$show_ttfp      = ! Plugin::isTTfPActive();
		$show_wc        = Plugin::isWoocommerceActive() && ! Plugin::isWCActive();

		// Flash messages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_key( $_GET['hd_pll_msg'] ?? '' );
		if ( $msg ) {
			$messages = [
				'saved'        => __( 'Settings saved.', 'hd-pll' ),
				'imported'     => sprintf(
					/* translators: %d: number of imported items */
					__( 'Translations imported: %d items.', 'hd-pll' ),
					absint( $_GET['hd_pll_count'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				),
				'export_error' => sanitize_text_field( $_GET['hd_pll_error'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'import_error' => sanitize_text_field( $_GET['hd_pll_error'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			];
			if ( isset( $messages[ $msg ] ) && '' !== $messages[ $msg ] ) {
				$noticeType = in_array( $msg, [ 'export_error', 'import_error' ], true ) ? 'error' : 'success';
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $noticeType ), esc_html( $messages[ $msg ] ) );
			}
		}

		?>
		<div class="form-wrap">
			<?php if ( $show_wc ) : ?>
			<div class="notice notice-success inline" style="margin: 0 0 20px 0;">
				<p><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> <strong><?php esc_html_e( 'WooCommerce Integration Active', 'hd-pll' ); ?>:</strong> <?php esc_html_e( 'Native translation support for products and emails is running automatically.', 'hd-pll' ); ?></p>
			</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="hd_pll_save" value="1">

				<?php if ( $show_pro ) : ?>
				<!-- Pro Features -->
				<h3><?php esc_html_e( 'Pro Features', 'hd-pll' ); ?></h3>
				<p class="description"><?php esc_html_e( 'These features replace Polylang Pro. Disable this section by activating the Polylang Pro plugin.', 'hd-pll' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $pro_features as $slug => $label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="hd_pll_features[<?php echo esc_attr( $slug ); ?>]" value="1"
									<?php checked( ! empty( $pll_settings[ $slug ] ) ); ?>>
								<?php esc_html_e( 'Enable', 'hd-pll' ); ?>
							</label>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
				<?php endif; ?>

				<hr>

				<!-- Admin Force Locale (T-B1) -->
				<h3><?php esc_html_e( 'Admin Dashboard Language', 'hd-pll' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Control the language used for the WordPress admin dashboard.', 'hd-pll' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin Language', 'hd-pll' ); ?></th>
						<td>
							<?php $force_locale = $pll_settings['admin_force_locale'] ?? 'content'; ?>
							<select name="hd_pll_admin_force_locale">
								<option value="content" <?php selected( $force_locale, 'content' ); ?>><?php esc_html_e( 'Content language (default)', 'hd-pll' ); ?></option>
								<option value="default" <?php selected( $force_locale, 'default' ); ?>><?php esc_html_e( 'Always use default language', 'hd-pll' ); ?></option>
								<option value="profile" <?php selected( $force_locale, 'profile' ); ?>><?php esc_html_e( 'Use user profile language', 'hd-pll' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Choose how the admin dashboard language is determined. "Content language" follows the content you are editing.', 'hd-pll' ); ?></p>
						</td>
					</tr>
				</table>

			<?php AiSettingsSection::render( $pll_settings ); ?>



				<?php if ( $show_ttfp ) : ?>
				<hr>

				<!-- Translation Scanner Settings -->
				<h3><?php esc_html_e( 'Theme & Plugin Translation', 'hd-pll' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Select themes and plugins to scan for translatable strings. Strings will appear in Languages > String translations.', 'hd-pll' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Themes', 'hd-pll' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $themes as $name => $display ) : ?>
								<label style="display: block; margin-bottom: 4px;">
									<input type="checkbox" name="hd_pll_translation[themes][]"
										value="<?php echo esc_attr( $name ); ?>"
										<?php checked( in_array( $name, $trans_settings['themes'], true ) ); ?>>
									<?php echo esc_html( $display ); ?>
								</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugins', 'hd-pll' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $plugins as $name => $display ) : ?>
								<label style="display: block; margin-bottom: 4px;">
									<input type="checkbox" name="hd_pll_translation[plugins][]"
										value="<?php echo esc_attr( $name ); ?>"
										<?php checked( in_array( $name, $trans_settings['plugins'], true ) ); ?>>
									<?php echo esc_html( $display ); ?>
								</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<hr>

				<!-- Translation Import/Export -->
				<h3><?php esc_html_e( 'Translation Import/Export', 'hd-pll' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Export string translations in CSV, PO, or XLIFF format. Import translated files back.', 'hd-pll' ); ?></p>
					<?php

					$languages     = \PLL()->model->get_languages_list();
					$defaultLang   = \PLL()->model->get_default_language();
					$strings       = class_exists( 'PLL_Admin_Strings' ) ? \PLL_Admin_Strings::get_strings() : [];
					$groups        = array_unique( wp_list_pluck( $strings, 'context' ) );
					$formatFactory = new FileFormatFactory();
					$exportFormats = $formatFactory->getSupportedFormats( 'strings' );

					?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Export Strings', 'hd-pll' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Target languages', 'hd-pll' ); ?></legend>
								<p><strong><?php esc_html_e( 'Target languages:', 'hd-pll' ); ?></strong></p>
								<?php foreach ( $languages as $language ) : ?>
									<?php if ( $defaultLang && $defaultLang->slug !== $language->slug ) : ?>
									<label style="display:inline-block;margin-right:12px;">
										<input type="checkbox" name="hd_pll_export_langs[]" value="<?php echo esc_attr( $language->slug ); ?>" checked>
										<?php echo esc_html( $language->name ); ?>
									</label>
									<?php endif; ?>
								<?php endforeach; ?>
							</fieldset>

							<?php if ( ! empty( $groups ) ) : ?>
							<p style="margin-top:8px;">
								<label for="hd-pll-export-group"><?php esc_html_e( 'Filter group:', 'hd-pll' ); ?></label>
								<select name="hd_pll_export_group" id="hd-pll-export-group">
									<option value=""><?php esc_html_e( 'All groups', 'hd-pll' ); ?></option>
									<?php foreach ( $groups as $group ) : ?>
									<option value="<?php echo esc_attr( $group ); ?>"><?php echo esc_html( $group ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<?php endif; ?>

							<p style="margin-top:8px;">
								<strong><?php esc_html_e( 'File format:', 'hd-pll' ); ?></strong><br>
								<?php foreach ( $exportFormats as $key => $fmt ) : ?>
								<label style="display:inline-block; margin-right:12px;">
									<input type="radio" name="hd_pll_export_format" value="<?php echo esc_attr( $key ); ?>"
										<?php checked( 'csv', $key ); ?>>
									<?php echo esc_html( $fmt['label'] ); ?>
								</label>
								<?php endforeach; ?>
							</p>

							<p>
								<button type="submit" name="hd_pll_export" value="1" class="button button-secondary">
									<?php esc_html_e( 'Download', 'hd-pll' ); ?>
								</button>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Import Translations', 'hd-pll' ); ?></th>
						<td>
							<input type="file" id="hd-pll-import-file" accept=".csv,.po,.xliff,.xlf">
							<button type="button" id="hd-pll-import-btn" class="button button-secondary" style="margin-left:8px;">
								<?php esc_html_e( 'Import', 'hd-pll' ); ?>
							</button>
							<span id="hd-pll-import-spinner" class="spinner" style="float:none;"></span>
							<div id="hd-pll-import-result" style="margin-top:8px;"></div>
							<p class="description"><?php esc_html_e( 'Upload a CSV, PO, or XLIFF file to import string translations.', 'hd-pll' ); ?></p>
						</td>
					</tr>
				</table>
				<?php endif; ?>

				<?php submit_button( __( 'Save Settings', 'hd-pll' ) ); ?>
			</form>

			<?php if ( $show_ttfp ) : ?>
			<script>
			(function(){
				var btn      = document.getElementById('hd-pll-import-btn');
				var fileEl   = document.getElementById('hd-pll-import-file');
				var spinner  = document.getElementById('hd-pll-import-spinner');
				var resultEl = document.getElementById('hd-pll-import-result');

				if (!btn || !fileEl) return;

				function showNotice(type, msg) {
					var div = document.createElement('div');
					div.className = 'notice notice-' + type + ' inline';
					var p = document.createElement('p');
					p.textContent = msg;
					div.appendChild(p);
					resultEl.innerHTML = '';
					resultEl.appendChild(div);
				}

				btn.addEventListener('click', function(){
					var file = fileEl.files[0];
					if (!file) {
						showNotice('warning', '<?php echo esc_js( __( 'Please select a file first.', 'hd-pll' ) ); ?>');
						return;
					}

					btn.disabled = true;
					spinner.classList.add('is-active');
					resultEl.innerHTML = '';

					fetch('<?php echo esc_url_raw( rest_url( ( defined( 'REST_NAMESPACE' ) ? REST_NAMESPACE : 'hd/v1' ) . '/pll/import' ) ); ?>', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/octet-stream',
							'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>',
							'X-Import-Filename': file.name
						},
						credentials: 'same-origin',
						body: file
					})
					.then(function(r){
						return r.text().then(function(text){
							if (!text) throw new Error('Empty response (HTTP ' + r.status + ')');
							try { return JSON.parse(text); }
							catch(e) { throw new Error('HTTP ' + r.status + ': ' + text.substring(0, 200)); }
						});
					})
					.then(function(data){
						var type = data.success ? 'success' : 'error';
						showNotice(type, data.message || '<?php echo esc_js( __( 'Unknown error.', 'hd-pll' ) ); ?>');
						if (data.success) fileEl.value = '';
					})
					.catch(function(err){
						showNotice('error', err.message);
					})
					.finally(function(){
						btn.disabled = false;
						spinner.classList.remove('is-active');
					});
				});
			})();
			</script>
			<?php endif; ?>
		</div>
		<?php
	}



	/* ---------- Helpers ---------- */

	/**
	 * Get Pro feature slugs and labels.
	 *
	 * @return array<string, string>
	 */
	public static function getProFeatureLabels(): array {
		return [
			'translate_slugs'   => __( 'Translate URL Slugs', 'hd-pll' ),
			'duplicate_content' => __( 'Duplicate Content on Translation', 'hd-pll' ),
			'share_slugs'       => __( 'Share Slugs Across Languages', 'hd-pll' ),
			'locale_fallback'   => __( 'Locale Fallback', 'hd-pll' ),
		];
	}

	/**
	 * Get available themes for scanning.
	 *
	 * @return array<string, string> name => display label
	 */
	private static function getAvailableThemes(): array {
		$result = [];

		foreach ( wp_get_themes() as $name => $theme ) {
			$textdomain = $theme->get( 'TextDomain' );
			$label      = $name;

			if ( $textdomain && $textdomain !== $name ) {
				$label .= sprintf( ' (TextDomain: %s)', $textdomain );
			}

			$result[ $name ] = $label;
		}

		return $result;
	}

	/**
	 * Get available plugins for scanning (excludes Polylang-related).
	 *
	 * @return array<string, string> name => display label
	 */
	private static function getAvailablePlugins(): array {
		$result  = [];
		$exclude = [ 'polylang', 'polylang-pro', 'theme-translation-for-polylang', 'polylang-theme-translation' ];
		$plugins = wp_get_active_and_valid_plugins();

		if ( \is_multisite() ) {
			$plugins = array_merge( $plugins, wp_get_active_network_plugins() );
		}

		$all_plugin_data = function_exists( 'get_plugins' ) ? get_plugins() : [];

		foreach ( $plugins as $plugin ) {
			$plugin_dir  = dirname( $plugin );
			$plugin_name = pathinfo( $plugin, PATHINFO_FILENAME );

			if ( in_array( $plugin_name, $exclude, true ) || $plugin_dir === WP_PLUGIN_DIR ) {
				continue;
			}

			$label = $plugin_name;

			// Try to get plugin full name.
			foreach ( $all_plugin_data as $key => $info ) {
				if ( pathinfo( $key, PATHINFO_FILENAME ) === $plugin_name ) {
					$full_name  = $info['Name'] ?? '';
					$textdomain = $info['TextDomain'] ?? '';

					if ( $full_name ) {
						$label = $full_name;
					}
					if ( $textdomain && $textdomain !== $plugin_name ) {
						$label .= sprintf( ' (TextDomain: %s)', $textdomain );
					}
					break;
				}
			}

			$result[ $plugin_name ] = $label;
		}

		return $result;
	}
}
