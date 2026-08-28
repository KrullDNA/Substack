<?php
/**
 * AJAX signup handler and reCAPTCHA.
 *
 * Handles the front end newsletter signup: server side validation, an invisible
 * honeypot, Google reCAPTCHA v3 verification that fails open when Google cannot
 * be reached, and adding the subscriber to Campaign Monitor with double opt-in
 * and the consent metadata custom fields.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Subscribe
 */
class KDNA_AB_Subscribe {

	/**
	 * Nonce action for the front end form.
	 */
	const NONCE_ACTION = 'kdna_ab_subscribe';

	/**
	 * The reCAPTCHA verify endpoint.
	 */
	const RECAPTCHA_VERIFY = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Subscribe|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Subscribe
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers the AJAX handlers for logged in and out users.
	 */
	private function __construct() {
		add_action( 'wp_ajax_kdna_ab_subscribe', array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_nopriv_kdna_ab_subscribe', array( $this, 'ajax_subscribe' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Submission
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Handles a signup submission.
	 *
	 * @return void
	 */
	public function ajax_subscribe() {
		$i18n = self::messages();

		// Nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => $i18n['expired'] ), 403 );
		}

		// Honeypot. A filled field means a bot, so silently accept and stop.
		$honeypot = isset( $_POST['kdna_ab_hp'] ) ? trim( (string) wp_unslash( $_POST['kdna_ab_hp'] ) ) : '';

		if ( '' !== $honeypot ) {
			wp_send_json_success( array( 'message' => $i18n['success'] ) );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$page  = isset( $_POST['kdna_ab_page'] ) ? esc_url_raw( wp_unslash( $_POST['kdna_ab_page'] ) ) : '';
		$token = isset( $_POST['recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_token'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => $i18n['invalid_email'], 'field' => 'email' ), 200 );
		}

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => $i18n['name_required'], 'field' => 'name' ), 200 );
		}

		// reCAPTCHA, fail open.
		$recaptcha = self::check_recaptcha( $token );

		if ( 'fail' === $recaptcha ) {
			wp_send_json_error( array( 'message' => $i18n['recaptcha'] ), 200 );
		}

		// Configuration.
		$settings = kdna_ab_get_settings();

		if ( ! kdna_ab_api()->has_key() || '' === $settings['list_id'] ) {
			wp_send_json_error( array( 'message' => $i18n['unavailable'] ), 200 );
		}

		// Already subscribed check.
		$existing = kdna_ab_api()->get_subscriber( $settings['list_id'], $email );

		if ( ! is_wp_error( $existing ) && isset( $existing['State'] ) && 'Active' === $existing['State'] ) {
			wp_send_json_error( array( 'message' => $i18n['already'] ), 200 );
		}

		// Add the subscriber, which triggers the double opt-in confirmation.
		$payload = array(
			'EmailAddress'                            => $email,
			'Name'                                    => $name,
			'CustomFields'                            => self::consent_fields( $settings, $page ),
			'ConsentToTrack'                          => 'No',
			'Resubscribe'                             => true,
			'RestartSubscriptionBasedAutoresponders'  => false,
		);

		$result = kdna_ab_api()->add_subscriber( $settings['list_id'], $payload );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$msg  = ( is_array( $data ) && ! empty( $data['status'] ) && (int) $data['status'] >= 500 )
				? $i18n['network']
				: $result->get_error_message();

			wp_send_json_error( array( 'message' => $msg ), 200 );
		}

		/**
		 * Fires after a successful signup.
		 *
		 * @param string $email Email address.
		 * @param string $name  Name.
		 */
		do_action( 'kdna_ab_subscribed', $email, $name );

		wp_send_json_success( array( 'message' => $i18n['success'] ) );
	}

	/**
	 * Builds the consent metadata custom fields.
	 *
	 * @param array  $settings Settings.
	 * @param string $page     Source page URL.
	 * @return array
	 */
	private static function consent_fields( $settings, $page ) {
		return array(
			array(
				'Key'   => (string) $settings['cf_date_key'],
				'Value' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'Key'   => (string) $settings['cf_ip_key'],
				'Value' => self::client_ip(),
			),
			array(
				'Key'   => (string) $settings['cf_page_key'],
				'Value' => $page,
			),
		);
	}

	/**
	 * Returns the client IP address.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}

	/*
	 * -----------------------------------------------------------------------
	 * reCAPTCHA
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Checks a reCAPTCHA token for a submission.
	 *
	 * Returns 'pass' to allow, or 'fail' to block. The direction is fail open:
	 * if reCAPTCHA is not configured, no token was supplied, or Google could not
	 * be reached, the submission is allowed. Only a genuine low score or an
	 * explicit rejection blocks it.
	 *
	 * @param string $token The token from the front end.
	 * @return string pass or fail.
	 */
	public static function check_recaptcha( $token ) {
		$settings = kdna_ab_get_settings();
		$secret   = KDNA_AB_Crypto::decrypt( $settings['recaptcha_secret_key'] );

		if ( '' === $settings['recaptcha_site_key'] || '' === $secret ) {
			return 'pass';
		}

		if ( '' === $token ) {
			// The front end could not produce a token, fail open.
			return 'pass';
		}

		$result = self::verify_recaptcha( $token, $secret );

		if ( ! $result['reached'] ) {
			// Google could not be reached, fail open.
			return 'pass';
		}

		if ( ! $result['success'] ) {
			return 'fail';
		}

		$threshold = (float) $settings['recaptcha_threshold'];

		if ( null !== $result['score'] && $result['score'] < $threshold ) {
			return 'fail';
		}

		return 'pass';
	}

	/**
	 * Performs the reCAPTCHA verify round trip against Google.
	 *
	 * @param string $token  Token to verify.
	 * @param string $secret Secret key.
	 * @return array With reached, success, score and errors.
	 */
	public static function verify_recaptcha( $token, $secret ) {
		$response = wp_remote_post(
			self::RECAPTCHA_VERIFY,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'reached' => false,
				'success' => false,
				'score'   => null,
				'errors'  => array( $response->get_error_message() ),
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return array(
				'reached' => false,
				'success' => false,
				'score'   => null,
				'errors'  => array(),
			);
		}

		return array(
			'reached' => true,
			'success' => ! empty( $data['success'] ),
			'score'   => isset( $data['score'] ) ? (float) $data['score'] : null,
			'errors'  => isset( $data['error-codes'] ) ? (array) $data['error-codes'] : array(),
		);
	}

	/**
	 * The user facing messages.
	 *
	 * @return array
	 */
	public static function messages() {
		return array(
			'success'       => __( 'Thanks. Please check your inbox to confirm your subscription.', 'kdna-article-broadcast' ),
			'invalid_email' => __( 'Please enter a valid email address.', 'kdna-article-broadcast' ),
			'name_required' => __( 'Please enter your name.', 'kdna-article-broadcast' ),
			'already'       => __( 'You are already subscribed.', 'kdna-article-broadcast' ),
			'recaptcha'     => __( 'We could not verify that you are human. Please try again.', 'kdna-article-broadcast' ),
			'network'       => __( 'Something went wrong at our end. Please try again in a moment.', 'kdna-article-broadcast' ),
			'unavailable'   => __( 'Signups are not available right now.', 'kdna-article-broadcast' ),
			'expired'       => __( 'This form has expired. Please reload the page and try again.', 'kdna-article-broadcast' ),
		);
	}
}
