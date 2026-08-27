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

		// Stage 4.
		add_action( 'wp_ajax_kdna_ab_get_meta_fields', array( $this, 'ajax_get_meta_fields' ) );
		add_action( 'wp_ajax_kdna_ab_get_subfields', array( $this, 'ajax_get_subfields' ) );
		add_action( 'wp_ajax_kdna_ab_save_content', array( $this, 'ajax_save_content' ) );
		add_action( 'wp_ajax_kdna_ab_preview_content', array( $this, 'ajax_preview_content' ) );

		// Stage 5.
		add_action( 'wp_ajax_kdna_ab_save_sending', array( $this, 'ajax_save_sending' ) );

		// Stage 9.
		add_action( 'wp_ajax_kdna_ab_save_digest', array( $this, 'ajax_save_digest' ) );

		// Stage 10.
		add_action( 'wp_ajax_kdna_ab_save_signup', array( $this, 'ajax_save_signup' ) );
		add_action( 'wp_ajax_kdna_ab_test_recaptcha', array( $this, 'ajax_test_recaptcha' ) );
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

		// The content settings placeholder picker uses the media library.
		wp_enqueue_media();

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
			'content'     => $this->content_for_display( $settings ),
			'metaFields'  => $this->list_public_meta_keys(),
			'sending'     => $this->sending_for_display( $settings ),
			'digest'      => $this->digest_for_display( $settings ),
			'signup'      => $this->signup_for_display( $settings ),
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
				'contentSaved'  => __( 'Content settings saved.', 'kdna-article-broadcast' ),
				'sendingSaved'  => __( 'Sending settings saved.', 'kdna-article-broadcast' ),
				'previewing'    => __( 'Assembling preview...', 'kdna-article-broadcast' ),
				'chooseImage'   => __( 'Choose placeholder image', 'kdna-article-broadcast' ),
				'mediaTitle'    => __( 'Select a placeholder image', 'kdna-article-broadcast' ),
				'mediaButton'   => __( 'Use this image', 'kdna-article-broadcast' ),
				'digestSaved'   => __( 'Digest settings saved.', 'kdna-article-broadcast' ),
				'buildingDigest' => __( 'Building digest...', 'kdna-article-broadcast' ),
				'signupSaved'   => __( 'Signup settings saved.', 'kdna-article-broadcast' ),
				'testingRecaptcha' => __( 'Running reCAPTCHA...', 'kdna-article-broadcast' ),
				'recaptchaRunError' => __( 'reCAPTCHA could not run in the browser. Check the site key.', 'kdna-article-broadcast' ),
				'enterSiteKey'  => __( 'Enter a site key first.', 'kdna-article-broadcast' ),
			),
		);
	}

	/**
	 * Shapes the Stage 10 signup settings for the browser.
	 *
	 * The reCAPTCHA secret is never sent to the browser, only a masked hint.
	 *
	 * @param array $settings Full settings array.
	 * @return array
	 */
	private function signup_for_display( $settings ) {
		$secret = KDNA_AB_Crypto::decrypt( $settings['recaptcha_secret_key'] );

		return array(
			'recaptchaSiteKey'   => (string) $settings['recaptcha_site_key'],
			'hasSecret'          => ( '' !== $secret ),
			'maskedSecret'       => KDNA_AB_Crypto::mask( $secret ),
			'recaptchaThreshold' => (float) $settings['recaptcha_threshold'],
			'cfDateKey'          => (string) $settings['cf_date_key'],
			'cfIpKey'            => (string) $settings['cf_ip_key'],
			'cfPageKey'          => (string) $settings['cf_page_key'],
		);
	}

	/**
	 * Shapes the Stage 9 digest settings for the browser.
	 *
	 * @param array $settings Full settings array.
	 * @return array
	 */
	private function digest_for_display( $settings ) {
		$next = KDNA_AB_Digest::next_run_timestamp();

		return array(
			'digestDay'     => (int) $settings['digest_day'],
			'digestTime'    => (string) $settings['digest_time'],
			'digestOverlap' => (bool) $settings['digest_overlap'],
			'digestMax'     => (int) $settings['digest_max'],
			'digestWindow'  => (int) $settings['digest_window'],
			'digestSubject' => (string) $settings['digest_subject'],
			'digestIntro'   => (string) $settings['digest_intro'],
			'nextRun'       => $next ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) : '',
			'days'          => array(
				__( 'Sunday', 'kdna-article-broadcast' ),
				__( 'Monday', 'kdna-article-broadcast' ),
				__( 'Tuesday', 'kdna-article-broadcast' ),
				__( 'Wednesday', 'kdna-article-broadcast' ),
				__( 'Thursday', 'kdna-article-broadcast' ),
				__( 'Friday', 'kdna-article-broadcast' ),
				__( 'Saturday', 'kdna-article-broadcast' ),
			),
		);
	}

	/**
	 * Shapes the Stage 4 content settings for the browser.
	 *
	 * @param array $settings Full settings array.
	 * @return array
	 */
	private function content_for_display( $settings ) {
		return array(
			'introField'        => (string) $settings['intro_field'],
			'repeaterField'     => (string) $settings['repeater_field'],
			'repeaterBody'      => (string) $settings['repeater_body'],
			'repeaterHeading'   => (string) $settings['repeater_heading'],
			'repeaterImage'     => (string) $settings['repeater_image'],
			'teaserWordCount'   => (int) $settings['teaser_word_count'],
			'teaserTrimSentence' => (bool) $settings['teaser_trim_sentence'],
			'previewUseHeading' => (bool) $settings['preview_use_heading'],
			'placeholderImage'  => (string) $settings['placeholder_image'],
			'emailImageW'       => (int) $settings['email_image_w'],
			'emailImageH'       => (int) $settings['email_image_h'],
			'dateFormat'        => (string) $settings['date_format'],
			'ctaLabel'          => (string) $settings['cta_label'],
			'utmSource'         => (string) $settings['utm_source'],
			'utmMedium'         => (string) $settings['utm_medium'],
			'utmCampaign'       => (string) $settings['utm_campaign'],
			'readTimeMetaKey'   => (string) $settings['read_time_meta_key'],
		);
	}

	/**
	 * Shapes the Stage 5 sending settings for the browser.
	 *
	 * @param array $settings Full settings array.
	 * @return array
	 */
	private function sending_for_display( $settings ) {
		$test = isset( $settings['test_addresses'] ) && is_array( $settings['test_addresses'] ) ? $settings['test_addresses'] : array();

		return array(
			'sendMode'      => KDNA_AB_Sender::normalise_mode( $settings['send_mode'] ),
			'holdWindow'    => (int) $settings['hold_window'],
			'notifyEmail'   => (string) $settings['notify_email'],
			'adminEmail'    => (string) get_option( 'admin_email' ),
			'testAddresses' => implode( "\n", $test ),
		);
	}

	/**
	 * Lists the public post meta keys present on the site.
	 *
	 * Underscore prefixed protected keys are excluded. This is what populates the
	 * JetEngine field mapping dropdowns, so the list reflects the real fields on
	 * this site rather than a hard-coded set. The currently configured keys are
	 * always included, even if no post carries a value yet.
	 *
	 * @return array
	 */
	private function list_public_meta_keys() {
		global $wpdb;

		$cache_key = self::CACHE_PREFIX . 'meta_keys';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keys = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'post'
			 AND pm.meta_key NOT LIKE '\_%'
			 ORDER BY pm.meta_key ASC
			 LIMIT 300"
		);

		$keys = is_array( $keys ) ? array_map( 'strval', $keys ) : array();

		// Always include the configured keys so a saved value is never lost.
		$settings   = kdna_ab_get_settings();
		$configured = array( $settings['intro_field'], $settings['repeater_field'] );

		foreach ( $configured as $key ) {
			$key = (string) $key;
			if ( '' !== $key && ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}

		sort( $keys );

		set_transient( $cache_key, $keys, self::CACHE_TTL );

		return $keys;
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
	 * Stage 4 AJAX handlers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: returns the public post meta keys, refreshing the cache.
	 *
	 * @return void
	 */
	public function ajax_get_meta_fields() {
		$this->verify_request();

		delete_transient( self::CACHE_PREFIX . 'meta_keys' );

		wp_send_json_success( array( 'metaFields' => $this->list_public_meta_keys() ) );
	}

	/**
	 * AJAX: returns the sub-field keys found inside a repeater.
	 *
	 * Decodes the repeater from a recent post that has data, so the sub-field
	 * dropdowns list the real keys on this site.
	 *
	 * @return void
	 */
	public function ajax_get_subfields() {
		$this->verify_request();

		$repeater = isset( $_POST['repeater'] ) ? sanitize_text_field( wp_unslash( $_POST['repeater'] ) ) : '';

		if ( '' === $repeater ) {
			wp_send_json_error( array( 'message' => __( 'Choose a repeater field first.', 'kdna-article-broadcast' ) ), 400 );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
				'posts_per_page' => 25,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $repeater,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$subfields   = array();
		$sample_post = 0;

		foreach ( $query->posts as $pid ) {
			$rows = KDNA_AB_Content::decode_repeater( $pid, $repeater );

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					foreach ( array_keys( $row ) as $sub_key ) {
						$sub_key = (string) $sub_key;
						if ( '' !== $sub_key && ! in_array( $sub_key, $subfields, true ) ) {
							$subfields[] = $sub_key;
						}
					}
				}
				$sample_post = (int) $pid;
				break;
			}
		}

		// Keep the configured sub-fields available even if not seen in the sample.
		$settings = kdna_ab_get_settings();

		foreach ( array( $settings['repeater_body'], $settings['repeater_heading'], $settings['repeater_image'] ) as $key ) {
			$key = (string) $key;
			if ( '' !== $key && ! in_array( $key, $subfields, true ) ) {
				$subfields[] = $key;
			}
		}

		sort( $subfields );

		wp_send_json_success(
			array(
				'subfields'  => $subfields,
				'samplePost' => $sample_post,
			)
		);
	}

	/**
	 * AJAX: saves the content assembly settings.
	 *
	 * @return void
	 */
	public function ajax_save_content() {
		$this->verify_request();

		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$in  = json_decode( $raw, true );

		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'The settings could not be read. Please try again.', 'kdna-article-broadcast' ) ), 400 );
		}

		$settings = $this->get_settings();

		$settings['intro_field']      = isset( $in['introField'] ) ? sanitize_text_field( $in['introField'] ) : '';
		$settings['repeater_field']   = isset( $in['repeaterField'] ) ? sanitize_text_field( $in['repeaterField'] ) : '';
		$settings['repeater_body']    = isset( $in['repeaterBody'] ) ? sanitize_text_field( $in['repeaterBody'] ) : '';
		$settings['repeater_heading'] = isset( $in['repeaterHeading'] ) ? sanitize_text_field( $in['repeaterHeading'] ) : '';
		$settings['repeater_image']   = isset( $in['repeaterImage'] ) ? sanitize_text_field( $in['repeaterImage'] ) : '';

		$settings['teaser_word_count']    = isset( $in['teaserWordCount'] ) ? max( 1, absint( $in['teaserWordCount'] ) ) : 40;
		$settings['teaser_trim_sentence'] = ! empty( $in['teaserTrimSentence'] );
		$settings['preview_use_heading']  = ! empty( $in['previewUseHeading'] );

		$settings['placeholder_image'] = $this->sanitise_media_value( isset( $in['placeholderImage'] ) ? $in['placeholderImage'] : '' );

		$settings['email_image_w'] = isset( $in['emailImageW'] ) ? max( 1, absint( $in['emailImageW'] ) ) : 1200;
		$settings['email_image_h'] = isset( $in['emailImageH'] ) ? max( 1, absint( $in['emailImageH'] ) ) : 630;

		$settings['date_format'] = isset( $in['dateFormat'] ) ? sanitize_text_field( $in['dateFormat'] ) : '';
		$settings['cta_label']   = isset( $in['ctaLabel'] ) ? sanitize_text_field( $in['ctaLabel'] ) : '';

		$settings['utm_source']   = isset( $in['utmSource'] ) ? sanitize_text_field( $in['utmSource'] ) : '';
		$settings['utm_medium']   = isset( $in['utmMedium'] ) ? sanitize_text_field( $in['utmMedium'] ) : '';
		$settings['utm_campaign'] = isset( $in['utmCampaign'] ) ? sanitize_text_field( $in['utmCampaign'] ) : '';

		$settings['read_time_meta_key'] = isset( $in['readTimeMetaKey'] ) ? sanitize_text_field( $in['readTimeMetaKey'] ) : '';

		update_option( KDNA_AB_OPTION, $settings );

		wp_send_json_success(
			array(
				'message' => __( 'Content settings saved.', 'kdna-article-broadcast' ),
				'content' => $this->content_for_display( $settings ),
			)
		);
	}

	/**
	 * Sanitises a media value that may be an attachment ID or a URL.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private function sanitise_media_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			return (string) absint( $value );
		}

		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $value );
		}

		return '';
	}

	/**
	 * AJAX: assembles a preview for a post, or the most recent one.
	 *
	 * @return void
	 */
	public function ajax_preview_content() {
		$this->verify_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			$latest = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			$post_id = ! empty( $latest ) ? (int) $latest[0] : 0;
		}

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'No post was found to preview.', 'kdna-article-broadcast' ) ), 200 );
		}

		$assembled = KDNA_AB_Content::assemble( $post_id );

		$base = array(
			'postId'    => $post_id,
			'postTitle' => get_the_title( $post_id ),
			'editLink'  => get_edit_post_link( $post_id, 'raw' ),
		);

		if ( is_wp_error( $assembled ) ) {
			wp_send_json_success(
				array_merge(
					$base,
					array(
						'blocked' => true,
						'message' => $assembled->get_error_message(),
					)
				)
			);
		}

		wp_send_json_success(
			array_merge(
				$base,
				array(
					'blocked'   => false,
					'assembled' => $assembled,
				)
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Stage 5 AJAX handler
	 * -----------------------------------------------------------------------
	 */

	/**
	 * AJAX: saves the sending settings.
	 *
	 * @return void
	 */
	public function ajax_save_sending() {
		$this->verify_request();

		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$in  = json_decode( $raw, true );

		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'The settings could not be read. Please try again.', 'kdna-article-broadcast' ) ), 400 );
		}

		$notify = isset( $in['notifyEmail'] ) ? sanitize_email( $in['notifyEmail'] ) : '';

		if ( '' !== $notify && ! is_email( $notify ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid notification email address.', 'kdna-article-broadcast' ) ), 200 );
		}

		$settings = $this->get_settings();

		$settings['send_mode']    = KDNA_AB_Sender::normalise_mode( isset( $in['sendMode'] ) ? $in['sendMode'] : 'draft' );
		$settings['hold_window']  = isset( $in['holdWindow'] ) ? max( 1, absint( $in['holdWindow'] ) ) : 30;
		$settings['notify_email'] = $notify;

		// Up to four standing test addresses, split on commas or new lines.
		$test_raw   = isset( $in['testAddresses'] ) ? (string) $in['testAddresses'] : '';
		$candidates = preg_split( '/[\s,]+/', $test_raw, -1, PREG_SPLIT_NO_EMPTY );
		$valid      = array();

		foreach ( (array) $candidates as $candidate ) {
			$email = sanitize_email( $candidate );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $valid, true ) ) {
				$valid[] = $email;
			}
		}

		$settings['test_addresses'] = array_slice( $valid, 0, 4 );

		update_option( KDNA_AB_OPTION, $settings );

		wp_send_json_success(
			array(
				'message' => __( 'Sending settings saved.', 'kdna-article-broadcast' ),
				'sending' => $this->sending_for_display( $settings ),
			)
		);
	}

	/**
	 * AJAX: saves the weekly digest settings and reschedules the digest.
	 *
	 * @return void
	 */
	public function ajax_save_digest() {
		$this->verify_request();

		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$in  = json_decode( $raw, true );

		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'The settings could not be read. Please try again.', 'kdna-article-broadcast' ) ), 400 );
		}

		$settings = $this->get_settings();

		$day = isset( $in['digestDay'] ) ? (int) $in['digestDay'] : 1;
		$settings['digest_day'] = min( 6, max( 0, $day ) );

		$time = isset( $in['digestTime'] ) ? sanitize_text_field( $in['digestTime'] ) : '09:00';
		$settings['digest_time'] = preg_match( '/^\d{1,2}:\d{2}$/', $time ) ? $time : '09:00';

		$settings['digest_overlap'] = ! empty( $in['digestOverlap'] );
		$settings['digest_max']     = isset( $in['digestMax'] ) ? max( 1, absint( $in['digestMax'] ) ) : 6;
		$settings['digest_window']  = isset( $in['digestWindow'] ) ? max( 1, absint( $in['digestWindow'] ) ) : 72;
		$settings['digest_subject'] = isset( $in['digestSubject'] ) ? sanitize_text_field( $in['digestSubject'] ) : '';
		$settings['digest_intro']   = isset( $in['digestIntro'] ) ? sanitize_textarea_field( $in['digestIntro'] ) : '';

		update_option( KDNA_AB_OPTION, $settings );

		// The schedule may have changed, so reschedule the next digest.
		KDNA_AB_Digest::reschedule();

		wp_send_json_success(
			array(
				'message' => __( 'Digest settings saved.', 'kdna-article-broadcast' ),
				'digest'  => $this->digest_for_display( $settings ),
			)
		);
	}

	/**
	 * AJAX: saves the signup and reCAPTCHA settings.
	 *
	 * @return void
	 */
	public function ajax_save_signup() {
		$this->verify_request();

		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$in  = json_decode( $raw, true );

		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'The settings could not be read. Please try again.', 'kdna-article-broadcast' ) ), 400 );
		}

		$settings = $this->get_settings();

		$settings['recaptcha_site_key'] = isset( $in['recaptchaSiteKey'] ) ? sanitize_text_field( $in['recaptchaSiteKey'] ) : '';

		// Only replace the stored secret when a new one is entered.
		$secret_raw = isset( $in['recaptchaSecretKey'] ) ? sanitize_text_field( $in['recaptchaSecretKey'] ) : '';
		if ( '' !== $secret_raw ) {
			$settings['recaptcha_secret_key'] = KDNA_AB_Crypto::encrypt( $secret_raw );
		}

		$threshold = isset( $in['recaptchaThreshold'] ) ? (float) $in['recaptchaThreshold'] : 0.5;
		$settings['recaptcha_threshold'] = min( 1.0, max( 0.0, $threshold ) );

		$settings['cf_date_key'] = isset( $in['cfDateKey'] ) ? sanitize_text_field( $in['cfDateKey'] ) : '';
		$settings['cf_ip_key']   = isset( $in['cfIpKey'] ) ? sanitize_text_field( $in['cfIpKey'] ) : '';
		$settings['cf_page_key'] = isset( $in['cfPageKey'] ) ? sanitize_text_field( $in['cfPageKey'] ) : '';

		update_option( KDNA_AB_OPTION, $settings );

		wp_send_json_success(
			array(
				'message' => __( 'Signup settings saved.', 'kdna-article-broadcast' ),
				'signup'  => $this->signup_for_display( $settings ),
			)
		);
	}

	/**
	 * AJAX: performs a genuine reCAPTCHA verify round trip.
	 *
	 * @return void
	 */
	public function ajax_test_recaptcha() {
		$this->verify_request();

		$token         = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$secret_posted = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '';

		$secret = ( '' !== $secret_posted ) ? $secret_posted : KDNA_AB_Crypto::decrypt( $this->get_settings()['recaptcha_secret_key'] );

		if ( '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'Enter a secret key first.', 'kdna-article-broadcast' ) ), 200 );
		}

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'No reCAPTCHA token was produced in the browser.', 'kdna-article-broadcast' ) ), 200 );
		}

		$result = KDNA_AB_Subscribe::verify_recaptcha( $token, $secret );

		if ( ! $result['reached'] ) {
			wp_send_json_error( array( 'message' => __( 'Could not reach Google to verify the keys.', 'kdna-article-broadcast' ) ), 200 );
		}

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: reCAPTCHA score. */
						__( 'reCAPTCHA verified. Google returned a score of %s.', 'kdna-article-broadcast' ),
						null !== $result['score'] ? number_format_i18n( $result['score'], 2 ) : '1.00'
					),
				)
			);
		}

		$errors = $result['errors'];

		if ( in_array( 'invalid-input-secret', $errors, true ) ) {
			wp_send_json_error( array( 'message' => __( 'The secret key is invalid. Check it and try again.', 'kdna-article-broadcast' ) ), 200 );
		}

		if ( in_array( 'invalid-input-response', $errors, true ) || in_array( 'timeout-or-duplicate', $errors, true ) ) {
			wp_send_json_error( array( 'message' => __( 'The secret key reached Google, but the token was not accepted. Run the test again.', 'kdna-article-broadcast' ) ), 200 );
		}

		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %s: comma separated error codes. */
					__( 'reCAPTCHA test failed: %s', 'kdna-article-broadcast' ),
					! empty( $errors ) ? implode( ', ', array_map( 'sanitize_text_field', $errors ) ) : __( 'unknown error', 'kdna-article-broadcast' )
				),
			),
			200
		);
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
