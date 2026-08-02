<?php
/**
 * Translate Slugs Model — Manages translatable slug data and rewrite rules.
 *
 * Collects slugs from all translated post types, taxonomies, and WP built-in
 * bases (author, search, paged, front). Reads translations from Polylang MO
 * files. Stores results in a transient for performance.
 *
 * @package HDPLL\Pro
 */

declare(strict_types=1);

namespace HDPLL\Pro;

defined( 'ABSPATH' ) || exit;

final class TranslateSlugsModel {

	/**
	 * Array of translatable slug data.
	 * Structure: [ 'type' => [ 'slug' => string, 'translations' => [ lang => slug ] ] ]
	 *
	 * @var array<string, array{slug: string, translations: array<string, string>}>
	 */
	public array $translatedSlugs = [];

	/* ---------- Init ---------- */

	/**
	 * Initialize the model.
	 */
	public function init(): void {
		add_action( 'wp_loaded', [ $this, 'initTranslatedSlugs' ], 1 );
		add_action( 'pll_prepare_rewrite_rules', [ $this, 'prepareRewriteRules' ], 20 );
		add_action( 'pll_save_strings_translations', [ $this, 'flushRewriteRules' ] );
		add_action( 'admin_init', [ $this, 'registerSlugs' ] );
		add_filter( 'pll_sanitize_string_translation', [ $this, 'sanitizeStringTranslation' ], 10, 2 );

		// Cache invalidation.
		add_action( 'pll_add_language', [ $this, 'cleanCache' ] );
		add_action( 'pll_update_language', [ $this, 'cleanCache' ] );
	}

	/* ---------- Slug Collection ---------- */

	/**
	 * Initialize the translated slugs list.
	 * Called on `wp_loaded` so all CPTs and taxonomies are registered.
	 */
	public function initTranslatedSlugs(): void {
		$this->translatedSlugs = $this->getTranslatableSlugs();

		// Remove slugs that have no actual translations.
		foreach ( $this->translatedSlugs as $key => $value ) {
			if ( 1 === count( array_unique( $value['translations'] ) ) && reset( $value['translations'] ) === $value['slug'] ) {
				unset( $this->translatedSlugs[ $key ] );
			}
		}
	}

	/**
	 * Collect all translatable slugs from CPTs, taxonomies, and built-in bases.
	 * Results are cached in a transient.
	 *
	 * @return array<string, array{slug: string, translations: array<string, string>}>
	 */
	public function getTranslatableSlugs(): array {
		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		$slugs = get_transient( 'pll_translated_slugs' );

		if ( false !== $slugs ) {
			return $slugs;
		}

		$slugs = [];

		foreach ( \PLL()->model->get_languages_list() as $language ) {
			$mo = new \PLL_MO();
			$mo->import_from_db( $language );

			// Post types.
			foreach ( get_post_types( [], 'objects' ) as $type ) {
				if ( ! empty( $type->rewrite['slug'] ) && \PLL()->model->is_translated_post_type( $type->name ) ) {
					$slug    = trim( preg_replace( '#%.+?%#', '', $type->rewrite['slug'] ), '/' );
					$tr_slug = $mo->translate( $slug );

					$slugs[ $type->name ]['slug']                            = $slug;
					$slugs[ $type->name ]['translations'][ $language->slug ] = ! empty( $tr_slug ) ? $tr_slug : $slug;

					// Post type archives.
					if ( ! empty( $type->has_archive ) ) {
						$archive_slug = true === $type->has_archive ? $slug : $type->has_archive;

						if ( true === $type->has_archive ) {
							$slugs[ 'archive_' . $type->name ]['hide'] = true;
						}

						$slugs[ 'archive_' . $type->name ]['slug'] = $archive_slug;
						$tr_archive                                = $mo->translate( $archive_slug );
						$slugs[ 'archive_' . $type->name ]['translations'][ $language->slug ] = ! empty( $tr_archive ) ? $tr_archive : $archive_slug;
					}
				}
			}

			// Taxonomies.
			foreach ( get_taxonomies( [], 'objects' ) as $tax ) {
				if ( ! empty( $tax->rewrite['slug'] ) && ( \PLL()->model->is_translated_taxonomy( $tax->name ) || 'post_format' === $tax->name ) ) {
					$slug    = trim( $tax->rewrite['slug'], '/' );
					$tr_slug = $mo->translate( $slug );

					$slugs[ $tax->name ]['slug']                            = $slug;
					$slugs[ $tax->name ]['translations'][ $language->slug ] = ! empty( $tr_slug ) ? $tr_slug : $slug;
				}
			}

			// Built-in bases: author, search, attachment.
			foreach ( [ 'author', 'search', 'attachment' ] as $base ) {
				$tr_slug = $mo->translate( $base );

				$slugs[ $base ]['slug']                            = $base;
				$slugs[ $base ]['translations'][ $language->slug ] = ! empty( $tr_slug ) ? $tr_slug : $base;
			}

			// Paged: /page/ slug.
			$tr_paged = $mo->translate( 'page' );

			$slugs['paged']['slug']                            = 'page';
			$slugs['paged']['translations'][ $language->slug ] = ! empty( $tr_paged ) ? $tr_paged : 'page';

			// Front base (/blog/).
			if ( ! empty( $wp_rewrite->front ) ) {
				$front_slug = trim( $wp_rewrite->front, '/' );
				$tr_front   = $mo->translate( $front_slug );

				$slugs['front']['slug']                            = $front_slug;
				$slugs['front']['translations'][ $language->slug ] = ! empty( $tr_front ) ? $tr_front : $front_slug;
			}

			/** This filter is documented in polylang-pro */
			$slugs = apply_filters_ref_array( 'pll_translated_slugs', [ $slugs, $language, &$mo ] );
		}

		if ( did_action( 'wp_loaded' ) ) {
			set_transient( 'pll_translated_slugs', $slugs, WEEK_IN_SECONDS );
		}

		return $slugs;
	}

