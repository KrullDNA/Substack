<?php
/**
 * Single article send logic.
 *
 * Publishing a flagged post creates a campaign in Campaign Monitor from the
 * mapped single article template. Three send modes are supported, set globally:
 * create a draft only and notify, auto-send immediately, or auto-send after a
 * hold window. Draft is the default.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Sender
 */
class KDNA_AB_Sender {

	/**
	 * Cron hook fired when a hold window ends.
	 */
	const HOLD_HOOK = 'kdna_ab_hold_send';

	/**
	 * Post meta storing the hold cancel token.
	 */
	const META_HOLD_TOKEN = '_kdna_ab_hold_token';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Sender|null
	 */
	private static $instance = null;

	/**
	 * Post IDs already processed this request, to avoid a double fire.
	 *
	 * @var array
	 */
	private static $processed = array();

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Sender
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers the hooks.
	 */
	private function __construct() {
		// Catches manual publishing and scheduled posts publishing via WP Cron.
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( self::HOLD_HOOK, array( $this, 'run_hold_send' ), 10, 2 );
		add_action( 'admin_post_kdna_ab_cancel_hold', array( $this, 'handle_cancel_hold' ) );
		add_action( 'admin_notices', array( $this, 'cancelled_notice' ) );
	}

	/**
	 * Shows a confirmation notice after a hold send is cancelled.
	 *
	 * @return void
	 */
	public function cancelled_notice() {
		if ( empty( $_GET['kdna_ab_cancelled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'The scheduled broadcast was cancelled. The draft is still available in Campaign Monitor.', 'kdna-article-broadcast' )
			. '</p></div>';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Publish trigger
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Reacts to a post moving into the published status.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Already published, so this is an update. Never create a second campaign.
		if ( 'publish' === $old_status ) {
			return;
		}

		if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$this->maybe_broadcast( (int) $post->ID );
	}

	/**
	 * Applies the guard conditions and, if they pass, starts the broadcast.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function maybe_broadcast( $post_id ) {
		// Guard: never process the same post twice in one request.
		if ( isset( self::$processed[ $post_id ] ) ) {
			return;
		}
		self::$processed[ $post_id ] = true;

		// Guard: the send checkbox must be ticked.
		if ( '1' !== (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_SEND, true ) ) {
			return;
		}

		// Guard: the post must not have a campaign already, no duplicates.
		if ( '' !== (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_CAMPAIGN_ID, true ) ) {
			return;
		}

		// Guard: the plugin must be fully configured.
		$missing = self::configuration_gaps();

		if ( ! empty( $missing ) ) {
			KDNA_AB_Meta_Box::record_failure(
				$post_id,
				sprintf(
					/* translators: %s: comma separated list of missing settings. */
					__( 'Not broadcast, the plugin is not fully configured. Missing: %s.', 'kdna-article-broadcast' ),
					implode( ', ', $missing )
				),
				time()
			);
			return;
		}

		$settings = kdna_ab_get_settings();
		$mode     = self::normalise_mode( $settings['send_mode'] );

		$this->create_campaign( $post_id, $mode );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Campaign creation
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Creates the campaign, then acts on the send mode.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mode    Send mode, draft, auto or hold.
	 * @return void
	 */
	private function create_campaign( $post_id, $mode ) {
		$assembled = KDNA_AB_Content::assemble( $post_id );

		if ( is_wp_error( $assembled ) ) {
			KDNA_AB_Meta_Box::record_failure( $post_id, $assembled->get_error_message(), time() );
			self::log_attempt( $post_id, 'article', 'failed', '', $mode, $assembled->get_error_message() );
			return;
		}

		$settings = kdna_ab_get_settings();
		$payload  = self::build_payload( $post_id, $assembled, $settings );

		$campaign_id = kdna_ab_api()->create_campaign_from_template( $settings['client_id'], $payload );

		if ( is_wp_error( $campaign_id ) ) {
			// Stage 8 adds retries. For now the failure is recorded.
			KDNA_AB_Meta_Box::record_failure( $post_id, $campaign_id->get_error_message(), time() );
			self::log_attempt( $post_id, 'article', 'failed', '', $mode, $campaign_id->get_error_message() );
			return;
		}

		$campaign_id = trim( (string) $campaign_id );

		if ( '' === $campaign_id ) {
			$message = __( 'Campaign Monitor did not return a campaign ID.', 'kdna-article-broadcast' );
			KDNA_AB_Meta_Box::record_failure( $post_id, $message, time() );
			self::log_attempt( $post_id, 'article', 'failed', '', $mode, $message );
			return;
		}

		if ( 'auto' === $mode ) {
			$this->do_auto_send( $post_id, $campaign_id );
		} elseif ( 'hold' === $mode ) {
			$this->do_hold( $post_id, $campaign_id, $settings );
		} else {
			// Draft only.
			KDNA_AB_Meta_Box::record_campaign( $post_id, $campaign_id, 'draft', 'draft', time() );
			self::log_attempt( $post_id, 'article', 'draft', $campaign_id, 'draft' );
			$this->notify_admin( $post_id, $campaign_id, 'draft' );
		}
	}

