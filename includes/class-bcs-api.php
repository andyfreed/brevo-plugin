<?php
/**
 * Brevo REST API client.
 *
 * Thin wrapper around the Brevo v3 API using the WordPress HTTP API.
 * Docs: https://developers.brevo.com/reference
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCS_API {

	const BASE_URL = 'https://api.brevo.com/v3';

	/**
	 * Brevo API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * @param string $api_key Optional. Falls back to the stored option.
	 */
	public function __construct( $api_key = '' ) {
		$this->api_key = $api_key ? $api_key : (string) get_option( BCS_OPTION_API_KEY, '' );
	}

	/**
	 * @return bool Whether an API key is configured.
	 */
	public function has_key() {
		return '' !== trim( $this->api_key );
	}

	/**
	 * Perform a request against the Brevo API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path beginning with a slash, e.g. /contacts.
	 * @param array  $query  Query args.
	 * @param array  $body   Request body (for POST/PUT).
	 * @return array|WP_Error Decoded response on success (empty array for 204).
	 */
	private function request( $method, $path, $query = array(), $body = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'bcs_no_key', __( 'No Brevo API key configured.', 'brevo-contact-sync' ) );
		}

		$url = self::BASE_URL . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'api-key'      => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? $data['message']
				: sprintf( /* translators: %d: HTTP status code */ __( 'Brevo API returned status %d.', 'brevo-contact-sync' ), $code );
			if ( is_array( $data ) && isset( $data['code'] ) ) {
				$message .= ' [' . $data['code'] . ']';
			}
			return new WP_Error( 'bcs_api_error', $message, array( 'status' => $code, 'body' => $raw ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Validate the API key by fetching the account.
	 *
	 * @return array|WP_Error
	 */
	public function get_account() {
		return $this->request( 'GET', '/account' );
	}

	/**
	 * Fetch all contact attributes (your Brevo custom fields live here).
	 *
	 * @return array|WP_Error List of attribute definitions.
	 */
	public function get_attributes() {
		$data = $this->request( 'GET', '/contacts/attributes' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['attributes'] ) ? $data['attributes'] : array();
	}

	/**
	 * Create a contact attribute (custom field) in Brevo.
	 *
	 * @param string $name     Attribute name (UPPER_SNAKE recommended).
	 * @param string $type     text|date|float|boolean|category.
	 * @param string $category Attribute category, normally "normal".
	 * @return array|WP_Error
	 */
	public function create_attribute( $name, $type = 'text', $category = 'normal' ) {
		$name = strtoupper( preg_replace( '/[^A-Z0-9_]/i', '_', $name ) );
		return $this->request(
			'POST',
			'/contacts/attributes/' . rawurlencode( $category ) . '/' . rawurlencode( $name ),
			array(),
			array( 'type' => $type )
		);
	}

	/**
	 * Fetch contact lists.
	 *
	 * @return array|WP_Error
	 */
	public function get_lists() {
		$data = $this->request( 'GET', '/contacts/lists', array( 'limit' => 50, 'offset' => 0 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['lists'] ) ? $data['lists'] : array();
	}

	/**
	 * Create or update a single contact (real-time path).
	 *
	 * @param string $email      Contact email.
	 * @param array  $attributes Map of ATTRIBUTE_NAME => value.
	 * @param array  $list_ids   List IDs to add the contact to.
	 * @return array|WP_Error
	 */
	public function upsert_contact( $email, $attributes = array(), $list_ids = array() ) {
		$body = array(
			'email'         => $email,
			'updateEnabled' => true,
		);
		if ( ! empty( $attributes ) ) {
			$body['attributes'] = $attributes;
		}
		if ( ! empty( $list_ids ) ) {
			$body['listIds'] = array_values( array_filter( array_map( 'intval', $list_ids ) ) );
		}
		return $this->request( 'POST', '/contacts', array(), $body );
	}

	/**
	 * Bulk import/update many contacts asynchronously.
	 *
	 * Brevo processes this in the background and returns a processId.
	 * Far faster than per-contact upserts for large batches.
	 *
	 * @param array $contacts  Each: array( 'email' => ..., 'attributes' => array(...) ).
	 * @param array $list_ids  Lists to add everyone to.
	 * @return array|WP_Error  { processId: int } on success.
	 */
	public function import_contacts( array $contacts, array $list_ids = array() ) {
		if ( empty( $contacts ) ) {
			return array( 'processId' => 0 );
		}

		$body = array(
			'jsonBody'                => array_values( $contacts ),
			'updateExistingContacts'  => true,
			'emptyContactsAttributes' => false,
		);
		if ( ! empty( $list_ids ) ) {
			$body['listIds'] = array_values( array_filter( array_map( 'intval', $list_ids ) ) );
		}

		return $this->request( 'POST', '/contacts/import', array(), $body );
	}
}
