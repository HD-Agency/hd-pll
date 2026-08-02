<?php
/**
 * AI translation row actions.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class RowActions {

	public function register(): void {
		add_filter( 'post_row_actions', [ $this, 'postActions' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'postActions' ], 10, 2 );
		add_filter( 'tag_row_actions', [ $this, 'termActions' ], 10, 2 );
	}

	/**
	 * @param array<string, string> $actions Row actions.
	 *
	 * @return array<string, string>
	 */
	public function postActions( array $actions, \WP_Post $post ): array {
		if ( ! AiClient::isAvailable() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		if ( ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( $post->post_type ) ) {
			return $actions;
		}

		if ( 'trash' === $post->post_status ) {
			return $actions;
		}

		foreach ( $this->missingLanguages( $post->ID, 'pll_get_post' ) as $lang ) {
			$actions[ 'hd_pll_ai_' . $lang ] = sprintf(
				'<a href="#" class="hd-pll-ai-action" data-type="%s" data-source-id="%d" data-target-lang="%s">%s</a>',
				esc_attr( $post->post_type ),
				$post->ID,
				esc_attr( $lang ),
				esc_html( strtoupper( $lang ) )
			);
		}

		return $actions;
	}

	/**
	 * @param array<string, string> $actions Row actions.
	 *
	 * @return array<string, string>
	 */
	public function termActions( array $actions, \WP_Term $term ): array {
		if ( ! AiClient::isAvailable() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_categories' ) ) {
			return $actions;
		}

		foreach ( $this->missingLanguages( $term->term_id, 'pll_get_term' ) as $lang ) {
			$actions[ 'hd_pll_ai_' . $lang ] = sprintf(
				'<a href="#" class="hd-pll-ai-action" data-type="term" data-source-id="%d" data-target-lang="%s">%s</a>',
				$term->term_id,
				esc_attr( $lang ),
				esc_html( strtoupper( $lang ) )
			);
		}

		return $actions;
	}

	/**
	 * Get languages for which this object has no translation.
	 *
	 * @param int      $id     Object ID.
	 * @param callable $lookup PLL lookup fn (pll_get_post or pll_get_term).
	 *
	 * @return string[]
	 */
	private function missingLanguages( int $id, callable $lookup ): array {
		$languages = function_exists( 'pll_languages_list' ) ? pll_languages_list( [ 'fields' => 'slug' ] ) : [];

		return array_values(
			array_filter(
				(array) $languages,
				static fn( string $lang ): bool => ! $lookup( $id, $lang )
			)
		);
	}
}
