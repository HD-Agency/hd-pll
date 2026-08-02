<?php
/**
 * Override for Polylang Free's Translate Slugs preview module.
 *
 * Sets `active_option` to `'none'` so the module displays as "Activated"
 * on the Polylang Settings page when HD PLL Pro's TranslateSlugs feature is enabled.
 *
 * @package HDPLL\Admin
 */

declare(strict_types=1);

namespace HDPLL\Admin;

defined( 'ABSPATH' ) || exit;

class HD_PLL_Settings_Translate_Slugs extends \PLL_Settings_Preview_Translate_Slugs {

	/**
	 * @param \PLL_Settings $polylang Polylang object.
	 * @param array         $args     Optional arguments.
	 */
	public function __construct( &$polylang, array $args = [] ) {
		parent::__construct( $polylang, array_merge( $args, [ 'active_option' => 'none' ] ) );
	}
}
