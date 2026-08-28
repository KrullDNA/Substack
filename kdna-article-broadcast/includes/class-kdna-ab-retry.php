<?php
/**
 * Cron retry handling and failure surfacing.
 *
 * When a Campaign Monitor call fails, a retryable error is retried by WP Cron at
 * five, twenty and sixty minutes, while a permanent error fails immediately. Every
 * attempt is logged. After the final failure an admin email is sent and a
 * dismissible dashboard notice is shown until the failure is resolved.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Retry
 */
class KDNA_AB_Retry {

	/**
	 * Cron hook that runs a retry.
	 */
	const RETRY_HOOK = 'kdna_ab_retry_send';

	/**
	 * Post meta holding the number of retries scheduled so far.
	 */
	const META_RETRY_COUNT = '_kdna_ab_retry_count';

	/**
	 * Post meta holding the timestamp of the first failure in the current cycle.
	 */
	const META_FIRST_FAIL = '_kdna_ab_first_fail';

	/**
	 * Option holding the current unresolved failures.
	 */
	const FAILURES_OPTION = 'kdna_ab_failures';

	/**
	 * Option holding a sequence counter, bumped on each new failure.
	 */
	const SEQ_OPTION = 'kdna_ab_failures_seq';

	/**
	 * User meta storing the sequence a user last dismissed.
	 */
	const USER_META = 'kdna_ab_failures_dismissed';

