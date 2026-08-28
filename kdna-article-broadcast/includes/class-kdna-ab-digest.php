<?php
/**
 * Weekly digest logic.
 *
 * On a weekly schedule, gathers qualifying posts published since the last
 * digest, builds a roundup campaign from the digest template repeater, one block
 * per article, creates it as a draft and emails the administrator for approval.
 * Nothing is sent to subscribers until the Approve and send link is used, and an
 * unapproved digest expires after a configurable window.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Digest
 */
class KDNA_AB_Digest {

	/**
	 * Cron hook that runs the scheduled digest.
	 */
	const RUN_HOOK = 'kdna_ab_run_digest';

	/**
	 * Cron hook that expires an unapproved digest.
	 */
	const EXPIRE_HOOK = 'kdna_ab_expire_digest';

	/**
	 * Option storing the timestamp of the last digest run.
	 */
	const LAST_OPTION = 'kdna_ab_last_digest';

	/**
	 * Option storing pending digests awaiting approval.
	 */
	const PENDING_OPTION = 'kdna_ab_pending_digests';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Digest|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Digest
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
		add_action( self::RUN_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::EXPIRE_HOOK, array( $this, 'expire' ), 10, 1 );
		add_action( 'admin_post_kdna_ab_approve_digest', array( $this, 'handle_approve' ) );
		add_action( 'wp_ajax_kdna_ab_build_digest_now', array( $this, 'ajax_build_now' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Scheduling
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Reschedules the next weekly digest event from the settings.
	 *
	 * A self rescheduling single event is used, so the digest fires on the exact
	 * configured day and time in the site timezone.
	 *
	 * @return void
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::RUN_HOOK );

		$next = self::next_run_timestamp();

		if ( $next ) {
			wp_schedule_single_event( $next, self::RUN_HOOK );
		}
	}

	/**
	 * Computes the next run timestamp for the configured day and time.
	 *
	 * @return int Unix timestamp, or 0 on a bad configuration.
	 */
	public static function next_run_timestamp() {
		$settings = kdna_ab_get_settings();
		$day      = (int) $settings['digest_day']; // 0 Sunday to 6 Saturday.
		$time     = (string) $settings['digest_time'];

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			$hour   = 9;
			$minute = 0;
		} else {
			$hour   = min( 23, max( 0, (int) $m[1] ) );
			$minute = min( 59, max( 0, (int) $m[2] ) );
		}

