<?php
/**
 * W-A3: Order Language Admin UI for Polylang (HPOS-only).
 *
 * @package HDPLL\WC\Admin
 */

declare(strict_types=1);

namespace HDPLL\WC\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminOrders {

	public function __construct() {
		add_action( 'wp_loaded', [ $this, 'registerColumns' ], 20 );
		add_action( 'add_meta_boxes', [ $this, 'addMetaBoxes' ], 50 );
		add_filter( 'woocommerce_admin_order_actions', [ $this, 'adminOrderActions' ] );
		add_filter( 'woocommerce_admin_order_preview_actions', [ $this, 'adminOrderActions' ] );
		add_filter( 'pll_admin_current_language', [ $this, 'setCurrentLanguage' ] );
		add_action( 'woocommerce_after_order_object_save', [ $this, 'saveOrderLanguage' ] );
	}

	public function registerColumns(): void {
		foreach ( [ 'shop_order', 'shop_order_refund' ] as $type ) {
			add_filter( "woocommerce_{$type}_list_table_columns", [ $this, 'addOrderColumn' ], 100 );
			add_action( "woocommerce_{$type}_list_table_custom_column", [ $this, 'orderColumn' ], 10, 2 );
		}
	}

	public function addOrderColumn( array $columns ): array {
		if ( empty( \PLL()->curlang ) ) {
			$columns['language'] = sprintf(
				'<span class="order_language tips" data-tip="%1$s">%1$s</span>',
				esc_attr__( 'Language', 'flavor' )
			);
		}

		return $columns;
	}

	public function orderColumn( string $column, $order ): void {
		if ( 'language' !== $column ) {
			return;
		}

		$order_obj = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		$slug      = $order_obj ? $order_obj->get_meta( '_pll_language' ) : '';

		if ( ! $slug ) {
			return;
		}

		$lang = \PLL()->model->get_language( $slug );

		if ( $lang ) {
			echo $lang->flag // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				? $lang->flag . '<span class="screen-reader-text">' . esc_html( $lang->name ) . '</span>'
				: esc_html( $lang->slug );
		}
	}

	public function addMetaBoxes( string $screen_id ): void {
		$allowed = array_map( 'wc_get_page_screen_id', [ 'shop_order', 'shop_order_refund' ] );

		if ( ! in_array( $screen_id, $allowed, true ) ) {
			return;
		}

		remove_meta_box( 'ml_box', $screen_id, 'side' );

		add_meta_box( 'hd_pll_order_lang', __( 'Language', 'flavor' ), [ $this, 'renderLanguageMetabox' ], $screen_id, 'side', 'high' );
	}

	public function renderLanguageMetabox( $order_object ): void {
		$order_id = $order_object instanceof \WC_Order ? $order_object->get_id() : ( $order_object->ID ?? 0 );
		$order    = wc_get_order( $order_id );
		$lang     = $order ? ( $order->get_meta( '_pll_language' ) ?: \pll_default_language() ) : \pll_default_language();

		$dropdown  = new \PLL_Walker_Dropdown();
		$languages = \PLL()->model->get_languages_list();

		wp_nonce_field( 'pll_language', '_pll_nonce' );

		printf(
			'<p><strong>%1$s</strong></p><label class="screen-reader-text" for="post_lang_choice">%1$s</label><div id="select-post-language">%2$s</div>',
			esc_html__( 'Language', 'flavor' ),
			$dropdown->walk(
				$languages,
				-1,
				[
					'name'     => 'post_lang_choice',
					'class'    => 'post_lang_choice tags-input',
					'selected' => $lang,
					'flag'     => true,
				]
			) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	public function saveOrderLanguage( \WC_Order $order ): void {
		if ( ! isset( $_GET['id'], $_GET['page'], $_GET['action'], $_POST['post_lang_choice'], $_POST['_pll_nonce'] ) ) {
			return;
		}

		if ( 'edit' !== sanitize_key( $_GET['action'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'pll_language', '_pll_nonce' );

		$new_lang   = \PLL()->model->get_language( sanitize_key( $_POST['post_lang_choice'] ) );
		$order_lang = $order->get_meta( '_pll_language' );

		if ( empty( $new_lang ) || ( ! empty( $order_lang ) && $new_lang->slug === $order_lang ) ) {
			return;
		}

		$order->update_meta_data( '_pll_language', sanitize_key( $new_lang->slug ) );
		$order->save_meta_data();
		\PLL()->curlang = $new_lang;
	}

	public function adminOrderActions( array $actions ): array {
		$items = $actions['status']['actions'] ?? $actions;

		foreach ( $items as $key => $arr ) {
			if ( isset( $arr['url'] ) && str_contains( $arr['url'], 'admin-ajax.php' ) ) {
				$items[ $key ]['url'] = add_query_arg( 'pll_ajax_backend', 1, $arr['url'] );
			}
		}

		return isset( $actions['status']['actions'] )
			? array_merge( $actions, [ 'status' => array_merge( $actions['status'], [ 'actions' => $items ] ) ] )
			: $items;
	}

	public function setCurrentLanguage( $current_language ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			! empty( $_GET['page'] ) && ! empty( $_GET['id'] ) && is_numeric( $_GET['id'] )
			&& 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'wc-orders' === $_GET['page']
		) {
			$order = wc_get_order( absint( $_GET['id'] ) );
			$slug  = $order ? $order->get_meta( '_pll_language' ) : '';
			$lang  = $slug ? \PLL()->model->get_language( $slug ) : false;

			if ( $lang ) {
				return $lang;
			}
		}
		// phpcs:enable

		return $current_language;
	}
}
