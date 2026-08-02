<?php
/**
 * W3: WooCommerce string translations.
 *
 * Registers WC-specific strings into Polylang's string translation system
 * and translates them on the frontend. Covers: email subjects/headings,
 * gateway titles/descriptions, shipping method titles, tax labels,
 * attribute labels, price format options, and misc WC options.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

use HD\Core\DB;
use HD\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class Strings {

	/** @var array<string, \PLL_MO> */
	private static array $moCache = [];

	/**
	 * WC option keys to register + translate.
	 */
	private const WC_OPTIONS = [
		'email_footer_text'                           => [
			'name'      => 'Footer text',
			'multiline' => true,
		],
		'demo_store_notice'                           => [
			'name'      => 'Store notice text',
			'multiline' => true,
		],
		'price_display_suffix'                        => [ 'name' => 'price_display_suffix' ],
		'currency_pos'                                => [ 'name' => 'Currency position' ],
		'price_thousand_sep'                          => [ 'name' => 'Thousand separator' ],
		'price_decimal_sep'                           => [ 'name' => 'Decimal separator' ],
		'registration_privacy_policy_text'            => [
			'name'      => 'Registration privacy policy',
			'multiline' => true,
		],
		'checkout_privacy_policy_text'                => [
			'name'      => 'Checkout privacy policy',
			'multiline' => true,
		],
		'checkout_terms_and_conditions_checkbox_text' => [ 'name' => 'Terms and conditions' ],
		'email_from_name'                             => [ 'name' => 'email_from_name' ],
		'email_from_address'                          => [ 'name' => 'email_from_address' ],
		'email_header_image'                          => [ 'name' => 'Email header image' ],
	];

	public function __construct() {
		// Translate strings in emails (called by Emails module).
		add_action( 'hd_pll_email_language', [ $this, 'translateEmails' ] );

		if ( \PLL() instanceof \PLL_Frontend || \PLL() instanceof \PLL_REST_Request ) {
			add_action( 'init', [ $this, 'translateStrings' ] );
		}

		if ( ! ( \PLL() instanceof \PLL_Frontend ) ) {
			add_filter( 'woocommerce_attribute_label', [ $this, 'attributeLabel' ], 10, 3 );
		}

		if ( \PLL() instanceof \PLL_Settings ) {
			add_action( 'init', [ $this, 'registerStrings' ], 99 );
			add_filter( 'pll_sanitize_string_translation', [ $this, 'sanitizeStrings' ], 10, 3 );
		}

		// Translate gateway/shipping options via PLL_Translate_Option.
		add_action( 'woocommerce_init', [ $this, 'translateOptions' ] );
		add_filter( 'woocommerce_shipping_zone_shipping_methods', [ $this, 'translateShippingMethods' ] );
		add_action( 'wc_payment_gateways_initialized', [ $this, 'translatePaymentGateways' ] );
	}

	/* ---------- Registration (admin only, on PLL settings page) ---------- */

	/**
	 * Register all WC strings into Polylang.
	 */
	public function registerStrings(): void {
		// Emails: subject, heading, additional_content.
		$this->registerSubOptions(
			\WC_Emails::instance()->get_emails(),
			fn( string $prop ): bool => str_starts_with( $prop, 'subject' ) || str_starts_with( $prop, 'heading' ) || str_starts_with( $prop, 'additional_content' ),
			fn( string $prop ): bool => 'additional_content' === $prop
		);

		// BACS account details.
		$bacs_accounts = Helper::getOption( 'woocommerce_bacs_accounts', [] );
		if ( is_array( $bacs_accounts ) ) {
			foreach ( $bacs_accounts as $account ) {
				\pll_register_string( 'Account name', $account['account_name'], 'WooCommerce' );
				\pll_register_string( 'Bank name', $account['bank_name'], 'WooCommerce' );
			}
		}

		// Shipping methods (loads zones).
		\WC_Shipping_Zones::get_zones();
		$this->registerSubOptions(
			\WC_Shipping::instance()->get_shipping_methods(),
			fn( string $prop ): bool => 'title' === $prop
		);

		// Single WC options.
		foreach ( self::WC_OPTIONS as $key => $arr ) {
			$option = Helper::getOption( "woocommerce_{$key}" );
			if ( $option ) {
				\pll_register_string( $arr['name'], $option, 'WooCommerce', ! empty( $arr['multiline'] ) );
			}
		}

		// Attribute labels.
		foreach ( \wc_get_attribute_taxonomies() as $attr ) {
			\pll_register_string( 'Attribute', $attr->attribute_label, 'WooCommerce' );
		}

		// Tax rate labels.
		$db = DB::db();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no user input.
		$labels = $db->get_col( "SELECT tax_rate_name FROM {$db->prefix}woocommerce_tax_rates WHERE tax_rate_name != ''" );
		foreach ( $labels as $label ) {
			\pll_register_string( 'Tax name', $label, 'WooCommerce' );
		}

		// Local pickup locations.
		$pickup_locations = Helper::getOption( 'pickup_location_pickup_locations', [] );
		if ( is_array( $pickup_locations ) ) {
			foreach ( $pickup_locations as $location ) {
				if ( ! empty( $location['name'] ) ) {
					\pll_register_string( 'Local pickup location name', $location['name'], 'WooCommerce' );
				}
				if ( ! empty( $location['details'] ) ) {
					\pll_register_string( 'Local pickup location details', $location['details'], 'WooCommerce' );
				}
			}
		}

		$pickup_settings = Helper::getOption( 'woocommerce_pickup_location_settings', [] );
		if ( is_array( $pickup_settings ) && ! empty( $pickup_settings['title'] ) ) {
			\pll_register_string( 'Local pickup title', $pickup_settings['title'], 'WooCommerce' );
		}
	}

	/**
	 * Register sub-options (emails, shipping) into PLL string translation.
	 *
	 * @param \WC_Settings_API[] $objects          Objects with form_fields.
	 * @param callable           $isTranslatedCb   Predicate: should this prop be translated?
	 * @param callable|null      $isMultilineCb    Predicate: should input be multiline?
	 */
	private function registerSubOptions( array $objects, callable $isTranslatedCb, ?callable $isMultilineCb = null ): void {
		foreach ( $objects as $obj ) {
			if ( isset( $obj->enabled ) && 'no' === $obj->enabled ) {
				continue;
			}

			foreach ( array_keys( $obj->form_fields ) as $prop ) {
				if ( ! $isTranslatedCb( $prop ) ) {
					continue;
				}

				$value = ( $obj->settings[ $prop ] ?? '' )
					?: ( $obj->form_fields[ $prop ]['default'] ?? $obj->$prop ?? '' );
				if ( ! empty( $value ) ) {
					$multiline = $isMultilineCb ? $isMultilineCb( $prop ) : false;
					\pll_register_string( "{$prop}_{$obj->id}", $value, 'WooCommerce', $multiline );
				}
			}
		}
	}

	/* ---------- Translation (frontend) ---------- */

	/**
	 * Setup filters to translate WC strings on frontend.
	 */
	public function translateStrings(): void {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		// Gateway instructions.
		add_action( 'woocommerce_email_before_order_table', [ $this, 'translateInstructions' ], 5 );
		add_action( 'woocommerce_before_thankyou', [ $this, 'translateInstructions' ] );

		// BACS accounts.
		add_filter( 'woocommerce_bacs_accounts', [ $this, 'translateBacsAccounts' ] );

		// Reset shipping when language cookie differs.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Value used in comparison only.
		if ( defined( 'PLL_COOKIE' ) && isset( $_COOKIE[ PLL_COOKIE ] ) && \pll_current_language() !== $_COOKIE[ PLL_COOKIE ] ) {
			add_action(
				'woocommerce_before_calculate_totals',
				static function (): void {
					unset( \WC()->session->shipping_for_package );
				}
			);
		}

		// WC options.
		foreach ( array_keys( self::WC_OPTIONS ) as $key ) {
			add_filter( "option_woocommerce_{$key}", 'pll__' );
		}

		// Attributes.
		add_filter( 'woocommerce_attribute_taxonomies', [ $this, 'attributeTaxonomies' ] );
		add_filter( 'woocommerce_attribute_label', 'pll__' );

		// Tax labels.
		add_filter( 'woocommerce_rate_label', 'pll__' );
		add_filter( 'woocommerce_find_rates', [ $this, 'findRates' ] );
		add_filter( 'woocommerce_order_get_tax_totals', [ $this, 'setTaxLabel' ] );

		// Gateways.
		add_filter( 'woocommerce_gateway_title', 'pll__' );
		add_filter( 'woocommerce_gateway_description', 'pll__' );

		// Shipping methods.
		add_filter( 'woocommerce_package_rates', [ $this, 'translateShipping' ] );
		add_filter( 'woocommerce_shipping_rate_label', 'pll__' );
	}

	/**
	 * Translate emails: subject, heading, options + site title/date.
	 */
	public function translateEmails(): void {
		add_filter( 'woocommerce_email_get_option', [ $this, 'translateEmailOption' ], 10, 4 );

		// Blog name/description/date/time format.
		foreach ( [ 'option_blogname', 'option_blogdescription', 'option_date_format', 'option_time_format' ] as $filter ) {
			add_filter( $filter, 'pll__', 1 );
		}

		$this->translateStrings();

		// Re-init email settings in case of bulk sends.
		foreach ( \WC_Emails::instance()->get_emails() as $email ) {
			$email->init_settings();
		}
	}

	/* ---------- Translate Options (PLL_Translate_Option) ---------- */

	/**
	 * Translate pickup location options.
	 */
	public function translateOptions(): void {
		if ( ! class_exists( 'PLL_Translate_Option' ) ) {
			return;
		}

		new \PLL_Translate_Option(
			'pickup_location_pickup_locations',
			[
				'*' => [
					'name'    => 1,
					'details' => 1,
				],
			],
			[ 'context' => 'WooCommerce' ]
		);

		new \PLL_Translate_Option(
			'woocommerce_pickup_location_settings',
			[ 'title' => 1 ],
			[ 'context' => 'WooCommerce' ]
		);
	}

	/**
	 * Translate shipping methods from zones.
	 *
	 * @param \WC_Shipping_Method[] $methods Shipping method instances.
	 *
	 * @return \WC_Shipping_Method[]
	 */
	public function translateShippingMethods( array $methods ): array {
		if ( ! class_exists( 'PLL_Translate_Option' ) ) {
			return $methods;
		}

		foreach ( $methods as $method ) {
			if ( ! $method instanceof \WC_Shipping_Method ) {
				continue;
			}

			new \PLL_Translate_Option(
				$method->get_instance_option_key(),
				[ 'title' => true ],
				[ 'context' => 'WooCommerce' ]
			);
		}

		return $methods;
	}

	/**
	 * Translate payment gateway options via PLL_Translate_Option.
	 *
	 * @param \WC_Payment_Gateways $gateways WC gateways registry.
	 */
	public function translatePaymentGateways( \WC_Payment_Gateways $gateways ): void {
		if ( ! class_exists( 'PLL_Translate_Option' ) ) {
			return;
		}

		foreach ( $gateways->payment_gateways() as $gateway ) {
			new \PLL_Translate_Option(
				$gateway->get_option_key(),
				[
					'title'        => true,
					'description'  => true,
					'instructions' => true,
				],
				[ 'context' => 'WooCommerce' ]
			);
		}
	}

	/* ---------- Individual translation callbacks ---------- */

	/**
	 * Translate email subject/heading/additional_content options.
	 */
	public function translateEmailOption( string $value, \WC_Email $email, string $_value, string $key ): string {
		if ( str_starts_with( $key, 'subject' ) || str_starts_with( $key, 'heading' ) || str_starts_with( $key, 'additional_content' ) ) {
			return \pll__( $value );
		}

		return $value;
	}

	/**
	 * Translate gateway instructions on thankyou page and in emails.
	 */
	public function translateInstructions(): void {
		if ( ! class_exists( 'PLL_Translate_Option' ) ) {
			return;
		}

		$gateways = \WC_Payment_Gateways::instance()->get_available_payment_gateways();
		foreach ( $gateways as $gateway ) {
			if ( ! isset( $gateway->form_fields['instructions'] ) ) {
				continue;
			}

			$gateway->init_settings();

			new \PLL_Translate_Option(
				$gateway->get_option_key(),
				[ 'instructions' => true ],
				[ 'context' => 'WooCommerce' ]
			);
		}
	}

	/**
	 * Translate BACS account names and bank names.
	 */
	public function translateBacsAccounts( mixed $accounts ): mixed {
		if ( ! is_array( $accounts ) ) {
			return $accounts;
		}

		foreach ( $accounts as $k => $account ) {
			$accounts[ $k ]['account_name'] = \pll__( $account['account_name'] );
			$accounts[ $k ]['bank_name']    = \pll__( $account['bank_name'] );
		}

		return $accounts;
	}

	/**
	 * Translate attribute labels.
	 *
	 * @param \stdClass[] $attribute_taxonomies Attribute taxonomies.
	 *
	 * @return \stdClass[]
	 */
	public function attributeTaxonomies( array $attribute_taxonomies ): array {
		foreach ( $attribute_taxonomies as $attr ) {
			$attr->attribute_label = \pll__( $attr->attribute_label );
		}

		return $attribute_taxonomies;
	}

	/**
	 * Translate attribute label on admin (for variation titles).
	 */
	public function attributeLabel( string $label, string $name, mixed $product ): string {
		if ( ! ( $product instanceof \WC_Product ) || doing_action( 'wp_ajax_woocommerce_do_ajax_product_export' ) ) {
			return $label;
		}

		$lang = \pll_get_post_language( $product->get_id() );
		if ( ! $lang ) {
			return $label;
		}

		$language = \PLL()->model->get_language( $lang );
		if ( ! $language ) {
			return $label;
		}

		return $this->moForLanguage( $language )->translate( $label );
	}

	private function moForLanguage( object $language ): \PLL_MO {
		$key = (string) ( $language->slug ?? $language->locale ?? spl_object_id( $language ) );

		if ( ! isset( self::$moCache[ $key ] ) ) {
			$mo = new \PLL_MO();
			$mo->import_from_db( $language );
			self::$moCache[ $key ] = $mo;
		}

		return self::$moCache[ $key ];
	}

	/**
	 * Translate shipping rate labels.
	 *
	 * @param \WC_Shipping_Rate[] $rates Shipping rates.
	 *
	 * @return \WC_Shipping_Rate[]
	 */
	public function translateShipping( array $rates ): array {
		foreach ( $rates as $key => $rate ) {
			$rates[ $key ]->set_label( \pll__( $rate->get_label() ) );
		}

		return $rates;
	}

	/**
	 * Translate tax rate labels.
	 */
	public function findRates( array $rates ): array {
		foreach ( $rates as $k => $rate ) {
			$rates[ $k ]['label'] = \pll__( $rate['label'] );
		}

		return $rates;
	}

	/**
	 * Refresh tax rate labels on orders (for emails).
	 */
	public function setTaxLabel( array $tax_totals ): array {
		foreach ( $tax_totals as $code => $tax ) {
			$tax_totals[ $code ]->label = \WC_Tax::get_rate_label( $tax->rate_id );
		}

		return $tax_totals;
	}

	/**
	 * Sanitize WC string translations.
	 */
	public function sanitizeStrings( string $translation, string $name, string $context ): string {
		if ( 'WooCommerce' !== $context ) {
			return $translation;
		}

		return match ( $name ) {
			'Account name', 'Bank name', 'Attribute', 'Tax name',
			'Local pickup location name', 'Local pickup location details', 'Local pickup title',
			'price_display_suffix', 'Thousand separator', 'Decimal separator',
			'email_from_name' => \wc_clean( $translation ),

			'email_from_address' => \sanitize_email( $translation ),
			'Email header image' => \sanitize_url( $translation ),

			'Currency position' => in_array( $translation, [ 'left', 'right', 'left_space', 'right_space' ], true )
				? $translation
				: Helper::getOption( 'woocommerce_currency_pos', 'left' ),

			default => \wp_kses_post( trim( $translation ) ),
		};
	}
}
