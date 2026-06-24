<?php
/**
 * Sync engine: build Brevo attributes from a WordPress user, push a single
 * user in real time, and run a batched bulk sync over every customer.
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCS_Sync {

	/** How many users to push per WP-Cron batch run. */
	const BATCH_SIZE = 500;

	/* ---------------------------------------------------------------------
	 * Settings helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @return array Saved field mapping (each: meta_key, brevo_attr, transform).
	 */
	public static function get_mapping() {
		$map = get_option( BCS_OPTION_MAPPING, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * @return array Plugin settings with defaults.
	 */
	public static function get_settings() {
		$defaults = array(
			'default_list_id' => 0,
			'realtime'        => 1,
			'include_empty'   => 0,
			'last_full_sync'  => '',
			'checkout_optin'  => 0,
			'optin_label'     => __( 'Keep me updated by email', 'brevo-contact-sync' ),
			'optin_default'   => 'unchecked',
		);
		return wp_parse_args( (array) get_option( BCS_OPTION_SETTINGS, array() ), $defaults );
	}

	/** User-meta key recording checkout marketing consent. */
	const OPTIN_META = 'bcs_marketing_optin';

	/**
	 * @return int[] Configured default list IDs (one or none).
	 */
	private static function default_list_ids() {
		$id = (int) self::get_settings()['default_list_id'];
		return $id ? array( $id ) : array();
	}

	/**
	 * Check a Brevo list exists; return a helpful WP_Error if not.
	 *
	 * @param BCS_API $api     API client.
	 * @param int     $list_id List ID to validate.
	 * @return WP_Error|null Null if the list is valid.
	 */
	public static function validate_list_or_error( BCS_API $api, $list_id ) {
		$list_id = (int) $list_id;
		if ( $list_id < 1 ) {
			return new WP_Error( 'bcs_no_list', __( 'Choose a Brevo list on the Connection page first — Brevo requires a target list.', 'brevo-contact-sync' ) );
		}
		if ( ! is_wp_error( $api->get_list( $list_id ) ) ) {
			return null;
		}

		// Build a hint listing the lists that DO exist.
		$lists = $api->get_lists();
		if ( ! is_wp_error( $lists ) && ! empty( $lists ) ) {
			$pairs = array();
			foreach ( $lists as $l ) {
				$pairs[] = '#' . $l['id'] . ' ' . $l['name'];
			}
			$hint = ' ' . sprintf( /* translators: %s: list of lists */ __( 'Your lists are: %s.', 'brevo-contact-sync' ), implode( ', ', $pairs ) );
		} else {
			$hint = ' ' . __( 'You have no lists yet — create one in Brevo under Contacts → Lists, then select it.', 'brevo-contact-sync' );
		}

		return new WP_Error(
			'bcs_bad_list',
			sprintf( /* translators: %d: list id */ __( 'Brevo list #%d does not exist. Pick a valid list on the Connection page.', 'brevo-contact-sync' ), $list_id ) . $hint
		);
	}

	/**
	 * Lists a given user should be added to, honouring checkout opt-in.
	 *
	 * When the checkout opt-in feature is on, a user is only added to the
	 * marketing list if they have consented; their contact data still syncs.
	 * When the feature is off, the default list applies to everyone (legacy).
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	private static function list_ids_for_user( $user_id ) {
		$settings = self::get_settings();
		if ( empty( $settings['checkout_optin'] ) ) {
			return self::default_list_ids();
		}
		$consent = get_user_meta( $user_id, self::OPTIN_META, true );
		return ( 'yes' === $consent ) ? self::default_list_ids() : array();
	}

	/* ---------------------------------------------------------------------
	 * Building a contact from a user
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the best email for a user (billing first, then account).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_email( $user_id ) {
		$billing = get_user_meta( $user_id, 'billing_email', true );
		if ( $billing && is_email( $billing ) ) {
			return sanitize_email( $billing );
		}
		$user = get_userdata( $user_id );
		return ( $user && is_email( $user->user_email ) ) ? sanitize_email( $user->user_email ) : '';
	}

	/**
	 * Build the Brevo attributes payload for a user from the saved mapping.
	 *
	 * @param int  $user_id       User ID.
	 * @param bool $include_empty Include attributes whose value is empty/null.
	 * @return array Map of BREVO_ATTR => value.
	 */
	public static function build_attributes( $user_id, $include_empty = false ) {
		$attributes = array();
		foreach ( self::get_mapping() as $m ) {
			$meta_key   = isset( $m['meta_key'] ) ? $m['meta_key'] : '';
			$brevo_attr = isset( $m['brevo_attr'] ) ? strtoupper( $m['brevo_attr'] ) : '';
			$transform  = isset( $m['transform'] ) ? $m['transform'] : 'raw';
			if ( '' === $meta_key || '' === $brevo_attr ) {
				continue;
			}

			$raw   = get_user_meta( $user_id, $meta_key, true );
			$value = BCS_Meta::transform_value( $raw, $transform );

			if ( null === $value || '' === $value ) {
				if ( ! $include_empty ) {
					continue;
				}
				$value = '';
			}
			$attributes[ $brevo_attr ] = $value;
		}
		return $attributes;
	}

	/**
	 * Build a Brevo import row { email, attributes } for a user, or null.
	 *
	 * @param int  $user_id       User ID.
	 * @param bool $include_empty Pass-through to build_attributes().
	 * @return array|null
	 */
	private static function build_contact_row( $user_id, $include_empty ) {
		$email = self::user_email( $user_id );
		if ( '' === $email ) {
			return null;
		}
		$row = array( 'email' => $email );
		$attrs = self::build_attributes( $user_id, $include_empty );
		if ( ! empty( $attrs ) ) {
			$row['attributes'] = $attrs;
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Real-time single-user sync
	 * ------------------------------------------------------------------- */

	/**
	 * Register WooCommerce / profile event hooks for real-time syncing.
	 */
	public static function register_event_hooks() {
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_user_event' ), 20, 1 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'on_user_event' ), 20, 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_user_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 20, 1 );
	}

	/**
	 * Push a user when a user-level event fires.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_user_event( $user_id ) {
		if ( ! self::realtime_ready() ) {
			return;
		}
		self::push_user( (int) $user_id );
	}

	/**
	 * Push the customer behind a completed order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_completed( $order_id ) {
		if ( ! self::realtime_ready() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( $user_id ) {
			self::push_user( (int) $user_id );
		} elseif ( $order->get_billing_email() ) {
			// Guest checkout: push by email with whatever the order carries.
			$api = new BCS_API();
			$api->upsert_contact( sanitize_email( $order->get_billing_email() ), array(), self::default_list_ids() );
		}
	}

	/**
	 * @return bool Whether real-time sync is configured and enabled.
	 */
	private static function realtime_ready() {
		$settings = self::get_settings();
		if ( empty( $settings['realtime'] ) ) {
			return false;
		}
		if ( empty( self::get_mapping() ) ) {
			return false;
		}
		return ( new BCS_API() )->has_key();
	}

	/**
	 * Push one user to Brevo immediately.
	 *
	 * @param int $user_id User ID.
	 * @return array|WP_Error|null
	 */
	public static function push_user( $user_id ) {
		$settings = self::get_settings();
		$row      = self::build_contact_row( $user_id, ! empty( $settings['include_empty'] ) );
		if ( ! $row ) {
			return null;
		}

		$api    = new BCS_API();
		$result = $api->upsert_contact(
			$row['email'],
			isset( $row['attributes'] ) ? $row['attributes'] : array(),
			self::list_ids_for_user( $user_id )
		);

		if ( is_wp_error( $result ) ) {
			self::log( sprintf( 'Real-time push failed for user %d (%s): %s', $user_id, $row['email'], $result->get_error_message() ) );
		}
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Batched bulk sync (all customers)
	 * ------------------------------------------------------------------- */

	/**
	 * @return int Total users eligible for sync.
	 */
	public static function total_users() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	}

	/**
	 * @return array Current bulk-sync state.
	 */
	public static function get_state() {
		$defaults = array(
			'running'   => 0,
			'offset'    => 0,
			'total'     => 0,
			'processed' => 0,
			'errors'    => 0,
			'last_msg'  => '',
		);
		return wp_parse_args( (array) get_option( BCS_OPTION_SYNC, array() ), $defaults );
	}

	/**
	 * Start a full bulk sync of all users.
	 *
	 * @return true|WP_Error
	 */
	public static function start_full_sync() {
		$api = new BCS_API();
		if ( ! $api->has_key() ) {
			return new WP_Error( 'bcs_no_key', __( 'Add your Brevo API key first.', 'brevo-contact-sync' ) );
		}
		if ( empty( self::get_mapping() ) ) {
			return new WP_Error( 'bcs_no_map', __( 'Map at least one field before syncing.', 'brevo-contact-sync' ) );
		}
		$list_error = self::validate_list_or_error( $api, self::get_settings()['default_list_id'] );
		if ( $list_error ) {
			return $list_error;
		}

		update_option(
			BCS_OPTION_SYNC,
			array(
				'running'   => 1,
				'offset'    => 0,
				'total'     => self::total_users(),
				'processed' => 0,
				'errors'    => 0,
				'last_msg'  => __( 'Starting…', 'brevo-contact-sync' ),
			)
		);

		// Kick off the first batch right away (loopback + cron fallback).
		delete_transient( 'bcs_batch_lock' );
		self::kick( 'bcs_run_batch', BCS_CRON_BATCH );
		return true;
	}

	/**
	 * Cancel an in-progress bulk sync.
	 */
	public static function cancel_full_sync() {
		wp_clear_scheduled_hook( BCS_CRON_BATCH );
		delete_transient( 'bcs_batch_lock' );
		$state            = self::get_state();
		$state['running'] = 0;
		$state['last_msg'] = __( 'Cancelled.', 'brevo-contact-sync' );
		update_option( BCS_OPTION_SYNC, $state );
	}

	/**
	 * Process one batch of users, then reschedule until done.
	 * Fired by WP-Cron via the BCS_CRON_BATCH hook.
	 */
	public static function run_batch() {
		$state = self::get_state();
		if ( empty( $state['running'] ) ) {
			return;
		}
		// Prevent overlapping runs (loopback + cron could both fire).
		if ( get_transient( 'bcs_batch_lock' ) ) {
			return;
		}
		set_transient( 'bcs_batch_lock', 1, 120 );

		global $wpdb;
		$settings      = self::get_settings();
		$include_empty = ! empty( $settings['include_empty'] );
		$offset        = (int) $state['offset'];

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT %d OFFSET %d",
				self::BATCH_SIZE,
				$offset
			)
		);

		if ( empty( $user_ids ) ) {
			self::finish_sync( $state );
			return;
		}

		// Build the whole batch and send it in ONE async import call. (We don't
		// do per-contact upserts here — 500 sequential API calls per batch would
		// time PHP out before progress is saved. Bulk loads everyone into the
		// configured list; who actually receives email is governed by Brevo's
		// global unsubscribe flag, not list membership.)
		$list_ids = self::default_list_ids();
		$contacts = array();
		foreach ( $user_ids as $uid ) {
			$row = self::build_contact_row( (int) $uid, $include_empty );
			if ( $row ) {
				$contacts[] = $row;
			}
		}

		// Advance the cursor by the number of users scanned (not just those with
		// an email), so the bar always reaches 100%.
		$state['offset'] = $offset + count( $user_ids );

		if ( ! empty( $contacts ) ) {
			$result = ( new BCS_API() )->import_contacts( $contacts, $list_ids );
			if ( is_wp_error( $result ) ) {
				$state['errors']  += count( $contacts );
				$state['last_msg'] = $result->get_error_message();
				self::log( 'Batch import failed at offset ' . $offset . ': ' . $result->get_error_message() );
			} else {
				$state['processed'] += count( $contacts );
				$state['last_msg']   = sprintf( /* translators: %d: number of contacts queued */ __( 'Queued %d contacts with Brevo.', 'brevo-contact-sync' ), count( $contacts ) );
			}
		}

		// Persist progress immediately so the UI reflects each batch.
		update_option( BCS_OPTION_SYNC, $state );
		delete_transient( 'bcs_batch_lock' );

		if ( $state['offset'] >= (int) $state['total'] || count( $user_ids ) < self::BATCH_SIZE ) {
			self::finish_sync( $state );
			return;
		}

		// Continue with the next batch immediately (loopback), with a cron fallback.
		self::kick( 'bcs_run_batch', BCS_CRON_BATCH );
	}

	/* ---------------------------------------------------------------------
	 * Self-continuing background runner (loopback + cron fallback)
	 * ------------------------------------------------------------------- */

	/**
	 * Token authorising loopback requests (no user session in a loopback).
	 *
	 * @return string
	 */
	public static function loopback_token() {
		$token = get_option( 'bcs_loopback_token' );
		if ( ! $token ) {
			$token = wp_generate_password( 24, false );
			update_option( 'bcs_loopback_token', $token, false );
		}
		return $token;
	}

	/**
	 * Trigger the next batch via a non-blocking loopback request, and schedule
	 * a cron event as a fallback in case loopback HTTP is blocked.
	 *
	 * @param string $ajax_action admin-ajax action name.
	 * @param string $cron_hook   Cron hook to use as fallback.
	 */
	public static function kick( $ajax_action, $cron_hook ) {
		if ( ! wp_next_scheduled( $cron_hook ) ) {
			wp_schedule_single_event( time() + 45, $cron_hook );
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => $ajax_action,
					'token'  => self::loopback_token(),
				),
			)
		);
	}

	/**
	 * AJAX entry point for the loopback runner (bulk sync).
	 */
	public static function ajax_run_batch() {
		if ( ! isset( $_POST['token'] ) || ! hash_equals( self::loopback_token(), sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		@set_time_limit( 0 ); // phpcs:ignore
		self::run_batch();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Mark a bulk sync complete.
	 *
	 * @param array $state Current state.
	 */
	private static function finish_sync( $state ) {
		$state['running']  = 0;
		$state['last_msg'] = sprintf(
			/* translators: 1: processed count 2: error count */
			__( 'Done. %1$d contacts synced, %2$d errors.', 'brevo-contact-sync' ),
			(int) $state['processed'],
			(int) $state['errors']
		);
		update_option( BCS_OPTION_SYNC, $state );

		$settings                   = self::get_settings();
		$settings['last_full_sync'] = current_time( 'mysql' );
		update_option( BCS_OPTION_SETTINGS, $settings );

		delete_transient( 'bcs_batch_lock' );
		wp_clear_scheduled_hook( BCS_CRON_BATCH );
	}

	/**
	 * Log to the WP debug log when WP_DEBUG_LOG is on.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Brevo Contact Sync] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
