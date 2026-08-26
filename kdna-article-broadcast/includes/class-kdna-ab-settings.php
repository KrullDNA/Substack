<?php
/**
 * Settings page and options.
 *
 * Registers the Settings > KDNA Article Broadcast screen, enqueues the Alpine
 * driven admin interface only on that screen, and handles the two AJAX actions
 * that back it: a genuine connection test and a save that only persists once
 * the key has been validated against the live API.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Settings
 */
class KDNA_AB_Settings {

	/**
	 * The admin menu slug for this page.
	 */
	const MENU_SLUG = 'kdna-article-broadcast';

	/**
	 * The capability required to view and change settings.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action used by both AJAX handlers.
	 */
	const NONCE_ACTION = 'kdna_ab_settings';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Settings|null
	 */
	private static $instance = null;

	/**
	 * The page hook suffix, captured when the menu is added.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers the admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . KDNA_AB_BASENAME, array( $this, 'action_links' ) );

		add_action( 'wp_ajax_kdna_ab_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_kdna_ab_save_settings', array( $this, 'ajax_save_settings' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Menu and assets
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Adds the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook_suffix = add_options_page(
			__( 'KDNA Article Broadcast', 'kdna-article-broadcast' ),
			__( 'KDNA Article Broadcast', 'kdna-article-broadcast' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'kdna-article-broadcast' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Enqueues admin styles and scripts, only on this plugin settings screen.
	 *
	 * Conditional asset loading per the KDNA conventions: nothing loads on other
	 * admin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
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

		// Our controller script. Defines the Alpine component on the alpine:init
		// event, so registration order relative to Alpine does not matter.
		wp_enqueue_script(
			'kdna-ab-admin',
			KDNA_AB_URL . 'admin/admin-script.js',
			array(),
			KDNA_AB_VERSION,
			array( 'in_footer' => true )
		);

		/**
		 * Filters the Alpine.js source URL.
		 *
		 * Defaults to a pinned CDN build. A site that prefers to bundle Alpine
		 * locally can point this at a file inside the theme or another plugin.
		 *
		 * @param string $src Alpine.js script URL.
		 */
		$alpine_src = apply_filters( 'kdna_ab_alpine_src', 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js' );

		// Alpine depends on our handle, so our component is defined first, and it
		// is deferred as Alpine requires.
		wp_enqueue_script(
			'kdna-ab-alpine',
			$alpine_src,
			array( 'kdna-ab-admin' ),
			'3.14.1',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script( 'kdna-ab-admin', 'kdnaAb', $this->script_data() );
	}

	/**
	 * Builds the data object handed to the front end script.
	 *
	 * The real API key is never sent to the browser. Only a masked hint and a
	 * has key flag are exposed.
	 *
	 * @return array
	 */
	private function script_data() {
		$settings   = $this->get_settings();
		$plain_key  = KDNA_AB_Crypto::decrypt( $settings['api_key'] );
		$connection = isset( $settings['connection'] ) && is_array( $settings['connection'] ) ? $settings['connection'] : array();

		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
			'hasKey'     => ( '' !== $plain_key ),
			'maskedKey'  => KDNA_AB_Crypto::mask( $plain_key ),
			'connection' => $this->connection_for_display( $connection ),
			'i18n'       => array(
				'testing'      => __( 'Testing connection...', 'kdna-article-broadcast' ),
				'saving'       => __( 'Saving...', 'kdna-article-broadcast' ),
				'enterKey'     => __( 'Please enter an API key first.', 'kdna-article-broadcast' ),
				'networkError' => __( 'The request could not be completed. Please try again.', 'kdna-article-broadcast' ),
				'saved'        => __( 'Settings saved.', 'kdna-article-broadcast' ),
			),
		);
	}

	/**
	 * Shapes stored connection data for safe display.
	 *
	 * @param array $connection Stored connection record.
	 * @return array
	 */
	private function connection_for_display( $connection ) {
		if ( empty( $connection ) || empty( $connection['verified'] ) ) {
			return array( 'verified' => false );
		}

		$checked = isset( $connection['checked'] ) ? (int) $connection['checked'] : 0;

		return array(
			'verified'    => true,
			'clientCount' => isset( $connection['client_count'] ) ? (int) $connection['client_count'] : 0,
			'clients'     => isset( $connection['clients'] ) && is_array( $connection['clients'] ) ? array_values( $connection['clients'] ) : array(),
			'checkedText' => $checked ? sprintf(
				/* translators: %s: human readable time difference, for example "2 hours". */
				__( 'Last verified %s ago', 'kdna-article-broadcast' ),
				human_time_diff( $checked, time() )
			) : '',
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Page render
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the settings page by including the view template.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kdna-article-broadcast' ) );
		}

		require KDNA_AB_DIR . 'admin/admin-page.php';
	}

