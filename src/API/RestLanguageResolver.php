<?php
/**
 * REST Language Resolver — shared utilities for PLL REST API integration.
 *
 * Provides language validation, response value resolution, and translation-map
 * reads used by LanguageField, QueryFilter, and WCQueryFilter.
 *
 * Rules:
 * - Only use Polylang public API functions (pll_*, PLL()->model->*).
 * - Never query Polylang's language taxonomy tables directly.
 * - `lang=all` normalises to null (no filter).
 *
 * @package HDPLL\API
 */

declare(strict_types=1);

namespace HDPLL\API;

defined( 'ABSPATH' ) || exit;

final class RestLanguageResolver {

	/* ---------- Language Validation --------------------------------- */

	/**
	 * Return the list of active language slugs.
	 *
	 * @return string[]
	 */
	public static function getLanguageSlugs(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		return (array) \pll_languages_list( [ 'fields' => 'slug' ] );
	}

	/**
	 * Check whether a slug is a registered Polylang language.
	 *
	 * @param string $slug Language slug.
	 *
	 * @return bool
	 */
	public static function isValidLanguage( string $slug ): bool {
		return in_array( $slug, self::getLanguageSlugs(), true );
	}

	/**
	 * Normalise a REST `lang` query parameter.
	 *
	 * - `lang=all` → null  (no filter; return all languages)
	 * - empty string → null
	 * - valid slug  → slug
	 * - invalid     → WP_Error
	 *
	 * @param string $lang Raw `lang` parameter value.
	 *
	 * @return string|null|\WP_Error Normalised slug, null (no filter), or WP_Error.
	 */
	public static function normalizeLang( string $lang ): string|null|\WP_Error {
		$lang = sanitize_key( $lang );

		if ( '' === $lang || 'all' === $lang ) {
			return null;
		}

		if ( ! self::isValidLanguage( $lang ) ) {
			return new \WP_Error(
				'pll_rest_invalid_lang',
				/* translators: %s: language slug */
				sprintf( __( 'Invalid Polylang language slug: %s', 'hd-pll' ), $lang ),
				[ 'status' => 400 ]
			);
		}

		return $lang;
	}

	/* ---------- Post Language ---------------------------------------- */

	/**
	 * Get the language slug assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string Empty string when not set.
	 */
	public static function getPostLanguage( int $post_id ): string {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return '';
		}

