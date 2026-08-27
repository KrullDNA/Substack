<?php
/**
 * Uninstall routine.
 *
 * Data removal is gated behind the delete_on_uninstall setting, which is off by
 * default. With it off, uninstalling leaves every option, post meta value and
 * the send log table intact. With it on, everything the plugin created is
 * removed cleanly. Nothing here is destructive unless the site owner has
 * explicitly opted in.
 *
 * @package KDNA_Article_Broadcast
 */

// Only run from a genuine WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$kdna_ab_settings = get_option( 'kdna_ab_settings' );

if ( ! is_array( $kdna_ab_settings ) || empty( $kdna_ab_settings['delete_on_uninstall'] ) ) {
	// Opted out, or never configured. Leave all data intact.
	return;
}

global $wpdb;

// 1. Options.
$kdna_ab_options = array(
	'kdna_ab_settings',
	'kdna_ab_version',
	'kdna_ab_db_version',
	'kdna_ab_failures',
	'kdna_ab_failures_seq',
	'kdna_ab_last_digest',
	'kdna_ab_pending_digests',
	'kdna_ab_last_api',
);

foreach ( $kdna_ab_options as $kdna_ab_option ) {
	delete_option( $kdna_ab_option );
}

// 2. Post meta, every key the plugin owns.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_kdna\_ab\_%'" );

// 3. User meta, the notice dismissal flag.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'kdna_ab_failures_dismissed'" );

// 4. Cached transients, both the value and the timeout rows.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_kdna\_ab\_%' OR option_name LIKE '\_transient\_timeout\_kdna\_ab\_%'" );

// 5. Scheduled cron events.
foreach ( array( 'kdna_ab_hold_send', 'kdna_ab_retry_send', 'kdna_ab_run_digest', 'kdna_ab_expire_digest', 'kdna_ab_purge_log' ) as $kdna_ab_hook ) {
	wp_clear_scheduled_hook( $kdna_ab_hook );
}

// 6. The send log table.
$kdna_ab_table = $wpdb->prefix . 'kdna_ab_send_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$kdna_ab_table}" );