	/**
	 * Sends the campaign immediately.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $campaign_id Campaign ID.
	 * @return void
	 */
	private function do_auto_send( $post_id, $campaign_id ) {
		// Lock immediately so the campaign is never created twice.
		KDNA_AB_Meta_Box::record_campaign( $post_id, $campaign_id, 'draft', 'auto', time() );

		$sent = kdna_ab_api()->send_campaign( $campaign_id, self::notify_address() );

		if ( is_wp_error( $sent ) ) {
			// The draft exists in Campaign Monitor, only the send failed.
			$message = sprintf(
				/* translators: %s: error message. */
				__( 'Draft created but the send failed: %s', 'kdna-article-broadcast' ),
				$sent->get_error_message()
			);
			KDNA_AB_Meta_Box::record_failure( $post_id, $message, time() );
			self::log_attempt( $post_id, 'article', 'failed', $campaign_id, 'auto', $message );
			$this->notify_admin( $post_id, $campaign_id, 'auto_failed' );
			return;
		}

		KDNA_AB_Meta_Box::record_campaign( $post_id, $campaign_id, 'sent', 'auto', time() );
		self::log_attempt( $post_id, 'article', 'sent', $campaign_id, 'auto' );
		$this->notify_admin( $post_id, $campaign_id, 'sent' );
	}

	/**
	 * Holds the campaign as a draft and schedules the send.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $campaign_id Campaign ID.
	 * @param array  $settings    Settings.
	 * @return void
	 */
	private function do_hold( $post_id, $campaign_id, $settings ) {
		$minutes = max( 1, (int) $settings['hold_window'] );
		$release = time() + ( $minutes * MINUTE_IN_SECONDS );

		KDNA_AB_Meta_Box::record_campaign( $post_id, $campaign_id, 'held', 'hold', $release );

		// A cancel token, so the cancel link works beyond a nonce lifetime.
		$token = wp_generate_password( 20, false );
		update_post_meta( $post_id, self::META_HOLD_TOKEN, $token );

		wp_schedule_single_event( $release, self::HOLD_HOOK, array( $post_id, $campaign_id ) );

		self::log_attempt( $post_id, 'article', 'held', $campaign_id, 'hold' );
		$this->notify_admin( $post_id, $campaign_id, 'held' );
	}

	/**
	 * Runs the scheduled send at the end of a hold window.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $campaign_id Campaign ID.
	 * @return void
	 */
	public function run_hold_send( $post_id, $campaign_id ) {
		$post_id     = (int) $post_id;
		$campaign_id = (string) $campaign_id;

		// Only proceed if the post is still held with this same campaign.
		if ( 'held' !== (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_STATUS, true ) ) {
			return;
		}

		if ( $campaign_id !== (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_CAMPAIGN_ID, true ) ) {
			return;
		}

		delete_post_meta( $post_id, self::META_HOLD_TOKEN );

		$sent = kdna_ab_api()->send_campaign( $campaign_id, self::notify_address() );

		if ( is_wp_error( $sent ) ) {
			$message = sprintf(
				/* translators: %s: error message. */
				__( 'Hold window ended but the send failed: %s', 'kdna-article-broadcast' ),
				$sent->get_error_message()
			);
			KDNA_AB_Meta_Box::record_failure( $post_id, $message, time() );
			self::log_attempt( $post_id, 'article', 'failed', $campaign_id, 'hold', $message );
			$this->notify_admin( $post_id, $campaign_id, 'auto_failed' );
			return;
		}

		KDNA_AB_Meta_Box::record_campaign( $post_id, $campaign_id, 'sent', 'hold', time() );
		self::log_attempt( $post_id, 'article', 'sent', $campaign_id, 'hold' );
		$this->notify_admin( $post_id, $campaign_id, 'sent' );
	}

