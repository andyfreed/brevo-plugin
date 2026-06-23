<?php
/**
 * Customer (user) meta detection + value transforms.
 *
 * Scans wp_usermeta for keys worth syncing, filters out internal/noise keys,
 * samples values, guesses the right transform, and converts a stored meta
 * value into something Brevo will accept as an attribute.
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCS_Meta {

	/**
	 * Meta keys to surface at the top of the mapping page, in this order.
	 * These are the customer fields you actually sync to Brevo; everything
	 * else is listed afterwards by how many users have it.
	 *
	 * @var string[]
	 */
	private static $priority = array(
		'first_name',
		'last_name',
		'flms_license-cfp',
		'flms_license-eaotrp',
		'flms_license-erpa',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_postcode',
		'billing_state',
		'flms_license-cpa',
		'shipping_company',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'bhfe-cpa-states',
		'billing_company',
		'flms_has-license-iar',
		'flms_license-iar',
	);

	/**
	 * Exact meta keys to always hide from the mapping UI (WP/plugin internals).
	 *
	 * @var string[]
	 */
	private static $deny_exact = array(
		'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'admin_color',
		'use_ssl', 'show_admin_bar_front', 'locale', 'wp_user_level', 'wp_capabilities',
		'session_tokens', 'default_password_nag', 'dismissed_wp_pointers', 'nickname',
		'last_update', 'wc_last_active', 'flms_last_active', 'paying_customer',
		'shipping_method', '_woocommerce_persistent_cart_1', '_woocommerce_tracks_anon_id',
		'wp_dashboard_quick_press_last_post_id', 'closedpostboxes_page', 'metaboxhidden_page',
		'bhfe_license_check_processed', 'description',
	);

	/**
	 * Regex patterns for keys to hide (course/exam state, capability variants, junk).
	 *
	 * @var string[]
	 */
	private static $deny_patterns = array(
		'/^_/',                         // private meta (_mc4wp_*, _wc_*, _woocommerce_*, _order_*).
		'/^wp_/',                       // wp_user-settings, wp_*_capabilities, etc.
		'/^flms_\d/',                   // per-course exam state: flms_86494:1_exam_attempts.
		'/exam/i',                      // any exam state.
		'/_attempt/i',
		'/current_exam_questions/i',
		'/web_fodder/i',                // flms_web_fodder_imported_*.
		'/^elementor/i',
		'/^wfls?[-_]/i',                // Wordfence.
		'/^closedpostboxes|^metaboxhidden|^meta-box-order/i',
		'/capabilities$|user_level$/i',
		'/^community-events-location$/i',
	);

	/**
	 * Detect candidate customer meta keys, ranked by how many users have them.
	 * Cached for an hour because the GROUP BY scans a large table.
	 *
	 * @param bool $show_all Include normally-hidden keys.
	 * @return array[] Each: array( key, users, sample, transform ).
	 */
	public static function detect_keys( $show_all = false ) {
		global $wpdb;

		$cache_key = $show_all ? 'bcs_meta_keys_all' : 'bcs_meta_keys';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Frequency of each meta key. Limit keeps it bounded on huge sites.
		$rows = $wpdb->get_results(
			"SELECT meta_key, COUNT(*) AS users
			 FROM {$wpdb->usermeta}
			 GROUP BY meta_key
			 HAVING users > 1
			 ORDER BY users DESC
			 LIMIT 300",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = $row['meta_key'];
			if ( ! $show_all && self::is_denied( $key ) ) {
				continue;
			}

			$sample = self::sample_value( $key );

			$out[] = array(
				'key'       => $key,
				'users'     => (int) $row['users'],
				'sample'    => $sample,
				'transform' => self::guess_transform( $sample ),
			);
		}

		// Make sure every priority key is present, even if rarely used.
		$present = wp_list_pluck( $out, 'key' );
		foreach ( self::$priority as $pk ) {
			if ( in_array( $pk, $present, true ) ) {
				continue;
			}
			$sample = self::sample_value( $pk );
			$out[]  = array(
				'key'       => $pk,
				'users'     => self::count_users_with( $pk ),
				'sample'    => $sample,
				'transform' => self::guess_transform( $sample ),
			);
		}

		// Sort: priority keys first (in listed order), then the rest by frequency.
		$priority = array_flip( self::$priority );
		usort(
			$out,
			static function ( $a, $b ) use ( $priority ) {
				$ia = isset( $priority[ $a['key'] ] ) ? $priority[ $a['key'] ] : PHP_INT_MAX;
				$ib = isset( $priority[ $b['key'] ] ) ? $priority[ $b['key'] ] : PHP_INT_MAX;
				if ( $ia !== $ib ) {
					return $ia <=> $ib;
				}
				return $b['users'] <=> $a['users'];
			}
		);

		set_transient( $cache_key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Count how many users have a given meta key.
	 *
	 * @param string $key Meta key.
	 * @return int
	 */
	private static function count_users_with( $key ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $key )
		);
	}

	/**
	 * Whether a key should be hidden by default.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_denied( $key ) {
		if ( in_array( $key, self::$deny_exact, true ) ) {
			return true;
		}
		foreach ( self::$deny_patterns as $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch one non-empty sample value for a meta key.
	 *
	 * @param string $key Meta key.
	 * @return string Raw stored value (possibly serialized).
	 */
	private static function sample_value( $key ) {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta}
				 WHERE meta_key = %s AND meta_value <> '' AND meta_value <> '0'
				 LIMIT 1",
				$key
			)
		);
		return null === $val ? '' : (string) $val;
	}

	/**
	 * Guess the transform to apply based on a sample value.
	 *
	 * @param string $sample Raw value.
	 * @return string raw|bool|date|number|serialized_list
	 */
	public static function guess_transform( $sample ) {
		if ( '' === $sample ) {
			return 'raw';
		}
		// Serialized PHP (possibly double-serialized) → list.
		if ( self::looks_serialized( $sample ) && is_array( self::deep_unserialize( $sample ) ) ) {
			return 'serialized_list';
		}
		$lower = strtolower( trim( $sample ) );
		if ( in_array( $lower, array( 'on', 'off', 'yes', 'no', 'true', 'false' ), true ) ) {
			return 'bool';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sample ) || preg_match( '#^\d{1,2}/\d{1,2}/\d{4}$#', $sample ) ) {
			return 'date';
		}
		if ( is_numeric( $sample ) ) {
			return 'number';
		}
		return 'raw';
	}

	/**
	 * Convert a user's stored meta value into a Brevo-ready attribute value.
	 *
	 * @param mixed  $value     Value from get_user_meta() (WP unserializes once).
	 * @param string $transform Transform key.
	 * @return mixed|null Null means "skip this attribute".
	 */
	public static function transform_value( $value, $transform ) {
		switch ( $transform ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				$lower = strtolower( trim( (string) $value ) );
				if ( '' === $lower ) {
					return false;
				}
				return ! in_array( $lower, array( 'off', 'no', 'false', '0' ), true );

			case 'number':
				if ( '' === $value || null === $value ) {
					return null;
				}
				return is_numeric( $value ) ? 0 + $value : null;

			case 'date':
				$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
				return $ts ? gmdate( 'Y-m-d', $ts ) : null;

			case 'serialized_list':
				$decoded = self::deep_unserialize( $value );
				if ( is_array( $decoded ) ) {
					$flat = array();
					array_walk_recursive(
						$decoded,
						static function ( $v ) use ( &$flat ) {
							if ( '' !== $v && null !== $v ) {
								$flat[] = (string) $v;
							}
						}
					);
					return implode( ', ', $flat );
				}
				return '' === (string) $decoded ? null : (string) $decoded;

			case 'raw':
			default:
				if ( is_array( $value ) ) {
					$value = self::deep_unserialize( $value );
					if ( is_array( $value ) ) {
						return implode( ', ', array_map( 'strval', $value ) );
					}
				}
				$str = trim( (string) $value );
				return '' === $str ? null : $str;
		}
	}

	/**
	 * Heuristic: does this string look like PHP-serialized data?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function looks_serialized( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return (bool) preg_match( '/^(a:\d+:\{|s:\d+:"|i:\d+;|O:\d+:")/', trim( $value ) );
	}

	/**
	 * Unserialize repeatedly to cope with double-serialized meta
	 * (e.g. bhfe-cpa-states stored as s:19:"a:1:{i:0;s:2:\"AZ\";}").
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function deep_unserialize( $value ) {
		$guard = 0;
		while ( is_string( $value ) && self::looks_serialized( $value ) && $guard < 5 ) {
			$un = maybe_unserialize( $value );
			if ( $un === $value ) {
				break;
			}
			$value = $un;
			$guard++;
		}
		return $value;
	}

	/**
	 * Clear the detection cache (after a sync or when asked to rescan).
	 */
	public static function flush_cache() {
		delete_transient( 'bcs_meta_keys' );
		delete_transient( 'bcs_meta_keys_all' );
	}
}
