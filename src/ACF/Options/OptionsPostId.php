<?php
/**
 * ACF Options Pages — Transparent Post ID Rewriting.
 *
 * Hooks into ACF at the core level to make all ACF APIs
 * (get_field, update_field, have_rows, the_field) work transparently
 * with per-language option storage — no theme code changes required.
 *
 * Storage key pattern: `{post_id}_{lang_slug}` (e.g. `theme-options_vi`).
 * Default language uses the unsuffixed post_id.
 *
 * Features:
 * - Transparent post_id rewriting via `acf/validate_post_id`.
 * - Repeater/flexible-content reference fallback via `acf/load_reference`.
 * - Smart value fallback via `acf/load_value` (scalar / array / repeater).
 * - Untranslated context API: switchToUntranslated() / restoreCurrentLang().
 * - Extensibility filters: excluded post_ids, per-field fallback control.
 *
 * @package HDPLL\ACF\Options
 */

declare(strict_types=1);

namespace HDPLL\ACF\Options;

defined( 'ABSPATH' ) || exit;

final class OptionsPostId {

	/**
	 * Stack for untranslated context (supports nested calls).
	 *
	 * @var array<int, true>
	 */
	private static array $untranslatedStack = [];

	/**
	 * Cached list of registered options page post_ids.
	 *
	 * @var string[]|null
	 */
	private static ?array $optionPageIds = null;

	/**
	 * Cached regex fragment for language slugs (e.g. (en|vi)).
	 *
	 * @var string|null
	 */
	private static ?string $langRegex = null;

	/**
	 * Register hooks. Called from ACFIntegration::onAcfInit().
	 */
	public function boot(): void {
		// Core-level post_id rewriting — covers ALL ACF APIs.
		add_filter( 'acf/validate_post_id', [ $this, 'rewritePostId' ] );

		// Fix repeater/have_rows reference lookups for localized post_ids.
		add_filter( 'acf/load_reference', [ $this, 'loadReference' ], 10, 3 );

		// Smart fallback to default-language value when localized value is absent.
		add_filter( 'acf/load_value', [ $this, 'loadValueFallback' ], 10, 3 );
	}

	/* ---------- acf/validate_post_id -------------------------------- */

	/**
	 * Append language suffix to registered options page post_ids.
	 *
	 * @param string $futurePostId Post ID after ACF's own validation.
	 *
	 * @return string
	 */
	public function rewritePostId( string $futurePostId ): string {
		// Skip: in untranslated context.
		if ( ! empty( self::$untranslatedStack ) ) {
			return $futurePostId;
		}

		// Skip: not a registered options page.
		if ( ! $this->isOptionsPage( $futurePostId ) ) {
			return $futurePostId;
		}

		// Skip: the generic 'options' key is localized by acf/settings/current_language.
		if ( 'options' === $this->stripLocaleSuffix( $futurePostId ) ) {
			return $futurePostId;
		}

		// Skip: already localized.
		if ( $this->isLocalized( $futurePostId ) ) {
			return $futurePostId;
		}

		// Skip: current language is the default language.
		$currentLang = pll_current_language();
		$defaultLang = pll_default_language();
		if ( ! $currentLang || $currentLang === $defaultLang ) {
			return $futurePostId;
		}

		// Skip: excluded by filter.
		$excluded = apply_filters( 'hd_pll_acf_options_excluded_post_ids', [] );
		if ( in_array( $futurePostId, (array) $excluded, true ) ) {
			return $futurePostId;
		}

		return $futurePostId . '_' . $currentLang;
	}

	/* ---------- acf/load_reference ---------------------------------- */

