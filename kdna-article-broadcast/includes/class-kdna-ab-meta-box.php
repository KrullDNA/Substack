<?php
/**
 * Post editor panel, the Article Broadcast meta box.
 *
 * Gives per post control over broadcasting. It appears on the Posts post type
 * only, and works in both editors: a document setting panel in Gutenberg, and a
 * classic side meta box in the Classic editor.
 *
 * The post meta keys defined here are part of the plugin contract and must stay
 * identical in the future Klaviyo edition, so send history and per post settings
 * carry across a swap.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Meta_Box
 */
class KDNA_AB_Meta_Box {

	/*
	 * Post meta keys. Underscore prefixed so they are hidden from the default
	 * custom fields UI, but still exposed to the block editor through the REST
	 * registration below. These keys must not change, the Klaviyo edition shares
	 * them.
	 */
	const META_SEND           = '_kdna_ab_send';
	const META_SUBJECT        = '_kdna_ab_subject';
	const META_SUBJECT_AUTO   = '_kdna_ab_subject_auto';
	const META_PREVIEW        = '_kdna_ab_preview_text';
	const META_TEASER         = '_kdna_ab_teaser_override';
	const META_CTA_OVERRIDE   = '_kdna_ab_cta_override';
	const META_STATUS         = '_kdna_ab_status';
	const META_STATUS_MESSAGE = '_kdna_ab_status_message';
	const META_STATUS_TIME    = '_kdna_ab_status_time';
	const META_CAMPAIGN_ID    = '_kdna_ab_campaign_id';
	const META_MODE           = '_kdna_ab_sent_mode';

	/**
	 * Nonce action for the classic form.
	 */
	const NONCE_ACTION = 'kdna_ab_save_meta';

	/**
	 * Nonce action for the unlock AJAX request.
	 */
	const UNLOCK_NONCE = 'kdna_ab_unlock';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Meta_Box|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Meta_Box
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
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		add_action( 'wp_ajax_kdna_ab_unlock_resend', array( $this, 'ajax_unlock' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Meta registration
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the post meta so the block editor can read and write it.
	 *
	 * @return void
	 */
	public function register_meta() {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		$string_keys = array(
			self::META_SEND         => 'sanitize_text_field',
			self::META_SUBJECT      => 'sanitize_text_field',
			self::META_SUBJECT_AUTO => 'sanitize_text_field',
			self::META_CTA_OVERRIDE => 'sanitize_text_field',
			self::META_STATUS       => 'sanitize_text_field',
			self::META_STATUS_TIME  => 'sanitize_text_field',
			self::META_CAMPAIGN_ID  => 'sanitize_text_field',
			self::META_MODE         => 'sanitize_text_field',
		);

		foreach ( $string_keys as $key => $sanitize ) {
			register_post_meta(
				'post',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $auth,
					'sanitize_callback' => $sanitize,
				)
			);
		}

		$textarea_keys = array( self::META_PREVIEW, self::META_TEASER, self::META_STATUS_MESSAGE );

		foreach ( $textarea_keys as $key ) {
			register_post_meta(
				'post',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $auth,
					'sanitize_callback' => 'sanitize_textarea_field',
				)
			);
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Status helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Human labels for each status state.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'not_sent' => __( 'Not sent', 'kdna-article-broadcast' ),
			'queued'   => __( 'Queued', 'kdna-article-broadcast' ),
			'held'     => __( 'Held, waiting for the hold window', 'kdna-article-broadcast' ),
			'draft'    => __( 'Draft created in Campaign Monitor', 'kdna-article-broadcast' ),
			'sent'     => __( 'Sent', 'kdna-article-broadcast' ),
			'failed'   => __( 'Failed', 'kdna-article-broadcast' ),
		);
	}

