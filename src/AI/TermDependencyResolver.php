<?php
/**
 * Resolve translated term dependencies before post/product commits.
 *
 * @package HDPLL\AI
 */

declare(strict_types=1);

namespace HDPLL\AI;

defined( 'ABSPATH' ) || exit;

final class TermDependencyResolver {

	/**
	 * Return missing translated terms for a post, ordered parent-first.
	 *
	 * @return array<int, array{term_id:int,taxonomy:string}>
	 */
	public function missingForPost( int $sourceId, string $targetLang ): array|\WP_Error {
		$source = get_post( $sourceId );
		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'hd_pll_ai_dependency_post_not_found', __( 'Source post not found.', 'hd-pll' ) );
		}

		if ( ! function_exists( 'pll_is_translated_taxonomy' ) || ! function_exists( 'pll_get_term' ) ) {
			return [];
		}

		$ordered = [];
		$seen    = [];

		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$taxonomyObject = get_taxonomy( $taxonomy );
			if ( ! $taxonomyObject || ! empty( $taxonomyObject->_pll ) || ! \pll_is_translated_taxonomy( $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $sourceId, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$this->addMissingWithParents( $term, $targetLang, $ordered, $seen );
				}
			}
		}

		return array_values( $ordered );
	}

	/**
	 * @param array<int, array{term_id:int,taxonomy:string}> $ordered Missing terms.
	 * @param array<string, bool>                           $seen    Seen term keys.
	 */
	private function addMissingWithParents( \WP_Term $term, string $targetLang, array &$ordered, array &$seen ): void {
		if ( $term->parent ) {
			$parent = get_term( (int) $term->parent, $term->taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$this->addMissingWithParents( $parent, $targetLang, $ordered, $seen );
			}
		}

		if ( \pll_get_term( $term->term_id, $targetLang ) ) {
			return;
		}

		$key = $term->taxonomy . ':' . $term->term_id;
		if ( isset( $seen[ $key ] ) ) {
			return;
		}

		$seen[ $key ] = true;
		$ordered[]    = [
			'term_id'  => (int) $term->term_id,
			'taxonomy' => $term->taxonomy,
		];
	}
}