	/**
	 * Fall back to the unsuffixed post_id for field key lookups.
	 *
	 * Without this, `have_rows()` and repeater sub-field references fail for
	 * localized post_ids because the `_field_key` meta is stored under the
	 * original (unsuffixed) post_id.
	 *
	 * @param string|null $reference The field key reference (null/empty = not found yet).
	 * @param string      $fieldName Field name.
	 * @param string      $postId    ACF post ID (may be localized).
	 *
	 * @return string|null
	 */
	public function loadReference( ?string $reference, string $fieldName, string $postId ): ?string {
		if ( ! empty( $reference ) || ! $postId ) {
			return $reference;
		}

		$regex = $this->getLangRegex();
		if ( ! $regex ) {
			return $reference;
		}

		$strippedPostId = preg_replace( '/_(?:' . $regex . ')$/', '', $postId ) ?? $postId;
		if ( $strippedPostId === $postId ) {
			// Not localized — nothing to fall back to.
			return $reference;
		}

		// Temporarily remove self to avoid infinite recursion.
		remove_filter( 'acf/load_reference', [ $this, 'loadReference' ], 10 );
		$fallback = acf_get_reference( $fieldName, $strippedPostId );
		add_filter( 'acf/load_reference', [ $this, 'loadReference' ], 10, 3 );

		return $fallback ?? $reference;
	}

	/* ---------- acf/load_value -------------------------------------- */

	/**
	 * Fall back to the default-language value when the localized value is absent.
	 *
	 * Handles three cases:
	 * - Repeater: fallback when row count is 0.
	 * - Array: fallback when ALL elements are empty strings.
	 * - Scalar: fallback when value === ''.
	 *
	 * @param mixed  $value  Value loaded by ACF.
	 * @param string $postId ACF post ID.
	 * @param array  $field  ACF field definition.
	 *
	 * @return mixed
	 */
	public function loadValueFallback( mixed $value, string $postId, array $field ): mixed {
		// Only apply on frontend or AJAX (not admin save context).
		$shouldEnable = acf_is_ajax() || ( ! is_admin() && $this->isOptionsPage( $postId ) );

		/**
		 * Filters whether default-language fallback is active for this field.
		 *
		 * @param bool   $enable  Whether fallback is active.
		 * @param string $postId  ACF post ID.
		 * @param array  $field   ACF field definition.
		 */
		if ( ! apply_filters( 'hd_pll_acf_options_enable_fallback', $shouldEnable, $postId, $field ) ) {
			return $value;
		}

		$strippedPostId = $this->stripLocaleSuffix( $postId );

		// Not a localized post_id — nothing to fall back to.
		if ( $strippedPostId === $postId ) {
			return $value;
		}

		// Value is present — no fallback needed.
		// ACF repeater/flexible returns false when empty (not array/null).
		if ( $this->hasTranslatedValue( $value, $field ) ) {
			return $value;
		}

		// Load from the unsuffixed (default-language) post_id.
		remove_filter( 'acf/load_value', [ $this, 'loadValueFallback' ], 10 );
		$value = acf_get_value( $strippedPostId, $field );
		add_filter( 'acf/load_value', [ $this, 'loadValueFallback' ], 10, 3 );

		return $value;
	}

	/* ---------- Untranslated context API ---------------------------- */

	/**
	 * Push untranslated context — subsequent get_field() calls load default-language values.
	 * Must be paired with restoreCurrentLang().
	 *
	 * Usage:
	 *   OptionsPostId::switchToUntranslated();
	 *   $value = get_field( 'my_field', 'theme-options' ); // loads default lang
	 *   OptionsPostId::restoreCurrentLang();
	 */
	public static function switchToUntranslated(): void {
		self::$untranslatedStack[] = true;
	}

	/**
	 * Pop untranslated context — restores per-language loading.
	 */
	public static function restoreCurrentLang(): void {
		if ( ! empty( self::$untranslatedStack ) ) {
			array_pop( self::$untranslatedStack );
		}
	}

	/* ---------- Private helpers ------------------------------------- */