	/**
	 * Builds the status display for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array With state, label, detail, locked and campaign_id.
	 */
	public static function get_status_display( $post_id ) {
		$status      = (string) get_post_meta( $post_id, self::META_STATUS, true );
		$state       = ( '' === $status ) ? 'not_sent' : $status;
		$labels      = self::status_labels();
		$label       = isset( $labels[ $state ] ) ? $labels[ $state ] : $labels['not_sent'];
		$detail      = '';
		$time        = (int) get_post_meta( $post_id, self::META_STATUS_TIME, true );
		$campaign_id = (string) get_post_meta( $post_id, self::META_CAMPAIGN_ID, true );
		$format      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		if ( 'sent' === $state ) {
			if ( $time ) {
				$detail = sprintf(
					/* translators: %s: formatted date and time of the send. */
					__( 'Sent on %s', 'kdna-article-broadcast' ),
					wp_date( $format, $time )
				);
			}
		} elseif ( 'draft' === $state ) {
			if ( $time ) {
				$detail = sprintf(
					/* translators: %s: formatted date and time the draft was created. */
					__( 'Draft created on %s. Open it in Campaign Monitor to send.', 'kdna-article-broadcast' ),
					wp_date( $format, $time )
				);
			}
		} elseif ( 'failed' === $state ) {
			$detail = (string) get_post_meta( $post_id, self::META_STATUS_MESSAGE, true );
		} elseif ( 'held' === $state && $time ) {
			$detail = sprintf(
				/* translators: %s: formatted date and time the hold window ends. */
				__( 'Sends at %s unless cancelled', 'kdna-article-broadcast' ),
				wp_date( $format, $time )
			);
		}

		return array(
			'state'       => $state,
			'label'       => $label,
			'detail'      => $detail,
			// A campaign exists, so the post is locked against creating a second one.
			'locked'      => ( '' !== $campaign_id ),
			'campaign_id' => $campaign_id,
		);
	}

