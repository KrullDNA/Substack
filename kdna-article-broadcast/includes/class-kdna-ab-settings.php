<?php
/**
 * Settings page and options.
 *
 * Registers the Settings > KDNA Article Broadcast screen, enqueues the Alpine
 * driven admin interface only on that screen, and handles the AJAX actions that
 * back it.
 *
 * Stage 1 delivered the connection test and encrypted key storage. Stage 2 adds
 * the client, list and template selection, cached against the live API in a one
 * hour transient with a manual refresh, plus the template region mapping panel.
 *
 * Region mapping note: Campaign Monitor v3.3 does not expose a template
 * editable regions through the API, and the create campaign from template
 * endpoint matches content by position. The mapping panel therefore assigns
 * each KDNA field to a positional slot within its region type, which is exactly
 * the by position mapping the brief describes.
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
	 * Nonce action used by every AJAX handler.
	 */
	const NONCE_ACTION = 'kdna_ab_settings';

	/**
	 * Transient key prefix for cached API data.
	 */
	const CACHE_PREFIX = 'kdna_ab_cache_';

	/**
	 * Cache lifetime in seconds, one hour.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

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

		// Stage 1.
		add_action( 'wp_ajax_kdna_ab_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_kdna_ab_save_settings', array( $this, 'ajax_save_settings' ) );

		// Stage 2.
		add_action( 'wp_ajax_kdna_ab_get_clients', array( $this, 'ajax_get_clients' ) );
		add_action( 'wp_ajax_kdna_ab_get_client_data', array( $this, 'ajax_get_client_data' ) );
		add_action( 'wp_ajax_kdna_ab_get_template', array( $this, 'ajax_get_template' ) );
		add_action( 'wp_ajax_kdna_ab_refresh_cache', array( $this, 'ajax_refresh_cache' ) );
		add_action( 'wp_ajax_kdna_ab_save_selection', array( $this, 'ajax_save_selection' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Field definitions
	 *
	 * The fixed set of KDNA content fields that map onto template regions. Kept
	 * in one place so the PHP validation and the JavaScript UI agree.
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Fields for the single article template.
	 *
	 * @return array List of arrays with key, label and type.
	 */
	public static function single_fields() {
		return array(
			array(
				'key'   => 'subject_heading',
				'label' => __( 'Subject heading', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'article_title',
				'label' => __( 'Article title', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'teaser',
				'label' => __( 'Teaser text', 'kdna-article-broadcast' ),
				'type'  => 'multiline',
			),
			array(
				'key'   => 'category',
				'label' => __( 'Category', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'author',
				'label' => __( 'Author', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'read_time',
				'label' => __( 'Read time', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'article_link',
				'label' => __( 'Article link', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'cta_label',
				'label' => __( 'CTA label', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'featured_image',
				'label' => __( 'Featured image', 'kdna-article-broadcast' ),
				'type'  => 'image',
			),
		);
	}

	/**
	 * Top level fields for the weekly digest template, outside the repeater.
	 *
	 * @return array
	 */
	public static function digest_top_fields() {
		return array(
			array(
				'key'   => 'intro',
				'label' => __( 'Intro line', 'kdna-article-broadcast' ),
				'type'  => 'multiline',
			),
		);
	}

	/**
	 * Per article fields inside the weekly digest repeater.
	 *
	 * @return array
	 */
	public static function digest_repeater_fields() {
		return array(
			array(
				'key'   => 'article_title',
				'label' => __( 'Article title', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'teaser',
				'label' => __( 'Teaser text', 'kdna-article-broadcast' ),
				'type'  => 'multiline',
			),
			array(
				'key'   => 'category',
				'label' => __( 'Category', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'author',
				'label' => __( 'Author', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'read_time',
				'label' => __( 'Read time', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'article_link',
				'label' => __( 'Article link', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'cta_label',
				'label' => __( 'CTA label', 'kdna-article-broadcast' ),
				'type'  => 'singleline',
			),
			array(
				'key'   => 'section_image',
				'label' => __( 'Section image', 'kdna-article-broadcast' ),
				'type'  => 'image',
			),
		);
	}

	/**
	 * Human labels for each region type.
	 *
	 * @return array
	 */
	public static function region_type_labels() {
		return array(
			'singleline' => __( 'Single line', 'kdna-article-broadcast' ),
			'multiline'  => __( 'Multi line', 'kdna-article-broadcast' ),
			'image'      => __( 'Image', 'kdna-article-broadcast' ),
			'repeater'   => __( 'Repeater', 'kdna-article-broadcast' ),
		);
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
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
			'hasKey'      => ( '' !== $plain_key ),
			'maskedKey'   => KDNA_AB_Crypto::mask( $plain_key ),
			'connection'  => $this->connection_for_display( $connection ),
			'selection'   => $this->selection_for_display( $settings ),
			'fields'      => array(
				'single'          => self::single_fields(),
				'digestTop'       => self::digest_top_fields(),
				'digestRepeater'  => self::digest_repeater_fields(),
			),
			'typeLabels'  => self::region_type_labels(),
			'i18n'        => array(
				'testing'       => __( 'Testing connection...', 'kdna-article-broadcast' ),
				'saving'        => __( 'Saving...', 'kdna-article-broadcast' ),
				'enterKey'      => __( 'Please enter an API key first.', 'kdna-article-broadcast' ),
				'networkError'  => __( 'The request could not be completed. Please try again.', 'kdna-article-broadcast' ),
				'saved'         => __( 'Settings saved.', 'kdna-article-broadcast' ),
				'loading'       => __( 'Loading...', 'kdna-article-broadcast' ),
				'refreshing'    => __( 'Refreshing from Campaign Monitor...', 'kdna-article-broadcast' ),
				'selectClient'  => __( 'Select a client', 'kdna-article-broadcast' ),
				'selectList'    => __( 'Select a list', 'kdna-article-broadcast' ),
				'selectTemplate' => __( 'Select a template', 'kdna-article-broadcast' ),
				'unused'        => __( 'Not used', 'kdna-article-broadcast' ),
				'selectionSaved' => __( 'Selection and mapping saved.', 'kdna-article-broadcast' ),
				'invalidEmail'  => __( 'Please enter a valid from email address.', 'kdna-article-broadcast' ),
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

	/**
	 * Shapes the saved Stage 2 selection for the browser.
	 *
	 * @param array $settings Full settings array.
	 * @return array
	 */
	private function selection_for_display( $settings ) {
		return array(
			'clientId'      => (string) $settings['client_id'],
			'clientName'    => (string) $settings['client_name'],
			'listId'        => (string) $settings['list_id'],
			'listName'      => (string) $settings['list_name'],
			'templateSingle' => (string) $settings['template_single_id'],
			'templateDigest' => (string) $settings['template_digest_id'],
			'fromName'      => (string) $settings['from_name'],
			'fromEmail'     => (string) $settings['from_email'],
			'replyTo'       => (string) $settings['reply_to'],
			'mappingSingle' => $this->normalise_mapping( $settings['mapping_single'], self::single_fields() ),
			'mappingDigestTop' => $this->normalise_mapping(
				isset( $settings['mapping_digest']['top'] ) ? $settings['mapping_digest']['top'] : array(),
				self::digest_top_fields()
			),
			'mappingDigestRepeater' => $this->normalise_mapping(
				isset( $settings['mapping_digest']['repeater'] ) ? $settings['mapping_digest']['repeater'] : array(),
				self::digest_repeater_fields()
			),
		);
	}

	/**
	 * Normalises a stored mapping, seeding sensible defaults where empty.
	 *
	 * Each field gets an include flag and a one based position. When nothing is
	 * stored yet, fields are included in their listed order so Nick starts from a
	 * working layout and adjusts.
	 *
	 * @param mixed $stored Stored mapping, keyed by field key.
	 * @param array $fields Field definition list.
	 * @return array
	 */
	private function normalise_mapping( $stored, $fields ) {
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		// Track next default position per type.
		$next = array(
			'singleline' => 1,
			'multiline'  => 1,
			'image'      => 1,
		);

		foreach ( $fields as $field ) {
			$key  = $field['key'];
			$type = $field['type'];

			if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$include  = ! empty( $stored[ $key ]['include'] );
				$position = isset( $stored[ $key ]['position'] ) ? max( 1, (int) $stored[ $key ]['position'] ) : $next[ $type ];
			} else {
				// Default: included, in listed order per type.
				$include  = true;
				$position = $next[ $type ];
			}

			$out[ $key ] = array(
				'include'  => $include,
				'position' => $position,
			);

			if ( isset( $next[ $type ] ) ) {
				$next[ $type ] = max( $next[ $type ], $position ) + 1;
			}
		}

		return $out;
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
	 * Stage 1 AJAX handlers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: performs a genuine round trip connection test.
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
	 * AJAX: validates then saves the API key.
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

		// A new key can point at a different account, so clear any cached data.
		$this->clear_cache();

		$result['message']    = __( 'Settings saved. Connection to Campaign Monitor verified.', 'kdna-article-broadcast' );
		$result['connection'] = $this->connection_for_display( $settings['connection'] );

		wp_send_json_success( $result );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Stage 2 AJAX handlers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: returns the account clients, from cache where possible.
	 *
	 * @return void
	 */
	public function ajax_get_clients() {
		$this->verify_request();

		$clients = $this->cached_clients();

		if ( is_wp_error( $clients ) ) {
			wp_send_json_error( array( 'message' => $clients->get_error_message() ), 200 );
		}

		wp_send_json_success( array( 'clients' => $clients ) );
	}

	/**
	 * AJAX: returns the lists and templates for a selected client.
	 *
	 * @return void
	 */
	public function ajax_get_client_data() {
		$this->verify_request();

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		if ( '' === $client_id ) {
			wp_send_json_error( array( 'message' => __( 'No client was selected.', 'kdna-article-broadcast' ) ), 400 );
		}

		$lists = $this->cached_lists( $client_id );

		if ( is_wp_error( $lists ) ) {
			wp_send_json_error( array( 'message' => $lists->get_error_message() ), 200 );
		}

		$templates = $this->cached_templates( $client_id );

		if ( is_wp_error( $templates ) ) {
			wp_send_json_error( array( 'message' => $templates->get_error_message() ), 200 );
		}

		wp_send_json_success(
			array(
				'lists'     => $lists,
				'templates' => $templates,
			)
		);
	}

	/**
	 * AJAX: returns template details for the visual reference.
	 *
	 * @return void
	 */
	public function ajax_get_template() {
		$this->verify_request();

		$template_id = isset( $_POST['template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) : '';

		if ( '' === $template_id ) {
			wp_send_json_error( array( 'message' => __( 'No template was selected.', 'kdna-article-broadcast' ) ), 400 );
		}

		$template = $this->cached_template( $template_id );

		if ( is_wp_error( $template ) ) {
			wp_send_json_error( array( 'message' => $template->get_error_message() ), 200 );
		}

		wp_send_json_success( array( 'template' => $template ) );
	}

	/**
	 * AJAX: clears all cached API data and returns a fresh client list.
	 *
	 * @return void
	 */
	public function ajax_refresh_cache() {
		$this->verify_request();

		$this->clear_cache();

		$clients = $this->cached_clients();

		if ( is_wp_error( $clients ) ) {
			wp_send_json_error( array( 'message' => $clients->get_error_message() ), 200 );
		}

		wp_send_json_success(
			array(
				'clients' => $clients,
				'message' => __( 'Cache cleared and refreshed from Campaign Monitor.', 'kdna-article-broadcast' ),
			)
		);
	}

	/**
	 * AJAX: saves the client, list, template selection and region mapping.
	 *
	 * @return void
	 */
	public function ajax_save_selection() {
		$this->verify_request();

		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$in  = json_decode( $raw, true );

		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'The selection could not be read. Please try again.', 'kdna-article-broadcast' ) ), 400 );
		}

		$settings = $this->get_settings();

		// Simple scalar fields.
		$client_id       = isset( $in['clientId'] ) ? sanitize_text_field( $in['clientId'] ) : '';
		$client_name     = isset( $in['clientName'] ) ? sanitize_text_field( $in['clientName'] ) : '';
		$list_id         = isset( $in['listId'] ) ? sanitize_text_field( $in['listId'] ) : '';
		$list_name       = isset( $in['listName'] ) ? sanitize_text_field( $in['listName'] ) : '';
		$template_single = isset( $in['templateSingle'] ) ? sanitize_text_field( $in['templateSingle'] ) : '';
		$template_digest = isset( $in['templateDigest'] ) ? sanitize_text_field( $in['templateDigest'] ) : '';
		$from_name       = isset( $in['fromName'] ) ? sanitize_text_field( $in['fromName'] ) : '';
		$from_email      = isset( $in['fromEmail'] ) ? sanitize_email( $in['fromEmail'] ) : '';
		$reply_to        = isset( $in['replyTo'] ) ? sanitize_email( $in['replyTo'] ) : '';

		// A from email, if given, must be valid.
		if ( '' !== $from_email && ! is_email( $from_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid from email address.', 'kdna-article-broadcast' ) ), 200 );
		}

		if ( '' !== $reply_to && ! is_email( $reply_to ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid reply to email address.', 'kdna-article-broadcast' ) ), 200 );
		}

		$settings['client_id']            = $client_id;
		$settings['client_name']          = $client_name;
		$settings['list_id']              = $list_id;
		$settings['list_name']            = $list_name;
		$settings['template_single_id']   = $template_single;
		$settings['template_single_name'] = isset( $in['templateSingleName'] ) ? sanitize_text_field( $in['templateSingleName'] ) : '';
		$settings['template_digest_id']   = $template_digest;
		$settings['template_digest_name'] = isset( $in['templateDigestName'] ) ? sanitize_text_field( $in['templateDigestName'] ) : '';
		$settings['from_name']            = $from_name;
		$settings['from_email']           = $from_email;
		$settings['reply_to']             = $reply_to;

		$settings['mapping_single'] = $this->sanitise_mapping(
			isset( $in['mappingSingle'] ) ? $in['mappingSingle'] : array(),
			self::single_fields()
		);

		$settings['mapping_digest'] = array(
			'top'      => $this->sanitise_mapping(
				isset( $in['mappingDigestTop'] ) ? $in['mappingDigestTop'] : array(),
				self::digest_top_fields()
			),
			'repeater' => $this->sanitise_mapping(
				isset( $in['mappingDigestRepeater'] ) ? $in['mappingDigestRepeater'] : array(),
				self::digest_repeater_fields()
			),
		);

		update_option( KDNA_AB_OPTION, $settings );

		wp_send_json_success(
			array(
				'message'   => __( 'Selection and mapping saved.', 'kdna-article-broadcast' ),
				'selection' => $this->selection_for_display( $settings ),
			)
		);
	}

	/**
	 * Sanitises a submitted mapping against the known field list.
	 *
	 * Only recognised field keys are kept, include is cast to bool and position
	 * to a positive integer, so nothing arbitrary reaches the option.
	 *
	 * @param mixed $in     Submitted mapping.
	 * @param array $fields Field definition list.
	 * @return array
	 */
	private function sanitise_mapping( $in, $fields ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		foreach ( $fields as $field ) {
			$key = $field['key'];

			$include  = isset( $in[ $key ]['include'] ) ? (bool) $in[ $key ]['include'] : false;
			$position = isset( $in[ $key ]['position'] ) ? max( 1, (int) $in[ $key ]['position'] ) : 1;

			$out[ $key ] = array(
				'include'  => $include,
				'position' => $position,
			);
		}

		return $out;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Cached data layer
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the account clients, cached for one hour.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	private function cached_clients( $force = false ) {
		$key = self::CACHE_PREFIX . 'clients';

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = kdna_ab_api()->get_clients();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$clients = array();

		foreach ( (array) $response as $client ) {
			if ( isset( $client['ClientID'], $client['Name'] ) ) {
				$clients[] = array(
					'id'   => sanitize_text_field( $client['ClientID'] ),
					'name' => sanitize_text_field( $client['Name'] ),
				);
			}
		}

		set_transient( $key, $clients, self::CACHE_TTL );

		return $clients;
	}

	/**
	 * Returns a client subscriber lists, cached for one hour.
	 *
	 * @param string $client_id Client ID.
	 * @param bool   $force     Bypass the cache.
	 * @return array|WP_Error
	 */
	private function cached_lists( $client_id, $force = false ) {
		$key = self::CACHE_PREFIX . 'lists_' . md5( $client_id );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = kdna_ab_api()->get_client_lists( $client_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$lists = array();

		foreach ( (array) $response as $list ) {
			if ( isset( $list['ListID'], $list['Name'] ) ) {
				$lists[] = array(
					'id'   => sanitize_text_field( $list['ListID'] ),
					'name' => sanitize_text_field( $list['Name'] ),
				);
			}
		}

		set_transient( $key, $lists, self::CACHE_TTL );

		return $lists;
	}

	/**
	 * Returns a client templates, cached for one hour.
	 *
	 * @param string $client_id Client ID.
	 * @param bool   $force     Bypass the cache.
	 * @return array|WP_Error
	 */
	private function cached_templates( $client_id, $force = false ) {
		$key = self::CACHE_PREFIX . 'templates_' . md5( $client_id );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = kdna_ab_api()->get_client_templates( $client_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$templates = array();

		foreach ( (array) $response as $template ) {
			if ( isset( $template['TemplateID'], $template['Name'] ) ) {
				$templates[] = array(
					'id'         => sanitize_text_field( $template['TemplateID'] ),
					'name'       => sanitize_text_field( $template['Name'] ),
					'screenshot' => isset( $template['ScreenshotURL'] ) ? esc_url_raw( $template['ScreenshotURL'] ) : '',
					'preview'    => isset( $template['PreviewURL'] ) ? esc_url_raw( $template['PreviewURL'] ) : '',
				);
			}
		}

		set_transient( $key, $templates, self::CACHE_TTL );

		return $templates;
	}

	/**
	 * Returns a single template detail, cached for one hour.
	 *
	 * @param string $template_id Template ID.
	 * @param bool   $force       Bypass the cache.
	 * @return array|WP_Error
	 */
	private function cached_template( $template_id, $force = false ) {
		$key = self::CACHE_PREFIX . 'template_' . md5( $template_id );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = kdna_ab_api()->get_template( $template_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$template = array(
			'id'         => isset( $response['TemplateID'] ) ? sanitize_text_field( $response['TemplateID'] ) : $template_id,
			'name'       => isset( $response['Name'] ) ? sanitize_text_field( $response['Name'] ) : '',
			'screenshot' => isset( $response['ScreenshotURL'] ) ? esc_url_raw( $response['ScreenshotURL'] ) : '',
			'preview'    => isset( $response['PreviewURL'] ) ? esc_url_raw( $response['PreviewURL'] ) : '',
		);

		set_transient( $key, $template, self::CACHE_TTL );

		return $template;
	}

	/**
	 * Clears every cached API transient this plugin stores.
	 *
	 * Uses a direct query because the cache keys for lists, templates and single
	 * templates are hashed per ID and are not otherwise enumerable.
	 *
	 * @return void
	 */
	private function clear_cache() {
		global $wpdb;

		delete_transient( self::CACHE_PREFIX . 'clients' );

		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		if ( ! empty( $names ) ) {
			foreach ( $names as $name ) {
				$transient = preg_replace( '/^_transient_/', '', $name );
				delete_transient( $transient );
			}
		}
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
				/* translators: 1: number of clients, 2: comma separated list of client names. */
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
