<?php
/**
 * Share Slugs — Allow posts/terms in different languages to share the same slug.
 *
 * Scopes slug uniqueness to within a single language, so translations
 * can have identical slugs without WordPress appending `-2`.
 *
 * Requires: pretty permalinks + language from URL (force_lang > 0).
 *
 * @package HDPLL\Pro
 */

declare(strict_types=1);

namespace HDPLL\Pro;

use HD\Core\DB;
use HDPLL\Contracts\PllFeatureInterface;

defined( 'ABSPATH' ) || exit;

final class ShareSlugs implements PllFeatureInterface {

	private const TERM_SLUG_SEPARATOR = '___';

	public static function slug(): string {
		return 'share_slugs';
	}

	/**
	 * Register hooks — only when pretty permalinks + force_lang > 0.
	 */
	public function register(): void {
		if ( ! get_option( 'permalink_structure' ) || empty( \PLL()->options['force_lang'] ) ) {
			return;
		}

		// ── Post slug sharing ──
		add_filter( 'wp_unique_post_slug', [ $this, 'uniquePostSlug' ], 10, 6 );
		add_action( 'pll_translate_media', [ $this, 'syncMediaSlug' ], 20, 2 );

		// ── Post query by slug + language ──
		add_action( 'parse_query', [ $this, 'parseQuery' ], 0 );
		add_filter( 'posts_join', [ $this, 'postsJoin' ], 10, 2 );
		add_filter( 'posts_where', [ $this, 'postsWhere' ], 10, 2 );

		// ── Term slug sharing ──
		add_filter( 'pre_term_slug', [ $this, 'preTermSlug' ], 10, 2 );
		add_action( 'created_term', [ $this, 'saveTerm' ], 1, 3 );
		add_action( 'edited_term', [ $this, 'saveTerm' ], 1, 3 );
	}

	/* ================================================================
	 *  POST SLUG SHARING
	 * ================================================================ */

	/**
	 * Scope post slug uniqueness within language.
	 *
	 * If WP appended `-2` because of a cross-language duplicate,
	 * we re-check within the language boundary and restore the original slug if unique there.
	 *
	 * @param string $slug          Modified slug from WP.
	 * @param int    $post_ID       Post ID.
	 * @param string $post_status   Post status (unused).
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent post ID.
	 * @param string $original_slug Original slug before WP modification.
	 */
	public function uniquePostSlug( string $slug, int $post_ID, string $post_status, string $post_type, int $post_parent, string $original_slug ): string {
		// Nothing to do if slug wasn't modified or post type not translated.
		if ( $original_slug === $slug || ! \PLL()->model->is_translated_post_type( $post_type ) ) {
			return $slug;
		}

		$lang = \PLL()->model->post->get_language( $post_ID );

		if ( empty( $lang ) ) {
			return $slug;
		}

		return $this->isPostSlugUniqueInLang( $original_slug, $post_ID, $post_type, $post_parent, $lang )
			? $original_slug
			: $slug;
	}