		try {
			$tz        = wp_timezone();
			$now       = new DateTime( 'now', $tz );
			$candidate = new DateTime( 'now', $tz );
			$candidate->setTime( $hour, $minute, 0 );

			$today_dow = (int) $candidate->format( 'w' );
			$diff      = ( $day - $today_dow + 7 ) % 7;

			if ( 0 === $diff && $candidate <= $now ) {
				$diff = 7;
			}

			if ( $diff > 0 ) {
				$candidate->modify( '+' . $diff . ' days' );
			}

			return $candidate->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * Runs the scheduled digest, then reschedules for next week.
	 *
	 * @return void
	 */
	public function run_scheduled() {
		$this->build( 'schedule' );
		self::reschedule();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Building
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds a digest.
	 *
	 * @param string $trigger schedule or manual.
	 * @return array|WP_Error Result data, or WP_Error on a hard failure.
	 */
	public function build( $trigger = 'schedule' ) {
		$gaps = self::configuration_gaps();

		if ( ! empty( $gaps ) ) {
			$message = sprintf(
				/* translators: %s: comma separated list of missing settings. */
				__( 'Digest not built, not fully configured. Missing: %s.', 'kdna-article-broadcast' ),
				implode( ', ', $gaps )
			);
			$this->log( 0, __( 'Weekly digest', 'kdna-article-broadcast' ), 'failed', '', $message, 0 );
			return new WP_Error( 'kdna_ab_digest_config', $message );
		}

		$settings = kdna_ab_get_settings();
		$ids      = self::gather( $settings );

		if ( empty( $ids ) ) {
			$this->log( 0, __( 'Weekly digest', 'kdna-article-broadcast' ), 'skipped', '', __( 'No qualifying posts published since the last digest.', 'kdna-article-broadcast' ), 0 );

			if ( 'schedule' === $trigger ) {
				update_option( self::LAST_OPTION, time(), false );
			}

			return array( 'skipped' => true );
		}

		$payload = self::build_payload( $ids, $settings );

		if ( empty( $payload ) ) {
			$message = __( 'No usable article content for the digest.', 'kdna-article-broadcast' );
			$this->log( 0, __( 'Weekly digest', 'kdna-article-broadcast' ), 'skipped', '', $message, 0 );
			return array( 'skipped' => true );
		}

		$campaign_id = kdna_ab_api()->create_campaign_from_template( $settings['client_id'], $payload );

		if ( is_wp_error( $campaign_id ) ) {
			$this->log( 0, self::digest_title( $ids ), 'failed', '', $campaign_id->get_error_message(), count( $ids ) );
			return $campaign_id;
		}

		$campaign_id = trim( (string) $campaign_id );

		if ( '' === $campaign_id ) {
			$message = __( 'Campaign Monitor did not return a campaign ID for the digest.', 'kdna-article-broadcast' );
			$this->log( 0, self::digest_title( $ids ), 'failed', '', $message, count( $ids ) );
			return new WP_Error( 'kdna_ab_digest_no_id', $message );
		}

		$window  = max( 1, (int) $settings['digest_window'] );
		$expires = time() + ( $window * HOUR_IN_SECONDS );
		$token   = wp_generate_password( 24, false );

		$log_id = $this->log(
			0,
			self::digest_title( $ids ),
			'draft',
			$campaign_id,
			__( 'Draft created, awaiting approval.', 'kdna-article-broadcast' ),
			KDNA_AB_Sender::estimate_recipients( $settings['list_id'] )
		);

		$pending = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $pending ) ) {
			$pending = array();
		}

		$pending[ $campaign_id ] = array(
			'token'    => $token,
			'post_ids' => array_map( 'intval', $ids ),
			'expires'  => $expires,
			'log_id'   => (int) $log_id,
			'created'  => time(),
		);

		update_option( self::PENDING_OPTION, $pending, false );
		wp_schedule_single_event( $expires, self::EXPIRE_HOOK, array( $campaign_id ) );
		update_option( self::LAST_OPTION, time(), false );

		$this->send_approval_email( $campaign_id, $ids, $expires, $token );

		return array(
			'campaign_id' => $campaign_id,
			'count'       => count( $ids ),
		);
	}

