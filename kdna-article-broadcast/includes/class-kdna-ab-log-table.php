<?php
/**
 * Send log list screen.
 *
 * A WP_List_Table for the send log, with filtering by status and type, search,
 * pagination, bulk delete and the per row actions.
 *
 * This class extends WP_List_Table, which is only available on admin screens, so
 * the parent file is required before this class is loaded, in KDNA_AB_Log.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Log_Table
 */
class KDNA_AB_Log_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'kdna_ab_log_row',
				'plural'   => 'kdna_ab_log_rows',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The base URL for the log page.
	 *
	 * @return string
	 */
	private function base_url() {
		return admin_url( 'options-general.php?page=' . KDNA_AB_Log::MENU_SLUG );
	}

	/**
	 * Defines the columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'created_at'  => __( 'Date', 'kdna-article-broadcast' ),
			'post_title'  => __( 'Post', 'kdna-article-broadcast' ),
			'type'        => __( 'Type', 'kdna-article-broadcast' ),
			'status'      => __( 'Status', 'kdna-article-broadcast' ),
			'recipients'  => __( 'Recipients', 'kdna-article-broadcast' ),
			'mode'        => __( 'Mode', 'kdna-article-broadcast' ),
			'campaign_id' => __( 'Campaign', 'kdna-article-broadcast' ),
		);
	}

	/**
	 * Defines the sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'post_title' => array( 'post_title', false ),
			'status'     => array( 'status', false ),
			'type'       => array( 'type', false ),
			'recipients' => array( 'recipients', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'kdna-article-broadcast' ) );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="log[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Default column render, escaped.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'type':
				$types = KDNA_AB_Log::types();
				return isset( $types[ $item['type'] ] ) ? esc_html( $types[ $item['type'] ] ) : esc_html( $item['type'] );

			case 'recipients':
				return (int) $item['recipients'] > 0 ? esc_html( number_format_i18n( (int) $item['recipients'] ) ) : '&mdash;';

			case 'mode':
				return '' !== $item['mode'] ? esc_html( $item['mode'] ) : '&mdash;';

			case 'campaign_id':
				return '' !== $item['campaign_id'] ? '<code>' . esc_html( $item['campaign_id'] ) . '</code>' : '&mdash;';

			default:
				return esc_html( isset( $item[ $column_name ] ) ? $item[ $column_name ] : '' );
		}
	}

	/**
	 * Date column, converted to the site timezone.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$gmt = $item['created_at'];

		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return '&mdash;';
		}

		$timestamp = strtotime( $gmt . ' UTC' );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return esc_html( wp_date( $format, $timestamp ) );
	}

	/**
	 * Status column with a coloured badge.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		$statuses = KDNA_AB_Log::statuses();
		$label    = isset( $statuses[ $item['status'] ] ) ? $statuses[ $item['status'] ] : $item['status'];

		return '<span class="kdna-ab-log-badge kdna-ab-log-badge--' . esc_attr( $item['status'] ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Post column with the row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_post_title( $item ) {
		$title = '' !== $item['post_title'] ? $item['post_title'] : __( '(no title)', 'kdna-article-broadcast' );
		$id    = (int) $item['id'];

		$out = '<strong>' . esc_html( $title ) . '</strong>';

		if ( (int) $item['post_id'] > 0 ) {
			$edit = get_edit_post_link( (int) $item['post_id'], 'raw' );
			if ( $edit ) {
				$out = '<strong><a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a></strong>';
			}
		}

		$actions = array();

		if ( '' !== $item['campaign_id'] ) {
			$actions['view_cm'] = '<a href="' . esc_url( KDNA_AB_Sender::campaign_app_url( $item['campaign_id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View in Campaign Monitor', 'kdna-article-broadcast' ) . '</a>';
		}

		if ( 'failed' === $item['status'] ) {
			$actions['retry'] = '<a href="' . esc_url( $this->row_action_url( 'retry', $id ) ) . '">' . esc_html__( 'Retry', 'kdna-article-broadcast' ) . '</a>';
		}

		if ( 'held' === $item['status'] ) {
			$actions['cancel'] = '<a href="' . esc_url( $this->row_action_url( 'cancel', $id ) ) . '">' . esc_html__( 'Cancel', 'kdna-article-broadcast' ) . '</a>';
		}

		if ( 'failed' === $item['status'] && '' !== $item['message'] ) {
			$view = add_query_arg(
				array(
					'page'   => KDNA_AB_Log::MENU_SLUG,
					'action' => 'view',
					'log'    => $id,
				),
				admin_url( 'options-general.php' )
			);
			$actions['view_response'] = '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View response', 'kdna-article-broadcast' ) . '</a>';
		}

		$actions['deleterow'] = '<a href="' . esc_url( $this->row_action_url( 'deleterow', $id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this log entry?', 'kdna-article-broadcast' ) ) . '\');">' . esc_html__( 'Delete', 'kdna-article-broadcast' ) . '</a>';

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Builds a nonced action URL for a row.
	 *
	 * @param string $action Action key.
	 * @param int    $id     Row ID.
	 * @return string
	 */
	private function row_action_url( $action, $id ) {
		$url = add_query_arg(
			array(
				'page'   => KDNA_AB_Log::MENU_SLUG,
				'action' => $action,
				'log'    => $id,
			),
			admin_url( 'options-general.php' )
		);

		return wp_nonce_url( $url, 'kdna_ab_log_row_' . $id );
	}

	/**
	 * Renders the status and type filters above the table.
	 *
	 * @param string $which Position, top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = isset( $_REQUEST['kdna_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['kdna_status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_type = isset( $_REQUEST['kdna_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['kdna_type'] ) ) : '';

		echo '<div class="alignleft actions">';

		echo '<select name="kdna_status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'kdna-article-broadcast' ) . '</option>';
		foreach ( KDNA_AB_Log::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';

		echo '<select name="kdna_type">';
		echo '<option value="">' . esc_html__( 'All types', 'kdna-article-broadcast' ) . '</option>';
		foreach ( KDNA_AB_Log::types() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current_type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'kdna-article-broadcast' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Message shown when the log is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No broadcasts logged yet.', 'kdna-article-broadcast' );
	}

	/**
	 * Processes the bulk delete action.
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids = isset( $_REQUEST['log'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['log'] ) ) : array();

		if ( ! empty( $ids ) ) {
			KDNA_AB_Log::delete( $ids );
		}
	}

	/**
	 * Prepares the items, applying filters, search, ordering and paging.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->process_bulk_action();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_REQUEST['kdna_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['kdna_status'] ) ) : '';
		$type    = isset( $_REQUEST['kdna_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['kdna_type'] ) ) : '';
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'status'   => $status,
			'type'     => $type,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => ( $current_page - 1 ) * $per_page,
		);

		$this->items = KDNA_AB_Log::query( $args );
		$total       = KDNA_AB_Log::count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}
}
