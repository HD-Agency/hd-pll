<?php
/**
 * ACF Options Pages — Admin Orchestrator.
 *
 * Adds language translation UI to ACF Options Pages:
 * - Language switcher bar in the Publish metabox sidebar
 * - Slide-over panel for editing translations
 * - AJAX endpoints for form rendering, saving, and copying
 *
 * @package HDPLL\ACF\Options
 */

declare(strict_types=1);

namespace HDPLL\ACF\Options;

use PLL_Language;

defined( 'ABSPATH' ) || exit;

final class OptionsAdmin {

	private const NONCE_ACTION = 'pll_acf_options_translate';

	/**
	 * Current options page definition (set on admin_load).
	 *
	 * @var array|null
	 */
	private ?array $currentPage = null;

	/**
	 * Register hooks. Called from ACFIntegration::onAcfInit().
	 */
	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}

		// AJAX endpoints (always register, regardless of current page).
		add_action( 'wp_ajax_hd_pll_acf_options_form', [ $this, 'ajaxRenderForm' ] );
		add_action( 'wp_ajax_hd_pll_acf_options_save', [ $this, 'ajaxSave' ] );
		add_action( 'wp_ajax_hd_pll_acf_options_copy', [ $this, 'ajaxCopy' ] );
		add_action( 'wp_ajax_hd_pll_acf_options_remove', [ $this, 'ajaxRemove' ] );

		// Detect current options page and inject UI.
		add_action( 'acf/options_page/submitbox_before_major_actions', [ $this, 'renderLanguageSwitcher' ] );
		add_action( 'admin_footer', [ $this, 'maybeRenderSlideOver' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybeEnqueueAssets' ], 20 );
	}

	/* ---------- UI Rendering ---------------------------------------- */

	/**
	 * Render language switcher inside the Publish metabox.
	 *
	 * @param array $page Current options page definition.
	 */
	public function renderLanguageSwitcher( array $page ): void {
		$languages = $this->getLanguages();
		if ( count( $languages ) < 2 ) {
			return;
		}

		$this->currentPage = $page;
		$defaultSlug       = pll_default_language();
		$currentSlug       = pll_current_language();
		$postId            = $page['post_id'];
		$basePostId        = $this->stripLocaleSuffix( $postId, $languages );
		$statusMap         = $this->buildStatusMap( $postId, $languages );

		// Context hint: show which language this native page applies to.
		$currentLang = PLL()->model->get_language( $currentSlug );
		if ( $currentLang instanceof PLL_Language ) {
			printf(
				'<p class="misc-pub-section" style="color:#646970;font-size:12px;">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: current language name */
						__( 'You are editing options for: %s', 'hd-pll' ),
						$currentLang->name
					)
				)
			);
		}

		include __DIR__ . '/views/language-switcher.php';
	}

	/**
	 * Render slide-over panel shell in admin_footer (only on options pages).
	 */
	public function maybeRenderSlideOver(): void {
		if ( empty( $this->currentPage ) ) {
			return;
		}

		$defaultLang     = $this->getDefaultLanguage();
		$defaultLangName = $defaultLang ? $defaultLang->name : '';

		include __DIR__ . '/views/slide-over.php';
	}

	/**
	 * Enqueue JS/CSS on options pages.
	 */
	public function maybeEnqueueAssets(): void {
		if ( ! $this->isOptionsPageScreen() ) {
			return;
		}

		// Ensure acf-input is available (it should be on options pages).
		if ( ! wp_script_is( 'acf-input', 'registered' ) ) {
			return;
		}

		$assetUrl = HD_PLL_URL . 'assets/';

		wp_enqueue_style( 'pll-acf-options-translate', $assetUrl . 'options-translate.css', [], HD_PLL_VERSION );
		wp_register_script( 'pll-acf-options-translate', $assetUrl . 'options-translate.js', [ 'acf-input', 'jquery', 'wp-util' ], HD_PLL_VERSION, true );
		wp_enqueue_script( 'pll-acf-options-translate' );

		wp_localize_script(
			'pll-acf-options-translate',
			'pllAcfOptions',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => [
					'translateTo'    => __( 'Translate to %s', 'hd-pll' ),
					'saving'         => __( 'Saving…', 'hd-pll' ),
					'copying'        => __( 'Copying…', 'hd-pll' ),
					'removing'       => __( 'Removing…', 'hd-pll' ),
					'saved'          => __( 'Saved!', 'hd-pll' ),
					'copied'         => __( 'Copied!', 'hd-pll' ),
					'removed'        => __( 'Removed!', 'hd-pll' ),
					'confirmRemove'  => __( 'Remove this translation? Fields will fall back to the default language.', 'hd-pll' ),
					'hasTranslation' => __( 'Has translation', 'hd-pll' ),
					'noTranslation'  => __( 'No translation', 'hd-pll' ),
					'error'          => __( 'An error occurred. Please try again.', 'hd-pll' ),
				],
			]
		);
	}

	/* ---------- AJAX Endpoints --------------------------------------- */

	/**
	 * AJAX: Render ACF form fields for target language.
	 */
	public function ajaxRenderForm(): void {
		$this->verifyAjaxNonce();
		[ $postId, $lang, $menuSlug ] = $this->getAjaxParams();
		$this->checkCapability( $menuSlug );

		// Set language context for the AJAX request
		$pllLang = PLL()->model->get_language( $lang );
		if ( $pllLang instanceof PLL_Language ) {
			PLL()->curlang = $pllLang;
		}
		acf_update_setting( 'current_language', $lang );

		$languages   = $this->getLanguages();
		$basePostId  = $this->stripLocaleSuffix( $postId, $languages );
		$defaultLang = pll_default_language();
		$langPostId  = ( $lang === $defaultLang ) ? $basePostId : "{$basePostId}_{$lang}";

		// Get field groups assigned to this options page.
		$fieldGroups = acf_get_field_groups( [ 'options_page' => $menuSlug ] );
		if ( empty( $fieldGroups ) ) {
			wp_send_json_error( [ 'message' => __( 'No field groups found.', 'hd-pll' ) ] );
		}

		// Render fields to buffer.
		ob_start();

		// ACF form data (hidden inputs for save context).
		acf_form_data(
			[
				'screen'  => 'options',
				'post_id' => $langPostId,
			]
		);

		foreach ( $fieldGroups as $fieldGroup ) {
			$fields = acf_get_fields( $fieldGroup );
			if ( empty( $fields ) ) {
				continue;
			}

			echo '<div class="acf-postbox" style="margin-bottom: 16px;">';
			echo '<h3 class="hndle" style="padding: 10px 12px; margin: 0; border-bottom: 1px solid #dcdcde; font-size: 14px;">';
			echo acf_esc_html( acf_get_field_group_title( $fieldGroup ) );
			echo '</h3>';
			echo '<div class="inside acf-fields" style="padding: 0;">';
			acf_render_fields( $fields, $langPostId, 'div', $fieldGroup['instruction_placement'] );
			echo '</div></div>';
		}

		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * AJAX: Save ACF form data for target language.
	 */
	public function ajaxSave(): void {
		$this->verifyAjaxNonce();
		[ $postId, $lang, $menuSlug ] = $this->getAjaxParams();
		$this->checkCapability( $menuSlug );

		// Set language context for the AJAX request
		$pllLang = PLL()->model->get_language( $lang );
		if ( $pllLang instanceof PLL_Language ) {
			PLL()->curlang = $pllLang;
		}
		acf_update_setting( 'current_language', $lang );

		$languages   = $this->getLanguages();
		$basePostId  = $this->stripLocaleSuffix( $postId, $languages );
		$defaultLang = pll_default_language();
		$langPostId  = ( $lang === $defaultLang ) ? $basePostId : "{$basePostId}_{$lang}";

		// Get the options page config for autoload setting.
		$page = function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $menuSlug ) : null;
		if ( $page && isset( $page['autoload'] ) ) {
			acf_update_setting( 'autoload', $page['autoload'] );
		}

		// Validate and save.
		if ( acf_validate_save_post() ) {
			// Disable Polylang "Copy" sync — our popup handles translation independently.
			add_filter( 'acf/load_field', [ $this, 'neutralizePolylangSync' ] );
			acf_save_post( $langPostId );
			remove_filter( 'acf/load_field', [ $this, 'neutralizePolylangSync' ] );

			wp_send_json_success( [ 'message' => __( 'Translation saved.', 'hd-pll' ) ] );
		}

		$errors = acf_get_validation_errors();
		wp_send_json_error(
			[
				'message' => __( 'Validation failed.', 'hd-pll' ),
				'errors'  => is_array( $errors ) ? $errors : [],
			]
		);
	}

	/**
	 * AJAX: Copy all field values from default language to target.
	 */
	public function ajaxCopy(): void {
		$this->verifyAjaxNonce();
		[ $postId, $lang, $menuSlug ] = $this->getAjaxParams();
		$this->checkCapability( $menuSlug );

		$defaultLang = pll_default_language();
		// Prevent copying default to default.
		if ( $lang === $defaultLang ) {
			wp_send_json_error( [ 'message' => __( 'Cannot copy to the default language.', 'hd-pll' ) ] );
		}

		$languages  = $this->getLanguages();
		$basePostId = $this->stripLocaleSuffix( $postId, $languages );
		$langPostId = "{$basePostId}_{$lang}";

		// 1. Temporarily switch context to default language to fetch original values
		$pllDefault = PLL()->model->get_language( $defaultLang );
		if ( $pllDefault instanceof PLL_Language ) {
			PLL()->curlang = $pllDefault;
		}
		acf_update_setting( 'current_language', $defaultLang );

		$fields = get_fields( $basePostId, false );
		if ( empty( $fields ) ) {
			wp_send_json_error( [ 'message' => __( 'No fields to copy.', 'hd-pll' ) ] );
		}

		// 2. Switch back context to target language to save copy
		$pllTarget = PLL()->model->get_language( $lang );
		if ( $pllTarget instanceof PLL_Language ) {
			PLL()->curlang = $pllTarget;
		}
		acf_update_setting( 'current_language', $lang );

		// Disable Polylang "Copy" sync — our popup handles translation independently.
		add_filter( 'acf/load_field', [ $this, 'neutralizePolylangSync' ] );

		// Copy each field value to the target post_id.
		foreach ( $fields as $fieldName => $value ) {
			update_field( $fieldName, $value, $langPostId );
		}

		remove_filter( 'acf/load_field', [ $this, 'neutralizePolylangSync' ] );

		wp_send_json_success( [ 'message' => __( 'Fields copied.', 'hd-pll' ) ] );
	}

	/**
	 * AJAX: Remove all stored field values for a translated options page.
	 */
	public function ajaxRemove(): void {
		$this->verifyAjaxNonce();
		[ $postId, $lang, $menuSlug ] = $this->getAjaxParams();
		$this->checkCapability( $menuSlug );

		// Validate language before setting context.
		$defaultLang = pll_default_language();
		if ( $lang === $defaultLang ) {
			wp_send_json_error( [ 'message' => __( 'Cannot remove the default language.', 'hd-pll' ) ] );
		}

		$pllLang = PLL()->model->get_language( $lang );
		if ( ! $pllLang instanceof PLL_Language ) {
			wp_send_json_error( [ 'message' => __( 'Invalid language.', 'hd-pll' ) ] );
		}

		// Set language context for the AJAX request.
		PLL()->curlang = $pllLang;
		acf_update_setting( 'current_language', $lang );

		$languages  = $this->getLanguages();
		$basePostId = $this->stripLocaleSuffix( $postId, $languages );

		$page = function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $menuSlug ) : null;
		if ( empty( $page ) || ( $page['post_id'] ?? '' ) !== $basePostId ) {
			wp_send_json_error( [ 'message' => __( 'Invalid options page.', 'hd-pll' ) ] );
		}

		$langPostId = "{$basePostId}_{$lang}";
		$meta       = (array) acf_get_meta( $langPostId );

		foreach ( array_keys( $meta ) as $key ) {
			if ( str_starts_with( $key, '_' ) ) {
				acf_delete_metadata( $langPostId, substr( $key, 1 ), true );
				continue;
			}

			acf_delete_metadata( $langPostId, $key, false );
		}

		wp_send_json_success( [ 'message' => __( 'Translation removed.', 'hd-pll' ) ] );
	}

	/* ---------- Polylang Sync Guard --------------------------------- */

	/**
	 * Override Polylang's "Copy" preference during our save operations.
	 *
	 * Prevents Polylang from duplicating/overwriting data across languages
	 * when saving via the translation popup. Our frontend fallback mechanism
	 * handles missing translations automatically.
	 *
	 * @param array $field ACF field settings.
	 *
	 * @return array
	 */
	public function neutralizePolylangSync( array $field ): array {
		if ( ! empty( $field['pll_preference'] ) ) {
			$field['pll_preference'] = '';
		}

		return $field;
	}

	/* ---------- Helpers ---------------------------------------------- */

	/**
	 * Verify AJAX nonce.
	 */
	private function verifyAjaxNonce(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'hd-pll' ) ], 403 );
		}
	}

	/**
	 * Verify options page capability.
	 *
	 * @param string $menuSlug Options page menu slug.
	 */
	private function checkCapability( string $menuSlug ): void {
		$capability = 'manage_options';
		if ( function_exists( 'acf_get_options_page' ) ) {
			$page = acf_get_options_page( $menuSlug );
			if ( ! empty( $page['capability'] ) ) {
				$capability = $page['capability'];
			}
		}

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'hd-pll' ) ], 403 );
		}
	}

	/**
	 * Extract and validate common AJAX parameters.
	 *
	 * @return array{0: string, 1: string, 2: string} [postId, lang, menuSlug]
	 */
	private function getAjaxParams(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyAjaxNonce().
		$postId = sanitize_text_field( wp_unslash( $_POST['post_id'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyAjaxNonce().
		$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyAjaxNonce().
		$menuSlug = sanitize_key( wp_unslash( $_POST['menu_slug'] ?? '' ) );

		if ( empty( $postId ) || empty( $lang ) || empty( $menuSlug ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing required parameters.', 'hd-pll' ) ] );
		}

		return [ $postId, $lang, $menuSlug ];
	}

	/**
	 * Get all PLL languages.
	 *
	 * @return PLL_Language[]
	 */
	private function getLanguages(): array {
		return PLL()->model->get_languages_list();
	}

	/**
	 * Get the default PLL language object.
	 */
	private function getDefaultLanguage(): ?PLL_Language {
		return PLL()->model->get_language( pll_default_language() ) ?: null;
	}

	/**
	 * Build a status map: ['lang_slug' => bool] indicating if the language has data.
	 *
	 * @param string         $postId    Base options page post_id.
	 * @param PLL_Language[] $languages PLL languages.
	 *
	 * @return array<string, bool>
	 */
	private function buildStatusMap( string $postId, array $languages ): array {
		$defaultSlug = pll_default_language();
		$basePostId  = $this->stripLocaleSuffix( $postId, $languages );
		$map         = [];

		foreach ( $languages as $lang ) {
			$checkId            = ( $lang->slug === $defaultSlug ) ? $basePostId : "{$basePostId}_{$lang->slug}";
			$map[ $lang->slug ] = $this->hasLanguageData( $checkId, $lang->slug, $languages, $defaultSlug );
		}

		return $map;
	}

	/**
	 * Strip language suffix from a post ID.
	 *
	 * @param string         $postId    Suffixed or unsuffixed post ID.
	 * @param PLL_Language[] $languages Registered languages.
	 *
	 * @return string
	 */
	private function stripLocaleSuffix( string $postId, array $languages ): string {
		$slugs = array_map( static fn( $l ) => $l->slug, $languages );
		if ( empty( $slugs ) ) {
			return $postId;
		}
		$pattern = implode( '|', array_map( 'preg_quote', $slugs, array_fill( 0, count( $slugs ), '/' ) ) );
		return preg_replace( '/_(?:' . $pattern . ')$/', '', $postId ) ?? $postId;
	}

	/**
	 * Determine if a language has data for the options page.
	 *
	 * @param string         $checkId     Option post ID to query.
	 * @param string         $langSlug    The language code of the option page.
	 * @param PLL_Language[] $languages   List of all languages.
	 * @param string         $defaultSlug Default language slug.
	 *
	 * @return bool
	 */
	private function hasLanguageData( string $checkId, string $langSlug, array $languages, string $defaultSlug ): bool {
		$meta = acf_get_meta( $checkId );
		if ( empty( $meta ) ) {
			return false;
		}

		if ( $langSlug !== $defaultSlug ) {
			return true;
		}

		// Default language checks: filter out other language prefix options from generic 'options'.
		foreach ( array_keys( (array) $meta ) as $key ) {
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			$isOtherLang = false;
			foreach ( $languages as $otherLang ) {
				if ( $otherLang->slug === $defaultSlug ) {
					continue;
				}

				if ( str_starts_with( $key, $otherLang->slug . '_' ) ) {
					$isOtherLang = true;
					break;
				}
			}

			if ( ! $isOtherLang ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the current admin screen is an ACF options page.
	 */
	private function isOptionsPageScreen(): bool {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return false;
		}

		$pages = acf_get_options_pages();
		if ( empty( $pages ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// ACF options page screen IDs follow patterns like:
		// "toplevel_page_{slug}" or "{parent}_page_{slug}".
		foreach ( $pages as $page ) {
			$slug = $page['menu_slug'] ?? '';
			if ( ! empty( $slug ) && str_contains( $screen->id, $slug ) ) {
				return true;
			}
		}

		return false;
	}
}