	/**
	 * Determine if a loaded value represents actual translated content.
	 *
	 * Returns true when the value should be used as-is (no fallback needed).
	 * Returns false when the value is absent/empty and should trigger fallback.
	 *
	 * Edge cases handled:
	 * - null → no value (ACF returns null when option meta key doesn't exist).
	 * - '' → treated as absent (ACF default for missing scalar meta).
	 * - false / 0 → empty indicator ONLY for repeater/flexible_content types.
	 *               For all other types (true_false, number, select), these are
	 *               valid user-set values and do NOT trigger fallback.
	 * - Non-empty array → has value.
	 * - Array with all empty-string elements → no value.
	 *
	 * @param mixed $value The value loaded by ACF.
	 * @param array $field The ACF field definition.
	 */
	private function hasTranslatedValue( mixed $value, array $field ): bool {
		// Null = meta key doesn't exist in wp_options.
		// Empty string = ACF's default for scalar fields with missing meta.
		// Note: false is NOT included here — it's a valid value for true_false fields.
		// For repeater/flexible, false/0 as empty indicators are handled below.
		if ( null === $value || '' === $value ) {
			return false;
		}

		// Repeater/flexible: ACF stores row count as numeric string.
		// '0' means explicitly empty, already handled above.
		// Integer 0 also means empty.
		$layoutTypes = [ 'repeater', 'flexible_content' ];
		if ( in_array( $field['type'] ?? '', $layoutTypes, true ) ) {
			if ( is_numeric( $value ) && (int) $value === 0 ) {
				return false;
			}

			if ( is_array( $value ) ) {
				return ! empty( $value );
			}

			// Non-zero numeric = has rows.
			return true;
		}

		// Generic array: fallback only when ALL elements are empty strings.
		if ( is_array( $value ) ) {
			$nonEmpty = array_filter( $value, static fn( $v ) => '' !== $v );
			return ! empty( $nonEmpty );
		}

		// Scalar with actual content.
		return true;
	}


	/**
	 * Check if a post_id belongs to a registered ACF options page.
	 *
	 * @param string $postId ACF post ID (may be localized).
	 */
	private function isOptionsPage( string $postId ): bool {
		$basePostId = $this->stripLocaleSuffix( $postId );

		if ( 'options' === $basePostId ) {
			return true;
		}

		$ids = $this->getOptionPageIds();
		if ( empty( $ids ) ) {
			return false;
		}

		return in_array( $basePostId, $ids, true );
	}

	/**
	 * Get all registered option page post_ids (cached per request).
	 *
	 * @return string[]
	 */
	private function getOptionPageIds(): array {
		if ( null === self::$optionPageIds ) {
			self::$optionPageIds = [];

			if ( function_exists( 'acf_get_options_pages' ) ) {
				foreach ( acf_get_options_pages() as $page ) {
					if ( ! empty( $page['post_id'] ) ) {
						self::$optionPageIds[] = $page['post_id'];
					}
				}
			}
		}

		return self::$optionPageIds;
	}

	/**
	 * Check if a post_id already has a PLL language suffix.
	 *
	 * @param string $postId ACF post ID.
	 */
	private function isLocalized( string $postId ): bool {
		$regex = $this->getLangRegex();
		if ( ! $regex ) {
			return false;
		}

		return 1 === preg_match( '/_(?:' . $regex . ')$/', $postId );
	}

	/**
	 * Strip the PLL language suffix from a post_id.
	 *
	 * @param string $postId ACF post ID.
	 *
	 * @return string Unsuffixed post_id.
	 */
	private function stripLocaleSuffix( string $postId ): string {
		$regex = $this->getLangRegex();
		if ( ! $regex ) {
			return $postId;
		}

		return preg_replace( '/_(?:' . $regex . ')$/', '', $postId ) ?? $postId;
	}

	/**
	 * Build and cache the regex fragment matching all PLL language slugs (e.g. `(en|vi)`).
	 *
	 * @return string Regex fragment, or empty string if PLL not ready.
	 */
	private function getLangRegex(): string {
		if ( null !== self::$langRegex ) {
			return self::$langRegex;
		}

		if ( ! function_exists( 'pll_languages_list' ) ) {
			self::$langRegex = '';
			return self::$langRegex;
		}

		$slugs = pll_languages_list(
			[
				'hide_empty' => false,
				'fields'     => 'slug',
			]
		);
		if ( empty( $slugs ) || ! is_array( $slugs ) ) {
			self::$langRegex = '';
			return self::$langRegex;
		}

		self::$langRegex = implode( '|', array_map( 'preg_quote', $slugs, array_fill( 0, count( $slugs ), '/' ) ) );

		return self::$langRegex;
	}
}
