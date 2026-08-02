<?php
/**
 * Translate Slugs — Frontend + admin link translation.
 *
 * Hooks into pll_post_type_link, pll_term_link, and various archive/page
 * link filters to translate base slugs in URLs.
 *
 * @package HDPLL\Pro
 */

declare(strict_types=1);

namespace HDPLL\Pro;

use HDPLL\Contracts\PllFeatureInterface;

defined( 'ABSPATH' ) || exit;

final class TranslateSlugs implements PllFeatureInterface {

	private TranslateSlugsModel $model;

	public static function slug(): string {
		return 'translate_slugs';
	}

	/**
	 * Register hooks with model.
	 */
	public function register(): void {
		$this->model = new TranslateSlugsModel();
		$this->model->init();

		// Post type + term link translation (both admin + frontend).
		add_filter( 'pll_post_type_link', [ $this, 'postTypeLink' ], 10, 3 );
		add_filter( 'pll_term_link', [ $this, 'termLink' ], 10, 3 );
		add_filter( 'post_type_archive_link', [ $this, 'translateArchiveSlug' ], 20, 2 );

		// Frontend-only hooks.
		if ( \PLL() instanceof \PLL_Frontend ) {
			$this->initFrontend();
		}
	}

	/* ---------- Link Translation (admin + frontend) ---------- */

	/**
	 * Translate slug in custom post type permalinks.
	 */
	public function postTypeLink( string $url, \PLL_Language|false $lang, \WP_Post $post ): string {
		if ( ! $lang ) {
			return $url;
		}

		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		if ( ! empty( $wp_rewrite->front ) && trim( $wp_rewrite->front, '/' ) ) {
			$url = $this->model->translateSlug( $url, $lang, 'front' );
		}

		return $this->model->translateSlug( $url, $lang, $post->post_type );
	}

	/**
	 * Translate slug in term permalinks.
	 */
	public function termLink( string $url, \PLL_Language|false $lang, \WP_Term $term ): string {
		if ( ! $lang ) {
			return $url;
		}

		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		if ( ! empty( $wp_rewrite->front ) && trim( $wp_rewrite->front, '/' ) ) {
			$url = $this->model->translateSlug( $url, $lang, 'front' );
		}

		return $this->model->translateSlug( $url, $lang, $term->taxonomy );
	}

	/**
	 * Translate slug in post type archive links.
	 */
	public function translateArchiveSlug( string $link, string $post_type = '' ): string {
		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		if ( empty( \PLL()->curlang ) ) {
			return $link;
		}

		$types = [
			'post_type_archive_link' => 'archive_' . $post_type,
			'get_pagenum_link'       => 'paged',
			'author_link'            => 'author',
			'attachment_link'        => 'attachment',
			'search_link'            => 'search',
		];

		$current_filter = current_filter();
		$type           = $types[ $current_filter ] ?? '';

		if ( $type ) {
			$link = $this->model->translateSlug( $link, \PLL()->curlang, $type );
		}

		if ( ! empty( $wp_rewrite->front ) && trim( $wp_rewrite->front, '/' ) ) {
			$link = $this->model->translateSlug( $link, \PLL()->curlang, 'front' );
		}

		return $link;
	}

	/* ---------- Frontend-only ---------- */

	/**
	 * Register frontend-specific hooks.
	 */
	private function initFrontend(): void {
		if ( \PLL()->links_model->using_permalinks ) {
			foreach ( [ 'author_link', 'search_link', 'get_pagenum_link', 'attachment_link' ] as $filter ) {
				add_filter( $filter, [ $this, 'translateArchiveSlug' ], 20 );
			}
		}

		add_filter( 'pll_get_archive_url', [ $this, 'archiveUrl' ], 10, 2 );
		add_filter( 'pll_check_canonical_url', [ $this, 'checkCanonicalUrl' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'fixWpRewrite' ], 1 );

		add_filter( 'pll_remove_paged_from_link', [ $this, 'removePaged' ], 10, 2 );
		add_filter( 'pll_add_paged_to_link', [ $this, 'addPaged' ], 10, 3 );
	}