	/**
	 * Check if a post slug is unique within its language (+ type scope).
	 */
	private function isPostSlugUniqueInLang( string $slug, int $post_ID, string $post_type, int $post_parent, \PLL_Language $lang ): bool {
		$db = DB::db();

		$join  = \PLL()->model->post->join_clause();
		$where = \PLL()->model->post->where_clause( $lang );

		if ( 'attachment' === $post_type ) {
			// Attachments: unique across all types within language.
			$sql = "SELECT 1 FROM {$db->posts}" . $join
				. $db->prepare( ' WHERE post_name = %s AND ID != %d', $slug, $post_ID )
				. $where . ' LIMIT 1';
		} elseif ( is_post_type_hierarchical( $post_type ) ) {
			// Hierarchical: unique within parent + type within language.
			$sql = "SELECT 1 FROM {$db->posts}" . $join
				. $db->prepare(
					" WHERE post_name = %s AND post_type IN (%s, 'attachment') AND ID != %d AND post_parent = %d",
					$slug,
					$post_type,
					$post_ID,
					$post_parent
				)
				. $where . ' LIMIT 1';
		} else {
			// Flat: unique within type within language.
			$sql = "SELECT 1 FROM {$db->posts}" . $join
				. $db->prepare( ' WHERE post_name = %s AND post_type = %s AND ID != %d', $slug, $post_type, $post_ID )
				. $where . ' LIMIT 1';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ! $db->get_var( $sql );
	}

	/**
	 * Copy slug from original media to its translation.
	 * Runs after PLL_Admin_Sync (priority 20).
	 */
	public function syncMediaSlug( int $post_id, int $tr_id ): void {
		$post = get_post( $post_id );
		if ( $post ) {
			wp_update_post(
				[
					'ID'        => $tr_id,
					'post_name' => $post->post_name,
				]
			);
		}
	}

	/* ================================================================
	 *  POST QUERY BY SLUG + LANGUAGE
	 * ================================================================ */

	/**
	 * Resolve correct page/post when querying by slug with shared slugs.
	 * Priority 0 — must run before other parse_query hooks.
	 */
	public function parseQuery( \WP_Query $query ): void {
		$lang = $this->getQueryLanguage( $query );
		if ( ! $lang ) {
			return;
		}

		$qv = $query->query_vars;

		// For hierarchical CPTs queried by name, treat as pagename.
		if ( empty( $qv['pagename'] ) && ! empty( $qv['name'] ) && ! empty( $qv['post_type'] )
			&& array_intersect( get_post_types( [ 'hierarchical' => true ] ), (array) $qv['post_type'] )
		) {
			$qv['pagename'] = $qv['name'];
		}

		if ( empty( $qv['pagename'] ) ) {
			return;
		}

		$post_type = empty( $qv['post_type'] ) ? 'page' : $qv['post_type'];
		$page      = $this->getPageByPath( $qv['pagename'], $lang->slug, $post_type );

		if ( $page ) {
			$query->queried_object    = $page;
			$query->queried_object_id = (int) $page->ID;
		}
	}

	/**
	 * Add language JOIN to name-based post queries.
	 */
	public function postsJoin( string $join, \WP_Query $query ): string {
		if ( $this->getQueryLanguage( $query ) ) {
			return $join . \PLL()->model->post->join_clause();
		}

		return $join;
	}

	/**
	 * Add language WHERE to name-based post queries.
	 */
	public function postsWhere( string $where, \WP_Query $query ): string {
		$lang = $this->getQueryLanguage( $query );
		if ( $lang ) {
			return $where . \PLL()->model->post->where_clause( $lang );
		}

		return $where;
	}

	/**
	 * Determine the language for a query, if it's a name-based query on a translated post type.
	 *
	 * @return \PLL_Language|false
	 */
	private function getQueryLanguage( \WP_Query $query ): \PLL_Language|false {
		$qv = $query->query_vars;

		if ( empty( $qv['name'] ) && empty( $qv['pagename'] ) ) {
			return false;
		}

		$post_type = empty( $qv['post_type'] ) ? 'post' : $qv['post_type'];

		if ( ! empty( $qv['attachment'] ) ) {
			$post_type = 'attachment';
		}

		if ( ! \PLL()->model->is_translated_post_type( $post_type ) ) {
			return false;
		}

		// Explicit lang in query.
		if ( ! empty( $qv['lang'] ) ) {
			return \PLL()->model->get_language( $qv['lang'] ) ?: false;
		}

		// Language from tax_query.
		if ( isset( $qv['tax_query'] ) && is_array( $qv['tax_query'] ) ) {
			foreach ( $qv['tax_query'] as $tax_query ) {
				if ( ! isset( $tax_query['taxonomy'] ) || 'language' !== $tax_query['taxonomy'] ) {
					continue;
				}

				$terms = is_array( $tax_query['terms'] ?? null )
					? ( 1 < count( $tax_query['terms'] ) ? null : reset( $tax_query['terms'] ) )
					: ( $tax_query['terms'] ?? null );

				if ( null === $terms ) {
					continue;
				}

				if ( isset( $tax_query['field'] ) && 'term_taxonomy_id' === $tax_query['field'] ) {
					$terms = "tt:{$terms}";
				}

				$lang = \PLL()->model->get_language( $terms );
				if ( $lang ) {
					return $lang;
				}
			}
		}

		// Fallback to current language.
		return \PLL()->curlang ?: false;
	}

	/**
	 * Get a page by its path within a specific language.
	 * Language-aware version of get_page_by_path().
	 *
	 * @param string          $page_path Slash-separated page path.
	 * @param string          $lang      Language slug.
	 * @param string|string[] $post_type Post type(s) to query.
	 */
	private function getPageByPath( string $page_path, string $lang, string|array $post_type = 'page' ): ?\WP_Post {
		$db = DB::db();

		$page_path = rawurlencode( urldecode( $page_path ) );
		$page_path = str_replace( [ '%2F', '%20' ], [ '/', ' ' ], $page_path );
		$parts     = array_map( 'sanitize_title_for_query', explode( '/', trim( $page_path, '/' ) ) );

		if ( empty( $parts ) ) {
			return null;
		}

		$post_types = is_array( $post_type ) ? $post_type : [ $post_type, 'attachment' ];

		$in_slugs = implode( ',', array_map( static fn( string $s ) => $db->prepare( '%s', $s ), $parts ) );
		$in_types = implode( ',', array_map( static fn( string $t ) => $db->prepare( '%s', $t ), $post_types ) );

		$join  = \PLL()->model->post->join_clause();
		$where = \PLL()->model->post->where_clause( $lang );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pages = $db->get_results(
			"SELECT ID, post_name, post_parent, post_type FROM {$db->posts}"
			. $join
			. " WHERE post_name IN ({$in_slugs}) AND post_type IN ({$in_types})"
			. $where,
			OBJECT_K
		);

		if ( empty( $pages ) ) {
			return null;
		}

		// Walk the hierarchy to find the matching page.
		$revparts = array_reverse( $parts );
		$foundid  = 0;

		foreach ( $pages as $page ) {
			if ( $page->post_name !== $revparts[0] ) {
				continue;
			}

			$count = 0;
			$p     = $page;

			while ( 0 !== (int) $p->post_parent && isset( $pages[ $p->post_parent ] ) ) {
				++$count;
				$parent = $pages[ $p->post_parent ];

				if ( ! isset( $revparts[ $count ] ) || $parent->post_name !== $revparts[ $count ] ) {
					break;
				}
				$p = $parent;
			}

			if ( 0 === (int) $p->post_parent && count( $revparts ) === $count + 1 && $p->post_name === $revparts[ $count ] ) {
				$foundid = $page->ID;
				if ( is_string( $post_type ) ? $page->post_type === $post_type
					: in_array( $page->post_type, $post_type, true )
				) {
					break;
				}
			}
		}

		return $foundid ? get_post( $foundid ) : null;
	}

	/* ================================================================
	 *  TERM SLUG SHARING
	 * ================================================================ */

	/**
	 * Append language suffix to term slug before WP saves it,
	 * making it temporarily unique. Suffix is stripped in saveTerm().
	 *
	 * @param string $slug     Term slug.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function preTermSlug( string $slug, string $taxonomy ): string {
		if ( ! \PLL()->model->is_translated_taxonomy( $taxonomy ) ) {
			return $slug;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$lang = sanitize_key( wp_unslash( $_POST['term_lang_choice'] ?? $_POST['inline_lang_choice'] ?? '' ) );

		if ( empty( $lang ) || ! \PLL()->model->get_language( $lang ) ) {
			return $slug;
		}

		// Only suffix if the slug actually conflicts.
		if ( ! $this->termSlugExistsInLang( $slug, $lang, $taxonomy ) ) {
			return $slug;
		}

		return $slug . self::TERM_SLUG_SEPARATOR . $lang;
	}

	/**
	 * After term is saved, strip the language suffix and set the real slug
	 * (which is now safe because the term has a language assigned).
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy name.
	 */
	public function saveTerm( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! \PLL()->model->is_translated_taxonomy( $taxonomy ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$pos = strpos( $term->slug, self::TERM_SLUG_SEPARATOR );
		if ( false === $pos ) {
			return;
		}

		$slug = substr( $term->slug, 0, $pos );
		$lang = substr( $term->slug, $pos + strlen( self::TERM_SLUG_SEPARATOR ) );

		// Verify uniqueness within language.
		if ( $this->termSlugExistsInLang( $slug, $lang, $taxonomy, $term_id ) ) {
			// Append numeric suffix if still conflicting.
			$num = 2;
			do {
				$try = $slug . '-' . $num;
				++$num;
			} while ( $num <= 100 && $this->termSlugExistsInLang( $try, $lang, $taxonomy, $term_id ) );
			$slug = $try;
		}

		// Direct update to avoid recursive hooks.
		DB::updateOneRow( 'terms', $term_id, [ 'slug' => $slug ], 'term_id' );
		clean_term_cache( $term_id, $taxonomy );
	}

	/**
	 * Check if a term slug exists within a specific language + taxonomy.
	 */
	private function termSlugExistsInLang( string $slug, string $lang, string $taxonomy, int $exclude_id = 0 ): bool {
		if ( method_exists( \PLL()->model, 'term_exists_by_slug' ) ) {
			$exists = \PLL()->model->term_exists_by_slug( $slug, $lang, $taxonomy );

			if ( ! $exists ) {
				return false;
			}

			// When excluding self, fall through to manual query which is language-aware.
			// get_term_by() is NOT language-aware and returns the first match in any language.
			if ( $exclude_id ) {
				return $this->termSlugExistsInLangQuery( $slug, $lang, $taxonomy, $exclude_id );
			}

			return true;
		}

		return $this->termSlugExistsInLangQuery( $slug, $lang, $taxonomy, $exclude_id );
	}

	/**
	 * Language-aware SQL check for term slug existence.
	 *
	 * Uses PLL's join/where clauses to scope by language, avoiding
	 * get_term_by() which is language-unaware.
	 */
	private function termSlugExistsInLangQuery( string $slug, string $lang, string $taxonomy, int $exclude_id = 0 ): bool {
		$db = DB::db();

		$join  = \PLL()->model->term->join_clause();
		$where = \PLL()->model->term->where_clause( $lang );

		$sql = "SELECT 1 FROM {$db->terms} AS t"
			. " INNER JOIN {$db->term_taxonomy} AS tt ON t.term_id = tt.term_id"
			. $join
			. $db->prepare( ' WHERE t.slug = %s AND tt.taxonomy = %s', $slug, $taxonomy )
			. $where;

		if ( $exclude_id ) {
			$sql .= $db->prepare( ' AND t.term_id != %d', $exclude_id );
		}

		$sql .= ' LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $db->get_var( $sql );
	}
}
