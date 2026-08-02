<?php
/**
 * W4: WooCommerce email language switching.
 *
 * Sends customer emails in the order language and
 * shop manager emails in the manager's preferred language.
 * Disables WC's built-in locale switching to avoid conflicts.
 *
 * @package HDPLL\WC
 */

declare(strict_types=1);

namespace HDPLL\WC;

use HD\Core\Helper;

defined( 'ABSPATH' ) || exit;

final class Emails {

	/**
	 * Stack of previous language states for nested email sends.
	 *
	 * @var array{switched: bool, language: \PLL_Language|null}[]
	 */
	private array $previousLanguages = [];

	public function __construct() {

		// Reload WC textdomain on locale switch.
		add_action( 'change_locale', [ $this, 'changeLocale' ], 1 );

		// Disable WC's built-in locale switching.
		add_filter( 'woocommerce_email_setup_locale', '__return_false' );
		add_filter( 'woocommerce_email_restore_locale', '__return_false' );

		// Save customer preferred language on registration.
		add_action( 'woocommerce_created_customer', [ $this, 'createdCustomer' ], 5 );

		// Translate site title in emails.
		add_filter( 'woocommerce_email_format_string_replace', [ $this, 'formatStringReplace' ], 10, 2 );

		// Delay setup until WC_Emails is initialized.
		add_action( 'woocommerce_email', [ $this, 'mailerInit' ] );
	}

	/* ---------- Init sub-hooks after WC_Emails ---------- */

	/**
	 * Setup email language hooks after WC_Emails init.
	 */
	public function mailerInit( \WC_Emails $mailer ): void {
		$this->userEmailsInit();
		$this->customerEmailsInit();
		add_action( 'woocommerce_before_resend_order_emails', [ $this, 'resendOrderEmail' ], 10, 2 );
		add_action( 'woocommerce_after_resend_order_email', [ $this, 'afterEmail' ] );

		if ( apply_filters( 'hd_pll_enable_shop_manager_email_language', true ) ) {
			$this->shopManagerEmailsInit( $mailer );
		}
	}

	/**
	 * User notification emails (new account, password reset).
	 */
	private function userEmailsInit(): void {
		$actions = apply_filters(
			'hd_pll_user_email_actions',
			[
				'woocommerce_created_customer_notification',
				'woocommerce_reset_password_notification',
			]
		);

		foreach ( $actions as $action ) {
			add_action( $action, [ $this, 'beforeUserEmail' ], 1 );
			add_action( $action, [ $this, 'afterEmail' ], 999 );
		}
	}

	/**
	 * Customer order notification emails.
	 */
	private function customerEmailsInit(): void {
		$actions = apply_filters(
			'hd_pll_order_email_actions',
			[
				'woocommerce_order_status_completed_notification',
				'woocommerce_new_customer_note_notification',
				'woocommerce_order_status_failed_to_on-hold_notification',
				'woocommerce_order_status_pending_to_on-hold_notification',
				'woocommerce_order_status_cancelled_to_on-hold_notification',
				'woocommerce_order_status_cancelled_to_processing_notification',
				'woocommerce_order_status_failed_to_processing_notification',
				'woocommerce_order_status_on-hold_to_processing_notification',
				'woocommerce_order_status_pending_to_processing_notification',
				'woocommerce_order_fully_refunded_notification',
				'woocommerce_order_partially_refunded_notification',
				'woocommerce_send_review_request',
			]
		);

		foreach ( $actions as $action ) {
			add_action( $action, [ $this, 'beforeOrderEmail' ], 1 );
			add_action( $action, [ $this, 'afterEmail' ], 999 );
		}
	}

