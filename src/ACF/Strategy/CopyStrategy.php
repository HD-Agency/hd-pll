<?php
/**
 * ACF Strategy — Copy.
 *
 * Copies field values from source to target, translating IDs
 * (images, posts, terms, galleries, page links) to the target language.
 *
 * Used during translation creation and bulk copy operations.
 *
 * @package HDPLL\ACF\Strategy
 */

declare(strict_types=1);

namespace HDPLL\ACF\Strategy;

use PLL_Language;
use HDPLL\ACF\Entity\AbstractEntity;

defined( 'ABSPATH' ) || exit;

class CopyStrategy extends AbstractStrategy {

	/** @var array<int, array<string, mixed>> */
	private array $translatableSlugsCache = [];

	/**
	 * Apply copy logic on a single (non-nested) field.
	 *
	 * Translates IDs based on field type.
	 */
	protected function apply( AbstractEntity $entity, mixed $value, array $field, array $args = [] ): mixed {
		$targetLang = $args['target_language'] ?? null;
		if ( ! $targetLang instanceof PLL_Language ) {
			return $value;
		}

		$value = match ( $field['type'] ) {
			'image', 'file'              => $this->translateMedia( $value, $targetLang ),
			'gallery'                    => $this->translateGallery( $value, $targetLang ),
			'post_object', 'relationship' => $this->translatePost( $value, $targetLang ),
			'taxonomy'                   => $this->translateTaxonomy( $value, $field, $targetLang ),
			'page_link'                  => $this->translatePageLink( $value, $targetLang ),
			'wysiwyg'                    => $this->translateWysiwyg( $value, $targetLang ),
			default                      => $value,
		};

		return $this->maybeTranslateDefaultValue( $value, $field, $args );
	}

	/**
	 * A field can be copied if its `translations` setting is not `ignore`.
	 */
	protected function canExecuteRecursive( array $field ): bool {
		if ( isset( $field['translations'] ) && 'ignore' !== $field['translations'] ) {
			return true;
		}

		return parent::canExecuteRecursive( $field );
	}

	/* ---------- Field type translators ---------------------------- */

	/**
	 * Translate a single media ID.
	 */
	protected function translateMedia( mixed $value, PLL_Language $lang ): mixed {
		if ( ! \PLL()->options['media_support'] || ! is_numeric( $value ) ) {
			return $value;
		}

		$trId = pll_get_post( (int) $value, $lang );

		// Auto-create media translation if it doesn't exist.
		if ( ! $trId ) {
			$trId = \PLL()->model->post->create_media_translation( (int) $value, $lang );
		}

		// Fall back to original if translation creation failed.
		return $trId ?: $value;
	}

	/**
	 * Translate gallery (array of media IDs).
	 *
	 * @param mixed        $values Gallery values.
	 * @param PLL_Language $lang   Target language.
	 *
	 * @return array Translated gallery IDs.
	 */
	protected function translateGallery( mixed $values, PLL_Language $lang ): array {
		if ( ! \PLL()->options['media_support'] || ! is_array( $values ) ) {
			return is_array( $values ) ? $values : [];
		}

		$result = [];
		foreach ( $values as $value ) {
			$result[] = $this->translateMedia( (int) $value, $lang );
		}

		// ACF gallery stores IDs as strings.
		return array_map( 'strval', $result );
	}

	/**
	 * Translate post_object / relationship field IDs.
	 *
	 * @param mixed        $value Single ID or array of IDs.
	 * @param PLL_Language $lang  Target language.
	 *
	 * @return mixed Translated ID(s).
	 */
	protected function translatePost( mixed $value, PLL_Language $lang ): mixed {
		if ( is_numeric( $value ) ) {
			$postType = get_post_type( (int) $value );
			if ( ! $postType || ! pll_is_translated_post_type( $postType ) ) {
				return $value;
			}

			$trId = pll_get_post( (int) $value, $lang );

			// Fall back to original if no translation exists.
			return $trId ?: $value;
		}

		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $id ) {
				$result[] = $this->translatePost( $id, $lang );
			}

