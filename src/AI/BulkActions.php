<?php
/**
 * Admin list-table bulk actions for AI translation jobs.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

use HDPLL\AI\Jobs\JobRepository;
use HDPLL\Plugin;

defined( 'ABSPATH' ) || exit;

final class BulkActions {

	private const ACTION = 'hd_pll_ai_enqueue';

	public function __construct( private readonly JobRepository $repository = new JobRepository() ) {}

	public function register(): void {
		foreach ( $this->postTypes() as $postType ) {
			add_filter( "bulk_actions-edit-{$postType}", [ $this, 'addAction' ] );
			add_filter( "handle_bulk_actions-edit-{$postType}", [ $this, 'handlePostAction' ], 10, 3 );
		}

		foreach ( $this->taxonomies() as $taxonomy ) {
			add_filter( "bulk_actions-edit-{$taxonomy}", [ $this, 'addAction' ] );
			add_filter( "handle_bulk_actions-edit-{$taxonomy}", [ $this, 'handleTermAction' ], 10, 3 );
		}

		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * @param array<string, string> $actions Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function addAction( array $actions ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$actions[ self::ACTION ] = __( 'AI translate', 'hd-pll' );

		return $actions;
	}

	/**
	 * @param int[] $postIds Selected post IDs.
	 */
	public function handlePostAction( string $redirectTo, string $action, array $postIds ): string {
		if ( self::ACTION !== $action || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) ) {
			return $redirectTo;
		}

		return $this->redirectWithCounts( $redirectTo, $this->enqueuePosts( array_map( 'absint', $postIds ) ) );
	}

	/**
	 * @param int[] $termIds Selected term IDs.
	 */
	public function handleTermAction( string $redirectTo, string $action, array $termIds ): string {
		if ( self::ACTION !== $action || ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_categories' ) ) {
			return $redirectTo;
		}

		return $this->redirectWithCounts( $redirectTo, $this->enqueueTerms( array_map( 'absint', $termIds ) ) );
	}

	public function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['hd_pll_ai_jobs'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$jobs = absint( $_GET['hd_pll_ai_jobs'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = absint( $_GET['hd_pll_ai_skipped'] ?? 0 );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: queued jobs, 2: skipped items. */
					__( 'AI translation draft jobs queued: %1$d. Skipped: %2$d.', 'hd-pll' ),
					$jobs,
					$skipped
				)
			)
		);
	}

	/**
	 * @param int[] $postIds Post IDs.
	 *
	 * @return array{queued:int,skipped:int}
	 */
	public function enqueuePosts( array $postIds ): array {
		$counts               = [
			'queued'  => 0,
			'skipped' => 0,
		];
		$postTypes            = $this->postTypes();
		$postIds              = $this->normalizeIds( $postIds );
		$posts                = $this->postsById( $postIds, $postTypes );
		$langs                = $this->postLanguagesById( $postIds );
		$hasBatchTranslations = function_exists( 'pll_get_post_translations' );
		$translationsById     = $hasBatchTranslations ? $this->postTranslationsById( $postIds ) : [];

		foreach ( $postIds as $postId ) {
			$post = $posts[ $postId ] ?? null;
			if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $postTypes, true ) ) {
				++$counts['skipped'];
				continue;
			}

			$sourceLang = $langs[ $postId ] ?? '';
			if ( '' === $sourceLang ) {
				++$counts['skipped'];
				continue;
			}

			$translations = $translationsById[ $postId ] ?? [];
			foreach ( $this->targetLanguages( $sourceLang ) as $targetLang ) {
				if ( $this->hasPostTranslation( $postId, $targetLang, $translations, $hasBatchTranslations ) ) {
					++$counts['skipped'];
					continue;
				}

				$result = $this->repository->create(
					$this->buildJobPayload( $post->post_type, $postId, $sourceLang, $targetLang )
				);

				if ( is_wp_error( $result ) ) {
					++$counts['skipped'];
				} else {
					++$counts['queued'];
				}
			}
		}

		return $counts;
	}

	/**
	 * @param int[] $termIds Term IDs.
	 *
	 * @return array{queued:int,skipped:int}
	 */
	public function enqueueTerms( array $termIds ): array {
		$counts               = [
			'queued'  => 0,
			'skipped' => 0,
		];
		$taxonomies           = $this->taxonomies();
		$termIds              = $this->normalizeIds( $termIds );
		$terms                = $this->termsById( $termIds, $taxonomies );
		$langs                = $this->termLanguagesById( $termIds );
		$hasBatchTranslations = function_exists( 'pll_get_term_translations' );
		$translationsById     = $hasBatchTranslations ? $this->termTranslationsById( $termIds ) : [];

		foreach ( $termIds as $termId ) {
			$term = $terms[ $termId ] ?? null;
			if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, $taxonomies, true ) ) {
				++$counts['skipped'];
				continue;
			}

			$sourceLang = $langs[ $termId ] ?? '';
			if ( '' === $sourceLang ) {
				++$counts['skipped'];
				continue;
			}

			$translations = $translationsById[ $termId ] ?? [];
			foreach ( $this->targetLanguages( $sourceLang ) as $targetLang ) {
				if ( $this->hasTermTranslation( $termId, $targetLang, $translations, $hasBatchTranslations ) ) {
					++$counts['skipped'];
					continue;
				}

				$result = $this->repository->create(
					$this->buildJobPayload( 'term', $termId, $sourceLang, $targetLang )
				);

				if ( is_wp_error( $result ) ) {
					++$counts['skipped'];
				} else {
					++$counts['queued'];
				}
			}
		}

		return $counts;
	}

	/**
	 * @return string[]
	 */
	private function postTypes(): array {
		$settings = Plugin::getCachedOptions();
		$types    = array_filter( array_map( 'sanitize_key', (array) ( $settings['ai_content_types'] ?? [] ) ) );

		if ( ! function_exists( 'pll_is_translated_post_type' ) ) {
			return $types;
		}

		return array_values( array_filter( $types, static fn( string $type ): bool => \pll_is_translated_post_type( $type ) ) );
	}

	/**
	 * @return string[]
	 */
	private function taxonomies(): array {
		$taxonomies = get_taxonomies( [ 'show_ui' => true ] );
		if ( ! function_exists( 'pll_is_translated_taxonomy' ) ) {
			return array_values( $taxonomies );
		}

		return array_values( array_filter( $taxonomies, static fn( string $taxonomy ): bool => \pll_is_translated_taxonomy( $taxonomy ) ) );
	}

	/**
	 * @return string[]
	 */
	private function targetLanguages( string $sourceLang ): array {
		$settings = Plugin::getCachedOptions();
		$langs    = array_filter( array_map( 'sanitize_key', (array) ( $settings['ai_default_target_languages'] ?? [] ) ) );

		return array_values( array_filter( $langs, static fn( string $lang ): bool => '' !== $lang && $lang !== $sourceLang ) );
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int[]
	 */
	private function normalizeIds( array $ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids )
				)
			)
		);
	}

	/**
	 * @param int[]    $postIds
	 * @param string[] $postTypes
	 *
	 * @return array<int, \WP_Post>
	 */
	private function postsById( array $postIds, array $postTypes ): array {
		if ( empty( $postIds ) || empty( $postTypes ) ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'      => $postTypes,
				'post_status'    => 'any',
				'post__in'       => $postIds,
				'posts_per_page' => count( $postIds ),
				'orderby'        => 'post__in',
			]
		);

		$indexed = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$indexed[ (int) $post->ID ] = $post;
			}
		}

		return $indexed;
	}

	/**
	 * @param int[] $termIds
	 * @param string[] $taxonomies
	 *
	 * @return array<int, \WP_Term>
	 */
	private function termsById( array $termIds, array $taxonomies ): array {
		if ( empty( $termIds ) || empty( $taxonomies ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomies,
				'include'    => $termIds,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$indexed = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$indexed[ (int) $term->term_id ] = $term;
			}
		}

		return $indexed;
	}

	/**
	 * @param int[] $postIds
	 *
	 * @return array<int, string>
	 */
	private function postLanguagesById( array $postIds ): array {
		$languages = [];
		foreach ( $postIds as $postId ) {
			$languages[ $postId ] = (string) \pll_get_post_language( $postId, 'slug' );
		}

		return $languages;
	}

	/**
	 * @param int[] $termIds
	 *
	 * @return array<int, string>
	 */
	private function termLanguagesById( array $termIds ): array {
		$languages = [];
		foreach ( $termIds as $termId ) {
			$languages[ $termId ] = (string) \pll_get_term_language( $termId, 'slug' );
		}

		return $languages;
	}

	/**
	 * @param int[] $postIds
	 *
	 * @return array<int, array<string, int>>
	 */
	private function postTranslationsById( array $postIds ): array {
		$translations = [];
		foreach ( $postIds as $postId ) {
			$map                     = \pll_get_post_translations( $postId );
			$translations[ $postId ] = is_array( $map ) ? array_map( 'absint', $map ) : [];
		}

		return $translations;
	}

	/**
	 * @param int[] $termIds
	 *
	 * @return array<int, array<string, int>>
	 */
	private function termTranslationsById( array $termIds ): array {
		$translations = [];
		foreach ( $termIds as $termId ) {
			$map                     = \pll_get_term_translations( $termId );
			$translations[ $termId ] = is_array( $map ) ? array_map( 'absint', $map ) : [];
		}

		return $translations;
	}

	/**
	 * @param array<string, int> $translations
	 */
	private function hasPostTranslation( int $postId, string $targetLang, array $translations, bool $translationsComplete ): bool {
		return $translationsComplete
			? ! empty( $translations[ $targetLang ] )
			: (bool) \pll_get_post( $postId, $targetLang );
	}

	/**
	 * @param array<string, int> $translations
	 */
	private function hasTermTranslation( int $termId, string $targetLang, array $translations, bool $translationsComplete ): bool {
		return $translationsComplete
			? ! empty( $translations[ $targetLang ] )
			: (bool) \pll_get_term( $termId, $targetLang );
	}

	/**
	 * Build the standard job payload for the repository.
	 *
	 * @return array<string, mixed>
	 */
	private function buildJobPayload( string $type, int $sourceId, string $sourceLang, string $targetLang ): array {
		return [
			'type'        => $type,
			'source_id'   => $sourceId,
			'source_lang' => $sourceLang,
			'target_lang' => $targetLang,
			'status'      => 'pending',
			'options'     => $this->jobOptions(),
			'attempts'    => 0,
			'last_error'  => '',
			'usage'       => [],
			'results'     => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jobOptions(): array {
		$settings = Plugin::getCachedOptions();

		return [
			'commit'            => true,
			'status'            => sanitize_key( (string) ( $settings['ai_default_post_status'] ?? 'draft' ) ),
			'translate_slug'    => ! empty( $settings['ai_translate_slug'] ),
			'translate_title'   => ! empty( $settings['ai_translate_title'] ),
			'translate_content' => ! empty( $settings['ai_translate_content'] ),
			'translate_excerpt' => ! empty( $settings['ai_translate_excerpt'] ),
			'translate_meta'    => ! empty( $settings['ai_translate_meta_keys'] ),
			'meta_keys'         => array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['ai_translate_meta_keys'] ?? [] ) ) ) ),
			'preserve_author'   => true,
		];
	}

	/**
	 * @param array{queued:int,skipped:int} $counts Job counts.
	 */
	private function redirectWithCounts( string $redirectTo, array $counts ): string {
		return add_query_arg(
			[
				'hd_pll_ai_jobs'    => $counts['queued'],
				'hd_pll_ai_skipped' => $counts['skipped'],
			],
			$redirectTo
		);
	}
}
