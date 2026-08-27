<?php
/**
 * Elementor widget registration and category.
 *
 * Registers the KDNA Tools category and the widgets, and registers the front end
 * assets so they load only where a widget is present. All Elementor hooks are
 * registered here at file load time in the constructor, never deferred inside
 * elementor/loaded.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Elementor
 */
class KDNA_AB_Elementor {

	/**
	 * The shared widget category slug. Must match the Klaviyo edition.
	 */
	const CATEGORY = 'kdna-tools';

	/**
	 * Front end style handle.
	 */
	const STYLE_HANDLE = 'kdna-ab-frontend';

	/**
	 * Front end script handle.
	 */
	const SCRIPT_HANDLE = 'kdna-ab-frontend';

	/**
	 * reCAPTCHA script handle.
	 */
	const RECAPTCHA_HANDLE = 'kdna-ab-recaptcha';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Elementor|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Elementor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers the Elementor hooks.
	 */
	private function __construct() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers the KDNA Tools category.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 * @return void
	 */
	public function register_category( $manager ) {
		$manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'KDNA Tools', 'kdna-article-broadcast' ),
				'icon'  => 'eicon-email-field',
			)
		);
	}

	/**
	 * Registers the widgets.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 * @return void
	 */
	public function register_widgets( $manager ) {
		require_once KDNA_AB_DIR . 'widgets/class-kdna-ab-widget-subscribe.php';
		require_once KDNA_AB_DIR . 'widgets/class-kdna-ab-widget-archive.php';

		$manager->register( new KDNA_AB_Widget_Subscribe() );
		$manager->register( new KDNA_AB_Widget_Archive() );
	}

	/**
	 * Registers the front end assets, so widgets can depend on them.
	 *
	 * Registering rather than enqueuing keeps loading conditional: Elementor only
	 * enqueues a widget's dependencies when the widget is present on the page.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			self::STYLE_HANDLE,
			KDNA_AB_URL . 'assets/css/kdna-article-broadcast.css',
			array(),
			KDNA_AB_VERSION
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			KDNA_AB_URL . 'assets/js/kdna-article-broadcast.js',
			array(),
			KDNA_AB_VERSION,
			true
		);

		$settings  = kdna_ab_get_settings();
		$site_key  = (string) $settings['recaptcha_site_key'];

		if ( '' !== $site_key ) {
			// The v3 script, loaded with the site key so grecaptcha.execute works.
			wp_register_script(
				self::RECAPTCHA_HANDLE,
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ),
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				true
			);
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'kdnaAbSubscribe',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( KDNA_AB_Subscribe::NONCE_ACTION ),
				'archiveNonce'     => wp_create_nonce( 'kdna_ab_archive' ),
				'recaptchaSiteKey' => $site_key,
				'i18n'             => KDNA_AB_Subscribe::messages(),
			)
		);
	}

	/**
	 * Whether reCAPTCHA is enabled, for the widget to declare the dependency.
	 *
	 * @return bool
	 */
	public static function recaptcha_enabled() {
		$settings = kdna_ab_get_settings();

		return '' !== (string) $settings['recaptcha_site_key'];
	}
}