	/**
	 * Gathers qualifying post IDs published since the last digest.
	 *
	 * @param array $settings Settings.
	 * @return array Post IDs, newest first.
	 */
	public static function gather( $settings ) {
		$since = (int) get_option( self::LAST_OPTION, 0 );

		if ( ! $since ) {
			$since = time() - WEEK_IN_SECONDS;
		}

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $settings['digest_max'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'date_query'     => array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', $since ),
					'inclusive' => false,
					'column'    => 'post_date_gmt',
				),
			),
		);

		// Overlap: exclude posts already broadcast individually.
		if ( ! empty( $settings['digest_overlap'] ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => KDNA_AB_Meta_Box::META_CAMPAIGN_ID,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => KDNA_AB_Meta_Box::META_CAMPAIGN_ID,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Builds the create campaign from template payload for a digest.
	 *
	 * @param array $ids      Post IDs.
	 * @param array $settings Settings.
	 * @return array|null Payload, or null when there is no usable content.
	 */
	public static function build_payload( $ids, $settings ) {
		$top_order  = KDNA_AB_Content::ordered_mapping( self::top_mapping( $settings ), KDNA_AB_Settings::digest_top_fields() );
		$top_values = array( 'intro' => KDNA_AB_Content::encode_entities( (string) $settings['digest_intro'] ) );

		$top_singlelines = array();
		foreach ( $top_order['singleline'] as $key ) {
			$top_singlelines[] = array( 'Content' => isset( $top_values[ $key ] ) ? $top_values[ $key ] : '' );
		}

		$top_multilines = array();
		foreach ( $top_order['multiline'] as $key ) {
			$top_multilines[] = array( 'Content' => isset( $top_values[ $key ] ) ? $top_values[ $key ] : '' );
		}

		$top_images = array();
		foreach ( $top_order['image'] as $key ) {
			$top_images[] = array(
				'Content' => '',
				'Alt'     => '',
			);
		}

		$rep_order = KDNA_AB_Content::ordered_mapping( self::repeater_mapping( $settings ), KDNA_AB_Settings::digest_repeater_fields() );
		$items     = array();

		foreach ( $ids as $post_id ) {
			$assembled = KDNA_AB_Content::assemble( $post_id );

			if ( is_wp_error( $assembled ) ) {
				// Skip a post with no usable content rather than fail the digest.
				continue;
			}

			$values = self::item_value_map( $assembled );

			$item_singlelines = array();
			foreach ( $rep_order['singleline'] as $key ) {
				$item_singlelines[] = array( 'Content' => isset( $values[ $key ] ) ? $values[ $key ] : '' );
			}

			$item_multilines = array();
			foreach ( $rep_order['multiline'] as $key ) {
				$item_multilines[] = array( 'Content' => isset( $values[ $key ] ) ? $values[ $key ] : '' );
			}

			$alt         = wp_strip_all_tags( get_the_title( $post_id ) );
			$item_images = array();
			foreach ( $rep_order['image'] as $key ) {
				$item_images[] = array(
					'Content' => $assembled['image_url'],
					'Alt'     => $alt,
				);
			}

			$items[] = array(
				'Singlelines' => $item_singlelines,
				'Multilines'  => $item_multilines,
				'Images'      => $item_images,
			);
		}

		if ( empty( $items ) ) {
			return null;
		}

		$subject = html_entity_decode(
			wp_strip_all_tags( str_replace( '{date}', wp_date( 'j M Y' ), (string) $settings['digest_subject'] ) ),
			ENT_QUOTES,
			'UTF-8'
		);

		$reply_to = ( '' !== $settings['reply_to'] ) ? $settings['reply_to'] : $settings['from_email'];

		return array(
			'Subject'         => $subject,
			'Name'            => sprintf( '%1$s (%2$s)', __( 'Weekly digest', 'kdna-article-broadcast' ), wp_date( 'Y-m-d H:i' ) ),
			'FromName'        => $settings['from_name'],
			'FromEmail'       => $settings['from_email'],
			'ReplyTo'         => $reply_to,
			'ListIDs'         => array( $settings['list_id'] ),
			'TemplateID'      => $settings['template_digest_id'],
			'TemplateContent' => array(
				'Singlelines' => $top_singlelines,
				'Multilines'  => $top_multilines,
				'Images'      => $top_images,
				'Repeaters'   => array(
					array( 'Items' => $items ),
				),
			),
		);
	}

	/**
	 * Maps a post's assembled values to the digest repeater field keys.
	 *
	 * @param array $assembled Assembled content.
	 * @return array
	 */
	private static function item_value_map( $assembled ) {
		return array(
			'article_title' => $assembled['title'],
			'teaser'        => $assembled['teaser'],
			'category'      => $assembled['category'],
			'author'        => $assembled['author'],
			'read_time'     => $assembled['read_time'],
			'article_link'  => $assembled['article_link'],
			'cta_label'     => $assembled['cta_label'],
		);
	}

	/**
	 * Returns the top level part of the digest mapping.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function top_mapping( $settings ) {
		return isset( $settings['mapping_digest']['top'] ) && is_array( $settings['mapping_digest']['top'] )
			? $settings['mapping_digest']['top']
			: array();
	}

	/**
	 * Returns the repeater part of the digest mapping.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function repeater_mapping( $settings ) {
		return isset( $settings['mapping_digest']['repeater'] ) && is_array( $settings['mapping_digest']['repeater'] )
			? $settings['mapping_digest']['repeater']
			: array();
	}

	/**
	 * Builds a title for the digest log row.
	 *
	 * @param array $ids Post IDs.
	 * @return string
	 */
	private static function digest_title( $ids ) {
		return sprintf(
			/* translators: %d: number of articles. */
			_n( 'Weekly digest, %d article', 'Weekly digest, %d articles', count( $ids ), 'kdna-article-broadcast' ),
			count( $ids )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Approval and expiry
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Handles the Approve and send link.
	 *
	 * @return void
	 */
	public function handle_approve() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to approve this digest.', 'kdna-article-broadcast' ) );
		}

		$campaign_id = isset( $_GET['cid'] ) ? sanitize_text_field( wp_unslash( $_GET['cid'] ) ) : '';
		$token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$pending = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $pending ) || ! isset( $pending[ $campaign_id ] ) ) {
			wp_die( esc_html__( 'This digest is no longer pending. It may have already been approved, or it expired.', 'kdna-article-broadcast' ) );
		}

		if ( ! hash_equals( (string) $pending[ $campaign_id ]['token'], $token ) ) {
			wp_die( esc_html__( 'This approval link is not valid.', 'kdna-article-broadcast' ) );
		}

		$sent = kdna_ab_api()->send_campaign( $campaign_id, KDNA_AB_Sender::notify_address() );

		if ( is_wp_error( $sent ) ) {
			// Leave the digest pending so it can be approved again once fixed.
			$this->log( 0, __( 'Weekly digest', 'kdna-article-broadcast' ), 'failed', $campaign_id, $sent->get_error_message(), 0 );
			$this->redirect_to_log( 'digest_failed' );
		}

		$settings = kdna_ab_get_settings();

		$this->log(
			0,
			__( 'Weekly digest', 'kdna-article-broadcast' ),
			'sent',
			$campaign_id,
			__( 'Approved and sent to subscribers.', 'kdna-article-broadcast' ),
			KDNA_AB_Sender::estimate_recipients( $settings['list_id'] )
		);

		self::clear_pending( $campaign_id );

		$this->redirect_to_log( 'digest_sent' );
	}

	/**
	 * Removes a pending digest and clears its expiry event.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return void
	 */
	public static function clear_pending( $campaign_id ) {
		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $campaign_id ) );

		$pending = get_option( self::PENDING_OPTION, array() );

		if ( is_array( $pending ) && isset( $pending[ $campaign_id ] ) ) {
			unset( $pending[ $campaign_id ] );
			update_option( self::PENDING_OPTION, $pending, false );
		}
	}

	/**
	 * Expires an unapproved digest.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return void
	 */
	public function expire( $campaign_id ) {
		$campaign_id = (string) $campaign_id;
		$pending     = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $pending ) || ! isset( $pending[ $campaign_id ] ) ) {
			return;
		}

		$this->log( 0, __( 'Weekly digest', 'kdna-article-broadcast' ), 'skipped', $campaign_id, __( 'Approval window elapsed, the digest was not sent. The draft remains in Campaign Monitor.', 'kdna-article-broadcast' ), 0 );

		unset( $pending[ $campaign_id ] );
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Redirects to the log page with a message code.
	 *
	 * @param string $msg Message code.
	 * @return void
	 */
	private function redirect_to_log( $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => KDNA_AB_Log::MENU_SLUG,
					'kdna_ab_msg' => $msg,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Manual build
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: builds a digest now, from the admin button.
	 *
	 * @return void
	 */
	public function ajax_build_now() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'kdna_ab_settings' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'kdna-article-broadcast' ) ), 403 );
		}

		$result = $this->build( 'manual' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 200 );
		}

		if ( ! empty( $result['skipped'] ) ) {
			wp_send_json_success( array( 'message' => __( 'No qualifying posts, so no digest was created.', 'kdna-article-broadcast' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of articles. */
					_n( 'Digest draft created with %d article. Check your email to approve and send.', 'Digest draft created with %d articles. Check your email to approve and send.', (int) $result['count'], 'kdna-article-broadcast' ),
					(int) $result['count']
				),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Configuration and helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns missing configuration items for a digest, empty when ready.
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

		if ( '' === $settings['from_email'] || ! is_email( $settings['from_email'] ) ) {
			$missing[] = __( 'from email', 'kdna-article-broadcast' );
		}

		if ( '' === $settings['template_digest_id'] ) {
			$missing[] = __( 'weekly digest template', 'kdna-article-broadcast' );
		}

		$rep_order = KDNA_AB_Content::ordered_mapping( self::repeater_mapping( $settings ), KDNA_AB_Settings::digest_repeater_fields() );

		if ( empty( $rep_order['singleline'] ) && empty( $rep_order['multiline'] ) && empty( $rep_order['image'] ) ) {
			$missing[] = __( 'digest repeater mapping', 'kdna-article-broadcast' );
		}

		return $missing;
	}

	/**
	 * Sends the approval email.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param array  $ids         Post IDs.
	 * @param int    $expires     Expiry timestamp.
	 * @param string $token       Approval token.
	 * @return void
	 */
	private function send_approval_email( $campaign_id, $ids, $expires, $token ) {
		$to  = KDNA_AB_Sender::notify_address();
		$app = KDNA_AB_Sender::campaign_app_url( $campaign_id );

		$approve = add_query_arg(
			array(
				'action' => 'kdna_ab_approve_digest',
				'cid'    => rawurlencode( $campaign_id ),
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$subject = __( 'Weekly digest ready to approve', 'kdna-article-broadcast' );

		$lines   = array();
		$lines[] = sprintf(
			/* translators: %d: number of articles. */
			_n( 'A weekly digest draft with %d article has been created and is waiting for your approval.', 'A weekly digest draft with %d articles has been created and is waiting for your approval.', count( $ids ), 'kdna-article-broadcast' ),
			count( $ids )
		);
		$lines[] = '';
		$lines[] = __( 'Articles in this digest:', 'kdna-article-broadcast' );

		foreach ( $ids as $post_id ) {
			$lines[] = '- ' . wp_strip_all_tags( get_the_title( $post_id ) );
		}

		$lines[] = '';
		/* translators: %s: Campaign Monitor URL. */
		$lines[] = sprintf( __( 'Preview in Campaign Monitor: %s', 'kdna-article-broadcast' ), $app );
		$lines[] = '';
		$lines[] = __( 'Approve and send to subscribers:', 'kdna-article-broadcast' );
		$lines[] = $approve;
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: formatted date and time. */
			__( 'If not approved by %s the digest expires and is not sent.', 'kdna-article-broadcast' ),
			wp_date( $format, $expires )
		);

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Adds a digest log entry.
	 *
	 * @param int    $post_id     Post ID, zero for a digest.
	 * @param string $title       Log title.
	 * @param string $status      Status.
	 * @param string $campaign_id Campaign ID.
	 * @param string $message     Message.
	 * @param int    $recipients  Recipient estimate.
	 * @return int Log row ID.
	 */
	private function log( $post_id, $title, $status, $campaign_id, $message, $recipients ) {
		if ( ! class_exists( 'KDNA_AB_Log' ) ) {
			return 0;
		}

		$settings = kdna_ab_get_settings();

		return KDNA_AB_Log::add(
			array(
				'post_id'     => $post_id,
				'post_title'  => $title,
				'type'        => 'digest',
				'status'      => $status,
				'campaign_id' => $campaign_id,
				'list_id'     => $settings['list_id'],
				'recipients'  => (int) $recipients,
				'mode'        => 'digest',
				'message'     => $message,
			)
		);
	}
}