	/**
	 * Nonce action for the dismiss request.
	 */
	const DISMISS_NONCE = 'kdna_ab_dismiss_failures';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Retry|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Retry
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
		add_action( self::RETRY_HOOK, array( $this, 'run_retry' ), 10, 1 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_ajax_kdna_ab_dismiss_failures', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * The retry offsets in minutes, measured from the first failure.
	 *
	 * Three retries over an hour: five, twenty and sixty minutes after the
	 * initial failure.
	 *
	 * @return array
	 */
	public static function delays() {
		return array( 5, 20, 60 );
	}

	/**
	 * The maximum number of retries.
	 *
	 * @return int
	 */
	public static function max_retries() {
		return count( self::delays() );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Outcome handling
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Handles a failed Campaign Monitor operation.
	 *
	 * Logs the attempt, records the failure on the post, then either schedules a
	 * retry for a retryable error, or finalises the failure for a permanent error
	 * or once the retries are exhausted.
	 *
	 * @param int      $post_id     Post ID.
	 * @param WP_Error $error       The error.
	 * @param string   $mode        Send mode in use.
	 * @param string   $campaign_id Campaign ID, if one exists.
	 * @return void
	 */
	public function on_failure( $post_id, $error, $mode, $campaign_id = '' ) {
		$post_id      = (int) $post_id;
		$retries_done = (int) get_post_meta( $post_id, self::META_RETRY_COUNT, true );
		$attempt      = $retries_done + 1;
		$message      = $error->get_error_message();

		// Anchor the retry schedule to the first failure of this cycle.
		if ( 0 === $retries_done ) {
			update_post_meta( $post_id, self::META_FIRST_FAIL, time() );
		}

		$log_id = KDNA_AB_Sender::log_attempt( $post_id, 'article', 'failed', $campaign_id, $mode, $message, null, $attempt );

		KDNA_AB_Meta_Box::record_failure( $post_id, $message, time() );

		$retryable = KDNA_AB_API::is_retryable_error( $error );

		if ( $retryable && $retries_done < self::max_retries() ) {
			$delays = self::delays();
			$base   = (int) get_post_meta( $post_id, self::META_FIRST_FAIL, true );
			$base   = $base ? $base : time();

			// Retry at the offset from the first failure, so the three retries
			// land at five, twenty and sixty minutes.
			$scheduled = $base + ( (int) $delays[ $retries_done ] * MINUTE_IN_SECONDS );

			if ( $scheduled <= time() ) {
				$scheduled = time() + MINUTE_IN_SECONDS;
			}

			update_post_meta( $post_id, self::META_RETRY_COUNT, $retries_done + 1 );
			wp_schedule_single_event( $scheduled, self::RETRY_HOOK, array( $post_id ) );

			$this->flag( $post_id, $log_id, $message, true );
			return;
		}

		// Terminal: permanent error, or retries exhausted.
		delete_post_meta( $post_id, self::META_RETRY_COUNT );
		delete_post_meta( $post_id, self::META_FIRST_FAIL );

		$this->flag( $post_id, $log_id, $message, false );
		$this->send_final_email( $post_id, $message, $log_id, $retryable );
	}

	/**
	 * Handles a successful operation, clearing any retry and failure state.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_success( $post_id ) {
		$this->clear_state( $post_id );
	}

	/**
	 * Clears retry counters and any unresolved failure flag for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function clear_state( $post_id ) {
		delete_post_meta( (int) $post_id, self::META_RETRY_COUNT );
		delete_post_meta( (int) $post_id, self::META_FIRST_FAIL );
		$this->resolve( (int) $post_id );
	}

	/**
	 * Runs a scheduled retry.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function run_retry( $post_id ) {
		KDNA_AB_Sender::retry_now( (int) $post_id );
	}

	/**
	 * The current attempt number for a post, one based.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function current_attempt( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::META_RETRY_COUNT, true ) + 1;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Failure flags and dashboard notice
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Records or updates an unresolved failure.
	 *
	 * @param int    $post_id   Post ID.
	 * @param int    $log_id    Related log row ID.
	 * @param string $message   Error message.
	 * @param bool   $scheduled Whether a retry is scheduled.
	 * @return void
	 */
	private function flag( $post_id, $log_id, $message, $scheduled ) {
		$failures = get_option( self::FAILURES_OPTION, array() );

		if ( ! is_array( $failures ) ) {
			$failures = array();
		}

		$failures[ (int) $post_id ] = array(
			'title'     => get_the_title( $post_id ),
			'log_id'    => (int) $log_id,
			'message'   => (string) $message,
			'scheduled' => (bool) $scheduled,
			'time'      => time(),
		);

		update_option( self::FAILURES_OPTION, $failures, false );
		update_option( self::SEQ_OPTION, (int) get_option( self::SEQ_OPTION, 0 ) + 1, false );
	}

	/**
	 * Resolves an unresolved failure for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function resolve( $post_id ) {
		$failures = get_option( self::FAILURES_OPTION, array() );

		if ( ! is_array( $failures ) || ! isset( $failures[ (int) $post_id ] ) ) {
			return;
		}

		unset( $failures[ (int) $post_id ] );
		update_option( self::FAILURES_OPTION, $failures, false );
	}

	/**
	 * Renders the dismissible dashboard notice for unresolved failures.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failures = get_option( self::FAILURES_OPTION, array() );

		if ( empty( $failures ) || ! is_array( $failures ) ) {
			return;
		}

		$seq       = (int) get_option( self::SEQ_OPTION, 0 );
		$dismissed = (int) get_user_meta( get_current_user_id(), self::USER_META, true );

		if ( $dismissed >= $seq ) {
			return;
		}

		$count    = count( $failures );
		$log_url  = admin_url( 'options-general.php?page=' . KDNA_AB_Log::MENU_SLUG . '&kdna_status=failed' );
		$retrying = false;

		foreach ( $failures as $failure ) {
			if ( ! empty( $failure['scheduled'] ) ) {
				$retrying = true;
				break;
			}
		}

		$headline = $retrying
			? _n(
				'%d article broadcast has failed and is being retried automatically.',
				'%d article broadcasts have failed and are being retried automatically.',
				$count,
				'kdna-article-broadcast'
			)
			: _n(
				'%d article broadcast has failed.',
				'%d article broadcasts have failed.',
				$count,
				'kdna-article-broadcast'
			);

		echo '<div id="kdna-ab-failure-notice" class="notice notice-error is-dismissible" data-nonce="' . esc_attr( wp_create_nonce( self::DISMISS_NONCE ) ) . '">';
		echo '<p><strong>' . esc_html__( 'KDNA Article Broadcast', 'kdna-article-broadcast' ) . '</strong> '
			. esc_html( sprintf( $headline, $count ) )
			. ' <a href="' . esc_url( $log_url ) . '">' . esc_html__( 'View the send log', 'kdna-article-broadcast' ) . '</a></p>';
		echo '</div>';

		$this->print_dismiss_script();
	}

	/**
	 * Prints the small script that persists a notice dismissal.
	 *
	 * @return void
	 */
	private function print_dismiss_script() {
		$ajax = admin_url( 'admin-ajax.php' );
		?>
		<script>
		( function () {
			var notice = document.getElementById( 'kdna-ab-failure-notice' );
			if ( ! notice ) { return; }
			notice.addEventListener( 'click', function ( event ) {
				if ( ! event.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var body = new FormData();
				body.append( 'action', 'kdna_ab_dismiss_failures' );
				body.append( 'nonce', notice.getAttribute( 'data-nonce' ) );
				fetch( <?php echo wp_json_encode( $ajax ); ?>, { method: 'POST', credentials: 'same-origin', body: body } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: records that the current user dismissed the notice.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::DISMISS_NONCE ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		update_user_meta( get_current_user_id(), self::USER_META, (int) get_option( self::SEQ_OPTION, 0 ) );

		wp_send_json_success();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Final failure email
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Sends the admin email after a terminal failure.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $message   Error message.
	 * @param int    $log_id    Related log row ID.
	 * @param bool   $exhausted True when retries were exhausted, false for a permanent error.
	 * @return void
	 */
	private function send_final_email( $post_id, $message, $log_id, $exhausted ) {
		$title = get_the_title( $post_id );
		$to    = KDNA_AB_Sender::notify_address();

		/* translators: %s: post title. */
		$subject = sprintf( __( 'Article broadcast failed: %s', 'kdna-article-broadcast' ), $title );

		$lines = array();

		if ( $exhausted ) {
			$lines[] = __( 'An article broadcast failed after three automatic retries and will not be retried again.', 'kdna-article-broadcast' );
		} else {
			$lines[] = __( 'An article broadcast failed with an error that will not be retried automatically.', 'kdna-article-broadcast' );
		}

		$lines[] = '';
		/* translators: %s: post title. */
		$lines[] = sprintf( __( 'Post: %s', 'kdna-article-broadcast' ), $title );
		/* translators: %s: error message. */
		$lines[] = sprintf( __( 'Error: %s', 'kdna-article-broadcast' ), $message );
		$lines[] = '';

		if ( $log_id ) {
			$log_url = admin_url( 'options-general.php?page=' . KDNA_AB_Log::MENU_SLUG . '&action=view&log=' . (int) $log_id );
			/* translators: %s: log entry URL. */
			$lines[] = sprintf( __( 'View the log entry: %s', 'kdna-article-broadcast' ), $log_url );
		}

		/* translators: %s: post edit URL. */
		$lines[] = sprintf( __( 'Edit the post: %s', 'kdna-article-broadcast' ), get_edit_post_link( $post_id, 'raw' ) );
		$lines[] = '';
		$lines[] = __( 'Fix the underlying issue, then press Retry on the log row.', 'kdna-article-broadcast' );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
