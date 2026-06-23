<?php
/**
 * WooCommerce checkout marketing opt-in checkbox.
 *
 * Renders a configurable "subscribe" checkbox on the (classic) checkout, like
 * the Mailchimp for WooCommerce plugin, and records the customer's consent on
 * the order and user so the sync engine can decide list membership.
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCS_Checkout {

	const FIELD = 'bcs_optin';

	/**
	 * Register checkout hooks when the feature is enabled.
	 */
	public static function hooks() {
		$settings = BCS_Sync::get_settings();
		if ( empty( $settings['checkout_optin'] ) ) {
			return;
		}

		/**
		 * Where the checkbox renders on the classic checkout. Filterable so the
		 * position can be moved (e.g. 'woocommerce_after_order_notes').
		 */
		$hook = apply_filters( 'bcs_checkout_optin_hook', 'woocommerce_review_order_before_submit' );
		add_action( $hook, array( __CLASS__, 'render_field' ) );

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'capture_to_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'after_order' ), 20, 3 );
	}

	/**
	 * Output the opt-in checkbox.
	 */
	public static function render_field() {
		if ( ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}
		$settings = BCS_Sync::get_settings();
		$label    = $settings['optin_label'] ? $settings['optin_label'] : __( 'Keep me updated by email', 'brevo-contact-sync' );
		$default  = ( 'checked' === $settings['optin_default'] ) ? 1 : 0;

		// Honour a re-submission after a validation error.
		if ( isset( $_POST['_wpnonce'] ) || isset( $_POST['woocommerce-process-checkout-nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$default = isset( $_POST[ self::FIELD ] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification
		}

		echo '<div class="bcs-optin-field">';
		woocommerce_form_field(
			self::FIELD,
			array(
				'type'    => 'checkbox',
				'class'   => array( 'form-row-wide', 'bcs-optin' ),
				'label'   => $label,
				'default' => $default,
			),
			$default
		);
		echo '</div>';
	}

	/**
	 * Whether the opt-in box was ticked on this submission.
	 *
	 * @return bool
	 */
	private static function is_opted_in() {
		// phpcs:ignore WordPress.Security.NonceVerification -- read within WooCommerce's own checkout nonce flow.
		return ! empty( $_POST[ self::FIELD ] );
	}

	/**
	 * Store consent on the order as it is created.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 */
	public static function capture_to_order( $order, $data ) {
		$order->update_meta_data( '_bcs_marketing_optin', self::is_opted_in() ? 'yes' : 'no' );
	}

	/**
	 * Persist consent to the user and push them to Brevo after checkout.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order object.
	 */
	public static function after_order( $order_id, $posted, $order ) {
		$consent = self::is_opted_in() ? 'yes' : 'no';
		$user_id = $order ? $order->get_user_id() : 0;

		if ( $user_id ) {
			update_user_meta( $user_id, BCS_Sync::OPTIN_META, $consent );
			BCS_Sync::push_user( (int) $user_id );
			return;
		}

		// Guest checkout: push by email, adding to the list only on consent.
		if ( 'yes' === $consent && $order && is_email( $order->get_billing_email() ) ) {
			$settings = BCS_Sync::get_settings();
			$list     = (int) $settings['default_list_id'];
			( new BCS_API() )->upsert_contact(
				sanitize_email( $order->get_billing_email() ),
				array(),
				$list ? array( $list ) : array()
			);
		}
	}
}
