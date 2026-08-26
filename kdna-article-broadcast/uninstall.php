<?php
/**
 * Uninstall routine.
 *
 * Data removal is deliberately gated behind the delete_on_uninstall setting,
 * which is off by default. With it off, uninstalling leaves all configuration
 * intact. The full gated routine, including the send log table, is completed in
 * the release stage. Nothing here is destructive unless the site owner has
 * explicitly opted in.
 *
 * @package KDNA_Article_Broadcast
 */

// Only run from a genuine WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$kdna_ab_settings = get_option( 'kdna_ab_settings' );

if ( is_array( $kdna_ab_settings ) && ! empty( $kdna_ab_settings['delete_on_uninstall'] ) ) {
	global $wpdb;

	delete_option( 'kdna_ab_settings' );
	delete_option( 'kdna_ab_version' );
	delete_option( 'kdna_ab_db_version' );

	// Remove the send log table. This happens on uninstall only, never on
	// deactivate, and only when the delete on uninstall setting is on.
	$kdna_ab_table = $wpdb->prefix . 'kdna_ab_send_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$kdna_ab_table}" );
}