	/**
	 * Shop manager emails — send in manager's preferred language.
	 */
	private function shopManagerEmailsInit( \WC_Emails $mailer ): void {
		if ( empty( $mailer->emails ) ) {
			return;
		}

		$email_config = [
			'WC_Email_Cancelled_Order' => [
				'actions'  => [
					'woocommerce_order_status_processing_to_cancelled_notification',
					'woocommerce_order_status_on-hold_to_cancelled_notification',
				],
				'callback' => 'sendCancelledOrderEmail',
			],
			'WC_Email_Failed_Order'    => [
				'actions'  => [
					'woocommerce_order_status_pending_to_failed_notification',
					'woocommerce_order_status_on-hold_to_failed_notification',
				],
				'callback' => 'sendFailedOrderEmail',
			],
			'WC_Email_New_Order'       => [
				'actions'  => [
					'woocommerce_order_status_pending_to_processing_notification',
					'woocommerce_order_status_pending_to_completed_notification',
					'woocommerce_order_status_pending_to_on-hold_notification',
					'woocommerce_order_status_failed_to_processing_notification',
					'woocommerce_order_status_failed_to_completed_notification',
					'woocommerce_order_status_failed_to_on-hold_notification',
					'woocommerce_order_status_cancelled_to_processing_notification',
					'woocommerce_order_status_cancelled_to_completed_notification',
					'woocommerce_order_status_cancelled_to_on-hold_notification',
				],
				'callback' => 'sendNewOrderEmail',
			],
		];

		foreach ( $email_config as $email_class => $config ) {
			if ( ! isset( $mailer->emails[ $email_class ] ) ) {
				continue;
			}

			$email = $mailer->emails[ $email_class ];
			foreach ( $config['actions'] as $action ) {
				remove_action( $action, [ $email, 'trigger' ], 10 );
				add_action( $action, [ $this, $config['callback'] ] );
			}
		}
	}

	/* ---------- Language switching ---------- */

	/**
	 * Set the email language (switch locale + PLL curlang).
	 */
	public function setEmailLanguage( \PLL_Language $language ): void {
		$this->previousLanguages[] = [
			'switched' => switch_to_locale( $language->locale ),
			'language' => \PLL()->curlang ?? null,
		];

		\PLL()->curlang = $language;

		// Re-init WC page IDs for the new language.
		WCPages::init();

		if ( ! is_locale_switched() ) {
			\PLL()->load_strings_translations( $language->get_locale() );
		}

		do_action( 'hd_pll_email_language' );
	}

	/**
	 * Switch language for order emails.
	 *
	 * @param int|array|\WC_Order $order Order, order ID, or array with 'order_id'.
	 */
	public function beforeOrderEmail( mixed $order ): void {
		$order_id = match ( true ) {
			is_numeric( $order )              => (int) $order,
			is_array( $order )                => (int) ( $order['order_id'] ?? 0 ),
			$order instanceof \WC_Order       => $order->get_id(),
			default                           => 0,
		};

		if ( ! $order_id ) {
			return;
		}

		$language = OrderLanguage::getLanguageObject( $order_id );
		if ( $language ) {
			$this->setEmailLanguage( $language );
		}
	}

	/**
	 * Switch language for admin-resend order emails.
	 */
	public function resendOrderEmail( \WC_Order $order, string $action ): void {
		if ( 'new_order' === $action ) {
			$this->sendNewOrderEmail( $order->get_id() );
			add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
			return;
		}

		$this->beforeOrderEmail( $order );
	}

	/**
	 * Switch language for user emails (new account, password reset).
	 *
	 * @param int|string $user User ID or login.
	 */
	public function beforeUserEmail( int|string $user ): void {
		if ( is_numeric( $user ) ) {
			$user_id = (int) $user;
		} else {
			$wp_user = get_user_by( 'login', $user );
			$user_id = $wp_user instanceof \WP_User ? $wp_user->ID : 0;
		}

		if ( ! $user_id ) {
			return;
		}

		$locale   = get_user_meta( $user_id, 'locale', true ) ?: get_locale();
		$language = \PLL()->model->get_language( $locale );

		if ( $language ) {
			$this->setEmailLanguage( $language );
		}
	}

	/**
	 * Restore previous language after email is sent.
	 */
	public function afterEmail(): void {
		if ( empty( $this->previousLanguages ) ) {
			return;
		}

		$previous = array_pop( $this->previousLanguages );

		if ( $previous['switched'] ) {
			restore_previous_locale();
		}

		\PLL()->curlang = $previous['language'];
	}

	/* ---------- Shop manager email senders ---------- */