		return (string) ( \pll_get_post_language( $post_id, 'slug' ) ?: '' );
	}

	/**
	 * Get the translations map for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, int> Map of lang_slug → post_id.
	 */
	public static function getPostTranslations( int $post_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return [];
		}

		$translations = \pll_get_post_translations( $post_id );

		return is_array( $translations ) ? array_map( 'intval', $translations ) : [];
	}

	/* ---------- Term Language --------------------------------------- */

	/**
	 * Get the language slug assigned to a term.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return string Empty string when not set.
	 */
	public static function getTermLanguage( int $term_id ): string {
		if ( ! function_exists( 'pll_get_term_language' ) ) {
			return '';
		}

		return (string) ( \pll_get_term_language( $term_id, 'slug' ) ?: '' );
	}

	/**
	 * Get the translations map for a term.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return array<string, int> Map of lang_slug → term_id.
	 */
	public static function getTermTranslations( int $term_id ): array {
		if ( ! function_exists( 'pll_get_term_translations' ) ) {
			return [];
		}

		$translations = \pll_get_term_translations( $term_id );

		return is_array( $translations ) ? array_map( 'intval', $translations ) : [];
	}

	/* ---------- Write-path Validation ------------------------------ */

	/**
	 * Validate a lang/translations write request for a post.
	 *
	 * @param int    $post_id     Target post ID.
	 * @param string $lang        Language slug to assign.
	 * @param string $object_type Post type slug.
	 *
	 * @return bool|\WP_Error
	 */
	public static function validatePostWrite( int $post_id, string $lang, string $object_type ): bool|\WP_Error {
		if ( ! self::isValidLanguage( $lang ) ) {
			return new \WP_Error(
				'pll_rest_invalid_lang',
				sprintf( __( 'Invalid Polylang language slug: %s', 'hd-pll' ), $lang ),
				[ 'status' => 400 ]
			);
		}

		if ( ! function_exists( 'pll_is_translated_post_type' ) || ! \pll_is_translated_post_type( $object_type ) ) {
			return new \WP_Error(
				'pll_rest_not_translatable',
				sprintf( __( 'Post type "%s" is not translatable.', 'hd-pll' ), $object_type ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Validate a lang/translations write request for a term.
	 *
	 * @param int    $term_id   Target term ID.
	 * @param string $lang      Language slug to assign.
	 * @param string $taxonomy  Taxonomy slug.
	 *
	 * @return bool|\WP_Error
	 */
	public static function validateTermWrite( int $term_id, string $lang, string $taxonomy ): bool|\WP_Error {
		if ( ! self::isValidLanguage( $lang ) ) {
			return new \WP_Error(
				'pll_rest_invalid_lang',
				sprintf( __( 'Invalid Polylang language slug: %s', 'hd-pll' ), $lang ),
				[ 'status' => 400 ]
			);
		}

		if ( ! function_exists( 'pll_is_translated_taxonomy' ) || ! \pll_is_translated_taxonomy( $taxonomy ) ) {
			return new \WP_Error(
				'pll_rest_not_translatable',
				sprintf( __( 'Taxonomy "%s" is not translatable.', 'hd-pll' ), $taxonomy ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Validate that each value in a translations map references an existing post
	 * whose assigned language matches the map key.
	 *
	 * @param array<string, int> $translations Map of lang_slug → post_id.
	 * @param string             $post_type    Expected post type for all linked translations.
	 *
	 * @return bool|\WP_Error
	 */
	public static function validatePostTranslationsMap( array $translations, string $post_type ): bool|\WP_Error {
		foreach ( $translations as $lang_slug => $target_id ) {
			if ( ! self::isValidLanguage( $lang_slug ) ) {
				return new \WP_Error(
					'pll_rest_invalid_lang',
					sprintf( __( 'Invalid language key in translations map: %s', 'hd-pll' ), $lang_slug ),
					[ 'status' => 400 ]
				);
			}

			$target_id = absint( $target_id );
			$target    = $target_id ? get_post( $target_id ) : null;
			if ( ! $target instanceof \WP_Post ) {
				return new \WP_Error(
					'pll_rest_invalid_target',
					sprintf( __( 'Translation target post %d does not exist.', 'hd-pll' ), $target_id ),
					[ 'status' => 400 ]
				);
			}

			if ( $target->post_type !== $post_type ) {
				return new \WP_Error(
					'pll_rest_type_mismatch',
					sprintf(
						__( 'Post %1$d has post type "%2$s", not "%3$s".', 'hd-pll' ),
						$target_id,
						$target->post_type,
						$post_type
					),
					[ 'status' => 400 ]
				);
			}

			$target_lang = self::getPostLanguage( $target_id );
			if ( $target_lang && $target_lang !== $lang_slug ) {
				return new \WP_Error(
					'pll_rest_lang_mismatch',
					sprintf(
						__( 'Post %1$d is assigned language "%2$s", not "%3$s".', 'hd-pll' ),
						$target_id,
						$target_lang,
						$lang_slug
					),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}

	/**
	 * Validate that each value in a translations map references an existing term
	 * whose assigned language matches the map key.
	 *
	 * @param array<string, int> $translations Map of lang_slug → term_id.
	 * @param string             $taxonomy     Taxonomy for the source term.
	 *
	 * @return bool|\WP_Error
	 */
	public static function validateTermTranslationsMap( array $translations, string $taxonomy ): bool|\WP_Error {
		foreach ( $translations as $lang_slug => $target_id ) {
			if ( ! self::isValidLanguage( $lang_slug ) ) {
				return new \WP_Error(
					'pll_rest_invalid_lang',
					sprintf( __( 'Invalid language key in translations map: %s', 'hd-pll' ), $lang_slug ),
					[ 'status' => 400 ]
				);
			}

			$target_id = absint( $target_id );
			if ( ! $target_id || ! get_term( $target_id, $taxonomy ) ) {
				return new \WP_Error(
					'pll_rest_invalid_target',
					sprintf( __( 'Translation target term %1$d does not exist in taxonomy "%2$s".', 'hd-pll' ), $target_id, $taxonomy ),
					[ 'status' => 400 ]
				);
			}

			$target_lang = self::getTermLanguage( $target_id );
			if ( $target_lang && $target_lang !== $lang_slug ) {
				return new \WP_Error(
					'pll_rest_lang_mismatch',
					sprintf(
						__( 'Term %1$d is assigned language "%2$s", not "%3$s".', 'hd-pll' ),
						$target_id,
						$target_lang,
						$lang_slug
					),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}
}