	/* ---------- URL Translation ---------- */

	/**
	 * Translate a slug in a permalink (from original slug).
	 */
	public function translateSlug( string $link, \PLL_Language $lang, string $type ): string {
		if ( ! isset( $this->translatedSlugs[ $type ] ) || empty( $this->translatedSlugs[ $type ]['slug'] ) ) {
			return $link;
		}

		return preg_replace(
			'#/' . preg_quote( $this->translatedSlugs[ $type ]['slug'], '#' ) . '(/|\?|\#|$)#',
			'/' . $this->getTranslatedSlug( $type, $lang->slug ) . '$1',
			$link
		);
	}

	/**
	 * Switch translated slug in a permalink (from any translated slug to target language).
	 */
	public function switchTranslatedSlug( string $link, \PLL_Language $lang, string $type ): string {
		if ( ! isset( $this->translatedSlugs[ $type ] ) || empty( $this->translatedSlugs[ $type ]['slug'] ) ) {
			return $link;
		}

		$slugs   = $this->translatedSlugs[ $type ]['translations'];
		$slugs[] = $this->translatedSlugs[ $type ]['slug'];
		$slugs   = $this->encodeDeep( $slugs );

		return preg_replace(
			'#/(' . implode( '|', array_unique( array_map( fn( $s ) => preg_quote( $s, '#' ), $slugs ) ) ) . ')(/|\?|\#|$)#',
			'/' . $this->getTranslatedSlug( $type, $lang->slug ) . '$2',
			$link
		);
	}

	/**
	 * Get translated slug value, URL-encoded.
	 */
	public function getTranslatedSlug( string $type, string $lang ): string {
		$translation = $this->translatedSlugs[ $type ]['translations'][ $lang ]
			?? $this->translatedSlugs[ $type ]['slug']
			?? '';

		return $this->encodeDeep( $translation );
	}

	/* ---------- Rewrite Rules ---------- */

	/**
	 * Add rewrite rule filters to translate slugs.
	 */
	public function prepareRewriteRules(): void {
		static $registered = false;

		if ( ! \PLL()->model->has_languages() || $registered ) {
			return;
		}

		$registered = true;

		$filters = [ 'rewrite_rules_array' => [ $this, 'rewriteTranslatedSlug' ] ];

		if ( method_exists( \PLL()->links_model, 'get_rewrite_rules_filters' ) ) {
			foreach ( \PLL()->links_model->get_rewrite_rules_filters() as $type ) {
				$filters[ $type . '_rewrite_rules' ] = [ $this, 'rewriteTranslatedSlug' ];
			}
		}

		foreach ( $filters as $rule => $callback ) {
			add_filter( $rule, $callback, 5 );
		}
	}

	/**
	 * Master rewrite rule filter — dispatches to type-specific translators.
	 *
	 * @param string[] $rules Rewrite rules.
	 *
	 * @return string[]
	 */
	public function rewriteTranslatedSlug( array $rules ): array {
		$filter = str_replace( '_rewrite_rules', '', current_filter() );

		// Paged rules must be processed first.
		$rules = $this->translatePaged( $rules );

		if ( 'rewrite_rules_array' === $filter ) {
			$rules = $this->translatePostTypeArchive( $rules );
		} else {
			$rules = $this->translateRule( $rules, $filter );
		}

		$rules = $this->translateRule( $rules, 'attachment' );
		$rules = $this->translateRule( $rules, 'front' );

		return $rules;
	}

	/* ---------- Rewrite Rule Translators ---------- */

	/**
	 * Replace slug in rewrite rule keys with multi-language pattern.
	 */
	private function translateRule( array $rules, string $type ): array {
		if ( empty( $this->translatedSlugs[ $type ] ) ) {
			return $rules;
		}

		$old      = $this->translatedSlugs[ $type ]['slug'] . '/';
		$new      = $this->getTranslatedSlugsPattern( $type );
		$newrules = [];

		foreach ( $rules as $key => $rule ) {
			if ( str_contains( $key, $old ) ) {
				$new_key              = str_starts_with( $key, $old ) ? str_replace( $old, $new, $key ) : str_replace( '/' . $old, '/' . $new, $key );
				$newrules[ $new_key ] = $rule;
			} else {
				$newrules[ $key ] = $rule;
			}
		}

		return $newrules;
	}

