<?php
/**
 * Newsletter subscribe Elementor widget.
 *
 * Stage 10 builds the functionality only: fields, layout switcher, success
 * behaviour, and the AJAX submission wiring. The full style controls arrive in
 * Stage 11, so the markup exposes kdna- prefixed classes and CSS custom property
 * hooks ready for them.
 *
 * The widget slug kdna-newsletter-subscribe must stay identical in the Klaviyo
 * edition.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Widget_Subscribe
 */
class KDNA_AB_Widget_Subscribe extends \Elementor\Widget_Base {

	/**
	 * The widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'kdna-newsletter-subscribe';
	}

	/**
	 * The widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'KDNA Newsletter Subscribe', 'kdna-article-broadcast' );
	}

	/**
	 * The widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-email-field';
	}

	/**
	 * The widget categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( KDNA_AB_Elementor::CATEGORY );
	}

	/**
	 * Search keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'kdna', 'newsletter', 'subscribe', 'signup', 'email', 'campaign monitor' );
	}

	/**
	 * Style dependencies, so the stylesheet loads only with the widget.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( KDNA_AB_Elementor::STYLE_HANDLE );
	}

	/**
	 * Script dependencies, so scripts load only with the widget.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		$depends = array( KDNA_AB_Elementor::SCRIPT_HANDLE );

		if ( KDNA_AB_Elementor::recaptcha_enabled() ) {
			$depends[] = KDNA_AB_Elementor::RECAPTCHA_HANDLE;
		}

		return $depends;
	}

	/**
	 * Removes the extra inner wrapper when the optimised markup experiment is on.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper() {
		return false;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Controls
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the widget controls.
	 *
	 * Stage 10 registers the content controls only. Style controls come in Stage
	 * 11.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'   => __( 'Heading', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Subscribe to our newsletter', 'kdna-article-broadcast' ),
				'dynamic' => array( 'active' => true ),
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Get our latest articles straight to your inbox.', 'kdna-article-broadcast' ),
				'dynamic' => array( 'active' => true ),
			)
		);

		$this->add_responsive_control(
			'layout',
			array(
				'label'        => __( 'Layout', 'kdna-article-broadcast' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'default'      => 'stacked',
				'options'      => array(
					'stacked' => __( 'Stacked', 'kdna-article-broadcast' ),
					'inline'  => __( 'Inline', 'kdna-article-broadcast' ),
				),
				'prefix_class' => 'kdna-ab-layout%s-',
			)
		);

		$this->add_control(
			'name_label',
			array(
				'label'   => __( 'Name label', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Name', 'kdna-article-broadcast' ),
			)
		);

		$this->add_control(
			'name_placeholder',
			array(
				'label'   => __( 'Name placeholder', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Your name', 'kdna-article-broadcast' ),
			)
		);

		$this->add_control(
			'email_label',
			array(
				'label'   => __( 'Email label', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Email', 'kdna-article-broadcast' ),
			)
		);

		$this->add_control(
			'email_placeholder',
			array(
				'label'   => __( 'Email placeholder', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'you@example.com', 'kdna-article-broadcast' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Button text', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Subscribe', 'kdna-article-broadcast' ),
			)
		);

		$this->add_control(
			'success_behaviour',
			array(
				'label'   => __( 'On success', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'message',
				'options' => array(
					'message'  => __( 'Show an inline message', 'kdna-article-broadcast' ),
					'redirect' => __( 'Redirect to a page', 'kdna-article-broadcast' ),
				),
			)
		);

		$this->add_control(
			'success_message',
			array(
				'label'     => __( 'Success message', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Thanks. Please check your inbox to confirm your subscription.', 'kdna-article-broadcast' ),
				'condition' => array( 'success_behaviour' => 'message' ),
			)
		);

		$this->add_control(
			'redirect_url',
			array(
				'label'       => __( 'Redirect to', 'kdna-article-broadcast' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'options'     => array( 'url' ),
				'condition'   => array( 'success_behaviour' => 'redirect' ),
				'placeholder' => __( 'https://your-site.com/thank-you', 'kdna-article-broadcast' ),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Render
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the widget on the front end.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$heading     = isset( $settings['heading'] ) ? $settings['heading'] : '';
		$description = isset( $settings['description'] ) ? $settings['description'] : '';
		$name_label  = isset( $settings['name_label'] ) ? $settings['name_label'] : __( 'Name', 'kdna-article-broadcast' );
		$name_ph     = isset( $settings['name_placeholder'] ) ? $settings['name_placeholder'] : '';
		$email_label = isset( $settings['email_label'] ) ? $settings['email_label'] : __( 'Email', 'kdna-article-broadcast' );
		$email_ph    = isset( $settings['email_placeholder'] ) ? $settings['email_placeholder'] : '';
		$button_text = isset( $settings['button_text'] ) ? $settings['button_text'] : __( 'Subscribe', 'kdna-article-broadcast' );
		$behaviour   = isset( $settings['success_behaviour'] ) ? $settings['success_behaviour'] : 'message';
		$success_msg = isset( $settings['success_message'] ) ? $settings['success_message'] : '';
		$redirect    = ( isset( $settings['redirect_url']['url'] ) && '' !== $settings['redirect_url']['url'] ) ? $settings['redirect_url']['url'] : '';

		$uid = 'kdna-ab-subscribe-' . $this->get_id();

		// A per render source page URL for the consent metadata.
		$page_url = ( is_singular() && get_the_ID() ) ? get_permalink( get_the_ID() ) : home_url( add_query_arg( array(), null ) );
		?>
		<div
			class="kdna-ab-subscribe"
			id="<?php echo esc_attr( $uid ); ?>"
			data-success="<?php echo esc_attr( $behaviour ); ?>"
			data-redirect="<?php echo esc_url( $redirect ); ?>"
		>
			<form class="kdna-ab-subscribe__form" novalidate>

				<?php if ( '' !== $heading ) : ?>
					<div class="kdna-ab-subscribe__heading"><?php echo esc_html( $heading ); ?></div>
				<?php endif; ?>

				<?php if ( '' !== $description ) : ?>
					<div class="kdna-ab-subscribe__description"><?php echo esc_html( $description ); ?></div>
				<?php endif; ?>

				<div class="kdna-ab-subscribe__fields">

					<div class="kdna-ab-subscribe__field kdna-ab-subscribe__field--name">
						<label class="kdna-ab-subscribe__label" for="<?php echo esc_attr( $uid . '-name' ); ?>"><?php echo esc_html( $name_label ); ?></label>
						<input
							type="text"
							id="<?php echo esc_attr( $uid . '-name' ); ?>"
							class="kdna-ab-subscribe__input kdna-ab-subscribe__input--name"
							name="name"
							placeholder="<?php echo esc_attr( $name_ph ); ?>"
							autocomplete="name"
							required
						/>
					</div>

					<div class="kdna-ab-subscribe__field kdna-ab-subscribe__field--email">
						<label class="kdna-ab-subscribe__label" for="<?php echo esc_attr( $uid . '-email' ); ?>"><?php echo esc_html( $email_label ); ?></label>
						<input
							type="email"
							id="<?php echo esc_attr( $uid . '-email' ); ?>"
							class="kdna-ab-subscribe__input kdna-ab-subscribe__input--email"
							name="email"
							placeholder="<?php echo esc_attr( $email_ph ); ?>"
							autocomplete="email"
							required
						/>
					</div>

					<div class="kdna-ab-subscribe__actions">
						<button type="submit" class="kdna-ab-subscribe__button">
							<span class="kdna-ab-subscribe__button-text"><?php echo esc_html( $button_text ); ?></span>
							<span class="kdna-ab-subscribe__spinner" aria-hidden="true"></span>
						</button>
					</div>

				</div>

				<?php /* Invisible honeypot. Positioned off screen, not display none, so bots fill it. */ ?>
				<div class="kdna-ab-subscribe__hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'kdna-article-broadcast' ); ?>
						<input type="text" name="kdna_ab_hp" tabindex="-1" autocomplete="off" />
					</label>
				</div>

				<input type="hidden" name="kdna_ab_page" value="<?php echo esc_url( $page_url ); ?>" />
				<input type="hidden" class="kdna-ab-subscribe__token" name="recaptcha_token" value="" />

				<div class="kdna-ab-subscribe__message kdna-ab-subscribe__message--success" data-message="<?php echo esc_attr( $success_msg ); ?>" role="status" aria-live="polite" hidden></div>
				<div class="kdna-ab-subscribe__message kdna-ab-subscribe__message--error" role="alert" aria-live="assertive" hidden></div>

			</form>
		</div>
		<?php
	}
}
