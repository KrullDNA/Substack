<?php
/**
 * Plugin Name:       KDNA Article Broadcast (Campaign Monitor)
 * Plugin URI:        https://krulldna.com/
 * Description:       Connects a WordPress blog to Campaign Monitor so publishing an article can create a ready to send email campaign. Stage 1 delivers the plugin foundation, settings page and a genuine Campaign Monitor connection test.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Nick Krull, Krull Design and Advertising
 * Author URI:        https://krulldna.com/
 * Text Domain:       kdna-article-broadcast
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * KDNA Article Broadcast, Campaign Monitor edition.
 * A companion Klaviyo edition follows later, so widget slugs, CSS class names,
 * CSS variable names and post meta keys are kept stable for a painless swap.
 *
 * @package KDNA_Article_Broadcast
 */

// No direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */

define( 'KDNA_AB_VERSION', '1.0.0' );
define( 'KDNA_AB_SLUG', 'kdna-article-broadcast' );
define( 'KDNA_AB_FILE', __FILE__ );
define( 'KDNA_AB_DIR', plugin_dir_path( __FILE__ ) );
define( 'KDNA_AB_URL', plugin_dir_url( __FILE__ ) );
define( 'KDNA_AB_BASENAME', plugin_basename( __FILE__ ) );

// Single option array that holds every plugin setting.
define( 'KDNA_AB_OPTION', 'kdna_ab_settings' );
// Stored schema version, so future stages can migrate options safely.
define( 'KDNA_AB_VERSION_OPTION', 'kdna_ab_version' );

/*
 * ---------------------------------------------------------------------------
 * Autoloader
 *
 * Maps a KDNA_AB_ class name to includes/class-kdna-ab-{name}.php, matching the
 * KDNA Tables convention. For example KDNA_AB_API loads
 * includes/class-kdna-ab-api.php.
 * ---------------------------------------------------------------------------
 */

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'KDNA_AB_' ) ) {
			return;
		}

		$file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$path = KDNA_AB_DIR . 'includes/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Activation and deactivation
 * ---------------------------------------------------------------------------
 */

/**
 * Runs on activation.
 *
 * Seeds default options without overwriting an existing configuration, and
 * records the schema version. No custom tables are created in Stage 1, the
 * send log table arrives in a later stage.
 *
 * @return void
 */
function kdna_ab_activate() {
	if ( false === get_option( KDNA_AB_OPTION, false ) ) {
		add_option( KDNA_AB_OPTION, kdna_ab_default_settings() );
	}

	update_option( KDNA_AB_VERSION_OPTION, KDNA_AB_VERSION );
}
register_activation_hook( __FILE__, 'kdna_ab_activate' );

/**
 * Runs on deactivation.
 *
 * Nothing is scheduled yet in Stage 1, so there is nothing to clear. Cron
 * events introduced by later stages will be unscheduled here.
 *
 * @return void
 */
function kdna_ab_deactivate() {
	// Reserved for later stages, for example clearing scheduled cron events.
}
register_deactivation_hook( __FILE__, 'kdna_ab_deactivate' );

/**
 * The default settings array.
 *
 * Kept in one place so activation, the settings screen and uninstall all agree
 * on the shape of the option.
 *
 * @return array
 */
function kdna_ab_default_settings() {
	return array(
		// Encrypted at rest, see KDNA_AB_Crypto.
		'api_key'             => '',
		// A record of the most recent successful connection test.
		'connection'          => array(),

		// Stage 2, client, list and template selection.
		'client_id'           => '',
		'client_name'         => '',
		'list_id'             => '',
		'list_name'           => '',
		'template_single_id'  => '',
		'template_single_name' => '',
		'template_digest_id'  => '',
		'template_digest_name' => '',
		'from_name'           => '',
		'from_email'          => '',
		'reply_to'            => '',
		// Positional field to region mapping, see KDNA_AB_Settings.
		'mapping_single'      => array(),
		'mapping_digest'      => array(),

		// Off by default, honoured by uninstall.php. The setting UI arrives in a later stage.
		'delete_on_uninstall' => false,
	);
}

/*
 * ---------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------------
 */

/**
 * Loads the plugin once WordPress is ready.
 *
 * @return void
 */
function kdna_ab_bootstrap() {
	load_plugin_textdomain( 'kdna-article-broadcast', false, dirname( KDNA_AB_BASENAME ) . '/languages' );

	// Admin only for Stage 1. Front end features arrive in later stages.
	if ( is_admin() ) {
		KDNA_AB_Settings::instance();
	}
}
add_action( 'plugins_loaded', 'kdna_ab_bootstrap' );

/**
 * Convenience accessor for a decrypted, ready to use API client.
 *
 * Later stages call this instead of wiring up the wrapper by hand.
 *
 * @return KDNA_AB_API
 */
function kdna_ab_api() {
	$settings = get_option( KDNA_AB_OPTION, array() );
	$key      = isset( $settings['api_key'] ) ? KDNA_AB_Crypto::decrypt( $settings['api_key'] ) : '';

	return new KDNA_AB_API( $key );
}