	/**
	 * Translate post type archive slug in rewrite rules.
	 */
	private function translatePostTypeArchive( array $rules ): array {
		$newrules = [];
		$cpts     = array_intersect( \PLL()->model->get_translated_post_types(), get_post_types( [ '_builtin' => false ] ) );

		foreach ( $rules as $key => $rule ) {
			$query = wp_parse_url( $rule, PHP_URL_QUERY );
			if ( ! is_string( $query ) ) {
				$newrules[ $key ] = $rule;
				continue;
			}

			parse_str( $query, $qv );

			if ( ! empty( $cpts ) && ! empty( $qv['post_type'] ) && is_string( $qv['post_type'] )
				&& in_array( $qv['post_type'], $cpts, true )
				&& ! str_contains( $rule, 'name=' )
				&& isset( $this->translatedSlugs[ 'archive_' . $qv['post_type'] ] )
			) {
				$archive_key = 'archive_' . $qv['post_type'];
				$new_slug    = $this->getTranslatedSlugsPattern( $archive_key );
				$newrules[ str_replace( $this->translatedSlugs[ $archive_key ]['slug'] . '/', $new_slug, $key ) ] = $rule;
			} else {
				$newrules[ $key ] = $rule;
			}
		}

		return $newrules;
	}

	/**
	 * Translate the /page/ slug in rewrite rules.
	 */
	private function translatePaged( array $rules ): array {
		if ( empty( $this->translatedSlugs['paged'] ) ) {
			return $rules;
		}

		$old      = $this->translatedSlugs['paged']['slug'] . '/';
		$new      = $this->getTranslatedSlugsPattern( 'paged' );
		$newrules = [];

		foreach ( $rules as $key => $rule ) {
			if ( str_contains( $key, '/page/' ) && preg_match( '#\[\d\]|\$\d#', $rule ) ) {
				$newrules[ str_replace( '/' . $old, '/' . $new, $key ) ] = $rule;
			} elseif ( str_starts_with( $key, 'page/' ) && preg_match( '#\[\d\]|\$\d#', $rule ) ) {
				$newrules[ str_replace( $old, $new, $key ) ] = $rule;
			} else {
				$newrules[ $key ] = $rule;
			}
		}

		return $newrules;
	}

	/* ---------- String Registration ---------- */

	/**
	 * Register translatable slugs as Polylang strings.
	 */
	public function registerSlugs(): void {
		foreach ( $this->getTranslatableSlugs() as $key => $type ) {
			if ( empty( $type['hide'] ) ) {
				\pll_register_string( 'slug_' . $key, $type['slug'], __( 'URL slugs', 'hd-pll' ) );
			}
		}
	}

	/**
	 * Sanitize slug translations before saving.
	 */
	public function sanitizeStringTranslation( string $translation, string $name ): string {
		if ( ! str_starts_with( $name, 'slug_' ) ) {
			return $translation;
		}

		$special_chars = [ '?', '#', '[', ']', '$', "'", '(', ')', '*', '+', ' ' ];
		$translation   = str_replace( $special_chars, '', $translation );
		$translation   = preg_replace( '#/+#', '/', '/' . str_replace( '#', '', $translation ) );

		if ( empty( $translation ) ) {
			return '';
		}

		$translation = sanitize_url( $translation );
		$translation = str_replace( 'http://', '', $translation );

		return trim( $translation, '/' );
	}

	/* ---------- Cache ---------- */

	/**
	 * Flush rewrite rules on slug translation save.
	 */
	public function flushRewriteRules(): void {
		delete_transient( 'pll_translated_slugs' );
		$this->initTranslatedSlugs();
		flush_rewrite_rules();
	}

	/**
	 * Clear slug cache on language add/update.
	 */
	public function cleanCache(): void {
		delete_transient( 'pll_translated_slugs' );
	}

	/* ---------- Helpers ---------- */

	/**
	 * Build regex pattern matching all translations of a slug type.
	 */
	private function getTranslatedSlugsPattern( string $type, bool $capture = false ): string {
		$slugs = [ preg_quote( $this->translatedSlugs[ $type ]['slug'], '#' ) ];

		foreach ( array_keys( $this->translatedSlugs[ $type ]['translations'] ) as $lang ) {
			if ( '' !== (string) $this->translatedSlugs[ $type ]['translations'][ $lang ] ) {
				$slugs[] = preg_quote( $this->translatedSlugs[ $type ]['translations'][ $lang ], '#' );
			}
		}

		return ( $capture ? '(' : '(?:' ) . implode( '|', array_unique( $slugs ) ) . ')/';
	}

	/**
	 * URL-encode slug(s) while preserving forward slashes.
	 *
	 * @param string|string[] $slug Slug or array of slugs.
	 *
	 * @return string|string[]
	 */
	public function encodeDeep( mixed $slug ): mixed {
		if ( is_array( $slug ) ) {
			return array_map( [ $this, 'encodeDeep' ], $slug );
		}

		return implode( '/', array_map( 'rawurlencode', explode( '/', (string) $slug ) ) );
	}
}