	/**
	 * Handles the cancel link from the notification email.
	 *
	 * Cancelling stops the scheduled send and leaves the draft intact in
	 * Campaign Monitor.
	 *
	 * @return void
	 */
	public function handle_cancel_hold() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel this send.', 'kdna-article-broadcast' ) );
		}

		$stored = (string) get_post_meta( $post_id, self::META_HOLD_TOKEN, true );

		if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
			wp_die( esc_html__( 'This cancel link is no longer valid. The send may have already gone out or been cancelled.', 'kdna-article-broadcast' ) );
		}

		self::cancel_hold( $post_id );

		$redirect = add_query_arg(
			array( 'kdna_ab_cancelled' => 1 ),
			get_edit_post_link( $post_id, 'raw' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Cancels a held send, leaving the draft intact.
	 *
	 * Clears the scheduled send and returns the post to the draft state. The
	 * campaign lock stays in place, so no second campaign can be created.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function cancel_hold( $post_id ) {
		$post_id     = (int) $post_id;
		$campaign_id = (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_CAMPAIGN_ID, true );

		wp_clear_scheduled_hook( self::HOLD_HOOK, array( $post_id, $campaign_id ) );
		delete_post_meta( $post_id, self::META_HOLD_TOKEN );

		KDNA_AB_Meta_Box::record_status( $post_id, 'draft', time() );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Test sends
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Sends a test of a post to the test recipients.
	 *
	 * A temporary draft campaign is created, the real rendered preview is sent to
	 * the recipients, then the temporary draft is deleted. This never sets the
	 * send lock and never records a real send.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error Recipients on success, WP_Error on failure.
	 */
	public static function send_test( $post_id ) {
		$post_id = (int) $post_id;

		$missing = self::configuration_gaps();

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'kdna_ab_not_configured',
				sprintf(
					/* translators: %s: comma separated list of missing settings. */
					__( 'The plugin is not fully configured. Missing: %s.', 'kdna-article-broadcast' ),
					implode( ', ', $missing )
				)
			);
		}

		$recipients = self::test_recipients();

		if ( empty( $recipients ) ) {
			return new WP_Error( 'kdna_ab_no_recipients', __( 'There are no valid test recipients. Add a test address in the settings.', 'kdna-article-broadcast' ) );
		}

		$assembled = KDNA_AB_Content::assemble( $post_id );

		if ( is_wp_error( $assembled ) ) {
			return $assembled;
		}

		$settings = kdna_ab_get_settings();
		$payload  = self::build_payload( $post_id, $assembled, $settings );

		// A distinct name so a test draft is easy to spot if cleanup ever fails.
		$payload['Name'] = sprintf( '[TEST] %1$s (%2$s)', wp_strip_all_tags( get_the_title( $post_id ) ), wp_date( 'Y-m-d H:i:s' ) );

		$api         = kdna_ab_api();
		$campaign_id = $api->create_campaign_from_template( $settings['client_id'], $payload );

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$campaign_id = trim( (string) $campaign_id );

		if ( '' === $campaign_id ) {
			return new WP_Error( 'kdna_ab_no_campaign', __( 'Campaign Monitor did not return a campaign ID for the test.', 'kdna-article-broadcast' ) );
		}

		$preview = $api->send_preview( $campaign_id, $recipients );

		// Remove the temporary draft whether or not the preview succeeded.
		$api->delete_campaign( $campaign_id );

		if ( is_wp_error( $preview ) ) {
			self::log_attempt( $post_id, 'test', 'failed', '', 'test', $preview->get_error_message(), count( $recipients ) );
			return $preview;
		}

		self::log_attempt( $post_id, 'test', 'sent', '', 'test', '', count( $recipients ) );

		return array( 'recipients' => $recipients );
	}

	/**
	 * Returns the test recipients: the current user, plus the standing addresses.
	 *
	 * Campaign Monitor accepts up to five preview recipients, so the list is
	 * capped.
	 *
	 * @return array
	 */
	public static function test_recipients() {
		$recipients = array();

		$current = wp_get_current_user();

		if ( $current && is_email( $current->user_email ) ) {
			$recipients[] = $current->user_email;
		}

		$settings = kdna_ab_get_settings();
		$standing = isset( $settings['test_addresses'] ) && is_array( $settings['test_addresses'] ) ? $settings['test_addresses'] : array();

		foreach ( $standing as $address ) {
			if ( is_email( $address ) ) {
				$recipients[] = $address;
			}
		}

		$recipients = array_values( array_unique( $recipients ) );

		return array_slice( $recipients, 0, 5 );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Payload
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds the create campaign from template payload.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $assembled Assembled content values.
	 * @param array $settings  Settings.
	 * @return array
	 */
	public static function build_payload( $post_id, $assembled, $settings ) {
		$order  = KDNA_AB_Content::ordered_mapping( $settings['mapping_single'], KDNA_AB_Settings::single_fields() );
		$values = self::single_value_map( $assembled );

		$singlelines = array();
		foreach ( $order['singleline'] as $key ) {
			$singlelines[] = array( 'Content' => isset( $values[ $key ] ) ? $values[ $key ] : '' );
		}

		$multilines = array();
		foreach ( $order['multiline'] as $key ) {
			$multilines[] = array( 'Content' => isset( $values[ $key ] ) ? $values[ $key ] : '' );
		}

		$alt    = wp_strip_all_tags( get_the_title( $post_id ) );
		$images = array();
		foreach ( $order['image'] as $key ) {
			$images[] = array(
				'Content' => $assembled['image_url'],
				'Alt'     => $alt,
			);
		}

		// The email Subject is a plain text line, not HTML, so it uses the raw
		// subject rather than the entity encoded body value.
		$subject = html_entity_decode( wp_strip_all_tags( KDNA_AB_Meta_Box::effective_subject( $post_id ) ), ENT_QUOTES, 'UTF-8' );

		$reply_to = ( '' !== $settings['reply_to'] ) ? $settings['reply_to'] : $settings['from_email'];

		return array(
			'Subject'         => $subject,
			'Name'            => sprintf( '%1$s (%2$s)', wp_strip_all_tags( get_the_title( $post_id ) ), wp_date( 'Y-m-d H:i' ) ),
			'FromName'        => $settings['from_name'],
			'FromEmail'       => $settings['from_email'],
			'ReplyTo'         => $reply_to,
			'ListIDs'         => array( $settings['list_id'] ),
			'TemplateID'      => $settings['template_single_id'],
			'TemplateContent' => array(
				'Singlelines' => $singlelines,
				'Multilines'  => $multilines,
				'Images'      => $images,
			),
		);
	}

	/**
	 * Maps single article field keys to their assembled values.
	 *
	 * @param array $assembled Assembled content values.
	 * @return array
	 */
	private static function single_value_map( $assembled ) {
		return array(
			'subject_heading' => $assembled['subject'],
			'article_title'   => $assembled['title'],
			'teaser'          => $assembled['teaser'],
			'category'        => $assembled['category'],
			'author'          => $assembled['author'],
			'read_time'       => $assembled['read_time'],
			'article_link'    => $assembled['article_link'],
			'cta_label'       => $assembled['cta_label'],
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Configuration
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns a list of missing configuration items, empty when fully set up.
	 *
	 * @return array
	 */
	public static function configuration_gaps() {
		$settings = kdna_ab_get_settings();
		$missing  = array();

		if ( ! kdna_ab_api()->has_key() ) {
			$missing[] = __( 'API key', 'kdna-article-broadcast' );
		}

		if ( '' === $settings['client_id'] ) {
			$missing[] = __( 'client', 'kdna-article-broadcast' );
		}

		if ( '' === $settings['list_id'] ) {
			$missing[] = __( 'list', 'kdna-article-broadcast' );
		}

		if ( '' === $settings['template_single_id'] ) {
			$missing[] = __( 'single article template', 'kdna-article-broadcast' );
		}

		if ( '' === $settings['from_email'] || ! is_email( $settings['from_email'] ) ) {
			$missing[] = __( 'from email', 'kdna-article-broadcast' );
		}

		$order = KDNA_AB_Content::ordered_mapping( $settings['mapping_single'], KDNA_AB_Settings::single_fields() );

		if ( empty( $order['singleline'] ) && empty( $order['multiline'] ) && empty( $order['image'] ) ) {
			$missing[] = __( 'template region mapping', 'kdna-article-broadcast' );
		}

		return $missing;
	}

	/**
	 * Normalises a send mode value.
	 *
	 * @param string $mode Raw mode.
	 * @return string draft, auto or hold.
	 */
	public static function normalise_mode( $mode ) {
		$mode = (string) $mode;

		return in_array( $mode, array( 'draft', 'auto', 'hold' ), true ) ? $mode : 'draft';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Notifications
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the notification recipient address.
	 *
	 * @return string
	 */
	public static function notify_address() {
		$settings = kdna_ab_get_settings();
		$email    = trim( (string) $settings['notify_email'] );

		if ( '' !== $email && is_email( $email ) ) {
			return $email;
		}

		return get_option( 'admin_email' );
	}

	/**
	 * Builds a link to open the campaign in Campaign Monitor.
	 *
	 * The API does not provide a draft preview URL, so this is a filterable base
	 * that a site can point at its own Campaign Monitor login. The campaign ID is
	 * always included in the email for reference.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return string
	 */
	public static function campaign_app_url( $campaign_id ) {
		/**
		 * Filters the Campaign Monitor app URL for a campaign.
		 *
		 * @param string $url         Default login URL.
		 * @param string $campaign_id Campaign ID.
		 */
		return apply_filters( 'kdna_ab_campaign_url', 'https://login.createsend.com/', $campaign_id );
	}

	/**
	 * Sends the admin notification email for a campaign event.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $campaign_id Campaign ID.
	 * @param string $event       draft, sent, held or auto_failed.
	 * @return void
	 */
	private function notify_admin( $post_id, $campaign_id, $event ) {
		$title = get_the_title( $post_id );
		$to    = self::notify_address();
		$app   = self::campaign_app_url( $campaign_id );

		$lines = array();

		switch ( $event ) {
			case 'sent':
				/* translators: %s: post title. */
				$subject = sprintf( __( 'Article broadcast sent: %s', 'kdna-article-broadcast' ), $title );
				$lines[] = __( 'The campaign has been created and sent to your subscribers.', 'kdna-article-broadcast' );
				break;

			case 'held':
				/* translators: %s: post title. */
				$subject = sprintf( __( 'Article broadcast held: %s', 'kdna-article-broadcast' ), $title );
				$lines[] = sprintf(
					/* translators: %s: formatted date and time. */
					__( 'The draft has been created and will send at %s unless you cancel it.', 'kdna-article-broadcast' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_STATUS_TIME, true ) )
				);
				break;

			case 'auto_failed':
				/* translators: %s: post title. */
				$subject = sprintf( __( 'Article broadcast needs attention: %s', 'kdna-article-broadcast' ), $title );
				$lines[] = __( 'The draft was created but the automatic send failed. You can send it manually in Campaign Monitor.', 'kdna-article-broadcast' );
				break;

			case 'draft':
			default:
				/* translators: %s: post title. */
				$subject = sprintf( __( 'Article broadcast draft ready: %s', 'kdna-article-broadcast' ), $title );
				$lines[] = __( 'A draft campaign has been created. Open it in Campaign Monitor to review and send.', 'kdna-article-broadcast' );
				break;
		}

		$lines[] = '';
		/* translators: %s: campaign ID. */
		$lines[] = sprintf( __( 'Campaign ID: %s', 'kdna-article-broadcast' ), $campaign_id );
		/* translators: %s: Campaign Monitor URL. */
		$lines[] = sprintf( __( 'Open in Campaign Monitor: %s', 'kdna-article-broadcast' ), $app );

		if ( 'held' === $event ) {
			$lines[] = '';
			$lines[] = __( 'Cancel this send:', 'kdna-article-broadcast' );
			$lines[] = self::cancel_url( $post_id );
		}

		$lines[] = '';
		/* translators: %s: post edit URL. */
		$lines[] = sprintf( __( 'Edit the post: %s', 'kdna-article-broadcast' ), get_edit_post_link( $post_id, 'raw' ) );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Builds the cancel URL for a held send.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function cancel_url( $post_id ) {
		$token = (string) get_post_meta( $post_id, self::META_HOLD_TOKEN, true );

		return add_query_arg(
			array(
				'action' => 'kdna_ab_cancel_hold',
				'post'   => $post_id,
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Logging and retry
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Records a log entry for an attempt.
	 *
	 * @param int         $post_id     Post ID.
	 * @param string      $type        article, digest or test.
	 * @param string      $status      Status.
	 * @param string      $campaign_id Campaign ID, if any.
	 * @param string      $mode        Send mode used.
	 * @param string      $message     Message or error.
	 * @param int|null    $recipients  Recipient count, or null to estimate from the list.
	 * @return void
	 */
	private static function log_attempt( $post_id, $type, $status, $campaign_id, $mode, $message = '', $recipients = null ) {
		if ( ! class_exists( 'KDNA_AB_Log' ) ) {
			return;
		}

		$settings = kdna_ab_get_settings();

		if ( null === $recipients ) {
			$recipients = self::estimate_recipients( $settings['list_id'] );
		}

		KDNA_AB_Log::add(
			array(
				'post_id'     => $post_id,
				'post_title'  => get_the_title( $post_id ),
				'type'        => $type,
				'status'      => $status,
				'campaign_id' => $campaign_id,
				'list_id'     => $settings['list_id'],
				'recipients'  => (int) $recipients,
				'mode'        => $mode,
				'message'     => $message,
			)
		);
	}

	/**
	 * Estimates the recipient count from the list active subscribers, cached.
	 *
	 * @param string $list_id List ID.
	 * @return int
	 */
	public static function estimate_recipients( $list_id ) {
		$list_id = (string) $list_id;

		if ( '' === $list_id ) {
			return 0;
		}

		$key    = 'kdna_ab_cache_liststats_' . md5( $list_id );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$stats = kdna_ab_api()->get_list_stats( $list_id );
		$count = 0;

		if ( ! is_wp_error( $stats ) && isset( $stats['TotalActiveSubscribers'] ) ) {
			$count = (int) $stats['TotalActiveSubscribers'];
		}

		set_transient( $key, $count, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Retries a broadcast from a log row.
	 *
	 * If the campaign already exists it is re-sent, otherwise it is recreated. A
	 * test row re-runs the test.
	 *
	 * @param array $row Log row.
	 * @return true|WP_Error
	 */
	public static function retry_from_log( $row ) {
		$post_id = (int) $row['post_id'];
		$type    = (string) $row['type'];

		if ( 'test' === $type ) {
			$result = self::send_test( $post_id );
			return is_wp_error( $result ) ? $result : true;
		}

		if ( 'article' !== $type ) {
			return new WP_Error( 'kdna_ab_retry_unsupported', __( 'This entry cannot be retried automatically.', 'kdna-article-broadcast' ) );
		}

		$campaign_id = (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_CAMPAIGN_ID, true );

		if ( '' !== $campaign_id ) {
			// The campaign exists, so re-send it rather than create a duplicate.
			$mode = '' !== $row['mode'] ? $row['mode'] : 'auto';
			$sent = kdna_ab_api()->send_campaign( $campaign_id, self::notify_address() );

			if ( is_wp_error( $sent ) ) {
				KDNA_AB_Meta_Box::record_failure( $post_id, $sent->get_error_message(), time() );
				self::log_attempt( $post_id, 'article', 'failed', $campaign_id, $mode, $sent->get_error_message() );
				return $sent;
			}

			KDNA_AB_Meta_Box::record_campaign( $post_id, $campaign_id, 'sent', $mode, time() );
			self::log_attempt( $post_id, 'article', 'sent', $campaign_id, $mode );
			return true;
		}

		// No campaign yet, recreate from the current settings.
		$settings = kdna_ab_get_settings();
		$mode     = self::normalise_mode( $settings['send_mode'] );

		self::instance()->create_campaign( $post_id, $mode );

		return true;
	}
}
