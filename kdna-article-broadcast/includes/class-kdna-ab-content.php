<?php
/**
 * Content assembly and UTM builder.
 *
 * Turns a WordPress post into the values Campaign Monitor needs. On this site
 * the article body does not live in post_content, it lives in JetEngine fields,
 * so this engine reads an intro field and an article_sections repeater rather
 * than the post body. See section 5 of the project brief.
 *
 * This stage assembles values only. There is no sending logic here.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Content
 */
class KDNA_AB_Content {

	/**
	 * The dedicated email image size name.
	 */
	const IMAGE_SIZE = 'kdna-ab-email';

	/**
	 * Singleton instance.
	 *
	 * @var KDNA_AB_Content|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return KDNA_AB_Content
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers the email image size.
	 */
	private function __construct() {
		add_action( 'after_setup_theme', array( $this, 'register_image_size' ) );
	}

	/**
	 * Registers the dedicated email image size from the configured dimensions.
	 *
	 * @return void
	 */
	public function register_image_size() {
		$settings = kdna_ab_get_settings();
		$width    = max( 1, (int) $settings['email_image_w'] );
		$height   = max( 1, (int) $settings['email_image_h'] );

		add_image_size( self::IMAGE_SIZE, $width, $height, true );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Assembly
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Assembles the email values for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error Assembled values, or a WP_Error when the post has no
	 *                        content to broadcast.
	 */
	public static function assemble( $post_id ) {
		$settings = kdna_ab_get_settings();
		$post_id  = (int) $post_id;

		$rows  = self::decode_repeater( $post_id, $settings['repeater_field'] );
		$intro = self::get_field_text( $post_id, $settings['intro_field'] );

		// Empty content guard. Block the send rather than produce an empty email.
		$has_intro = ( '' !== trim( wp_strip_all_tags( (string) $intro ) ) );
		$has_body  = false;

		foreach ( $rows as $row ) {
			$body = isset( $row[ $settings['repeater_body'] ] ) ? $row[ $settings['repeater_body'] ] : '';
			if ( '' !== trim( wp_strip_all_tags( (string) $body ) ) ) {
				$has_body = true;
				break;
			}
		}

		if ( ! $has_intro && ! $has_body ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'KDNA Article Broadcast: post %d has no intro and no article sections, send blocked.', $post_id ) );
			}