	/**
	 * Returns the effective subject line for a post.
	 *
	 * The custom subject if the field has been edited, otherwise the post title.
	 * Later stages use this when assembling the email.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function effective_subject( $post_id ) {
		$auto    = (string) get_post_meta( $post_id, self::META_SUBJECT_AUTO, true );
		$subject = (string) get_post_meta( $post_id, self::META_SUBJECT, true );

		if ( '0' === $auto && '' !== $subject ) {
			return $subject;
		}

		return get_the_title( $post_id );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Classic meta box
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Adds the classic side meta box, for the Classic editor only.
	 *
	 * In the block editor the same fields are provided by a document setting
	 * panel, so the classic box is not registered there to avoid a duplicate.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function add_meta_box( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		add_meta_box(
			'kdna-ab-metabox',
			__( 'Article Broadcast', 'kdna-article-broadcast' ),
			array( $this, 'render' ),
			'post',
			'side',
			'high'
		);
	}

	/**
	 * Renders the classic meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'kdna_ab_meta_nonce' );

		$status  = self::get_status_display( $post->ID );
		$send    = ( '1' === (string) get_post_meta( $post->ID, self::META_SEND, true ) );
		$auto    = ( '0' !== (string) get_post_meta( $post->ID, self::META_SUBJECT_AUTO, true ) );
		$subject = (string) get_post_meta( $post->ID, self::META_SUBJECT, true );
		$preview = (string) get_post_meta( $post->ID, self::META_PREVIEW, true );
		$teaser  = (string) get_post_meta( $post->ID, self::META_TEASER, true );
		$cta     = (string) get_post_meta( $post->ID, self::META_CTA_OVERRIDE, true );

		// When auto is on, show the title as the live subject value.
		$subject_display = ( $auto || '' === $subject ) ? get_the_title( $post->ID ) : $subject;
		?>
		<div class="kdna-ab-metabox" data-locked="<?php echo $status['locked'] ? '1' : '0'; ?>">

			<div class="kdna-ab-mb-status kdna-ab-mb-status--<?php echo esc_attr( $status['state'] ); ?>">
				<span class="kdna-ab-mb-status__label"><?php echo esc_html( $status['label'] ); ?></span>
				<?php if ( '' !== $status['detail'] ) : ?>
					<span class="kdna-ab-mb-status__detail"><?php echo esc_html( $status['detail'] ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $status['locked'] ) : ?>

				<p class="kdna-ab-mb-note">
					<?php esc_html_e( 'This post has been broadcast and is locked to prevent a duplicate send. Unlock it only if you genuinely need to resend.', 'kdna-article-broadcast' ); ?>
				</p>

				<dl class="kdna-ab-mb-readonly">
					<dt><?php esc_html_e( 'Subject', 'kdna-article-broadcast' ); ?></dt>
					<dd><?php echo esc_html( self::effective_subject( $post->ID ) ); ?></dd>
					<?php if ( '' !== $status['campaign_id'] ) : ?>
						<dt><?php esc_html_e( 'Campaign ID', 'kdna-article-broadcast' ); ?></dt>
						<dd><code><?php echo esc_html( $status['campaign_id'] ); ?></code></dd>
					<?php endif; ?>
				</dl>

				<button
					type="button"
					class="button button-secondary kdna-ab-mb-unlock"
					data-post="<?php echo esc_attr( $post->ID ); ?>"
				>
					<?php esc_html_e( 'Unlock and resend', 'kdna-article-broadcast' ); ?>
				</button>

			<?php else : ?>

				<input type="hidden" name="kdna_ab_fields_present" value="1" />

				<p class="kdna-ab-mb-row kdna-ab-mb-row--check">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::META_SEND ); ?>" value="1" <?php checked( $send ); ?> />
						<?php esc_html_e( 'Send to subscribers on publish', 'kdna-article-broadcast' ); ?>
					</label>
				</p>

				<p class="kdna-ab-mb-row">
					<label class="kdna-ab-mb-label" for="kdna-ab-mb-subject"><?php esc_html_e( 'Email subject', 'kdna-article-broadcast' ); ?></label>
					<input
						type="text"
						id="kdna-ab-mb-subject"
						class="widefat kdna-ab-mb-subject"
						name="<?php echo esc_attr( self::META_SUBJECT ); ?>"
						value="<?php echo esc_attr( $subject_display ); ?>"
					/>
					<input type="hidden" class="kdna-ab-mb-subject-auto" name="<?php echo esc_attr( self::META_SUBJECT_AUTO ); ?>" value="<?php echo $auto ? '1' : '0'; ?>" />
					<span class="kdna-ab-mb-hint"><?php esc_html_e( 'Pre-filled with the post title. Editing it stops the automatic update.', 'kdna-article-broadcast' ); ?></span>
				</p>

				<p class="kdna-ab-mb-row">
					<label class="kdna-ab-mb-label" for="kdna-ab-mb-preview"><?php esc_html_e( 'Preview text', 'kdna-article-broadcast' ); ?></label>
					<input
						type="text"
						id="kdna-ab-mb-preview"
						class="widefat"
						name="<?php echo esc_attr( self::META_PREVIEW ); ?>"
						value="<?php echo esc_attr( $preview ); ?>"
					/>
					<span class="kdna-ab-mb-hint"><?php esc_html_e( 'Optional. Falls back to the automatic teaser if left blank.', 'kdna-article-broadcast' ); ?></span>
				</p>

				<p class="kdna-ab-mb-row">
					<label class="kdna-ab-mb-label" for="kdna-ab-mb-teaser"><?php esc_html_e( 'Teaser override', 'kdna-article-broadcast' ); ?></label>
					<textarea
						id="kdna-ab-mb-teaser"
						class="widefat"
						rows="3"
						name="<?php echo esc_attr( self::META_TEASER ); ?>"
					><?php echo esc_textarea( $teaser ); ?></textarea>
					<span class="kdna-ab-mb-hint"><?php esc_html_e( 'Optional. Leave blank to use the automatically generated teaser.', 'kdna-article-broadcast' ); ?></span>
				</p>

				<p class="kdna-ab-mb-row">
					<label class="kdna-ab-mb-label" for="kdna-ab-mb-cta"><?php esc_html_e( 'CTA button label override', 'kdna-article-broadcast' ); ?></label>
					<input
						type="text"
						id="kdna-ab-mb-cta"
						class="widefat"
						name="<?php echo esc_attr( self::META_CTA_OVERRIDE ); ?>"
						value="<?php echo esc_attr( $cta ); ?>"
					/>
					<span class="kdna-ab-mb-hint"><?php esc_html_e( 'Optional. Leave blank to use the global CTA label from the settings page.', 'kdna-article-broadcast' ); ?></span>
				</p>

			<?php endif; ?>

		</div>
		<?php
	}

	/*
	 * -----------------------------------------------------------------------
	 * Save
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Saves the classic meta box fields.
	 *
	 * The block editor writes meta through the REST API, so this handler only
	 * runs for the Classic editor, gated on its nonce. When the post is locked
	 * the editable fields are not rendered, so their absence must not clear the
	 * stored values, which is why every write is guarded on the fields present
	 * marker.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_post( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['kdna_ab_meta_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['kdna_ab_meta_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// Only process fields when the editable form was actually rendered.
		if ( ! isset( $_POST['kdna_ab_fields_present'] ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_SEND, isset( $_POST[ self::META_SEND ] ) ? '1' : '' );

		$auto = ( isset( $_POST[ self::META_SUBJECT_AUTO ] ) && '0' === $_POST[ self::META_SUBJECT_AUTO ] ) ? '0' : '1';
		update_post_meta( $post_id, self::META_SUBJECT_AUTO, $auto );

		// While auto is on the title is the subject, so store no override.
		if ( '0' === $auto && isset( $_POST[ self::META_SUBJECT ] ) ) {
			update_post_meta( $post_id, self::META_SUBJECT, sanitize_text_field( wp_unslash( $_POST[ self::META_SUBJECT ] ) ) );
		} else {
			update_post_meta( $post_id, self::META_SUBJECT, '' );
		}

		$preview = isset( $_POST[ self::META_PREVIEW ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_PREVIEW ] ) ) : '';
		update_post_meta( $post_id, self::META_PREVIEW, $preview );

		$teaser = isset( $_POST[ self::META_TEASER ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_TEASER ] ) ) : '';
		update_post_meta( $post_id, self::META_TEASER, $teaser );

		$cta = isset( $_POST[ self::META_CTA_OVERRIDE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_CTA_OVERRIDE ] ) ) : '';
		update_post_meta( $post_id, self::META_CTA_OVERRIDE, $cta );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Unlock and resend, AJAX for the Classic editor
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Clears the send lock so a post can be broadcast again.
	 *
	 * Used by the Classic editor. The block editor clears the same meta through
	 * its own store and a normal save, so it does not use this endpoint.
	 *
	 * @return void
	 */
	public function ajax_unlock() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::UNLOCK_NONCE ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload and try again.', 'kdna-article-broadcast' ) ), 403 );
		}

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to unlock this post.', 'kdna-article-broadcast' ) ), 403 );
		}

		self::clear_send_lock( $post_id );

		// Keep the post flagged so a resend goes out on the next publish or update.
		update_post_meta( $post_id, self::META_SEND, '1' );

		wp_send_json_success(
			array(
				'message' => __( 'Unlocked. This post can be broadcast again.', 'kdna-article-broadcast' ),
				'status'  => self::get_status_display( $post_id ),
			)
		);
	}

	/**
	 * Clears every send lock and status meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function clear_send_lock( $post_id ) {
		delete_post_meta( $post_id, self::META_STATUS );
		delete_post_meta( $post_id, self::META_STATUS_MESSAGE );
		delete_post_meta( $post_id, self::META_STATUS_TIME );
		delete_post_meta( $post_id, self::META_CAMPAIGN_ID );
		delete_post_meta( $post_id, self::META_MODE );
	}

	/**
	 * Records a created or sent campaign against a post.
	 *
	 * This writes the send lock: the campaign ID, the status, the mode used and a
	 * timestamp. Any previous failure message is cleared.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $campaign_id Campaign Monitor campaign ID.
	 * @param string $status      One of draft, held or sent.
	 * @param string $mode        Send mode used.
	 * @param int    $time        Timestamp for the status.
	 * @return void
	 */
	public static function record_campaign( $post_id, $campaign_id, $status, $mode, $time ) {
		update_post_meta( $post_id, self::META_CAMPAIGN_ID, sanitize_text_field( $campaign_id ) );
		update_post_meta( $post_id, self::META_STATUS, sanitize_text_field( $status ) );
		update_post_meta( $post_id, self::META_MODE, sanitize_text_field( $mode ) );
		update_post_meta( $post_id, self::META_STATUS_TIME, (int) $time );
		delete_post_meta( $post_id, self::META_STATUS_MESSAGE );
	}

	/**
	 * Records a simple status change without touching the campaign lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status value.
	 * @param int    $time    Timestamp for the status.
	 * @return void
	 */
	public static function record_status( $post_id, $status, $time = 0 ) {
		update_post_meta( $post_id, self::META_STATUS, sanitize_text_field( $status ) );
		update_post_meta( $post_id, self::META_STATUS_TIME, (int) $time );
	}

	/**
	 * Records a failure against a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Failure message.
	 * @param int    $time    Timestamp.
	 * @return void
	 */
	public static function record_failure( $post_id, $message, $time ) {
		update_post_meta( $post_id, self::META_STATUS, 'failed' );
		update_post_meta( $post_id, self::META_STATUS_MESSAGE, sanitize_text_field( $message ) );
		update_post_meta( $post_id, self::META_STATUS_TIME, (int) $time );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Assets
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Enqueues the Classic editor script and shared meta box styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_classic( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		// The block editor uses its own panel and styles.
		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_style(
			'kdna-ab-meta-box',
			KDNA_AB_URL . 'admin/meta-box.css',
			array(),
			KDNA_AB_VERSION
		);

		wp_enqueue_script(
			'kdna-ab-meta-box-classic',
			KDNA_AB_URL . 'admin/meta-box-classic.js',
			array(),
			KDNA_AB_VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script( 'kdna-ab-meta-box-classic', 'kdnaAbMeta', $this->script_data() );
	}

	/**
	 * Enqueues the block editor panel script and shared meta box styles.
	 *
	 * @return void
	 */
	public function enqueue_block_editor() {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'kdna-ab-meta-box',
			KDNA_AB_URL . 'admin/meta-box.css',
			array(),
			KDNA_AB_VERSION
		);

		wp_enqueue_script(
			'kdna-ab-meta-box-gutenberg',
			KDNA_AB_URL . 'admin/meta-box-gutenberg.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose', 'wp-i18n', 'wp-date' ),
			KDNA_AB_VERSION,
			true
		);

		wp_localize_script( 'kdna-ab-meta-box-gutenberg', 'kdnaAbMeta', $this->script_data() );
	}

	/**
	 * Shared data handed to both editor scripts.
	 *
	 * @return array
	 */
	private function script_data() {
		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'unlockNonce' => wp_create_nonce( self::UNLOCK_NONCE ),
			'keys'        => array(
				'send'          => self::META_SEND,
				'subject'       => self::META_SUBJECT,
				'subjectAuto'   => self::META_SUBJECT_AUTO,
				'preview'       => self::META_PREVIEW,
				'teaser'        => self::META_TEASER,
				'ctaOverride'   => self::META_CTA_OVERRIDE,
				'status'        => self::META_STATUS,
				'statusMessage' => self::META_STATUS_MESSAGE,
				'statusTime'    => self::META_STATUS_TIME,
				'campaignId'    => self::META_CAMPAIGN_ID,
				'mode'          => self::META_MODE,
			),
			'labels'      => self::status_labels(),
			'dateFormat'  => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			'i18n'        => array(
				'panelTitle'    => __( 'Article Broadcast', 'kdna-article-broadcast' ),
				'sendLabel'     => __( 'Send to subscribers on publish', 'kdna-article-broadcast' ),
				'subjectLabel'  => __( 'Email subject', 'kdna-article-broadcast' ),
				'subjectHelp'   => __( 'Pre-filled with the post title. Editing it stops the automatic update.', 'kdna-article-broadcast' ),
				'previewLabel'  => __( 'Preview text', 'kdna-article-broadcast' ),
				'previewHelp'   => __( 'Optional. Falls back to the automatic teaser if left blank.', 'kdna-article-broadcast' ),
				'teaserLabel'   => __( 'Teaser override', 'kdna-article-broadcast' ),
				'teaserHelp'    => __( 'Optional. Leave blank to use the automatically generated teaser.', 'kdna-article-broadcast' ),
				'ctaLabel'      => __( 'CTA button label override', 'kdna-article-broadcast' ),
				'ctaHelp'       => __( 'Optional. Leave blank to use the global CTA label from the settings page.', 'kdna-article-broadcast' ),
				'statusHeading' => __( 'Broadcast status', 'kdna-article-broadcast' ),
				'sentPrefix'    => __( 'Sent on', 'kdna-article-broadcast' ),
				'draftPrefix'   => __( 'Draft created on', 'kdna-article-broadcast' ),
				'sendsPrefix'   => __( 'Sends at', 'kdna-article-broadcast' ),
				'sendsSuffix'   => __( 'unless cancelled', 'kdna-article-broadcast' ),
				'campaignLabel' => __( 'Campaign ID', 'kdna-article-broadcast' ),
				'lockedNote'    => __( 'This post has been broadcast and is locked to prevent a duplicate send. Unlock it only if you genuinely need to resend.', 'kdna-article-broadcast' ),
				'unlockButton'  => __( 'Unlock and resend', 'kdna-article-broadcast' ),
				'confirmUnlock' => __( 'Unlock this post so it can be broadcast again? Only do this if you genuinely need to resend.', 'kdna-article-broadcast' ),
				'unlockError'   => __( 'The post could not be unlocked. Please try again.', 'kdna-article-broadcast' ),
			),
		);
	}
}
