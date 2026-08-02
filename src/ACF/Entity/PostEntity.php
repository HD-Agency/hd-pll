<?php
/**
 * ACF Entity — Post fields.
 *
 * Handles ACF fields attached to translated post types.
 * Manages copy/sync on translation creation and synchronization.
 *
 * @package HDPLL\ACF\Entity
 */

declare(strict_types=1);

namespace HDPLL\ACF\Entity;

use PLL_Admin_Links;
use PLL_Language;
use HDPLL\ACF\Strategy\CopyStrategy;

defined( 'ABSPATH' ) || exit;

class PostEntity extends AbstractEntity {

	/**
	 * Previous language slug — used to reset ACF field store on lang switch.
	 */
	protected static string $previousLang = '';

	protected static function acfId( int $id ): int|string {
		return $id;
	}

	public function getType(): string {
		return 'post';
	}

	protected function getFromIdInRequest(): int {
		if ( ! \PLL()->links instanceof PLL_Admin_Links ) {
			return 0;
		}

		$data = \PLL()->links->get_data_from_new_post_translation_request();

		return ! empty( $data['from_post'] ) ? $data['from_post']->ID : 0;
	}

	/**
	 * Copy or sync ACF fields when PLL synchronizes/copies a post.
	 *
	 * Hooked to `pll_post_synchronized`.
	 *
	 * @param int    $trPostId Target post ID.
	 * @param string $lang     Target language slug.
	 * @param string $mode     'sync' or 'copy'.
	 */
	public function onPostSynchronized( int $trPostId, string $lang, string $mode ): void {
		$lang = \PLL()->model->get_language( $lang );
		if ( empty( $lang ) ) {
			return;
		}

		$this->maybeResetFieldsStore( $lang );

		// Both 'copy' and 'sync' modes: copy fields to the target post.
		// The $mode distinction ('copy' vs 'sync') is handled by PLL core
		// for post content. For ACF fields, we always copy with ID translation.
		$this->applyToAllFields( new CopyStrategy(), $trPostId, [ 'target_language' => $lang ] );
	}

	/**
	 * Reset ACF field store when target language changes.
	 *
	 * Ensures default values are resolved in the correct language context.
	 */
	protected function maybeResetFieldsStore( PLL_Language $lang ): void {
		if ( self::$previousLang !== $lang->slug ) {
			$store = acf_get_store( 'fields' );
			$store->reset();
			self::$previousLang = $lang->slug;
		}
	}
}
