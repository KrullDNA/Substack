<?php
/**
 * Campaign Monitor API wrapper.
 *
 * A single, shared HTTP layer used by every stage. It handles authentication,
 * timeouts, JSON encoding and decoding, Campaign Monitor error codes and rate
 * limit responses, and returns a WP_Error carrying the real API message on
 * failure rather than a generic one.
 *
 * Campaign Monitor API v3.3. Authentication is HTTP Basic, with the API key as
 * the username and any non empty string as the password.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_API
 */
class KDNA_AB_API {

	/**
	 * API base URL, including the version segment.
	 */
	const API_BASE = 'https://api.createsend.com/api/v3.3/';

	/**
	 * Default request timeout in seconds.
	 */
	const TIMEOUT = 20;

	/**
	 * The plain, decrypted API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Plain, decrypted API key.
	 */
	public function __construct( $api_key = '' ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Whether a key is present. Does not test validity, use get_clients() for that.
	 *
	 * @return bool
	 */
	public function has_key() {
		return '' !== trim( $this->api_key );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Endpoints
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Fetches the clients on the account.
	 *
	 * This is the endpoint used by the Stage 1 connection test, since it proves
	 * the key works and, on an agency account, names the clients the plugin can
	 * send from.
	 *
	 * @return array|WP_Error Array of clients on success, WP_Error on failure.
	 */
	public function get_clients() {
		return $this->request( 'GET', 'clients.json' );
	}

	/**
	 * Fetches the basic details for a single client.
	 *
	 * Returns the client BasicDetails, for example company name, country and
	 * time zone. Campaign Monitor does not expose verified sender addresses
	 * through the public API, so from and reply to addresses are validated by
	 * format rather than against a server list.
	 *
	 * @param string $client_id Campaign Monitor client ID.
	 * @return array|WP_Error
	 */
	public function get_client_details( $client_id ) {
		return $this->request( 'GET', 'clients/' . rawurlencode( $client_id ) . '.json' );
	}

	/**
	 * Fetches the subscriber lists belonging to a client.
	 *
	 * @param string $client_id Campaign Monitor client ID.
	 * @return array|WP_Error Array of lists on success.
	 */
	public function get_client_lists( $client_id ) {
		return $this->request( 'GET', 'clients/' . rawurlencode( $client_id ) . '/lists.json' );
	}

	/**
	 * Fetches the templates belonging to a client.
	 *
	 * @param string $client_id Campaign Monitor client ID.
	 * @return array|WP_Error Array of templates on success.
	 */
	public function get_client_templates( $client_id ) {
		return $this->request( 'GET', 'clients/' . rawurlencode( $client_id ) . '/templates.json' );
	}

	/**
	 * Fetches the details for a single template.
	 *
	 * Campaign Monitor returns the template name, preview URL and screenshot
	 * URL only. The API does not enumerate a template editable regions, which is
	 * why the plugin maps content to the template by position rather than by
	 * region name. The screenshot is used as a visual reference while mapping.
	 *
	 * @param string $template_id Campaign Monitor template ID.
	 * @return array|WP_Error
	 */
	public function get_template( $template_id ) {
		return $this->request( 'GET', 'templates/' . rawurlencode( $template_id ) . '.json' );
	}

	/**
	 * Fetches a list's statistics, including the active subscriber count.
	 *
	 * @param string $list_id Campaign Monitor list ID.
	 * @return array|WP_Error
	 */
	public function get_list_stats( $list_id ) {
		return $this->request( 'GET', 'lists/' . rawurlencode( $list_id ) . '/stats.json' );
	}

	/**
	 * Creates a draft campaign from a template.
	 *
	 * Returns the new campaign ID on success. The campaign is created as a draft,
	 * sending it is a separate call.
	 *
	 * @param string $client_id Campaign Monitor client ID.
	 * @param array  $payload   Campaign payload, including TemplateID and TemplateContent.
	 * @return string|WP_Error Campaign ID on success.
	 */
	public function create_campaign_from_template( $client_id, $payload ) {
		$result = $this->request( 'POST', 'campaigns/' . rawurlencode( $client_id ) . '/fromtemplate.json', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Campaign Monitor returns the campaign ID as a bare JSON string.
		return is_string( $result ) ? $result : (string) $result;
	}

	/**
	 * Sends a draft campaign.
	 *
	 * @param string $campaign_id       Campaign ID.
	 * @param string $confirmation_email Address, or comma separated addresses, for the send confirmation.
	 * @param string $send_date          Send date, or Immediately.
	 * @return true|WP_Error True on success.
	 */
	public function send_campaign( $campaign_id, $confirmation_email, $send_date = 'Immediately' ) {
		$result = $this->request(
			'POST',
			'campaigns/' . rawurlencode( $campaign_id ) . '/send.json',
			array(
				'ConfirmationEmail' => $confirmation_email,
				'SendDate'          => $send_date,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Sends a preview of a draft campaign to named recipients.
	 *
	 * This delivers the real rendered campaign to the given addresses, which is
	 * how a test send works.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param array  $recipients  Up to five recipient email addresses.
	 * @return true|WP_Error True on success.
	 */
	public function send_preview( $campaign_id, $recipients ) {
		$result = $this->request(
			'POST',
			'campaigns/' . rawurlencode( $campaign_id ) . '/sendpreview.json',
			array(
				'PreviewRecipients' => array_values( $recipients ),
				'Personalize'       => 'Fallback',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Deletes a draft campaign.
	 *
	 * Used to remove the temporary draft created for a test send.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return true|WP_Error True on success.
	 */
	public function delete_campaign( $campaign_id ) {
		$result = $this->request( 'DELETE', 'campaigns/' . rawurlencode( $campaign_id ) . '.json' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Core request
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Performs an authenticated request against the Campaign Monitor API.
	 *
	 * @param string     $method   HTTP method, for example GET or POST.
	 * @param string     $endpoint Endpoint relative to API_BASE, for example clients.json.
	 * @param array|null $body     Optional body, JSON encoded automatically.
	 * @return mixed|WP_Error Decoded response on success, WP_Error on failure.
	 */
	public function request( $method, $endpoint, $body = null ) {
		if ( ! $this->has_key() ) {
			return new WP_Error(
				'kdna_ab_no_key',
				__( 'No Campaign Monitor API key has been entered.', 'kdna-article-broadcast' ),
				array( 'retryable' => false )
			);
		}

		$url = self::API_BASE . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => self::TIMEOUT,
			'headers' => array(
				// API key as the username, any non empty string as the password.
				'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':x' ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'KDNA-Article-Broadcast/' . KDNA_AB_VERSION . '; ' . home_url( '/' ),
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		// Transport level failure, for example DNS, connection refused or timeout.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'kdna_ab_http',
				sprintf(
					/* translators: %s: underlying network error message. */
					__( 'Could not reach Campaign Monitor: %s', 'kdna-article-broadcast' ),
					$response->get_error_message()
				),
				array(
					'status'    => 0,
					'retryable' => true,
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// Success.
		if ( $code >= 200 && $code < 300 ) {
			return ( null === $data ) ? array() : $data;
		}

		return $this->build_error( $code, $data, $raw );
	}

	/**
	 * Turns a non 2xx response into a WP_Error carrying the real API message.
	 *
	 * Campaign Monitor error bodies look like {"Code":50,"Message":"..."}.
	 *
	 * @param int    $code HTTP status code.
	 * @param mixed  $data Decoded JSON body, or null.
	 * @param string $raw  Raw response body.
	 * @return WP_Error
	 */
	private function build_error( $code, $data, $raw ) {
		$api_code = ( is_array( $data ) && isset( $data['Code'] ) ) ? (int) $data['Code'] : 0;
		$message  = ( is_array( $data ) && isset( $data['Message'] ) && '' !== $data['Message'] )
			? (string) $data['Message']
			: $this->fallback_message( $code, $raw );

		return new WP_Error(
			'kdna_ab_api',
			$message,
			array(
				'status'    => $code,
				'api_code'  => $api_code,
				'body'      => $data,
				'retryable' => self::is_retryable_status( $code ),
			)
		);
	}

	/**
	 * Provides a readable message when the API did not return one.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $raw  Raw response body.
	 * @return string
	 */
	private function fallback_message( $code, $raw ) {
		switch ( $code ) {
			case 401:
				return __( 'Authentication failed. The API key was rejected by Campaign Monitor.', 'kdna-article-broadcast' );
			case 403:
				return __( 'The API key does not have permission for this action.', 'kdna-article-broadcast' );
			case 404:
				return __( 'The requested Campaign Monitor resource was not found.', 'kdna-article-broadcast' );
			case 429:
				return __( 'Campaign Monitor is rate limiting requests. Please wait a moment and try again.', 'kdna-article-broadcast' );
			case 500:
			case 502:
			case 503:
			case 504:
				return __( 'Campaign Monitor reported a temporary server error. Please try again shortly.', 'kdna-article-broadcast' );
			default:
				$snippet = trim( wp_strip_all_tags( (string) $raw ) );
				$snippet = ( '' !== $snippet ) ? ' ' . mb_substr( $snippet, 0, 200 ) : '';

				return sprintf(
					/* translators: 1: HTTP status code, 2: short response snippet. */
					__( 'Campaign Monitor returned an unexpected response (HTTP %1$d).%2$s', 'kdna-article-broadcast' ),
					$code,
					$snippet
				);
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Helpers shared with later stages
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Classifies an HTTP status as retryable or permanent.
	 *
	 * Timeouts, rate limits and server errors are retryable. Authentication and
	 * validation errors are permanent and should fail immediately. Stage 8 uses
	 * this to decide whether to schedule a retry.
	 *
	 * @param int $code HTTP status code, 0 for a transport failure.
	 * @return bool
	 */
	public static function is_retryable_status( $code ) {
		$code = (int) $code;

		// 0 means a transport failure such as a timeout, which is retryable.
		if ( 0 === $code ) {
			return true;
		}

		return in_array( $code, array( 408, 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Convenience check for a WP_Error produced by this wrapper.
	 *
	 * @param WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_retryable_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && ! empty( $data['retryable'] );
	}
}
