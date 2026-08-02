<?php
/**
 * Unified term translation/duplication model.
 *
 * Consolidates all term entity-creation logic into a single source of truth.
 *
 * Inspired by polylang-pro's PLL_Translation_Term_Model.
 *
 * @package HDPLL\Models
 */

declare(strict_types=1);

namespace HDPLL\Models;

defined( 'ABSPATH' ) || exit;

final class TranslationTermModel {

	/**
	 * Duplicate a term into a target language.
	 *
	 * If `$translatedFields` is provided (AI mode), overlay translated name/description
	 * onto the duplicated term.
	 *
	 * @param int                    $sourceTermId     Source term ID.
	 * @param string                 $targetLang       Target language slug.
	 * @param array<string, string>  $translatedFields Optional translated fields (term_name, term_description).
	 * @param array<string, mixed>   $options          Options: overwrite, translate_slug.
	 *
	 * @return int|\WP_Error Target term ID or error.
	 */
	public function duplicate( int $sourceTermId, string $targetLang, array $translatedFields = [], array $options = [] ): int|\WP_Error {
		$term = get_term( $sourceTermId );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'hd_pll_source_term_not_found', __( 'Source term not found.', 'hd-pll' ) );
		}

		$sourceLang = \pll_get_term_language( $sourceTermId );
		if ( ! $sourceLang || ! \PLL()->model->get_language( $targetLang ) ) {
			return new \WP_Error( 'hd_pll_invalid_language', __( 'Invalid source or target language.', 'hd-pll' ) );
		}

		$targetId  = \pll_get_term( $sourceTermId, $targetLang ) ?: 0;
		$overwrite = ! empty( $options['overwrite'] );

		if ( $targetId > 0 && ! $overwrite ) {
			return new \WP_Error( 'hd_pll_target_term_exists', __( 'Term translation already exists. Use overwrite option.', 'hd-pll' ) );
		}

		// Determine content: use translated fields if provided, otherwise copy source.
		$name        = $translatedFields['term_name'] ?? $term->name;
		$description = $translatedFields['term_description'] ?? $term->description;

		$args = [
			'description' => $description,
		];

		if ( ! empty( $options['translate_slug'] ) ) {
			$args['slug'] = sanitize_title( $name ) . '-' . $targetLang;
		}

		// Resolve parent hierarchy.
		if ( $term->parent ) {
			$parent = \pll_get_term( (int) $term->parent, $targetLang );
			if ( ! $parent ) {
				return new \WP_Error( 'hd_pll_missing_parent_term', __( 'Translated parent term is missing.', 'hd-pll' ) );
			}
			$args['parent'] = $parent;
		}

		if ( $targetId > 0 ) {
			$result = wp_update_term(
				$targetId,
				$term->taxonomy,
				[
					...$args,
					'name' => $name,
				]
			);
		} else {
			$result = wp_insert_term( $name, $term->taxonomy, $args );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$targetId = (int) $result['term_id'];

		// Set language and link translations.
		\pll_set_term_language( $targetId, $targetLang );
		$translations                = \pll_get_term_translations( $sourceTermId );
		$translations[ $sourceLang ] = $sourceTermId;
		$translations[ $targetLang ] = $targetId;
		\pll_save_term_translations( $translations );

		// Copy term meta.
		$this->copyTermMeta( $sourceTermId, $targetId );

		/**
		 * Fires after a term has been duplicated/translated.
		 *
		 * @param int    $sourceTermId Source term ID.
		 * @param int    $targetId     Target term ID.
		 * @param string $targetLang   Target language slug.
		 */
		do_action( 'hd_pll_term_duplicated', $sourceTermId, $targetId, $targetLang );

		return $targetId;
	}

	/**
	 * Copy all term meta from source to target.
	 */
	private function copyTermMeta( int $sourceId, int $targetId ): void {
		$sourceMeta = get_term_meta( $sourceId );
		if ( ! is_array( $sourceMeta ) || empty( $sourceMeta ) ) {
			return;
		}

		foreach ( $sourceMeta as $key => $values ) {
			// Skip PLL internal meta.
			if ( str_starts_with( $key, '_pll_' ) ) {
				continue;
			}

			delete_term_meta( $targetId, $key );
			foreach ( $values as $value ) {
				add_term_meta( $targetId, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}
}
