<?php
/**
 * Send log, the custom table, its data layer and the admin screen.
 *
 * The log is append heavy, one row per broadcast attempt, so it lives in a
 * custom table rather than a custom post type. The table schema is part of the
 * plugin contract and must stay identical in the future Klaviyo edition, so send
 * history can be migrated across.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Log
 */
class KDNA_AB_Log {

	/**
	 * Schema version, bumped when the table changes.
	 */
	const DB_VERSION = 1;

	/**
	 * Option storing the installed schema version.
	 */
	const DB_VERSION_OPTION = 'kdna_ab_db_version';

	/**
	 * Daily purge cron hook.
	 */
	const PURGE_HOOK = 'kdna_ab_purge_log';

	/**
	 * Admin page slug.
	 */
	const MENU_SLUG = 'kdna-article-broadcast-log';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Log|null
	 */
	private static $instance = null;

	/**
	 * The page hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Log
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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( self::PURGE_HOOK, array( $this, 'run_purge' ) );
	}

	/**
	 * Enqueues the shared admin stylesheet on the log screen for the badges.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'kdna-ab-admin',
			KDNA_AB_URL . 'admin/admin-style.css',
			array(),
			KDNA_AB_VERSION
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Table
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'kdna_ab_send_log';
	}

	/**
	 * Creates or updates the custom table. Called on activation.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_title text NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'article',
			status varchar(20) NOT NULL DEFAULT '',
			campaign_id varchar(100) NOT NULL DEFAULT '',
			list_id varchar(100) NOT NULL DEFAULT '',
			recipients int(10) unsigned NOT NULL DEFAULT 0,
			mode varchar(20) NOT NULL DEFAULT '',
			attempt int(10) unsigned NOT NULL DEFAULT 1,
			message text NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY type (type),
			KEY created_at (created_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Creates the table if the stored schema version is behind.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Vocabulary
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'draft'     => __( 'Draft created', 'kdna-article-broadcast' ),
			'held'      => __( 'Held', 'kdna-article-broadcast' ),
			'queued'    => __( 'Queued', 'kdna-article-broadcast' ),
			'sent'      => __( 'Sent', 'kdna-article-broadcast' ),
			'failed'    => __( 'Failed', 'kdna-article-broadcast' ),
			'cancelled' => __( 'Cancelled', 'kdna-article-broadcast' ),
			'skipped'   => __( 'Skipped', 'kdna-article-broadcast' ),
		);
	}

	/**
	 * Type labels.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'article' => __( 'Article', 'kdna-article-broadcast' ),
			'digest'  => __( 'Digest', 'kdna-article-broadcast' ),
			'test'    => __( 'Test', 'kdna-article-broadcast' ),
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Data layer
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Inserts a log row.
	 *
	 * @param array $args Row data.
	 * @return int Insert ID, or 0 on failure.
	 */
	public static function add( $args ) {
		global $wpdb;

		$defaults = array(
			'post_id'     => 0,
			'post_title'  => '',
			'type'        => 'article',
			'status'      => '',
			'campaign_id' => '',
			'list_id'     => '',
			'recipients'  => 0,
			'mode'        => '',
			'attempt'     => 1,
			'message'     => '',
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		);

		$row = wp_parse_args( $args, $defaults );

		$data = array(
			'post_id'     => (int) $row['post_id'],
			'post_title'  => (string) $row['post_title'],
			'type'        => (string) $row['type'],
			'status'      => (string) $row['status'],
			'campaign_id' => (string) $row['campaign_id'],
			'list_id'     => (string) $row['list_id'],
			'recipients'  => (int) $row['recipients'],
			'mode'        => (string) $row['mode'],
			'attempt'     => max( 1, (int) $row['attempt'] ),
			'message'     => (string) $row['message'],
			'created_at'  => (string) $row['created_at'],
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table_name(),
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetches a single row by ID.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Queries rows with filtering, search, ordering and paging.
	 *
	 * @param array $args Query arguments.
	 * @return array List of associative row arrays.
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$table    = self::table_name();
		$defaults = array(
			'status'   => '',
			'type'     => '',
			'search'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'offset'   => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		list( $where, $params ) = self::build_where( $args );

		$orderby = self::safe_orderby( $args['orderby'] );
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$params[] = (int) $args['per_page'];
		$params[] = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Counts rows matching the filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$table = self::table_name();

		list( $where, $params ) = self::build_where( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} {$where}";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Builds the WHERE clause and parameters for the filters.
	 *
	 * @param array $args Query arguments.
	 * @return array [ where sql, params ].
	 */
	private static function build_where( $args ) {
		$args   = wp_parse_args(
			$args,
			array(
				'status' => '',
				'type'   => '',
				'search' => '',
			)
		);
		$clauses = array();
		$params  = array();

		if ( '' !== $args['status'] && isset( self::statuses()[ $args['status'] ] ) ) {
			$clauses[] = 'status = %s';
			$params[]  = $args['status'];
		}

		if ( '' !== $args['type'] && isset( self::types()[ $args['type'] ] ) ) {
			$clauses[] = 'type = %s';
			$params[]  = $args['type'];
		}

		if ( '' !== $args['search'] ) {
			global $wpdb;
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '( post_title LIKE %s OR campaign_id LIKE %s OR message LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$where = empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $params );
	}

	/**
	 * Validates an orderby column against a safe list.
	 *
	 * @param string $orderby Requested column.
	 * @return string
	 */
	private static function safe_orderby( $orderby ) {
		$allowed = array( 'id', 'created_at', 'post_title', 'status', 'type', 'recipients' );

		return in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
	}

	/**
	 * Deletes rows by ID.
	 *
	 * @param array $ids Row IDs.
	 * @return int Number of rows deleted.
	 */
	public static function delete( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Deletes rows older than a number of months.
	 *
	 * @param int $months Age threshold in months. Zero keeps everything.
	 * @return int Rows deleted.
	 */
	public static function purge( $months ) {
		global $wpdb;

		$months = (int) $months;

		if ( $months < 1 ) {
			return 0;
		}

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $months * MONTH_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Runs the scheduled purge using the configured retention.
	 *
	 * @return void
	 */
	public function run_purge() {
		$settings = kdna_ab_get_settings();
		self::purge( (int) $settings['log_retention_months'] );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Admin screen
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Adds the log page under the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook_suffix = add_submenu_page(
			'options-general.php',
			__( 'Article Broadcast Log', 'kdna-article-broadcast' ),
			__( 'Article Broadcast Log', 'kdna-article-broadcast' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the log admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kdna-article-broadcast' ) );
		}

		$this->handle_actions();
		$this->handle_retention_save();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new KDNA_AB_Log_Table();
		$table->prepare_items();

		$settings  = kdna_ab_get_settings();
		$retention = (int) $settings['log_retention_months'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Article Broadcast Log', 'kdna-article-broadcast' ) . '</h1>';

		$this->render_action_notice();
		$this->render_view_box();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		$table->search_box( esc_html__( 'Search log', 'kdna-article-broadcast' ), 'kdna-ab-log' );
		$table->display();
		echo '</form>';

		// Retention control.
		echo '<form method="post" style="margin-top:16px;max-width:520px;">';
		wp_nonce_field( 'kdna_ab_log_retention', 'kdna_ab_log_retention_nonce' );
		echo '<h2>' . esc_html__( 'Retention', 'kdna-article-broadcast' ) . '</h2>';
		echo '<p><label for="kdna-ab-retention">' . esc_html__( 'Delete log rows older than this many months. Zero keeps everything.', 'kdna-article-broadcast' ) . '</label></p>';
		echo '<input type="number" min="0" step="1" id="kdna-ab-retention" name="kdna_ab_retention" class="small-text" value="' . esc_attr( $retention ) . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Save retention', 'kdna-article-broadcast' ) . '</button>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Shows a notice after a row action.
	 *
	 * @return void
	 */
	private function render_action_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['kdna_ab_msg'] ) ? sanitize_key( wp_unslash( $_GET['kdna_ab_msg'] ) ) : '';

		if ( '' === $msg ) {
			return;
		}

		$map = array(
			'retry_ok'     => array( 'success', __( 'Retry succeeded.', 'kdna-article-broadcast' ) ),
			'retry_failed' => array( 'error', __( 'Retry failed. See the latest log entry for the reason.', 'kdna-article-broadcast' ) ),
			'cancelled'    => array( 'success', __( 'The held send was cancelled. The draft is still in Campaign Monitor.', 'kdna-article-broadcast' ) ),
			'deleted'      => array( 'success', __( 'Log entry deleted.', 'kdna-article-broadcast' ) ),
			'digest_sent'  => array( 'success', __( 'The weekly digest was approved and sent to subscribers.', 'kdna-article-broadcast' ) ),
			'digest_failed' => array( 'error', __( 'The digest could not be sent. It is still pending, so you can try approving it again once the issue is fixed.', 'kdna-article-broadcast' ) ),
		);

		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	/**
	 * Shows the full stored message for a single row when requested.
	 *
	 * @return void
	 */
	private function render_view_box() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['action'] ) || 'view' !== $_GET['action'] || empty( $_GET['log'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id  = absint( $_GET['log'] );
		$row = self::get( $id );

		if ( ! $row ) {
			return;
		}

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Log entry', 'kdna-article-broadcast' ) . ' #' . esc_html( $id ) . '</strong></p>';
		echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #dcdcde;padding:10px;border-radius:4px;">'
			. esc_html( '' !== $row['message'] ? $row['message'] : __( 'No message recorded.', 'kdna-article-broadcast' ) )
			. '</pre></div>';
	}

	/**
	 * Handles the retry, cancel and delete row actions.
	 *
	 * @return void
	 */
	private function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( ! in_array( $action, array( 'retry', 'cancel', 'deleterow' ), true ) ) {
			return;
		}

		$id = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $id ) {
			return;
		}

		check_admin_referer( 'kdna_ab_log_row_' . $id );

		$row = self::get( $id );

		if ( ! $row ) {
			return;
		}

		$message = '';

		if ( 'retry' === $action ) {
			$result  = KDNA_AB_Sender::retry_from_log( $row );
			$message = is_wp_error( $result ) ? 'retry_failed' : 'retry_ok';
		} elseif ( 'cancel' === $action ) {
			KDNA_AB_Sender::cancel_hold( (int) $row['post_id'] );
			self::add(
				array(
					'post_id'    => (int) $row['post_id'],
					'post_title' => $row['post_title'],
					'type'       => $row['type'],
					'status'     => 'cancelled',
					'list_id'    => $row['list_id'],
					'mode'       => $row['mode'],
					'message'    => __( 'Hold cancelled from the send log.', 'kdna-article-broadcast' ),
				)
			);
			$message = 'cancelled';
		} elseif ( 'deleterow' === $action ) {
			self::delete( array( $id ) );
			$message = 'deleted';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'kdna_ab_msg' => $message ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Saves the retention setting.
	 *
	 * @return void
	 */
	private function handle_retention_save() {
		if ( ! isset( $_POST['kdna_ab_log_retention_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kdna_ab_log_retention_nonce'] ) ), 'kdna_ab_log_retention' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$months   = isset( $_POST['kdna_ab_retention'] ) ? absint( $_POST['kdna_ab_retention'] ) : 0;
		$settings = kdna_ab_get_settings();

		$settings['log_retention_months'] = $months;
		update_option( KDNA_AB_OPTION, $settings );
	}
}