			return new WP_Error(
				'kdna_ab_empty',
				__( 'This post has no intro and no article sections, so there is nothing to broadcast.', 'kdna-article-broadcast' )
			);
		}

		$teaser = self::build_teaser( $post_id, $rows, $intro );
		$image  = self::resolve_image( $post_id, $rows );
		$read   = self::get_read_time( $post_id );

		$permalink = get_permalink( $post_id );
		$link      = self::build_utm_url( $permalink, $post_id );

		$cta_override = trim( (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_CTA_OVERRIDE, true ) );
		$cta_label    = ( '' !== $cta_override ) ? $cta_override : (string) $settings['cta_label'];

		return array(
			'post_id'       => $post_id,
			'subject'       => self::encode_entities( KDNA_AB_Meta_Box::effective_subject( $post_id ) ),
			'preview_text'  => self::build_preview_text( $post_id, $rows, $teaser ),
			'title'         => self::encode_entities( get_the_title( $post_id ) ),
			'teaser'        => $teaser,
			'category'      => self::encode_entities( self::get_category( $post_id ) ),
			'author'        => self::encode_entities( self::get_author( $post_id ) ),
			'date'          => self::get_date( $post_id ),
			'read_time'     => self::encode_entities( $read ),
			'has_read_time' => ( '' !== $read ),
			'image_url'     => $image['url'],
			'image_source'  => $image['source'],
			'article_link'  => $link,
			'cta_label'     => self::encode_entities( $cta_label ),
			'cta_link'      => $link,
			'utm'           => self::utm_values( $post_id ),
		);
	}

	/**
	 * Turns a stored field mapping into ordered field keys per region type.
	 *
	 * Included fields are collected and sorted by their position within each
	 * type, giving the exact order the values must appear in the positional
	 * Campaign Monitor template content.
	 *
	 * @param array $mapping Stored mapping keyed by field key.
	 * @param array $fields  Field definition list.
	 * @return array With singleline, multiline and image arrays of field keys.
	 */
	public static function ordered_mapping( $mapping, $fields ) {
		$mapping = is_array( $mapping ) ? $mapping : array();
		$by_type = array(
			'singleline' => array(),
			'multiline'  => array(),
			'image'      => array(),
		);

		foreach ( $fields as $field ) {
			$key  = $field['key'];
			$type = $field['type'];

			if ( ! isset( $by_type[ $type ] ) ) {
				continue;
			}

			$row = isset( $mapping[ $key ] ) ? $mapping[ $key ] : null;

			if ( is_array( $row ) && ! empty( $row['include'] ) ) {
				$by_type[ $type ][] = array(
					'key' => $key,
					'pos' => isset( $row['position'] ) ? (int) $row['position'] : 1,
				);
			}
		}

		$out = array();

		foreach ( $by_type as $type => $items ) {
			usort(
				$items,
				static function ( $a, $b ) {
					return $a['pos'] - $b['pos'];
				}
			);

			$out[ $type ] = wp_list_pluck( $items, 'key' );
		}

		return $out;
	}

	/*
	 * -----------------------------------------------------------------------
	 * JetEngine repeater
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Decodes a JetEngine repeater into rows, in stored order.
	 *
	 * JetEngine stores a repeater as a single JSON string in one meta key.
	 * Reading the raw value as text would count the field structure as words, so
	 * the value must be decoded. The row order in the JSON matches the editor.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Repeater meta key.
	 * @return array List of rows, each an associative array of sub-field values.
	 */
	public static function decode_repeater( $post_id, $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return array();
		}

		$raw = get_post_meta( $post_id, $key, true );

		if ( empty( $raw ) ) {
			return array();
		}

		if ( is_array( $raw ) ) {
			$data = $raw;
		} else {
			$data = json_decode( (string) $raw, true );

			if ( ! is_array( $data ) ) {
				return array();
			}
		}

		$rows = array();

		foreach ( $data as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Returns the raw stored text of a single meta field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	public static function get_field_text( $post_id, $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return '';
		}

		$value = get_post_meta( $post_id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Teaser and preview text
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds the teaser.
	 *
	 * A per post override wins. Otherwise the teaser starts with the intro field
	 * and only continues into the first repeater row body copy if the configured
	 * word count has not been reached.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $rows    Decoded repeater rows.
	 * @param string $intro   Raw intro field value.
	 * @return string Entity encoded teaser.
	 */
	public static function build_teaser( $post_id, $rows, $intro = null ) {
		$settings = kdna_ab_get_settings();

		$override = trim( (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_TEASER, true ) );

		if ( '' !== $override ) {
			return self::encode_entities( self::normalise_text( $override ) );
		}

		if ( null === $intro ) {
			$intro = self::get_field_text( $post_id, $settings['intro_field'] );
		}

		$count    = max( 1, (int) $settings['teaser_word_count'] );
		$sentence = ! empty( $settings['teaser_trim_sentence'] );

		$combined = self::normalise_text( $intro );

		if ( self::word_count( $combined ) < $count && ! empty( $rows ) ) {
			$first_body = isset( $rows[0][ $settings['repeater_body'] ] ) ? $rows[0][ $settings['repeater_body'] ] : '';
			$combined   = trim( $combined . ' ' . self::normalise_text( $first_body ) );
		}

		return self::encode_entities( self::trim_words( $combined, $count, $sentence ) );
	}

	/**
	 * Builds the preview, or preheader, text.
	 *
	 * The per post preview field wins. Otherwise, if the setting is on, the first
	 * repeater row heading is used, and if not the teaser opening is used.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $rows    Decoded repeater rows.
	 * @param string $teaser  Already assembled teaser.
	 * @return string Entity encoded preview text.
	 */
	public static function build_preview_text( $post_id, $rows, $teaser ) {
		$settings = kdna_ab_get_settings();

		$preview_meta = trim( (string) get_post_meta( $post_id, KDNA_AB_Meta_Box::META_PREVIEW, true ) );

		if ( '' !== $preview_meta ) {
			return self::encode_entities( self::normalise_text( $preview_meta ) );
		}

		if ( ! empty( $settings['preview_use_heading'] ) && ! empty( $rows ) ) {
			$heading = self::normalise_text( isset( $rows[0][ $settings['repeater_heading'] ] ) ? $rows[0][ $settings['repeater_heading'] ] : '' );

			if ( '' !== $heading ) {
				return self::encode_entities( $heading );
			}
		}

		return $teaser;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Text helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Strips markup and normalises whitespace.
	 *
	 * Removes shortcodes, then HTML added by the WYSIWYG including script, style
	 * and captions, decodes any existing entities so the text is clean, then
	 * collapses whitespace. Entity encoding for the email happens later.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalise_text( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		$text = strip_shortcodes( $text );
		// Remove caption shortcode remnants and figure captions if any survive.
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Counts words in a UTF-8 safe way.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function word_count( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return 0;
		}

		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Trims text to a word count, optionally back to the last full sentence.
	 *
	 * @param string $text     Text.
	 * @param int    $count    Maximum words.
	 * @param bool   $sentence Trim back to the nearest full sentence.
	 * @return string
	 */
	public static function trim_words( $text, $count, $sentence ) {
		$text  = trim( (string) $text );
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) ) {
			return '';
		}

		$was_truncated = ( count( $words ) > $count );
		$truncated     = $was_truncated ? implode( ' ', array_slice( $words, 0, $count ) ) : implode( ' ', $words );

		if ( $sentence ) {
			// Trim back to the last sentence ending punctuation, if any.
			if ( preg_match( '/^(.*[\.\!\?])[^\.\!\?]*$/us', $truncated, $matches ) ) {
				$candidate = trim( $matches[1] );

				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
		}

		if ( $was_truncated ) {
			// Trailing ellipsis, converted to an entity by encode_entities.
			return $truncated . "\xE2\x80\xA6";
		}

		return $truncated;
	}

	/**
	 * Converts the characters that most often arrive garbled, especially in
	 * Outlook, into HTML entities.
	 *
	 * Registered trademark and similar symbols, curly quotes and apostrophes,
	 * ellipses, dashes and non-breaking spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function encode_entities( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		// Escape HTML significant characters first, so a title such as
		// "Tom & Jerry" or a stray angle bracket cannot break the email markup.
		$text = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );

		$map = array(
			"\xE2\x80\xA6" => '&hellip;', // Horizontal ellipsis.
			"\xE2\x80\x94" => '&mdash;',  // Em dash.
			"\xE2\x80\x93" => '&ndash;',  // En dash.
			"\xC2\xAE"     => '&reg;',    // Registered trademark.
			"\xE2\x84\xA2" => '&trade;',  // Trademark.
			"\xC2\xA9"     => '&copy;',   // Copyright.
			"\xE2\x80\x9C" => '&ldquo;',  // Left double quotation mark.
			"\xE2\x80\x9D" => '&rdquo;',  // Right double quotation mark.
			"\xE2\x80\x98" => '&lsquo;',  // Left single quotation mark.
			"\xE2\x80\x99" => '&rsquo;',  // Right single quotation mark, curly apostrophe.
			"\xC2\xA0"     => '&nbsp;',   // Non-breaking space.
		);

		return strtr( $text, $map );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Image, read time, taxonomy and date
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Resolves the email image with the three level fallback.
	 *
	 * WordPress featured image first, then the first repeater row section image,
	 * then the configured placeholder.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $rows    Decoded repeater rows.
	 * @return array With url and source keys.
	 */
	public static function resolve_image( $post_id, $rows ) {
		$settings = kdna_ab_get_settings();

		$thumb_id = get_post_thumbnail_id( $post_id );

		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, self::IMAGE_SIZE );
			if ( $url ) {
				return array(
					'url'    => $url,
					'source' => 'featured',
				);
			}
		}

		$image_key = $settings['repeater_image'];

		if ( ! empty( $rows ) && '' !== $image_key && isset( $rows[0][ $image_key ] ) ) {
			$url = self::media_value_to_url( $rows[0][ $image_key ] );
			if ( $url ) {
				return array(
					'url'    => $url,
					'source' => 'section',
				);
			}
		}

		$placeholder = trim( (string) $settings['placeholder_image'] );

		if ( '' !== $placeholder ) {
			$url = self::media_value_to_url( $placeholder );
			if ( $url ) {
				return array(
					'url'    => $url,
					'source' => 'placeholder',
				);
			}
		}

		return array(
			'url'    => '',
			'source' => 'none',
		);
	}

	/**
	 * Turns a media field value into a URL at the email image size.
	 *
	 * Handles an attachment ID, a URL string, or a JetEngine media array.
	 *
	 * @param mixed $value Media value.
	 * @return string URL, or empty string.
	 */
	public static function media_value_to_url( $value ) {
		if ( is_array( $value ) ) {
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
				$value = (int) $value['id'];
			} elseif ( isset( $value['url'] ) ) {
				return esc_url_raw( (string) $value['url'] );
			} else {
				return '';
			}
		}

		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_image_url( (int) $value, self::IMAGE_SIZE );
			return $url ? $url : '';
		}

		$value = (string) $value;

		if ( '' !== $value && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $value );
		}

		return '';
	}

	/**
	 * Reads the read time from KDNA Reading Time, with graceful degradation.
	 *
	 * The value is never recalculated here, so the email always matches the
	 * figure shown on the post. When KDNA Reading Time is inactive or returns
	 * nothing, an empty string is returned and the read time is omitted.
	 *
	 * The exact source is configurable, since KDNA Reading Time may expose its
	 * value as a post meta key or a function. A filter allows a site to override
	 * the lookup entirely.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_read_time( $post_id ) {
		/**
		 * Filters the read time value.
		 *
		 * Return a non empty string to supply the read time directly and skip the
		 * built in lookup.
		 *
		 * @param string|null $value   Read time value.
		 * @param int         $post_id Post ID.
		 */
		$pre = apply_filters( 'kdna_ab_read_time', null, $post_id );

		if ( ! empty( $pre ) ) {
			return (string) $pre;
		}

		$settings = kdna_ab_get_settings();

		$candidates = array();
		$configured = trim( (string) $settings['read_time_meta_key'] );

		if ( '' !== $configured ) {
			$candidates[] = $configured;
		}

		$candidates = array_merge(
			$candidates,
			array( '_kdna_reading_time', 'kdna_reading_time', '_reading_time', 'reading_time' )
		);

		foreach ( $candidates as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( '' !== $value && null !== $value && false !== $value ) {
				return (string) $value;
			}
		}

		if ( function_exists( 'kdna_reading_time' ) ) {
			$value = call_user_func( 'kdna_reading_time', $post_id );

			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Returns the primary category name.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_category( $post_id ) {
		$categories = get_the_category( $post_id );

		if ( ! empty( $categories ) && isset( $categories[0]->name ) ) {
			return (string) $categories[0]->name;
		}

		return '';
	}

	/**
	 * Returns the author display name.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_author( $post_id ) {
		$author_id = (int) get_post_field( 'post_author', $post_id );

		return (string) get_the_author_meta( 'display_name', $author_id );
	}

	/**
	 * Returns the published date in the configured format.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_date( $post_id ) {
		$settings = kdna_ab_get_settings();
		$format   = trim( (string) $settings['date_format'] );

		if ( '' === $format ) {
			$format = get_option( 'date_format' );
		}

		return (string) get_the_date( $format, $post_id );
	}

	/*
	 * -----------------------------------------------------------------------
	 * UTM
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the resolved UTM values for a post, with tokens replaced.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function utm_values( $post_id ) {
		$settings = kdna_ab_get_settings();

		$slug = get_post_field( 'post_name', $post_id );
		$date = wp_date( 'Y-m-d' );

		$campaign = str_replace(
			array( '{slug}', '{date}' ),
			array( $slug, $date ),
			(string) $settings['utm_campaign']
		);

		return array(
			'utm_source'   => (string) $settings['utm_source'],
			'utm_medium'   => (string) $settings['utm_medium'],
			'utm_campaign' => $campaign,
		);
	}

	/**
	 * Appends the UTM parameters to a URL.
	 *
	 * @param string $url     Base URL.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function build_utm_url( $url, $post_id ) {
		$args = array();

		foreach ( self::utm_values( $post_id ) as $key => $value ) {
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		if ( empty( $args ) ) {
			return $url;
		}

		return add_query_arg( $args, $url );
	}
}