	/**
	 * Translate slugs in archive URL for language switcher.
	 */
	public function archiveUrl( string $url, \PLL_Language $language ): string {
		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		if ( \is_post_type_archive() ) {
			$post_type = get_queried_object();
			if ( $post_type instanceof \WP_Post_Type ) {
				$url = $this->model->switchTranslatedSlug( $url, $language, 'archive_' . $post_type->name );
			}
		}

		if ( \is_author() ) {
			$url = $this->model->switchTranslatedSlug( $url, $language, 'author' );
		}

		if ( \is_search() ) {
			$url = $this->model->switchTranslatedSlug( $url, $language, 'search' );
		}

		if ( ! empty( $wp_rewrite->front ) && trim( $wp_rewrite->front, '/' ) ) {
			$url = $this->model->switchTranslatedSlug( $url, $language, 'front' );
		}

		return \PLL()->links_model->remove_paged_from_link( $url );
	}

	/**
	 * Fix canonical URL with translated slugs.
	 */
	public function checkCanonicalUrl( string $redirect_url, \PLL_Language $language ): string {
		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		$slugs = [];

		if ( \is_post_type_archive() ) {
			$obj = get_queried_object();
			if ( $obj instanceof \WP_Post_Type ) {
				$slugs[] = 'archive_' . $obj->name;
			}
		} elseif ( \is_single() || \is_page() ) {
			$post = get_post();
			if ( $post && \PLL()->model->is_translated_post_type( $post->post_type ) ) {
				$slugs[] = $post->post_type;
			}
		} elseif ( \is_category() || \is_tag() || \is_tax() ) {
			$obj = get_queried_object();
			if ( $obj instanceof \WP_Term && \PLL()->model->is_translated_taxonomy( $obj->taxonomy ) ) {
				$slugs[] = $obj->taxonomy;
			}
		} elseif ( \is_author() ) {
			$slugs[] = 'author';
		} elseif ( \is_search() ) {
			$slugs[] = 'search';
		}

		if ( \is_paged() ) {
			$slugs[] = 'paged';
		}

		if ( \is_attachment() ) {
			$slugs[] = 'attachment';
		}

		if ( ! empty( $wp_rewrite->front ) && trim( $wp_rewrite->front, '/' ) ) {
			$slugs[] = 'front';
		}

		foreach ( $slugs as $slug ) {
			$redirect_url = $this->model->switchTranslatedSlug( $redirect_url, $language, $slug );
		}

		return $redirect_url;
	}

	/**
	 * Hack wp_rewrite bases for translated slugs to prevent canonical redirect breaking.
	 */
	public function fixWpRewrite(): void {
		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		if ( empty( \PLL()->curlang ) ) {
			return;
		}

		$lang = \PLL()->curlang->slug;

		if ( isset( $this->model->translatedSlugs['author'] ) ) {
			$authorBase = $this->model->getTranslatedSlug( 'author', $lang );
			if ( '' !== $authorBase ) {
				$wp_rewrite->author_base = $authorBase;
			}
		}

		if ( isset( $this->model->translatedSlugs['search'] ) ) {
			$searchBase = $this->model->getTranslatedSlug( 'search', $lang );
			if ( '' !== $searchBase ) {
				$wp_rewrite->search_base = $searchBase;
			}
		}

		if ( isset( $this->model->translatedSlugs['paged'] ) ) {
			$pagedBase = $this->model->getTranslatedSlug( 'paged', $lang );
			if ( '' !== $pagedBase ) {
				$wp_rewrite->pagination_base = $pagedBase;
			}
		}
	}

	/**
	 * Remove translated paged slug from link.
	 */
	public function removePaged( string $_link, string $link ): string {
		if ( ! isset( $this->model->translatedSlugs['paged'] ) ) {
			return $_link;
		}

		$slugs   = $this->model->translatedSlugs['paged']['translations'];
		$slugs[] = $this->model->translatedSlugs['paged']['slug'];
		$slugs   = $this->model->encodeDeep( $slugs );

		return preg_replace(
			'#/(' . implode( '|', array_unique( $slugs ) ) . ')/[0-9]+/#',
			'/',
			$link
		);
	}

	/**
	 * Add translated paged slug to link.
	 */
	public function addPaged( string $_url, string $url, int $page ): string {
		if ( ! isset( $this->model->translatedSlugs['paged'] ) || empty( \PLL()->curlang ) ) {
			return $_url;
		}

		$slug = $this->model->getTranslatedSlug( 'paged', \PLL()->curlang->slug );

		return user_trailingslashit( trailingslashit( $url ) . $slug . '/' . $page, 'paged' );
	}
}
