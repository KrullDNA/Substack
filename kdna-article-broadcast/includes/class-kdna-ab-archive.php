<?php
/**
 * Newsletter archive data provider.
 *
 * Supplies the past broadcasts for the archive widget, from either the Campaign
 * Monitor API, cached in a transient, or the local send log. It also renders the
 * item markup, shared between the initial render and the Load more AJAX handler.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Archive
 */
class KDNA_AB_Archive {

	/**
	 * Transient key prefix for cached API campaigns.
	 */
	const CACHE_PREFIX = 'kdna_ab_cache_archive_';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Archive|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Archive
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers the Load more AJAX handlers.
	 */
	private function __construct() {
		add_action( 'wp_ajax_kdna_ab_archive_more', array( $this, 'ajax_more' ) );
		add_action( 'wp_ajax_nopriv_kdna_ab_archive_more', array( $this, 'ajax_more' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Data
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the archive items for a source.
	 *
	 * Each item is an array with subject, date (timestamp) and url.
	 *
	 * @param string $source api or local.
	 * @return array
	 */
	public static function get_items( $source ) {
		return ( 'local' === $source ) ? self::local_items() : self::api_items();
	}

	/**
	 * Returns sent campaigns from the Campaign Monitor API, cached for an hour.
	 *
	 * @return array
	 */
	private static function api_items() {
		$settings = kdna_ab_get_settings();
		$client   = (string) $settings['client_id'];

		if ( '' === $client ) {
			return array();
		}

		$key    = self::CACHE_PREFIX . md5( $client );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = kdna_ab_api()->get_sent_campaigns( $client );
		$items    = array();

		if ( ! is_wp_error( $response ) ) {
			foreach ( (array) $response as $campaign ) {
				if ( ! is_array( $campaign ) ) {
					continue;
				}

				$subject = isset( $campaign['Subject'] ) && '' !== $campaign['Subject']
					? $campaign['Subject']
					: ( isset( $campaign['Name'] ) ? $campaign['Name'] : '' );

				$items[] = array(
					'subject' => sanitize_text_field( $subject ),
					'date'    => isset( $campaign['SentDate'] ) ? (int) strtotime( $campaign['SentDate'] ) : 0,
					'url'     => isset( $campaign['WebVersionURL'] ) ? esc_url_raw( $campaign['WebVersionURL'] ) : '',
				);
			}
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['date'] - $a['date'];
			}
		);

		set_transient( $key, $items, HOUR_IN_SECONDS );

		return $items;
	}

	/**
	 * Returns sent broadcasts from the local send log, most recent first.
	 *
	 * @return array
	 */
	private static function local_items() {
		if ( ! class_exists( 'KDNA_AB_Log' ) ) {
			return array();
		}

		$rows = KDNA_AB_Log::query(
			array(
				'status'   => 'sent',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 100,
				'offset'   => 0,
			)
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$url     = ( $post_id > 0 ) ? (string) get_permalink( $post_id ) : '';

			$items[] = array(
				'subject' => sanitize_text_field( $row['post_title'] ),
				'date'    => isset( $row['created_at'] ) ? (int) strtotime( $row['created_at'] . ' UTC' ) : 0,
				'url'     => $url ? esc_url_raw( $url ) : '',
			);
		}

		return $items;
	}

	/**
	 * Clears the cached API campaigns for the current client.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		$settings = kdna_ab_get_settings();
		$client   = (string) $settings['client_id'];

		if ( '' !== $client ) {
			delete_transient( self::CACHE_PREFIX . md5( $client ) );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Rendering
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders a set of items to HTML.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	public static function render_items( $items ) {
		$out = '';

		foreach ( $items as $item ) {
			$out .= self::render_item( $item );
		}

		return $out;
	}

	/**
	 * Renders a single item to HTML.
	 *
	 * @param array $item Item with subject, date and url.
	 * @return string
	 */
	public static function render_item( $item ) {
		$subject = isset( $item['subject'] ) ? (string) $item['subject'] : '';
		$date    = isset( $item['date'] ) ? (int) $item['date'] : 0;
		$url     = isset( $item['url'] ) ? (string) $item['url'] : '';

		$subject_html = esc_html( $subject );

		if ( '' !== $url ) {
			$subject_html = '<a class="kdna-ab-archive__link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $subject_html . '</a>';
		}

		$date_html = '';

		if ( $date > 0 ) {
			$format    = get_option( 'date_format' );
			$date_html = '<div class="kdna-ab-archive__date"><time datetime="' . esc_attr( gmdate( 'c', $date ) ) . '">' . esc_html( wp_date( $format, $date ) ) . '</time></div>';
		}

		return '<article class="kdna-ab-archive__item">'
			. '<h3 class="kdna-ab-archive__subject">' . $subject_html . '</h3>'
			. $date_html
			. '</article>';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Load more
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: returns the next page of archive items.
	 *
	 * @return void
	 */
	public function ajax_more() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'kdna_ab_archive' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'api';
		$source = in_array( $source, array( 'api', 'local' ), true ) ? $source : 'api';
		$number = isset( $_POST['number'] ) ? max( 1, absint( $_POST['number'] ) ) : 10;
		$page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		$all    = self::get_items( $source );
		$offset = ( $page - 1 ) * $number;
		$slice  = array_slice( $all, $offset, $number );

		wp_send_json_success(
			array(
				'html'    => self::render_items( $slice ),
				'hasMore' => ( $offset + $number ) < count( $all ),
			)
		);
	}
}
