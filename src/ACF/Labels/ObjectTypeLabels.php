<?php
/**
 * ACF Labels — Object Type Labels (CPT & Taxonomy).
 *
 * Registers and translates labels of custom post types or taxonomies
 * created via ACF's UI. Replaces the abstract + 2 child classes pattern.
 *
 * @package HDPLL\ACF\Labels
 */

declare(strict_types=1);

namespace HDPLL\ACF\Labels;

use ACF_Internal_Post_Type;

defined( 'ABSPATH' ) || exit;

final class ObjectTypeLabels {

	/**
	 * @param 'post_type'|'taxonomy' $type      Object type key.
	 * @param string                 $acfClass  ACF internal class name (e.g. 'ACF_Post_Type').
	 * @param string                 $typeLabel Human-readable label for pll_register_string.
	 */
	public function __construct(
		private readonly string $type,
		private readonly string $acfClass,
		private readonly string $typeLabel
	) {}

	/**
	 * Register and translate strings.
	 * Called from ACFIntegration::onAcfInit().
	 */
	public function onAcfInit(): void {
		if ( ! defined( 'ACF_VERSION' ) || version_compare( ACF_VERSION, '6.1.0', '<' ) ) {
			return;
		}

		if ( ! acf_get_setting( 'enable_post_types' ) ) {
			return;
		}

		$this->registerStrings();

		if ( did_action( 'pll_language_defined' ) ) {
			$this->translateRegisteredStrings();
		} else {
			add_action( 'pll_language_defined', [ $this, 'translateRegisteredStrings' ] );
		}
	}

	/**
	 * Register strings for all active ACF-created objects.
	 */
	public function registerStrings(): void {
		foreach ( $this->getAcfTypeInstance()->get_posts( [ 'active' => true ] ) as $acfObject ) {
			$labelStart = sprintf( 'ACF %s, %s,', $this->typeLabel, $acfObject[ $this->type ] );

			pll_register_string( "{$labelStart} title", $acfObject['title'], 'ACF' );
			pll_register_string( "{$labelStart} description", $acfObject['description'], 'ACF', true );

			foreach ( $acfObject['labels'] as $key => $label ) {
				pll_register_string( "{$labelStart} {$key}", $label, 'ACF' );
			}
		}
	}

	/**
	 * Translate object labels when language is ready.
	 */
	public function translateRegisteredStrings(): void {
		$acfObjects = $this->getAcfTypeInstance()->get_posts( [ 'active' => true ] );
		$acfObjects = array_column( $acfObjects, $this->type, $this->type );
		$acfObjects = array_intersect_key( $this->getTypeObjects(), $acfObjects );

		foreach ( $acfObjects as $type ) {
			$type->label       = pll__( $type->label );
			$type->description = pll__( $type->description );

			foreach ( array_keys( get_object_vars( $type->labels ) ) as $key ) {
				$type->labels->$key = pll__( $type->labels->$key );
			}
		}
	}

	private function getAcfTypeInstance(): ACF_Internal_Post_Type {
		return acf_get_instance( $this->acfClass );
	}

	/**
	 * @return object[]
	 */
	private function getTypeObjects(): array {
		return match ( $this->type ) {
			'post_type' => $GLOBALS['wp_post_types'],
			'taxonomy'  => $GLOBALS['wp_taxonomies'],
			default     => [],
		};
	}
}
