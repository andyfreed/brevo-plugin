<?php
/**
 * Admin UI: connection settings, field mapping, and bulk sync.
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCS_Admin {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_bcs_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_bcs_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_bcs_save_mapping', array( $this, 'handle_save_mapping' ) );
		add_action( 'admin_post_bcs_start_sync', array( $this, 'handle_start_sync' ) );
		add_action( 'admin_post_bcs_cancel_sync', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_bcs_rescan', array( $this, 'handle_rescan' ) );
		add_action( 'admin_post_bcs_export_mapping', array( $this, 'handle_export_mapping' ) );
		add_action( 'admin_post_bcs_import_mapping', array( $this, 'handle_import_mapping' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BCS_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * @param array $links Plugin row links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=brevo-contact-sync' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'brevo-contact-sync' ) . '</a>' );
		return $links;
	}

	/**
	 * Register menu pages.
	 */
	public function menu() {
		add_menu_page(
			__( 'Brevo Sync', 'brevo-contact-sync' ),
			__( 'Brevo Sync', 'brevo-contact-sync' ),
			'manage_options',
			'brevo-contact-sync',
			array( $this, 'render_settings_page' ),
			'dashicons-email-alt',
			58
		);
		add_submenu_page( 'brevo-contact-sync', __( 'Connection', 'brevo-contact-sync' ), __( 'Connection', 'brevo-contact-sync' ), 'manage_options', 'brevo-contact-sync', array( $this, 'render_settings_page' ) );
		add_submenu_page( 'brevo-contact-sync', __( 'Field Mapping', 'brevo-contact-sync' ), __( 'Field Mapping', 'brevo-contact-sync' ), 'manage_options', 'brevo-field-mapping', array( $this, 'render_mapping_page' ) );
		add_submenu_page( 'brevo-contact-sync', __( 'Bulk Sync', 'brevo-contact-sync' ), __( 'Bulk Sync', 'brevo-contact-sync' ), 'manage_options', 'brevo-bulk-sync', array( $this, 'render_sync_page' ) );
		add_submenu_page( 'brevo-contact-sync', __( 'Contact Status', 'brevo-contact-sync' ), __( 'Contact Status', 'brevo-contact-sync' ), 'manage_options', 'brevo-contact-status', array( $this, 'render_status_page' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'brevo-contact-sync' ) );
		}
	}

	private function notify( $type, $text ) {
		set_transient( 'bcs_notice', array( 'type' => $type, 'text' => $text ), 60 );
	}

	private function maybe_notice() {
		$notice = get_transient( 'bcs_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'bcs_notice' );
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'notice-error' : 'notice-success',
			esc_html( $notice['text'] )
		);
	}

	/**
	 * Suggest an UPPER_SNAKE Brevo attribute name from a meta key.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private function suggest_attr_name( $key ) {
		$name = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', $key ) );
		return trim( $name, '_' );
	}

	/**
	 * Map a transform to a Brevo attribute type.
	 *
	 * @param string $transform Transform key.
	 * @return string
	 */
	private function transform_to_brevo_type( $transform ) {
		switch ( $transform ) {
			case 'bool':
				return 'boolean';
			case 'date':
				return 'date';
			case 'number':
				return 'float';
			default:
				return 'text';
		}
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	public function handle_save_settings() {
		$this->guard();
		check_admin_referer( 'bcs_save_settings' );

		update_option( BCS_OPTION_API_KEY, trim( sanitize_text_field( wp_unslash( $_POST['bcs_api_key'] ?? '' ) ) ) );

		$settings                    = BCS_Sync::get_settings();
		$settings['default_list_id'] = (int) ( $_POST['default_list_id'] ?? 0 );
		$settings['realtime']        = empty( $_POST['realtime'] ) ? 0 : 1;
		$settings['include_empty']   = empty( $_POST['include_empty'] ) ? 0 : 1;
		$settings['checkout_optin']  = empty( $_POST['checkout_optin'] ) ? 0 : 1;
		$settings['optin_label']     = sanitize_text_field( wp_unslash( $_POST['optin_label'] ?? '' ) );
		$settings['optin_default']   = ( 'checked' === ( $_POST['optin_default'] ?? '' ) ) ? 'checked' : 'unchecked';
		update_option( BCS_OPTION_SETTINGS, $settings );

		$this->notify( 'success', __( 'Settings saved.', 'brevo-contact-sync' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-contact-sync' ) );
		exit;
	}

	public function handle_test_connection() {
		$this->guard();
		check_admin_referer( 'bcs_test_connection' );

		$account = ( new BCS_API() )->get_account();
		if ( is_wp_error( $account ) ) {
			$this->notify( 'error', sprintf( /* translators: %s: error */ __( 'Connection failed: %s', 'brevo-contact-sync' ), $account->get_error_message() ) );
		} else {
			$who = $account['email'] ?? trim( ( $account['firstName'] ?? '' ) . ' ' . ( $account['lastName'] ?? '' ) );
			$this->notify( 'success', sprintf( /* translators: %s: account */ __( 'Connected to Brevo. Account: %s', 'brevo-contact-sync' ), $who ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-contact-sync' ) );
		exit;
	}

	public function handle_save_mapping() {
		$this->guard();
		check_admin_referer( 'bcs_save_mapping' );

		$meta_keys  = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['meta_key'] ?? array() ) ) );
		$enabled    = (array) ( $_POST['enabled'] ?? array() );
		$attrs      = (array) ( $_POST['brevo_attr'] ?? array() );
		$transforms = (array) ( $_POST['transform'] ?? array() );

		$api     = new BCS_API();
		$mapping = array();
		$created = 0;

		// Names of attributes that already exist in Brevo.
		$existing   = array();
		$attrs_list = $api->get_attributes();
		if ( ! is_wp_error( $attrs_list ) ) {
			foreach ( $attrs_list as $a ) {
				if ( isset( $a['name'] ) ) {
					$existing[ strtoupper( $a['name'] ) ] = true;
				}
			}
		}

		foreach ( $meta_keys as $key ) {
			if ( empty( $enabled[ $key ] ) ) {
				continue;
			}
			$transform = sanitize_text_field( $transforms[ $key ] ?? 'raw' );

			// Whatever the user typed becomes the Brevo field name (normalised
			// to Brevo's UPPER_SNAKE rules). Blank falls back to the meta key.
			$typed = trim( sanitize_text_field( $attrs[ $key ] ?? '' ) );
			if ( '' === $typed ) {
				$typed = $key;
			}
			$attr_name = $this->suggest_attr_name( $typed );
			if ( '' === $attr_name ) {
				continue;
			}

			// Create the field in Brevo if it doesn't exist yet.
			if ( ! isset( $existing[ $attr_name ] ) ) {
				$result = $api->create_attribute( $attr_name, $this->transform_to_brevo_type( $transform ) );
				if ( is_wp_error( $result ) && false === stripos( $result->get_error_message(), 'exist' ) ) {
					$this->notify( 'error', sprintf( /* translators: 1: field 2: error */ __( 'Could not create field %1$s: %2$s', 'brevo-contact-sync' ), $attr_name, $result->get_error_message() ) );
				} else {
					$existing[ $attr_name ] = true;
					$created++;
				}
			}

			$mapping[] = array(
				'meta_key'   => $key,
				'brevo_attr' => $attr_name,
				'transform'  => $transform,
			);
		}

		update_option( BCS_OPTION_MAPPING, $mapping );
		$msg = sprintf( /* translators: %d: count */ __( 'Saved %d field mappings.', 'brevo-contact-sync' ), count( $mapping ) );
		if ( $created ) {
			$msg .= ' ' . sprintf( /* translators: %d: count */ __( 'Created/verified %d Brevo field(s).', 'brevo-contact-sync' ), $created );
		}
		$this->notify( 'success', $msg );
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-field-mapping' ) );
		exit;
	}

	public function handle_start_sync() {
		$this->guard();
		check_admin_referer( 'bcs_start_sync' );
		$result = BCS_Sync::start_full_sync();
		if ( is_wp_error( $result ) ) {
			$this->notify( 'error', $result->get_error_message() );
		} else {
			$this->notify( 'success', __( 'Bulk sync started. It runs in the background.', 'brevo-contact-sync' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-bulk-sync' ) );
		exit;
	}

	public function handle_cancel_sync() {
		$this->guard();
		check_admin_referer( 'bcs_cancel_sync' );
		BCS_Sync::cancel_full_sync();
		$this->notify( 'success', __( 'Bulk sync cancelled.', 'brevo-contact-sync' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-bulk-sync' ) );
		exit;
	}

	public function handle_rescan() {
		$this->guard();
		check_admin_referer( 'bcs_rescan' );
		BCS_Meta::flush_cache();
		$this->notify( 'success', __( 'Re-scanned customer meta fields.', 'brevo-contact-sync' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-field-mapping' ) );
		exit;
	}

	/**
	 * Download the saved field mapping as a CSV template.
	 */
	public function handle_export_mapping() {
		$this->guard();
		check_admin_referer( 'bcs_export_mapping' );

		$mapping  = BCS_Sync::get_mapping();
		$filename = 'brevo-mapping-' . gmdate( 'Ymd' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'wp_meta_key', 'brevo_field', 'format' ) );
		foreach ( $mapping as $m ) {
			fputcsv( $out, array( $m['meta_key'], $m['brevo_attr'], $m['transform'] ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Import a mapping template CSV (replacing the current mapping).
	 */
	public function handle_import_mapping() {
		$this->guard();
		check_admin_referer( 'bcs_import_mapping' );

		if ( empty( $_FILES['mapping_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['mapping_csv']['tmp_name'] ) ) {
			$this->notify( 'error', __( 'No CSV file uploaded.', 'brevo-contact-sync' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=brevo-field-mapping' ) );
			exit;
		}

		$valid_transforms = array( 'raw', 'number', 'date', 'bool', 'serialized_list' );
		$rows             = array();
		$handle           = fopen( $_FILES['mapping_csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->notify( 'error', __( 'Could not read the uploaded file.', 'brevo-contact-sync' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=brevo-field-mapping' ) );
			exit;
		}

		$line = 0;
		while ( false !== ( $cols = fgetcsv( $handle, 4096 ) ) ) {
			$line++;
			$key   = isset( $cols[0] ) ? trim( sanitize_text_field( $cols[0] ) ) : '';
			$field = isset( $cols[1] ) ? trim( sanitize_text_field( $cols[1] ) ) : '';
			$fmt   = isset( $cols[2] ) ? trim( sanitize_text_field( $cols[2] ) ) : 'raw';

			// Skip header row and blanks.
			if ( 1 === $line && 'wp_meta_key' === strtolower( $key ) ) {
				continue;
			}
			if ( '' === $key || '' === $field ) {
				continue;
			}
			$rows[] = array(
				'meta_key'   => $key,
				'brevo_attr' => $this->suggest_attr_name( $field ),
				'transform'  => in_array( $fmt, $valid_transforms, true ) ? $fmt : 'raw',
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( empty( $rows ) ) {
			$this->notify( 'error', __( 'No valid mapping rows found in the CSV.', 'brevo-contact-sync' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=brevo-field-mapping' ) );
			exit;
		}

		// Create any missing Brevo fields referenced by the template.
		$api      = new BCS_API();
		$existing = array();
		$list     = $api->get_attributes();
		if ( ! is_wp_error( $list ) ) {
			foreach ( $list as $a ) {
				if ( isset( $a['name'] ) ) {
					$existing[ strtoupper( $a['name'] ) ] = true;
				}
			}
		}
		$created = 0;
		foreach ( $rows as $r ) {
			if ( '' !== $r['brevo_attr'] && ! isset( $existing[ $r['brevo_attr'] ] ) ) {
				$result = $api->create_attribute( $r['brevo_attr'], $this->transform_to_brevo_type( $r['transform'] ) );
				if ( ! is_wp_error( $result ) || false !== stripos( $result->get_error_message(), 'exist' ) ) {
					$existing[ $r['brevo_attr'] ] = true;
					$created++;
				}
			}
		}

		update_option( BCS_OPTION_MAPPING, $rows );
		$this->notify(
			'success',
			sprintf(
				/* translators: 1: mapping count 2: created field count */
				__( 'Imported %1$d mappings. Created %2$d new Brevo field(s).', 'brevo-contact-sync' ),
				count( $rows ),
				$created
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=brevo-field-mapping' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------- */

	public function render_settings_page() {
		$api      = new BCS_API();
		$settings = BCS_Sync::get_settings();
		$lists    = $api->has_key() ? $api->get_lists() : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Brevo Contact Sync', 'brevo-contact-sync' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p><?php esc_html_e( 'Push WooCommerce customers and their custom fields to Brevo. Steps: 1) connect, 2) map fields, 3) sync.', 'brevo-contact-sync' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bcs_save_settings" />
				<?php wp_nonce_field( 'bcs_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bcs_api_key"><?php esc_html_e( 'Brevo API Key (v3)', 'brevo-contact-sync' ); ?></label></th>
						<td>
							<input type="password" id="bcs_api_key" name="bcs_api_key" value="<?php echo esc_attr( get_option( BCS_OPTION_API_KEY, '' ) ); ?>" class="regular-text" autocomplete="off" placeholder="xkeysib-..." />
							<p class="description">
								<?php
								printf(
									wp_kses_post( __( 'Create at %s.', 'brevo-contact-sync' ) ),
									'<a href="https://app.brevo.com/settings/keys/api" target="_blank" rel="noopener">app.brevo.com → SMTP &amp; API → API Keys</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_list_id"><?php esc_html_e( 'Add contacts to list', 'brevo-contact-sync' ); ?></label></th>
						<td>
							<?php if ( ! is_wp_error( $lists ) && ! empty( $lists ) ) : ?>
								<select name="default_list_id" id="default_list_id">
									<option value="0"><?php esc_html_e( '— None —', 'brevo-contact-sync' ); ?></option>
									<?php foreach ( $lists as $list ) : ?>
										<option value="<?php echo esc_attr( $list['id'] ); ?>" <?php selected( $settings['default_list_id'], $list['id'] ); ?>>
											<?php echo esc_html( $list['name'] . ' (' . ( $list['totalSubscribers'] ?? 0 ) . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="number" name="default_list_id" id="default_list_id" value="<?php echo esc_attr( $settings['default_list_id'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Save a valid API key to pick from your lists.', 'brevo-contact-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Real-time sync', 'brevo-contact-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="realtime" value="1" <?php checked( $settings['realtime'], 1 ); ?> /> <?php esc_html_e( 'Update Brevo automatically when a customer registers, updates their profile, or completes an order.', 'brevo-contact-sync' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Empty values', 'brevo-contact-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="include_empty" value="1" <?php checked( $settings['include_empty'], 1 ); ?> /> <?php esc_html_e( 'Send empty fields too (overwrites/blanks them in Brevo). Off = only send fields that have a value.', 'brevo-contact-sync' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Checkout opt-in', 'brevo-contact-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show opt-in at checkout', 'brevo-contact-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="checkout_optin" value="1" <?php checked( $settings['checkout_optin'], 1 ); ?> /> <?php esc_html_e( 'Display a marketing opt-in checkbox on the checkout page.', 'brevo-contact-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'When on, customers are only added to the list above if they tick the box. Their contact details still sync regardless.', 'brevo-contact-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="optin_label"><?php esc_html_e( 'Checkbox text', 'brevo-contact-sync' ); ?></label></th>
						<td>
							<input type="text" id="optin_label" name="optin_label" value="<?php echo esc_attr( $settings['optin_label'] ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Keep me updated by email', 'brevo-contact-sync' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default state', 'brevo-contact-sync' ); ?></th>
						<td>
							<label style="margin-right:1.5em;"><input type="radio" name="optin_default" value="unchecked" <?php checked( $settings['optin_default'], 'unchecked' ); ?> /> <?php esc_html_e( 'Unchecked', 'brevo-contact-sync' ); ?></label>
							<label><input type="radio" name="optin_default" value="checked" <?php checked( $settings['optin_default'], 'checked' ); ?> /> <?php esc_html_e( 'Checked', 'brevo-contact-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'Tip: pre-checked opt-in may not be permitted under GDPR/CAN-SPAM in some regions.', 'brevo-contact-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'brevo-contact-sync' ) ); ?>
			</form>

			<?php if ( $api->has_key() ) : ?>
				<hr />
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bcs_test_connection" />
					<?php wp_nonce_field( 'bcs_test_connection' ); ?>
					<?php submit_button( __( 'Test Connection', 'brevo-contact-sync' ), 'secondary', 'submit', false ); ?>
				</form>
				<p style="margin-top:1em;">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=brevo-field-mapping' ) ); ?>"><?php esc_html_e( 'Next: map your custom fields →', 'brevo-contact-sync' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_mapping_page() {
		$api = new BCS_API();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Field Mapping', 'brevo-contact-sync' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<?php if ( ! $api->has_key() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Add your API key on the Connection page first.', 'brevo-contact-sync' ); ?></p></div>
				</div>
				<?php
				return;
			endif;

			$brevo_attrs = $api->get_attributes();
			if ( is_wp_error( $brevo_attrs ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div></div>', esc_html( $brevo_attrs->get_error_message() ) );
				return;
			}
			$attr_names = array();
			foreach ( $brevo_attrs as $a ) {
				if ( isset( $a['name'] ) ) {
					$attr_names[ strtoupper( $a['name'] ) ] = strtoupper( $a['name'] );
				}
			}

			$show_all = ! empty( $_GET['show_all'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$detected = BCS_Meta::detect_keys( $show_all );

			// Index existing mapping by meta key for pre-selection.
			$existing = array();
			foreach ( BCS_Sync::get_mapping() as $m ) {
				$existing[ $m['meta_key'] ] = $m;
			}
			// Make sure already-mapped keys appear even if filtered out.
			$detected_keys = wp_list_pluck( $detected, 'key' );
			foreach ( $existing as $mkey => $m ) {
				if ( ! in_array( $mkey, $detected_keys, true ) ) {
					$detected[] = array( 'key' => $mkey, 'users' => 0, 'sample' => '', 'transform' => $m['transform'] );
				}
			}

			$transforms = array(
				'raw'             => __( 'Text', 'brevo-contact-sync' ),
				'number'          => __( 'Number', 'brevo-contact-sync' ),
				'date'            => __( 'Date', 'brevo-contact-sync' ),
				'bool'            => __( 'Yes/No (boolean)', 'brevo-contact-sync' ),
				'serialized_list' => __( 'List (decode serialized)', 'brevo-contact-sync' ),
			);
			?>
			<p><?php esc_html_e( 'These are the customer fields found in your WordPress users. Tick the ones to sync, type (or pick) the Brevo field name to import into, and choose how the value is formatted. Your key fields are listed first; the rest follow by how many users have them. The contact email (top row) is always sent and can\'t be unchecked.', 'brevo-contact-sync' ); ?></p>
			<p class="description"><?php esc_html_e( 'The "Brevo field" box is editable — type any name you want. Names are normalised to Brevo\'s format (UPPER_CASE, no spaces), and a new field is created in Brevo automatically if it doesn\'t exist yet. Start typing to autocomplete from your existing Brevo fields.', 'brevo-contact-sync' ); ?></p>

			<datalist id="bcs-attr-names">
				<?php foreach ( $attr_names as $name ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>"></option>
				<?php endforeach; ?>
			</datalist>

			<details style="margin:1em 0;">
				<summary style="cursor:pointer;font-weight:600;">
					<?php
					/* translators: %d: number of Brevo fields */
					echo esc_html( sprintf( __( 'Your Brevo fields (%d) — click to view', 'brevo-contact-sync' ), count( $brevo_attrs ) ) );
					?>
				</summary>
				<table class="widefat striped" style="max-width:520px;margin-top:.5em;">
					<thead><tr><th><?php esc_html_e( 'Field name', 'brevo-contact-sync' ); ?></th><th><?php esc_html_e( 'Type', 'brevo-contact-sync' ); ?></th><th><?php esc_html_e( 'Category', 'brevo-contact-sync' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $brevo_attrs as $a ) : ?>
							<tr>
								<td><code><?php echo esc_html( strtoupper( $a['name'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( $a['type'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $a['category'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Brevo has no rename for fields. To rename, map to a new name here (it creates the new field), then delete the old one in Brevo under Contacts → Settings → Contact attributes.', 'brevo-contact-sync' ); ?></p>
			</details>

			<p>
				<?php $toggle = $show_all ? admin_url( 'admin.php?page=brevo-field-mapping' ) : admin_url( 'admin.php?page=brevo-field-mapping&show_all=1' ); ?>
				<a class="button" href="<?php echo esc_url( $toggle ); ?>"><?php echo $show_all ? esc_html__( 'Hide technical fields', 'brevo-contact-sync' ) : esc_html__( 'Show all meta fields', 'brevo-contact-sync' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bcs_rescan' ), 'bcs_rescan' ) ); ?>"><?php esc_html_e( 'Re-scan', 'brevo-contact-sync' ); ?></a>
			</p>

			<div style="display:flex;gap:1.5em;flex-wrap:wrap;align-items:flex-end;margin:1em 0;padding:1em;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
				<div>
					<strong><?php esc_html_e( 'Mapping templates', 'brevo-contact-sync' ); ?></strong>
					<p class="description" style="margin:.25em 0 .5em;"><?php esc_html_e( 'Save your current mapping as a CSV, or load one to reuse on another site.', 'brevo-contact-sync' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="bcs_export_mapping" />
						<?php wp_nonce_field( 'bcs_export_mapping' ); ?>
						<?php submit_button( __( 'Export mapping (CSV)', 'brevo-contact-sync' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:flex;gap:.5em;align-items:center;">
					<input type="hidden" name="action" value="bcs_import_mapping" />
					<?php wp_nonce_field( 'bcs_import_mapping' ); ?>
					<input type="file" name="mapping_csv" accept=".csv,text/csv" required />
					<?php submit_button( __( 'Import mapping', 'brevo-contact-sync' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bcs_save_mapping" />
				<?php wp_nonce_field( 'bcs_save_mapping' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:30px;"><?php esc_html_e( 'Sync', 'brevo-contact-sync' ); ?></th>
							<th><?php esc_html_e( 'WordPress field (meta key)', 'brevo-contact-sync' ); ?></th>
							<th><?php esc_html_e( 'Example value', 'brevo-contact-sync' ); ?></th>
							<th><?php esc_html_e( 'Users', 'brevo-contact-sync' ); ?></th>
							<th><?php esc_html_e( 'Brevo field', 'brevo-contact-sync' ); ?></th>
							<th><?php esc_html_e( 'Format', 'brevo-contact-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr style="background:#eef6ff;">
							<td title="<?php esc_attr_e( 'Always synced', 'brevo-contact-sync' ); ?>">&#10003;</td>
							<td><code>email</code></td>
							<td><span style="color:#666;"><?php esc_html_e( 'billing_email, then account email', 'brevo-contact-sync' ); ?></span></td>
							<td>&mdash;</td>
							<td><strong><?php esc_html_e( 'Contact email (identifier)', 'brevo-contact-sync' ); ?></strong></td>
							<td><?php esc_html_e( 'Always sent', 'brevo-contact-sync' ); ?></td>
						</tr>
						<?php foreach ( $detected as $row ) :
							$key       = $row['key'];
							$has       = isset( $existing[ $key ] );
							$cur_attr  = $has ? $existing[ $key ]['brevo_attr'] : '';
							$cur_trans = $has ? $existing[ $key ]['transform'] : $row['transform'];
							$suggest   = $this->suggest_attr_name( $key );
							// Pre-select an existing Brevo attribute whose name matches the suggestion.
							if ( '' === $cur_attr && isset( $attr_names[ $suggest ] ) ) {
								$cur_attr = $suggest;
							}
							$sample = (string) $row['sample'];
							if ( strlen( $sample ) > 48 ) {
								$sample = substr( $sample, 0, 48 ) . '…';
							}
							?>
							<tr>
								<td><input type="checkbox" name="enabled[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $has ); ?> /></td>
								<td><code><?php echo esc_html( $key ); ?></code><input type="hidden" name="meta_key[]" value="<?php echo esc_attr( $key ); ?>" /></td>
								<td><span style="color:#666;"><?php echo esc_html( $sample ); ?></span></td>
								<td><?php echo esc_html( $row['users'] ? number_format_i18n( $row['users'] ) : '—' ); ?></td>
								<td>
									<input type="text" list="bcs-attr-names" name="brevo_attr[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $cur_attr ); ?>" placeholder="<?php echo esc_attr( $suggest ); ?>" style="width:200px;" autocomplete="off" />
								</td>
								<td>
									<select name="transform[<?php echo esc_attr( $key ); ?>]">
										<?php foreach ( $transforms as $tval => $tlabel ) : ?>
											<option value="<?php echo esc_attr( $tval ); ?>" <?php selected( $cur_trans, $tval ); ?>><?php echo esc_html( $tlabel ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Mapping', 'brevo-contact-sync' ) ); ?>
			</form>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=brevo-bulk-sync' ) ); ?>"><?php esc_html_e( 'Next: run a sync →', 'brevo-contact-sync' ); ?></a></p>
		</div>
		<?php
	}

	public function render_sync_page() {
		$state    = BCS_Sync::get_state();
		$settings = BCS_Sync::get_settings();
		$running  = ! empty( $state['running'] );
		$mapped   = count( BCS_Sync::get_mapping() );
		$total    = $running ? (int) $state['total'] : BCS_Sync::total_users();
		$pct       = ( $running && $total ) ? min( 100, round( $state['offset'] / $total * 100 ) ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Sync', 'brevo-contact-sync' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<?php if ( $running ) : ?>
				<meta http-equiv="refresh" content="5" />
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: user count 2: mapped field count */
					esc_html__( '%1$s users, %2$d mapped field(s).', 'brevo-contact-sync' ),
					esc_html( number_format_i18n( $total ) ),
					(int) $mapped
				);
				?>
				<?php if ( ! empty( $settings['last_full_sync'] ) ) : ?>
					<br /><strong><?php esc_html_e( 'Last full sync:', 'brevo-contact-sync' ); ?></strong> <?php echo esc_html( $settings['last_full_sync'] ); ?>
				<?php endif; ?>
			</p>

			<?php if ( $mapped < 1 ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Map at least one field before syncing.', 'brevo-contact-sync' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $running ) : ?>
				<div style="max-width:600px;background:#e5e5e5;border-radius:4px;overflow:hidden;height:24px;margin:1em 0;">
					<div style="width:<?php echo esc_attr( $pct ); ?>%;background:#2271b1;height:24px;color:#fff;text-align:center;line-height:24px;font-size:12px;"><?php echo esc_html( $pct . '%' ); ?></div>
				</div>
				<p>
					<?php
					printf(
						/* translators: 1: processed 2: total 3: errors */
						esc_html__( 'Processed %1$s of %2$s. Errors: %3$d.', 'brevo-contact-sync' ),
						esc_html( number_format_i18n( $state['offset'] ) ),
						esc_html( number_format_i18n( $total ) ),
						(int) $state['errors']
					);
					?>
					<br /><em><?php echo esc_html( $state['last_msg'] ); ?></em>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bcs_cancel_sync" />
					<?php wp_nonce_field( 'bcs_cancel_sync' ); ?>
					<?php submit_button( __( 'Cancel Sync', 'brevo-contact-sync' ), 'delete', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'This page auto-refreshes every 5 seconds. The sync continues in the background even if you leave.', 'brevo-contact-sync' ); ?></p>
			<?php else : ?>
				<?php if ( ! empty( $state['last_msg'] ) ) : ?>
					<p><em><?php echo esc_html( $state['last_msg'] ); ?></em></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bcs_start_sync" />
					<?php wp_nonce_field( 'bcs_start_sync' ); ?>
					<?php submit_button( __( 'Sync all customers to Brevo now', 'brevo-contact-sync' ), 'primary', 'submit', false, $mapped < 1 ? array( 'disabled' => 'disabled' ) : array() ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'Sends customers to Brevo in background batches of 500 using Brevo\'s async import. Safe to run repeatedly — existing contacts are updated, not duplicated.', 'brevo-contact-sync' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Contact Status: look up an email and show its Brevo subscription state.
	 */
	public function render_status_page() {
		$api   = new BCS_API();
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Contact Status', 'brevo-contact-sync' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<?php if ( ! $api->has_key() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Add your API key on the Connection page first.', 'brevo-contact-sync' ); ?></p></div>
				</div>
				<?php
				return;
			endif;
			?>
			<p><?php esc_html_e( 'Look up a contact in Brevo to see whether they are subscribed or have unsubscribed (email blocklisted).', 'brevo-contact-sync' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="brevo-contact-status" />
				<input type="email" name="email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="customer@example.com" required />
				<?php submit_button( __( 'Check status', 'brevo-contact-sync' ), 'primary', 'submit', false ); ?>
			</form>

			<?php
			if ( '' === $email ) {
				echo '</div>';
				return;
			}

			$contact = $api->get_contact( $email );
			if ( is_wp_error( $contact ) ) {
				$code = (int) ( $contact->get_error_data()['status'] ?? 0 );
				if ( 404 === $code ) {
					printf( '<div class="notice notice-info" style="margin-top:1em;"><p>%s</p></div>', esc_html( sprintf( /* translators: %s: email */ __( '%s is not in Brevo yet — they have not been synced or imported.', 'brevo-contact-sync' ), $email ) ) );
				} else {
					printf( '<div class="notice notice-error" style="margin-top:1em;"><p>%s</p></div>', esc_html( $contact->get_error_message() ) );
				}
				echo '</div>';
				return;
			}

			$email_blacklisted = ! empty( $contact['emailBlacklisted'] );
			$sms_blacklisted   = ! empty( $contact['smsBlacklisted'] );
			$attributes        = isset( $contact['attributes'] ) && is_array( $contact['attributes'] ) ? $contact['attributes'] : array();
			$list_ids          = isset( $contact['listIds'] ) && is_array( $contact['listIds'] ) ? $contact['listIds'] : array();

			// Resolve list names for friendliness.
			$list_names = array();
			$lists      = $api->get_lists();
			if ( ! is_wp_error( $lists ) ) {
				$by_id = array();
				foreach ( $lists as $l ) {
					$by_id[ (int) $l['id'] ] = $l['name'];
				}
				foreach ( $list_ids as $lid ) {
					$list_names[] = isset( $by_id[ (int) $lid ] ) ? $by_id[ (int) $lid ] : ( '#' . $lid );
				}
			}
			?>
			<h2 style="margin-top:1.5em;"><?php echo esc_html( $email ); ?></h2>
			<?php if ( $email_blacklisted ) : ?>
				<p style="font-size:1.3em;">🚫 <strong style="color:#b32d2e;"><?php esc_html_e( 'Unsubscribed', 'brevo-contact-sync' ); ?></strong> <?php esc_html_e( '— email blocklisted in Brevo. They will not receive email campaigns.', 'brevo-contact-sync' ); ?></p>
			<?php else : ?>
				<p style="font-size:1.3em;">✅ <strong style="color:#007017;"><?php esc_html_e( 'Subscribed', 'brevo-contact-sync' ); ?></strong> <?php esc_html_e( '— can receive email campaigns.', 'brevo-contact-sync' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr><th style="width:200px;"><?php esc_html_e( 'Email subscription', 'brevo-contact-sync' ); ?></th><td><?php echo $email_blacklisted ? esc_html__( 'Unsubscribed (blocklisted)', 'brevo-contact-sync' ) : esc_html__( 'Subscribed', 'brevo-contact-sync' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'SMS subscription', 'brevo-contact-sync' ); ?></th><td><?php echo $sms_blacklisted ? esc_html__( 'Blocklisted', 'brevo-contact-sync' ) : esc_html__( 'OK', 'brevo-contact-sync' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Lists', 'brevo-contact-sync' ); ?></th><td><?php echo $list_names ? esc_html( implode( ', ', $list_names ) ) : '—'; ?></td></tr>
					<?php if ( ! empty( $contact['modifiedAt'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Last modified', 'brevo-contact-sync' ); ?></th><td><?php echo esc_html( $contact['modifiedAt'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $attributes ) ) : ?>
				<h3><?php esc_html_e( 'Attributes in Brevo', 'brevo-contact-sync' ); ?></h3>
				<table class="widefat striped" style="max-width:640px;">
					<thead><tr><th><?php esc_html_e( 'Field', 'brevo-contact-sync' ); ?></th><th><?php esc_html_e( 'Value', 'brevo-contact-sync' ); ?></th></tr></thead>
					<tbody>
						<?php
						foreach ( $attributes as $k => $v ) {
							if ( is_array( $v ) ) {
								$v = implode( ', ', $v );
							} elseif ( is_bool( $v ) ) {
								$v = $v ? 'true' : 'false';
							}
							printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $k ), esc_html( (string) $v ) );
						}
						?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
