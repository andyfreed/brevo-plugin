<?php
/**
 * Background CSV contact importer (e.g. Mailchimp exports → Brevo).
 *
 * Uploads a CSV, maps its columns to Brevo fields, marks the whole file as
 * subscribed or unsubscribed, and imports it into a Brevo list in background
 * batches. Unsubscribed files set emailBlacklisted = true so those contacts
 * are clearly marked and never emailed; subscribed files never touch the flag,
 * so they can't accidentally re-subscribe someone who opted out elsewhere.
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCS_Import {

	/** Rows per background batch. */
	const BATCH_SIZE = 500;

	/**
	 * @return array Current import job state, with defaults.
	 */
	public static function get_state() {
		$defaults = array(
			'staged'      => 0,   // file uploaded, awaiting column mapping
			'running'     => 0,   // import in progress
			'file'        => '',
			'header'      => array(),
			'status_mode' => 'subscribed', // 'subscribed' | 'unsubscribed'
			'list_id'     => 0,
			'email_col'   => -1,
			'col_map'     => array(), // column index => brevo field name
			'offset'      => 0,       // byte offset into the file
			'total'       => 0,
			'processed'   => 0,
			'errors'      => 0,
			'last_msg'    => '',
		);
		return wp_parse_args( (array) get_option( BCS_OPTION_IMPORT, array() ), $defaults );
	}

	/**
	 * @param array $state State to persist.
	 */
	private static function save_state( $state ) {
		update_option( BCS_OPTION_IMPORT, $state );
	}

	/**
	 * Directory where uploaded import files are stored.
	 *
	 * @return array { dir, url } absolute path + URL.
	 */
	private static function storage_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'bcs-imports';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Keep it private.
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/* ---------------------------------------------------------------------
	 * Step 1: stage an uploaded file
	 * ------------------------------------------------------------------- */

	/**
	 * Validate + store an uploaded CSV and read its header row.
	 *
	 * @param array  $file        One entry from $_FILES.
	 * @param string $status_mode 'subscribed' | 'unsubscribed'.
	 * @param int    $list_id     Target Brevo list.
	 * @return true|WP_Error
	 */
	public static function stage_upload( $file, $status_mode, $list_id ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'bcs_no_file', __( 'No CSV file uploaded.', 'brevo-contact-sync' ) );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'bcs_bad_ext', __( 'Please upload a .csv file.', 'brevo-contact-sync' ) );
		}

		self::cleanup_file(); // Remove any previous staged file.

		$dir  = self::storage_dir();
		$dest = $dir . '/import-' . wp_generate_password( 8, false ) . '.csv';
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'bcs_move_fail', __( 'Could not save the uploaded file.', 'brevo-contact-sync' ) );
		}

		$handle = fopen( $dest, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$header = $handle ? fgetcsv( $handle, 8192 ) : false;
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( empty( $header ) ) {
			return new WP_Error( 'bcs_empty', __( 'The CSV appears to be empty.', 'brevo-contact-sync' ) );
		}
		$header = array_map( 'trim', $header );

		self::save_state(
			array(
				'staged'      => 1,
				'running'     => 0,
				'file'        => $dest,
				'header'      => $header,
				'status_mode' => ( 'unsubscribed' === $status_mode ) ? 'unsubscribed' : 'subscribed',
				'list_id'     => (int) $list_id,
				'email_col'   => self::guess_email_column( $header ),
				'col_map'     => self::guess_column_map( $header ),
				'total'       => self::count_rows( $dest ),
				'last_msg'    => '',
			)
		);
		return true;
	}

	/**
	 * Find the most likely email column index.
	 *
	 * @param array $header Header cells.
	 * @return int
	 */
	private static function guess_email_column( $header ) {
		foreach ( $header as $i => $name ) {
			if ( false !== stripos( $name, 'email' ) ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/**
	 * Suggest a Brevo field per column for common Mailchimp headers.
	 *
	 * @param array $header Header cells.
	 * @return array column index => brevo field name
	 */
	private static function guess_column_map( $header ) {
		$known = array(
			'first name' => 'FIRSTNAME',
			'fname'      => 'FIRSTNAME',
			'last name'  => 'LASTNAME',
			'lname'      => 'LASTNAME',
			'phone'      => 'SMS',
			'company'    => 'COMPANY',
		);
		$map = array();
		foreach ( $header as $i => $name ) {
			$lc = strtolower( trim( $name ) );
			if ( false !== stripos( $name, 'email' ) ) {
				continue; // email is the identifier, not an attribute
			}
			if ( isset( $known[ $lc ] ) ) {
				$map[ $i ] = $known[ $lc ];
			}
		}
		return $map;
	}

	/**
	 * Read the first few data rows of the staged file for preview.
	 *
	 * @param int $limit Max rows.
	 * @return array[] Array of row arrays (cells).
	 */
	public static function get_preview( $limit = 5 ) {
		$state = self::get_state();
		if ( empty( $state['file'] ) || ! file_exists( $state['file'] ) ) {
			return array();
		}
		$rows   = array();
		$handle = fopen( $state['file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return array();
		}
		fgetcsv( $handle, 8192 ); // Skip header.
		$n = 0;
		while ( $n < $limit && false !== ( $row = fgetcsv( $handle, 8192 ) ) ) {
			$rows[] = $row;
			$n++;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Step 2: start the background import
	 * ------------------------------------------------------------------- */

	/**
	 * Begin importing the staged file with the confirmed column mapping.
	 *
	 * @param int   $email_col Column index holding the email.
	 * @param array $col_map   column index => brevo field name (raw).
	 * @return true|WP_Error
	 */
	public static function start( $email_col, $col_map ) {
		$state = self::get_state();
		if ( empty( $state['staged'] ) || ! file_exists( $state['file'] ) ) {
			return new WP_Error( 'bcs_no_staged', __( 'Upload a CSV first.', 'brevo-contact-sync' ) );
		}
		$api = new BCS_API();
		if ( ! $api->has_key() ) {
			return new WP_Error( 'bcs_no_key', __( 'Add your Brevo API key first.', 'brevo-contact-sync' ) );
		}
		if ( $email_col < 0 ) {
			return new WP_Error( 'bcs_no_email', __( 'Choose which column holds the email address.', 'brevo-contact-sync' ) );
		}
		if ( empty( $state['list_id'] ) ) {
			return new WP_Error( 'bcs_no_list', __( 'A target Brevo list is required.', 'brevo-contact-sync' ) );
		}

		// Normalise mapped field names and create any that don't exist in Brevo.
		$clean_map = array();
		foreach ( (array) $col_map as $i => $field ) {
			$name = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', trim( (string) $field ) ) );
			$name = trim( $name, '_' );
			if ( '' !== $name ) {
				$clean_map[ (int) $i ] = $name;
			}
		}
		$existing = array();
		$attrs    = $api->get_attributes();
		if ( ! is_wp_error( $attrs ) ) {
			foreach ( $attrs as $a ) {
				if ( isset( $a['name'] ) ) {
					$existing[ strtoupper( $a['name'] ) ] = true;
				}
			}
		}
		foreach ( array_unique( $clean_map ) as $field ) {
			if ( ! isset( $existing[ $field ] ) && 'SMS' !== $field ) {
				$api->create_attribute( $field, 'text' );
			}
		}

		$state['running']   = 1;
		$state['staged']    = 0;
		$state['email_col'] = (int) $email_col;
		$state['col_map']   = $clean_map;
		$state['offset']    = 0;
		$state['processed'] = 0;
		$state['errors']    = 0;
		$state['total']     = self::count_rows( $state['file'] );
		$state['last_msg']  = __( 'Starting…', 'brevo-contact-sync' );
		self::save_state( $state );

		if ( ! wp_next_scheduled( BCS_CRON_IMPORT ) ) {
			wp_schedule_single_event( time(), BCS_CRON_IMPORT );
		}
		spawn_cron();
		return true;
	}

	/**
	 * Count data rows (excluding the header) in a CSV.
	 *
	 * @param string $file Path.
	 * @return int
	 */
	private static function count_rows( $file ) {
		$lines  = 0;
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return 0;
		}
		while ( false !== fgets( $handle ) ) {
			$lines++;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return max( 0, $lines - 1 );
	}

	/**
	 * Cancel an import and remove the file.
	 */
	public static function cancel() {
		wp_clear_scheduled_hook( BCS_CRON_IMPORT );
		self::cleanup_file();
		delete_option( BCS_OPTION_IMPORT );
	}

	/**
	 * Delete the staged/working file, if any.
	 */
	private static function cleanup_file() {
		$state = self::get_state();
		if ( ! empty( $state['file'] ) && file_exists( $state['file'] ) ) {
			@unlink( $state['file'] ); // phpcs:ignore
		}
	}

	/* ---------------------------------------------------------------------
	 * Background processing
	 * ------------------------------------------------------------------- */

	/**
	 * Process one batch of CSV rows, then reschedule until EOF.
	 * Fired by WP-Cron via BCS_CRON_IMPORT.
	 */
	public static function run_batch() {
		$state = self::get_state();
		if ( empty( $state['running'] ) || empty( $state['file'] ) || ! file_exists( $state['file'] ) ) {
			return;
		}

		$handle = fopen( $state['file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			$state['running']  = 0;
			$state['last_msg'] = __( 'Could not open the import file.', 'brevo-contact-sync' );
			self::save_state( $state );
			return;
		}

		// Skip the header on the first pass; otherwise seek to where we left off.
		if ( 0 === (int) $state['offset'] ) {
			fgetcsv( $handle, 8192 );
		} else {
			fseek( $handle, (int) $state['offset'] );
		}

		$blacklist = ( 'unsubscribed' === $state['status_mode'] );
		$email_col = (int) $state['email_col'];
		$col_map   = (array) $state['col_map'];
		$contacts  = array();
		$read      = 0;

		while ( $read < self::BATCH_SIZE && false !== ( $row = fgetcsv( $handle, 8192 ) ) ) {
			$read++;
			$email = isset( $row[ $email_col ] ) ? sanitize_email( trim( $row[ $email_col ] ) ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}

			$attributes = array();
			foreach ( $col_map as $i => $field ) {
				if ( isset( $row[ $i ] ) && '' !== trim( $row[ $i ] ) && 'SMS' !== $field ) {
					$attributes[ $field ] = trim( $row[ $i ] );
				}
			}

			$contact = array( 'email' => $email );
			if ( ! empty( $attributes ) ) {
				$contact['attributes'] = $attributes;
			}
			if ( $blacklist ) {
				$contact['emailBlacklisted'] = true;
			}
			$contacts[] = $contact;
		}

		$new_offset = ftell( $handle );
		$eof        = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! empty( $contacts ) ) {
			$result = ( new BCS_API() )->import_contacts( $contacts, array( (int) $state['list_id'] ) );
			if ( is_wp_error( $result ) ) {
				$state['errors']  += count( $contacts );
				$state['last_msg'] = $result->get_error_message();
			} else {
				$state['processed'] += count( $contacts );
				$state['last_msg']   = sprintf(
					/* translators: %d: contacts queued */
					__( 'Queued %d contacts.', 'brevo-contact-sync' ),
					count( $contacts )
				);
			}
		}

		$state['offset'] = $new_offset;

		if ( $eof || 0 === $read ) {
			self::finish( $state );
			return;
		}

		self::save_state( $state );
		wp_schedule_single_event( time() + 2, BCS_CRON_IMPORT );
		spawn_cron();
	}

	/**
	 * Finish an import job.
	 *
	 * @param array $state State.
	 */
	private static function finish( $state ) {
		$state['running']  = 0;
		$state['last_msg'] = sprintf(
			/* translators: 1: processed 2: errors 3: status */
			__( 'Import complete. %1$d contacts imported as %3$s, %2$d errors.', 'brevo-contact-sync' ),
			(int) $state['processed'],
			(int) $state['errors'],
			'unsubscribed' === $state['status_mode'] ? __( 'unsubscribed', 'brevo-contact-sync' ) : __( 'subscribed', 'brevo-contact-sync' )
		);
		self::save_state( $state );
		wp_clear_scheduled_hook( BCS_CRON_IMPORT );
		self::cleanup_file();
	}
}