	/**
	 * Send order email grouped by manager language.
	 */
	private function sendOrderEmail( \WC_Email $email, int $order_id ): void {
		if ( ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		// Pre-validate: ensure order exists before any language switching.
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$recipients         = $this->getRecipients( $email );
		$emails_by_language = [];

		foreach ( $recipients as $recipient ) {
			$language = $this->getLanguageByEmail( $recipient );
			$emails_by_language[ $language->slug ]['language']     = $language;
			$emails_by_language[ $language->slug ]['recipients'][] = $recipient;
		}

		$original_recipient = $email->recipient;

		try {
			foreach ( $emails_by_language as $em ) {
				$this->setEmailLanguage( $em['language'] );

				try {
					$email->recipient = implode( ',', $em['recipients'] );
					$email->trigger( $order_id, $order );
				} finally {
					$this->afterEmail();
				}
			}
		} finally {
			$email->recipient = $original_recipient;
		}
	}

	public function sendCancelledOrderEmail( int $order_id ): void {
		$this->sendOrderEmail( \WC()->mailer()->emails['WC_Email_Cancelled_Order'], $order_id );
	}

	public function sendFailedOrderEmail( int $order_id ): void {
		$this->sendOrderEmail( \WC()->mailer()->emails['WC_Email_Failed_Order'], $order_id );
	}

	public function sendNewOrderEmail( int $order_id ): void {
		add_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
		try {
			$this->sendOrderEmail( \WC()->mailer()->emails['WC_Email_New_Order'], $order_id );
		} finally {
			remove_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
		}
	}

	/* ---------- Callbacks ---------- */

	/**
	 * Save customer locale on registration.
	 */
	public function createdCustomer( int $user_id ): void {
		update_user_meta( $user_id, 'locale', get_locale() );
	}

	/**
	 * Reload WC textdomain on locale switch.
	 */
	public function changeLocale(): void {
		if ( is_locale_switched() ) {
			if ( isset( \PLL()->filters ) ) {
				remove_filter( 'locale', [ \PLL()->filters, 'get_locale' ] );
				remove_filter( 'load_textdomain_mofile', [ \PLL()->filters, 'load_textdomain_mofile' ] );
			}
			add_filter( 'get_user_metadata', [ $this, 'filterUserLocale' ], 10, 3 );
		} else {
			if ( \PLL() instanceof \PLL_Frontend && isset( \PLL()->filters ) ) {
				add_filter( 'locale', [ \PLL()->filters, 'get_locale' ] );
				add_filter( 'load_textdomain_mofile', [ \PLL()->filters, 'load_textdomain_mofile' ] );
			}
			remove_filter( 'get_user_metadata', [ $this, 'filterUserLocale' ] );
		}
	}

	/**
	 * Translate site title in email placeholders.
	 */
	public function formatStringReplace( array $replace, \WC_Email $email ): array {
		$replace['blogname']   = $email->get_blogname();
		$replace['site-title'] = $email->get_blogname();

		if ( $email->object instanceof \WC_Order ) {
			$order_date = $email->object->get_date_created();
			if ( $order_date ) {
				$replace['order-date'] = \wc_format_datetime( $order_date );
			}
		}

		return $replace;
	}

	/**
	 * Filter user locale during email sending from admin.
	 */
	public function filterUserLocale( mixed $value, int $user_id, string $meta_key ): mixed {
		return 'locale' === $meta_key ? get_locale() : $value;
	}

	/* ---------- Helpers ---------- */

	/**
	 * Get language by user email.
	 */
	private function getLanguageByEmail( string $email ): \PLL_Language {
		$user     = get_user_by( 'email', $email );
		$language = null;

		if ( $user instanceof \WP_User ) {
			$locale   = get_user_meta( $user->ID, 'locale', true );
			$language = $locale ? \PLL()->model->get_language( $locale ) : null;
		}

		return $language ?: \pll_default_language( 'OBJECT' );
	}

	/**
	 * Parse email recipients from WC_Email.
	 *
	 * @return string[]
	 */
	private function getRecipients( \WC_Email $email ): array {
		$recipients = $email->get_option( 'recipient', Helper::getOption( 'admin_email' ) );
		$recipients = explode( ',', $recipients );
		$recipients = array_map( 'trim', $recipients );

		return array_filter( $recipients );
	}
}
