<?php
/**
 * Newsletter archive Elementor widget.
 *
 * Lists past broadcasts, from the Campaign Monitor API (cached) or the local
 * send log, in a list or grid, with pagination or a Load more button, an empty
 * state, and complete responsive style controls including hover states.
 *
 * The widget slug kdna-newsletter-archive must stay identical in the Klaviyo
 * edition. All styling is driven by kdna- prefixed CSS custom properties and no
 * selector targets elementor-widget-container.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Widget_Archive
 */
class KDNA_AB_Widget_Archive extends \Elementor\Widget_Base {

	/**
	 * The widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'kdna-newsletter-archive';
	}

	/**
	 * The widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'KDNA Newsletter Archive', 'kdna-article-broadcast' );
	}

	/**
	 * The widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-post-list';
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
		return array( 'kdna', 'newsletter', 'archive', 'campaigns', 'email', 'campaign monitor' );
	}

	/**
	 * Style dependencies.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( KDNA_AB_Elementor::STYLE_HANDLE );
	}

	/**
	 * Script dependencies, for the Load more button.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( KDNA_AB_Elementor::SCRIPT_HANDLE );
	}

	/**
	 * Removes the extra inner wrapper when the optimised markup experiment is on.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper() {
		return false;
	}

	/**
	 * The variable target selector.
	 *
	 * @return string
	 */
	private function var_target() {
		return '{{WRAPPER}} .kdna-ab-archive';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Controls
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the widget controls.
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
			'source',
			array(
				'label'   => __( 'Source', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'api',
				'options' => array(
					'api'   => __( 'Campaign Monitor API', 'kdna-article-broadcast' ),
					'local' => __( 'Local send log', 'kdna-article-broadcast' ),
				),
			)
		);

		$this->add_control(
			'number',
			array(
				'label'   => __( 'Items per page', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'list' => __( 'List', 'kdna-article-broadcast' ),
					'grid' => __( 'Grid', 'kdna-article-broadcast' ),
				),
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'     => __( 'Columns', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'default'   => array( 'size' => 3 ),
				'range'     => array( 'px' => array( 'min' => 1, 'max' => 6, 'step' => 1 ) ),
				'selectors' => array( $this->var_target() => '--kdna-archive-columns: {{SIZE}};' ),
				'condition' => array( 'layout' => 'grid' ),
			)
		);

		$this->add_control(
			'pagination',
			array(
				'label'   => __( 'Pagination', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'none',
				'options' => array(
					'none'      => __( 'None', 'kdna-article-broadcast' ),
					'load_more' => __( 'Load more button', 'kdna-article-broadcast' ),
					'numbers'   => __( 'Numbered pages', 'kdna-article-broadcast' ),
				),
			)
		);

		$this->add_control(
			'load_more_text',
			array(
				'label'     => __( 'Load more text', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Load more', 'kdna-article-broadcast' ),
				'condition' => array( 'pagination' => 'load_more' ),
			)
		);

		$this->add_control(
			'empty_message',
			array(
				'label'   => __( 'Empty state message', 'kdna-article-broadcast' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'No newsletters have been sent yet.', 'kdna-article-broadcast' ),
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Registers the style controls.
	 *
	 * @return void
	 */
	protected function register_style_controls() {
		$root = $this->var_target();
		$item = '{{WRAPPER}} .kdna-ab-archive__item';

		/* Wrapper. */
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
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $root => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'wrapper_margin',
			array(
				'label'      => __( 'Margin', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $root => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'gap_x',
			array(
				'label'      => __( 'Column gap', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array( $root => '--kdna-archive-gap-x: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'gap_y',
			array(
				'label'      => __( 'Row gap', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array( $root => '--kdna-archive-gap-y: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		/* Item card. */
		$this->start_controls_section(
			'section_style_item',
			array(
				'label' => __( 'Item card', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'item_border_style',
			array(
				'label'     => __( 'Border style', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'none'   => __( 'None', 'kdna-article-broadcast' ),
					'solid'  => __( 'Solid', 'kdna-article-broadcast' ),
					'dashed' => __( 'Dashed', 'kdna-article-broadcast' ),
					'dotted' => __( 'Dotted', 'kdna-article-broadcast' ),
				),
				'selectors' => array( $root => '--kdna-archive-item-border-style: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'item_border_width',
			array(
				'label'      => __( 'Border width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'selectors'  => array( $root => '--kdna-archive-item-border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'item_radius',
			array(
				'label'      => __( 'Border radius', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $root => '--kdna-archive-item-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'item_padding',
			array(
				'label'      => __( 'Padding', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $root => '--kdna-archive-item-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->start_controls_tabs( 'item_state_tabs' );

		$this->start_controls_tab( 'item_state_normal', array( 'label' => __( 'Normal', 'kdna-article-broadcast' ) ) );

		$this->add_control(
			'item_bg',
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-item-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'item_border_colour',
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-item-border-colour: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'item_shadow',
				'selector' => $item,
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'item_state_hover', array( 'label' => __( 'Hover', 'kdna-article-broadcast' ) ) );

		$this->add_control(
			'item_bg_hover',
			array(
				'label'     => __( 'Background', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-item-bg-hover: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'item_border_colour_hover',
			array(
				'label'     => __( 'Border colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-item-border-colour-hover: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'item_shadow_hover',
				'selector' => $item . ':hover',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		/* Subject. */
		$this->start_controls_section(
			'section_style_subject',
			array(
				'label' => __( 'Subject', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'subject_typography',
				'selector' => '{{WRAPPER}} .kdna-ab-archive__subject',
			)
		);

		$this->add_control(
			'subject_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-subject-colour: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'subject_colour_hover',
			array(
				'label'     => __( 'Hover colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-subject-colour-hover: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		/* Date. */
		$this->start_controls_section(
			'section_style_date',
			array(
				'label' => __( 'Date', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'date_typography',
				'selector' => '{{WRAPPER}} .kdna-ab-archive__date',
			)
		);

		$this->add_control(
			'date_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-date-colour: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		/* Divider, list layout. */
		$this->start_controls_section(
			'section_style_divider',
			array(
				'label'     => __( 'Divider (list)', 'kdna-article-broadcast' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'list' ),
			)
		);

		$this->add_control(
			'divider_style',
			array(
				'label'     => __( 'Style', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'solid',
				'options'   => array(
					'none'   => __( 'None', 'kdna-article-broadcast' ),
					'solid'  => __( 'Solid', 'kdna-article-broadcast' ),
					'dashed' => __( 'Dashed', 'kdna-article-broadcast' ),
					'dotted' => __( 'Dotted', 'kdna-article-broadcast' ),
				),
				'selectors' => array( $root => '--kdna-archive-divider-style: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'divider_width',
			array(
				'label'      => __( 'Width', 'kdna-article-broadcast' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'selectors'  => array( $root => '--kdna-archive-divider-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'divider_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-divider-colour: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		/* Empty state. */
		$this->start_controls_section(
			'section_style_empty',
			array(
				'label' => __( 'Empty state', 'kdna-article-broadcast' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'empty_typography',
				'selector' => '{{WRAPPER}} .kdna-ab-archive__empty',
			)
		);

		$this->add_control(
			'empty_colour',
			array(
				'label'     => __( 'Colour', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $root => '--kdna-archive-empty-colour: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'empty_align',
			array(
				'label'     => __( 'Alignment', 'kdna-article-broadcast' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array( 'title' => __( 'Left', 'kdna-article-broadcast' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Centre', 'kdna-article-broadcast' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'kdna-article-broadcast' ), 'icon' => 'eicon-text-align-right' ),
				),
				'selectors' => array( $root => '--kdna-archive-empty-align: {{VALUE}};' ),
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

		$source     = isset( $settings['source'] ) ? $settings['source'] : 'api';
		$source     = in_array( $source, array( 'api', 'local' ), true ) ? $source : 'api';
		$number     = isset( $settings['number'] ) ? max( 1, (int) $settings['number'] ) : 10;
		$layout     = ( isset( $settings['layout'] ) && 'grid' === $settings['layout'] ) ? 'grid' : 'list';
		$pagination = isset( $settings['pagination'] ) ? $settings['pagination'] : 'none';
		$empty_msg  = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';
		$more_text  = isset( $settings['load_more_text'] ) && '' !== $settings['load_more_text'] ? $settings['load_more_text'] : __( 'Load more', 'kdna-article-broadcast' );

		$all   = KDNA_AB_Archive::get_items( $source );
		$total = count( $all );

		echo '<div class="kdna-ab-archive kdna-ab-archive--' . esc_attr( $layout ) . '">';

		if ( 0 === $total ) {
			echo '<div class="kdna-ab-archive__empty">' . esc_html( $empty_msg ) . '</div>';
			echo '</div>';
			return;
		}

		// Which page to show. Numbered pagination reads the page from the URL.
		$page = 1;
		if ( 'numbers' === $pagination ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = isset( $_GET['kdna_archive_page'] ) ? max( 1, absint( $_GET['kdna_archive_page'] ) ) : 1;
		}

		$offset = ( $page - 1 ) * $number;
		$items  = array_slice( $all, $offset, $number );

		echo '<div class="kdna-ab-archive__items">';
		echo KDNA_AB_Archive::render_items( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		if ( 'load_more' === $pagination && $number < $total ) {
			printf(
				'<button type="button" class="kdna-ab-archive__more" data-source="%1$s" data-number="%2$d" data-page="1">%3$s</button>',
				esc_attr( $source ),
				(int) $number,
				esc_html( $more_text )
			);
		}

		if ( 'numbers' === $pagination ) {
			$this->render_pagination( $total, $number, $page );
		}

		echo '</div>';
	}

	/**
	 * Renders numbered pagination links.
	 *
	 * @param int $total  Total items.
	 * @param int $number Items per page.
	 * @param int $page   Current page.
	 * @return void
	 */
	private function render_pagination( $total, $number, $page ) {
		$pages = (int) ceil( $total / $number );

		if ( $pages < 2 ) {
			return;
		}

		echo '<nav class="kdna-ab-archive__pagination" aria-label="' . esc_attr__( 'Archive pages', 'kdna-article-broadcast' ) . '">';

		for ( $i = 1; $i <= $pages; $i++ ) {
			$url     = add_query_arg( 'kdna_archive_page', $i );
			$current = ( $i === $page ) ? ' kdna-ab-archive__page--current' : '';

			printf(
				'<a class="kdna-ab-archive__page%1$s" href="%2$s">%3$s</a>',
				esc_attr( $current ),
				esc_url( $url ),
				esc_html( number_format_i18n( $i ) )
			);
		}

		echo '</nav>';
	}
}
