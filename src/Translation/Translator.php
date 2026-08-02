<?php
/**
 * Gettext filter translator.
 *
 * Hooks into WordPress gettext filters to translate strings
 * from selected themes/plugins using Polylang MO translations.
 *
 * @package HDPLL\Translation
 */

declare(strict_types=1);

namespace HDPLL\Translation;

defined( 'ABSPATH' ) || exit;

final class Translator {

	private \PLL_MO|\NOOP_Translations $mo;
	private ?\PLL_Language $language;

	public function __construct( \PLL_Language $language ) {
		$this->language = $language;

		if ( class_exists( 'PLL_MO' ) ) {
			$this->mo = new \PLL_MO();
			$this->mo->import_from_db( $language );
		} else {
			$this->mo = new \NOOP_Translations();
		}

		add_filter( 'gettext', [ $this, 'gettext' ], 99, 3 );
		add_filter( 'ngettext', [ $this, 'ngettext' ], 99, 5 );
		add_filter( 'gettext_with_context', [ $this, 'gettextWithContext' ], 99, 4 );
		add_filter( 'plugin_locale', [ $this, 'pluginLocale' ], 99, 2 );
	}

	/**
	 * Filter: gettext.
	 */
	public function gettext( string $translation, string $text, string $domain ): string {
		if ( $this->shouldTranslate( $domain ) ) {
			$pll = $this->mo->translate( $text );
			if ( $pll !== $text ) {
				return $pll;
			}
		}

		return $translation;
	}

	/**
	 * Filter: ngettext (singular/plural).
	 */
	public function ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
		if ( $this->shouldTranslate( $domain ) ) {
			$result = $this->mo->translate_plural( $single, $plural, $number );

			// translate_plural returns the original singular/plural on miss.
			if ( $result !== $single && $result !== $plural ) {
				return $result;
			}
		}

		return $translation;
	}

	/**
	 * Filter: gettext_with_context.
	 */
	public function gettextWithContext( string $translation, string $text, string $context, string $domain ): string {
		if ( $this->shouldTranslate( $domain ) ) {
			$pll = $this->mo->translate( $text );
			if ( $pll !== $text ) {
				return $pll;
			}
		}

		return $translation;
	}

	/**
	 * Filter: plugin_locale — force locale to current PLL language.
	 */
	public function pluginLocale( string $locale, string $domain ): string {
		if ( $this->language instanceof \PLL_Language && $this->shouldTranslate( $domain ) ) {
			return $this->language->locale;
		}

		return $locale;
	}

	/**
	 * Check if a domain should be translated via our system.
	 */
	private function shouldTranslate( string $domain ): bool {
		static $cache = [];

		if ( isset( $cache[ $domain ] ) ) {
			return $cache[ $domain ];
		}

		$settings = Scanner::getSettings();

		$cache[ $domain ] = in_array( $domain, $settings['themes'], true )
			|| in_array( $domain, $settings['plugins'], true )
			|| in_array( $domain, $settings['domains'], true )
			|| in_array( $domain, $settings['additional_domains'], true )
			|| in_array( $domain, [ 'pll_string', 'hd', 'hd-pll' ], true );

		return $cache[ $domain ];
	}
}
