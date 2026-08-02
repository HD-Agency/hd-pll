<?php
/**
 * Locale Fallback — Falls back to default language strings
 * when current language has no translation.
 *
 * This ensures partial translations still display original language
 * strings instead of untranslated string IDs.
 *
 * @package HDPLL\Pro
 */

declare(strict_types=1);

namespace HDPLL\Pro;

use HDPLL\Contracts\PllFeatureInterface;

defined( 'ABSPATH' ) || exit;

final class LocaleFallback implements PllFeatureInterface {

	public static function slug(): string {
		return 'locale_fallback';
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'override_load_textdomain', [ $this, 'overrideLoadTextdomain' ], 10, 4 );
	}

	/**
	 * Load the default language MO file as a fallback when
	 * the current language translation file doesn't exist.
	 *
	 * @param bool        $override Whether to override textdomain loading.
	 * @param string      $domain   Text domain.
	 * @param string      $mofile   Path to the MO file.
	 * @param string|null $locale   The locale.
	 *
	 * @return bool
	 */
	public function overrideLoadTextdomain( bool $override, string $domain, string $mofile, ?string $locale = null ): bool {
		if ( $override ) {
			return $override;
		}

		$default_lang = \PLL()->model->get_language( \PLL()->options['default_lang'] ?? '' );
		if ( ! $default_lang ) {
			return $override;
		}

		$current_locale = $locale ?: get_locale();
		$default_locale = $default_lang->locale;

		// No need for fallback if we're already in the default language.
		if ( $current_locale === $default_locale ) {
			return $override;
		}

		// If the current locale's MO file doesn't exist, load the default locale's MO.
		$suffix = $current_locale . '.mo';

		if ( ! is_readable( $mofile ) && str_ends_with( $mofile, $suffix ) ) {
			$fallback_mofile = substr( $mofile, 0, -strlen( $suffix ) ) . $default_locale . '.mo';

			if ( is_readable( $fallback_mofile ) ) {
				load_textdomain( $domain, $fallback_mofile );

				return true; // Override — we already loaded the fallback.
			}
		}

		return $override;
	}
}
