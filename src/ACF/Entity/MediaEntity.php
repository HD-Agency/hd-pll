<?php
/**
 * ACF Entity — Media (Attachment) fields.
 *
 * Handles ACF fields on media attachments. Extends PostEntity
 * with media-specific ACF post ID format and copy logic.
 *
 * @package HDPLL\ACF\Entity
 */

declare(strict_types=1);

namespace HDPLL\ACF\Entity;

use PLL_Language;
use HDPLL\ACF\Strategy\CopyStrategy;

defined( 'ABSPATH' ) || exit;

final class MediaEntity extends PostEntity {

	protected static function acfId( int $id ): string {
		return 'attachment_' . $id;
	}

	/**
	 * Copy all ACF fields to a new media translation.
	 *
	 * Hooked to `pll_translate_media`.
	 *
	 * @param int          $toId           Target media ID.
	 * @param PLL_Language $targetLanguage Target language.
	 */
	public function copyFields( int $toId, PLL_Language $targetLanguage ): void {
		$this->maybeResetFieldsStore( $targetLanguage );
		$this->applyToAllFields( new CopyStrategy(), $toId, [ 'target_language' => $targetLanguage ] );
	}

	/**
	 * No-op for media. Media translations use a 2-step redirect flow:
	 * 1. Request creates the media translation.
	 * 2. Redirect to edit page where ACF loads fields.
	 *
	 * @param array $field ACF field definition.
	 *
	 * @return array Unmodified field.
	 */
	public function renderField( array $field ): array {
		return $field;
	}
}