			return array_map( 'strval', $result );
		}

		return $value;
	}

	/**
	 * Translate taxonomy field IDs.
	 */
	protected function translateTaxonomy( mixed $value, array $field, PLL_Language $lang ): mixed {
		if ( ! pll_is_translated_taxonomy( $field['taxonomy'] ?? '' ) ) {
			return $value;
		}

		return $this->translateTerm( $value, $lang );
	}

	/**
	 * Translate term ID(s).
	 */
	protected function translateTerm( mixed $value, PLL_Language $lang ): mixed {
		if ( is_numeric( $value ) ) {
			$trId = pll_get_term( (int) $value, $lang );

			// Fall back to original if no translation exists.
			return $trId ?: $value;
		}

		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $id ) {
				$result[] = $this->translateTerm( $id, $lang );
			}

			return $result;
		}

		return $value;
	}

	/**
	 * Translate page_link field (post IDs + CPT archive URLs).
	 */
	protected function translatePageLink( mixed $value, PLL_Language $lang ): mixed {
		if ( is_numeric( $value ) ) {
			return pll_get_post( (int) $value, $lang ) ?: $value;
		}

		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $p ) {
				if ( is_numeric( $p ) ) {
					$result[] = pll_get_post( (int) $p, $lang ) ?: $p;
				} elseif ( is_string( $p ) ) {
					$result[] = $this->translateCptArchiveLink( $p, $lang );
				}
			}

			return array_map( 'strval', $result );
		}

		if ( is_string( $value ) ) {
			return $this->translateCptArchiveLink( $value, $lang );
		}

		return $value;
	}

	/**
	 * Translate a CPT archive link URL.
	 */
	protected function translateCptArchiveLink( string $link, PLL_Language $lang ): string {
		// ACF doesn't use trailing slash for home_url().
		if ( home_url() === $link ) {
			$link = home_url( '/' );
		}

		if ( 'page' === get_option( 'show_on_front' ) && is_numeric( get_option( 'page_for_posts' ) ) ) {
			$postArchiveLink = get_permalink( $lang->page_for_posts );

			return ! empty( $postArchiveLink ) ? $postArchiveLink : $link;
		}

		$link = \PLL()->links_model->switch_language_in_link( $link, $lang );

		if ( isset( \PLL()->translate_slugs ) ) {
			$slugsModel = \PLL()->translate_slugs->slugs_model ?? null;
			foreach ( $this->translatableSlugs( $slugsModel ) as $type => $data ) {
				if ( str_starts_with( (string) $type, 'archive_' ) && is_object( $slugsModel ) ) {
					$link = $slugsModel->switch_translated_slug( $link, $lang, (string) $type );
				}
			}
		}

		return $link;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function translatableSlugs( mixed $slugsModel ): array {
		if ( ! is_object( $slugsModel ) || ! method_exists( $slugsModel, 'get_translatable_slugs' ) ) {
			return [];
		}

		$key = spl_object_id( $slugsModel );
		if ( ! array_key_exists( $key, $this->translatableSlugsCache ) ) {
			$slugs                                = $slugsModel->get_translatable_slugs();
			$this->translatableSlugsCache[ $key ] = is_array( $slugs ) ? $slugs : [];
		}

		return $this->translatableSlugsCache[ $key ];
	}

	/**
	 * Translate wysiwyg content (embedded links/images).
	 */
	protected function translateWysiwyg( mixed $value, PLL_Language $lang ): mixed {
		if ( is_string( $value ) && isset( \PLL()->sync_content ) ) {
			return \PLL()->sync_content->translate_content( $value, null, $lang );
		}

		return $value;
	}

	/**
	 * Handle translatable default values.
	 *
	 * If the field's value equals the default value in the source language,
	 * return the original (target) value instead.
	 */
	protected function maybeTranslateDefaultValue( mixed $value, array $field, array $args ): mixed {
		if ( ! isset( $field['pll_default_value'], $args['source_language'] ) || ! $args['source_language'] instanceof PLL_Language ) {
			return $value;
		}

		$defaultInSourceLang = pll_translate_string( $field['pll_default_value'], $args['source_language']->slug );

		return $defaultInSourceLang === $value ? $args['original_value'] : $value;
	}
}