	/*
	 * -----------------------------------------------------------------------
	 * AJAX handlers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: performs a genuine round trip connection test.
	 *
	 * Validates the supplied key, or the stored key if the field was left blank,
	 * against the live clients endpoint and reports the real result. Never saves.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$this->verify_request();

		$key = $this->resolve_key_from_request();

		if ( '' === $key ) {
			wp_send_json_error(
				array( 'message' => __( 'Please enter an API key first.', 'kdna-article-broadcast' ) ),
				400
			);
		}

		$result = $this->probe( $key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				200
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: validates then saves the settings.
	 *
	 * Nothing is written to the options table unless the key passes a live check,
	 * satisfying the Stage 1 rule that no settings are saved until validation
	 * passes.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		$this->verify_request();

		$key       = $this->resolve_key_from_request();
		$field_raw = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( '' === $key ) {
			wp_send_json_error(
				array( 'message' => __( 'Please enter an API key before saving.', 'kdna-article-broadcast' ) ),
				400
			);
		}

		$result = $this->probe( $key );

		if ( is_wp_error( $result ) ) {
			// Validation failed, so nothing is saved.
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				200
			);
		}

		$settings = $this->get_settings();

		// Only overwrite the stored key when the user actually entered a new one.
		if ( '' !== $field_raw ) {
			$settings['api_key'] = KDNA_AB_Crypto::encrypt( $key );
		}

		$settings['connection'] = array(
			'verified'     => true,
			'client_count' => $result['clientCount'],
			'clients'      => $result['clients'],
			'checked'      => time(),
		);

		update_option( KDNA_AB_OPTION, $settings );

		$result['message']    = __( 'Settings saved. Connection to Campaign Monitor verified.', 'kdna-article-broadcast' );
		$result['connection'] = $this->connection_for_display( $settings['connection'] );

		wp_send_json_success( $result );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Internal helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Runs a live clients lookup and shapes the outcome for the browser.
	 *
	 * @param string $key Plain API key.
	 * @return array|WP_Error
	 */
	private function probe( $key ) {
		$api      = new KDNA_AB_API( $key );
		$response = $api->get_clients();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$clients = array();

		if ( is_array( $response ) ) {
			foreach ( $response as $client ) {
				if ( is_array( $client ) && isset( $client['Name'] ) ) {
					$clients[] = sanitize_text_field( $client['Name'] );
				}
			}
		}

		$count = count( $clients );

		if ( $count > 0 ) {
			$message = sprintf(
				/* translators: %s: comma separated list of client names. */
				_n(
					'Connected to Campaign Monitor. Found %1$d client: %2$s.',
					'Connected to Campaign Monitor. Found %1$d clients: %2$s.',
					$count,
					'kdna-article-broadcast'
				),
				$count,
				implode( ', ', array_slice( $clients, 0, 12 ) )
			);
		} else {
			$message = __( 'Connected to Campaign Monitor. The account has no clients yet.', 'kdna-article-broadcast' );
		}

		return array(
			'message'     => $message,
			'clientCount' => $count,
			'clients'     => array_slice( $clients, 0, 50 ),
		);
	}

	/**
	 * Verifies the nonce and capability for an AJAX request, or dies.
	 *
	 * @return void
	 */
	private function verify_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do this.', 'kdna-article-broadcast' ) ),
				403
			);
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'kdna-article-broadcast' ) ),
				403
			);
		}
	}

	/**
	 * Returns the API key to test: the posted value, or the stored key if blank.
	 *
	 * @return string Plain API key, possibly empty.
	 */
	private function resolve_key_from_request() {
		$posted = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( '' !== $posted ) {
			return $posted;
		}

		$settings = $this->get_settings();

		return KDNA_AB_Crypto::decrypt( $settings['api_key'] );
	}

	/**
	 * Returns the settings array, merged over defaults so keys always exist.
	 *
	 * @return array
	 */
	private function get_settings() {
		$stored = get_option( KDNA_AB_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( kdna_ab_default_settings(), $stored );
	}
}
