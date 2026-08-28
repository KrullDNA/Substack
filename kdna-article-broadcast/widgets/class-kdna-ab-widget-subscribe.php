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

		$this->register_style_controls();
	}

	/**
	 * The selector for setting CSS variables on the widget.
	 *
	 * @return string
	 */
	private function var_target() {
		return '{{WRAPPER}} .kdna-ab-subscribe';
	}

	/**
	 * Registers the Stage 11 style controls.
	 *
	 * Every visible element and state is covered, every dimensional control is
	 * responsive, and everything is driven by CSS custom properties or per
	 * instance Elementor selectors. No selector targets elementor-widget-container.
	 *
	 * @return void
	 */
	protected function register_style_controls() {
		$root  = $this->var_target();
		$input = '{{WRAPPER}} .kdna-ab-subscribe__input';
		$btn   = '{{WRAPPER}} .kdna-ab-subscribe__button';

		/* ---------------------------------------------------------------- */
		/* Editor preview state.                                            */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_preview',
			array(
				'label' => __( 'Editor preview', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'editor_preview_state',
			array(
				'label'       => __( 'Preview state', 'kdna-article-broadcast' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'default',
				'options'     => array(
					'default' => __( 'Default', 'kdna-article-broadcast' ),
					'loading' => __( 'Loading', 'kdna-article-broadcast' ),
					'success' => __( 'Success', 'kdna-article-broadcast' ),
					'error'   => __( 'Error', 'kdna-article-broadcast' ),
				),
				'description' => __( 'Editor only. Preview each state while designing. This has no effect on the live site.', 'kdna-article-broadcast' ),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------- */
		/* Wrapper.                                                         */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_style_wrapper',
			array(
				'label' => __( 'Wrapper', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'wrapper_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $root,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'wrapper_border',
				'selector' => $root,
			)
		);

		$this->add_responsive_control(
			'wrapper_radius',
			array(
				'label'      => __( 'Border radius', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $root => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'wrapper_shadow',
				'selector' => $root,
			)
		);

		$this->add_responsive_control(
			'wrapper_padding',
			array(
				'label'      => __( 'Padding', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( $root => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'wrapper_margin',
			array(
				'label'      => __( 'Margin', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( $root => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'wrapper_max_width',
			array(
				'label'      => __( 'Maximum width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'vw' ),
				'range'      => array( 'px' => array( 'min' => 200, 'max' => 1200 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-max-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'wrapper_align',
			array(
				'label'     => __( 'Alignment', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array( 'title' => __( 'Left', 'kdna-article-broadcast' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Centre', 'kdna-article-broadcast' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'kdna-article-broadcast' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors' => array( $root => 'text-align: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------- */
		/* Heading and description.                                         */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_style_text',
			array(
				'label' => __( 'Heading and description', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'heading_heading',
			array(
				'label' => __( 'Heading', 'kdna-article-broadcast' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'selector' => '{{WRAPPER}} .kdna-ab-subscribe__heading',
			)
		);

		$this->add_control(
			'heading_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-heading-colour: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'heading_margin',
			array(
				'label'      => __( 'Margin', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .kdna-ab-subscribe__heading' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'description_heading',
			array(
				'label'     => __( 'Description', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .kdna-ab-subscribe__description',
			)
		);

		$this->add_control(
			'description_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-description-colour: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'description_margin',
			array(
				'label'      => __( 'Margin', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .kdna-ab-subscribe__description' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------- */
		/* Labels.                                                          */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_style_labels',
			array(
				'label' => __( 'Labels', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'hide_labels',
			array(
				'label'        => __( 'Hide labels', 'kdna-article-broadcast' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'none',
				'default'      => '',
				'selectors'    => array( '{{WRAPPER}} .kdna-ab-subscribe__label' => 'display: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .kdna-ab-subscribe__label',
			)
		);

		$this->add_control(
			'label_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-label-colour: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'label_spacing',
			array(
				'label'      => __( 'Spacing below label', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-label-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------- */
		/* Fields.                                                          */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => __( 'Fields', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'selector' => $input,
			)
		);

		$this->add_control(
			'input_colour',
			array(
				'label'     => __( 'Text colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-colour: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_placeholder_colour',
			array(
				'label'     => __( 'Placeholder colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-placeholder-colour: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_border_style',
			array(
				'label'     => __( 'Border style', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'solid'  => __( 'Solid', 'kdna-article-broadcast' ),
					'dashed' => __( 'Dashed', 'kdna-article-broadcast' ),
					'dotted' => __( 'Dotted', 'kdna-article-broadcast' ),
					'none'   => __( 'None', 'kdna-article-broadcast' ),
				),
				'selectors' => array( $root => '--kdna-subscribe-input-border-style: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'input_border_width',
			array(
				'label'      => __( 'Border width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-input-border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'input_radius',
			array(
				'label'      => __( 'Border radius', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $root => '--kdna-subscribe-input-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'input_padding',
			array(
				'label'      => __( 'Padding', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $root => '--kdna-subscribe-input-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'input_min_height',
			array(
				'label'      => __( 'Minimum height', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 30, 'max' => 80 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-input-min-height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->start_controls_tabs( 'input_state_tabs' );

		$this->start_controls_tab( 'input_state_normal', array( 'label' => __( 'Normal', 'kdna-article-broadcast' ) ) );

		$this->add_control(
			'input_bg',
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_border_colour',
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-border-colour: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'input_state_focus', array( 'label' => __( 'Focus', 'kdna-article-broadcast' ) ) );

		$this->add_control(
			'input_bg_focus',
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-bg-focus: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_border_colour_focus',
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-border-colour-focus: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'input_state_error', array( 'label' => __( 'Error', 'kdna-article-broadcast' ) ) );

		$this->add_control(
			'input_bg_error',
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-bg-error: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_border_colour_error',
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-input-border-colour-error: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'field_gap_x',
			array(
				'label'      => __( 'Field gap, horizontal', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'separator'  => 'before',
				'selectors'  => array( $root => '--kdna-subscribe-gap-x: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'field_gap_y',
			array(
				'label'      => __( 'Field gap, vertical', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-gap-y: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------- */
		/* Button.                                                          */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => __( 'Button', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => $btn,
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $root => '--kdna-subscribe-button-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_radius',
			array(
				'label'      => __( 'Border radius', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $root => '--kdna-subscribe-button-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'button_border_style',
			array(
				'label'     => __( 'Border style', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'none'   => __( 'None', 'kdna-article-broadcast' ),
					'solid'  => __( 'Solid', 'kdna-article-broadcast' ),
					'dashed' => __( 'Dashed', 'kdna-article-broadcast' ),
					'dotted' => __( 'Dotted', 'kdna-article-broadcast' ),
				),
				'selectors' => array( $root => '--kdna-subscribe-button-border-style: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'button_border_width',
			array(
				'label'      => __( 'Border width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-button-border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'button_transition',
			array(
				'label'      => __( 'Transition duration (s)', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 's' ),
				'range'      => array( 's' => array( 'min' => 0, 'max' => 2, 'step' => 0.1 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-button-transition: {{SIZE}}s;' ),
			)
		);

		$this->start_controls_tabs( 'button_state_tabs' );

		$this->add_button_state_tab( 'normal', __( 'Normal', 'kdna-article-broadcast' ), '', $btn, $root );
		$this->add_button_state_tab( 'hover', __( 'Hover', 'kdna-article-broadcast' ), '-hover', $btn . ':hover', $root );
		$this->add_button_state_tab( 'focus', __( 'Focus', 'kdna-article-broadcast' ), '-focus', $btn . ':focus', $root );
		$this->add_button_state_tab( 'disabled', __( 'Disabled', 'kdna-article-broadcast' ), '-disabled', $btn . '[disabled]', $root );

		$this->end_controls_tabs();

		$this->add_control(
			'button_icon',
			array(
				'label'     => __( 'Icon', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::ICONS,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'button_icon_position',
			array(
				'label'     => __( 'Icon position', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => '0',
				'options'   => array(
					'0' => __( 'Before text', 'kdna-article-broadcast' ),
					'2' => __( 'After text', 'kdna-article-broadcast' ),
				),
				'selectors' => array( $root => '--kdna-subscribe-button-icon-order: {{VALUE}};' ),
				'condition' => array( 'button_icon[value]!' => '' ),
			)
		);

		$this->add_responsive_control(
			'button_icon_size',
			array(
				'label'      => __( 'Icon size', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 48 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-button-icon-size: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'button_icon[value]!' => '' ),
			)
		);

		$this->add_responsive_control(
			'button_icon_gap',
			array(
				'label'      => __( 'Icon gap', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-button-icon-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_width',
			array(
				'label'        => __( 'Width mode', 'kdna-article-broadcast' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'default'      => 'auto',
				'options'      => array(
					'auto'  => __( 'Auto', 'kdna-article-broadcast' ),
					'fill'  => __( 'Fill', 'kdna-article-broadcast' ),
					'fixed' => __( 'Fixed', 'kdna-article-broadcast' ),
				),
				'prefix_class' => 'kdna-ab-btnwidth%s-',
				'separator'    => 'before',
			)
		);

		$this->add_responsive_control(
			'button_fixed_width',
			array(
				'label'      => __( 'Fixed width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 80, 'max' => 600 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-button-fixed-width: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'button_width' => 'fixed' ),
			)
		);

		$this->add_responsive_control(
			'button_valign',
			array(
				'label'     => __( 'Vertical alignment (inline)', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array( 'title' => __( 'Top', 'kdna-article-broadcast' ), 'icon' => 'eicon-v-align-top' ),
					'center'     => array( 'title' => __( 'Middle', 'kdna-article-broadcast' ), 'icon' => 'eicon-v-align-middle' ),
					'flex-end'   => array( 'title' => __( 'Bottom', 'kdna-article-broadcast' ), 'icon' => 'eicon-v-align-bottom' ),
					'stretch'    => array( 'title' => __( 'Stretch', 'kdna-article-broadcast' ), 'icon' => 'eicon-v-align-stretch' ),
				),
				'selectors' => array( $root => '--kdna-subscribe-button-valign: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		/* ---------------------------------------------------------------- */
		/* Loading.                                                         */
		/* ---------------------------------------------------------------- */
		$this->start_controls_section(
			'section_style_loading',
			array(
				'label' => __( 'Loading state', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'spinner_colour',
			array(
				'label'     => __( 'Spinner colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-spinner-colour: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'loading_text',
			array(
				'label'   => __( 'Button text while submitting', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Subscribing...', 'kdna-article-broadcast' ),
			)
		);

		$this->end_controls_section();

		$this->register_message_style_controls( 'success', __( 'Success message', 'kdna-article-broadcast' ), $root );
		$this->register_message_style_controls( 'error', __( 'Error message', 'kdna-article-broadcast' ), $root );
	}

	/**
	 * Adds one button state tab of colour controls.
	 *
	 * @param string $key      State key.
	 * @param string $label    Tab label.
	 * @param string $suffix   CSS variable suffix, for example -hover.
	 * @param string $selector The button selector for the box shadow.
	 * @param string $root     The variable target selector.
	 * @return void
	 */
	private function add_button_state_tab( $key, $label, $suffix, $selector, $root ) {
		$this->start_controls_tab( 'button_state_' . $key, array( 'label' => $label ) );

		$this->add_control(
			'button_colour' . $suffix,
			array(
				'label'     => __( 'Text colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-button-colour' . $suffix . ': {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_bg' . $suffix,
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-button-bg' . $suffix . ': {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_border_colour' . $suffix,
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-button-border-colour' . $suffix . ': {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_shadow_' . $key,
				'selector' => $selector,
			)
		);

		$this->end_controls_tab();
	}

	/**
	 * Registers the style controls for a message, success or error.
	 *
	 * @param string $key   success or error.
	 * @param string $label Section label.
	 * @param string $root  Variable target selector.
	 * @return void
	 */
	private function register_message_style_controls( $key, $label, $root ) {
		$selector = '{{WRAPPER}} .kdna-ab-subscribe__message--' . $key;

		$this->start_controls_section(
			'section_style_' . $key,
			array(
				'label' => $label,
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => $key . '_typography',
				'selector' => $selector,
			)
		);

		$this->add_control(
			$key . '_colour',
			array(
				'label'     => __( 'Text colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-' . $key . '-colour: {{VALUE}};' ),
			)
		);

		$this->add_control(
			$key . '_bg',
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-' . $key . '-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			$key . '_border_style',
			array(
				'label'     => __( 'Border style', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'none'   => __( 'None', 'kdna-article-broadcast' ),
					'solid'  => __( 'Solid', 'kdna-article-broadcast' ),
					'dashed' => __( 'Dashed', 'kdna-article-broadcast' ),
					'dotted' => __( 'Dotted', 'kdna-article-broadcast' ),
				),
				'selectors' => array( $root => '--kdna-subscribe-' . $key . '-border-style: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			$key . '_border_width',
			array(
				'label'      => __( 'Border width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-' . $key . '-border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			$key . '_border_colour',
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-subscribe-' . $key . '-border-colour: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			$key . '_radius',
			array(
				'label'      => __( 'Border radius', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $root => '--kdna-subscribe-' . $key . '-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			$key . '_padding',
			array(
				'label'      => __( 'Padding', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $root => '--kdna-subscribe-' . $key . '-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			$key . '_icon',
			array(
				'label'     => __( 'Icon', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::ICONS,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			$key . '_icon_size',
			array(
				'label'      => __( 'Icon size', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 48 ) ),
				'selectors'  => array( $root => '--kdna-subscribe-' . $key . '-icon-size: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( $key . '_icon[value]!' => '' ),
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

		$heading      = isset( $settings['heading'] ) ? $settings['heading'] : '';
		$description  = isset( $settings['description'] ) ? $settings['description'] : '';
		$name_label   = isset( $settings['name_label'] ) ? $settings['name_label'] : __( 'Name', 'kdna-article-broadcast' );
		$name_ph      = isset( $settings['name_placeholder'] ) ? $settings['name_placeholder'] : '';
		$email_label  = isset( $settings['email_label'] ) ? $settings['email_label'] : __( 'Email', 'kdna-article-broadcast' );
		$email_ph     = isset( $settings['email_placeholder'] ) ? $settings['email_placeholder'] : '';
		$button_text  = isset( $settings['button_text'] ) ? $settings['button_text'] : __( 'Subscribe', 'kdna-article-broadcast' );
		$loading_text = isset( $settings['loading_text'] ) ? $settings['loading_text'] : __( 'Subscribing...', 'kdna-article-broadcast' );
		$behaviour    = isset( $settings['success_behaviour'] ) ? $settings['success_behaviour'] : 'message';
		$success_msg  = isset( $settings['success_message'] ) ? $settings['success_message'] : '';
		$redirect     = ( isset( $settings['redirect_url']['url'] ) && '' !== $settings['redirect_url']['url'] ) ? $settings['redirect_url']['url'] : '';

		$uid = 'kdna-ab-subscribe-' . $this->get_id();

		// A per render source page URL for the consent metadata.
		$page_url = ( is_singular() && get_the_ID() ) ? get_permalink( get_the_ID() ) : home_url( add_query_arg( array(), null ) );

		// Editor only preview state. This has no effect on the front end.
		$is_editor = \Elementor\Plugin::$instance->editor->is_edit_mode();
		$preview   = isset( $settings['editor_preview_state'] ) ? $settings['editor_preview_state'] : 'default';

		$form_classes = array( 'kdna-ab-subscribe__form' );

		if ( $is_editor && 'loading' === $preview ) {
			$form_classes[] = 'kdna-ab-subscribe__form--loading';
		}

		if ( $is_editor && 'success' === $preview ) {
			$form_classes[] = 'kdna-ab-subscribe__form--preview-success';
		}

		$show_success = ( $is_editor && 'success' === $preview );
		$show_error   = ( $is_editor && 'error' === $preview );
		$success_seed = $show_success ? $success_msg : '';
		$error_seed   = $show_error ? __( 'This is how an error message looks.', 'kdna-article-broadcast' ) : '';
		?>
		<div
			class="kdna-ab-subscribe"
			id="<?php echo esc_attr( $uid ); ?>"
			data-success="<?php echo esc_attr( $behaviour ); ?>"
			data-redirect="<?php echo esc_url( $redirect ); ?>"
		>
			<form class="<?php echo esc_attr( implode( ' ', $form_classes ) ); ?>" novalidate>

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
							<span class="kdna-ab-subscribe__spinner" aria-hidden="true"></span>
							<?php if ( ! empty( $settings['button_icon']['value'] ) ) : ?>
								<span class="kdna-ab-subscribe__button-icon">
									<?php \Elementor\Icons_Manager::render_icon( $settings['button_icon'], array( 'aria-hidden' => 'true' ) ); ?>
								</span>
							<?php endif; ?>
							<span class="kdna-ab-subscribe__button-text"><?php echo esc_html( $button_text ); ?></span>
							<span class="kdna-ab-subscribe__button-loading"><?php echo esc_html( $loading_text ); ?></span>
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

				<div class="kdna-ab-subscribe__message kdna-ab-subscribe__message--success" data-message="<?php echo esc_attr( $success_msg ); ?>" role="status" aria-live="polite" <?php echo $show_success ? '' : 'hidden'; ?>>
					<?php if ( ! empty( $settings['success_icon']['value'] ) ) : ?>
						<span class="kdna-ab-subscribe__message-icon"><?php \Elementor\Icons_Manager::render_icon( $settings['success_icon'], array( 'aria-hidden' => 'true' ) ); ?></span>
					<?php endif; ?>
					<span class="kdna-ab-subscribe__message-text"><?php echo esc_html( $success_seed ); ?></span>
				</div>

				<div class="kdna-ab-subscribe__message kdna-ab-subscribe__message--error" role="alert" aria-live="assertive" <?php echo $show_error ? '' : 'hidden'; ?>>
					<?php if ( ! empty( $settings['error_icon']['value'] ) ) : ?>
						<span class="kdna-ab-subscribe__message-icon"><?php \Elementor\Icons_Manager::render_icon( $settings['error_icon'], array( 'aria-hidden' => 'true' ) ); ?></span>
					<?php endif; ?>
					<span class="kdna-ab-subscribe__message-text"><?php echo esc_html( $error_seed ); ?></span>
				</div>

			</form>
		</div>
		<?php
	}
}
