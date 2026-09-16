<?php
/**
 * Vistara — shared template helpers for the ACF flexible-content section partials.
 *
 * Design rule (html-to-partial / transplant method):
 * every partial in template-parts/sections/ is a byte-verbatim copy of its source
 * fragment with ONLY the dynamic nodes replaced. Helpers here therefore return
 * VALUES (urls, alts, sanitized strings, attribute strings) instead of echoing whole
 * blocks of markup — an echoing "button"/"image"/"section-head" helper would move the
 * source's <a>/<img>/<h2>/<svg> markup into PHP strings, which
 *   (a) loses the source's exact class + attribute ORDER, and
 *   (b) makes the tag census in lint_partial.py --fragment unverifiable.
 * The one exception is vst_section_setup(), whose whole job is to REPLACE the source's
 * hardcoded `id`/class attributes with editor-controlled ones.
 *
 * Deliberately NOT provided: a generic `vst_section_head()`. The 15 source sections use
 * five different marker/heading shapes (with/without .rv, with/without
 * style="justify-content:center", margin-top 1.2rem vs 1.1rem, some wrapped in an extra
 * .center div). Collapsing them into one helper would have re-authored that markup.
 * Each partial keeps its own verbatim marker + <h2> and only the TEXT is a field read.
 *
 * @package Vistara
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Text / HTML output
 * ---------------------------------------------------------------------- */

/**
 * Allowed HTML for "accent" headline fields (plain text fields whose authored value
 * carries the source's inline accent wrappers).
 *
 * Covers every accent wrapper that actually appears in the source fragments:
 *   <span class="accent">, <span class="grad-word" data-scramble>,
 *   <span class="limitless grad-word">, <span class="nw">, <em>, <br>.
 *
 * @return array KSES allowed-HTML map.
 */
function vst_accent_allowed_html() {
	return array(
		'span'   => array(
			'class'         => true,
			'data-scramble' => true,
			'aria-hidden'   => true,
		),
		'em'     => array( 'class' => true ),
		'strong' => array( 'class' => true ),
		'b'      => array( 'class' => true ),
		'i'      => array( 'class' => true ),
		'sup'    => array(),
		'sub'    => array(),
		'br'     => array(),
	);
}

/**
 * Restricted-KSES output for accent headline fields.
 *
 * @param mixed $value Raw field value.
 * @return string Sanitized HTML.
 */
function vst_accent_html( $value ) {
	if ( '' === $value || null === $value ) {
		return '';
	}
	return wp_kses( (string) $value, vst_accent_allowed_html() );
}

/**
 * Multi-line (textarea) output: escaped, with newlines as <br>.
 *
 * Every textarea in the schema is stored with new_lines: "" so the raw value keeps its
 * newlines; the template is responsible for the <br> conversion.
 *
 * @param mixed $value Raw field value.
 * @return string Escaped HTML.
 */
function vst_multiline_html( $value ) {
	if ( '' === $value || null === $value ) {
		return '';
	}
	return nl2br( esc_html( (string) $value ) );
}

/**
 * WYSIWYG output for a field whose source node is a single inline paragraph.
 *
 * Two schema fields are wysiwyg by approved plan decision #6 because their authored value
 * carries markup restricted kses cannot: behind_vistara.body_1 (an inline <img> logo chip
 * mid-sentence) and calendar_form.consent_text (two inline links). In BOTH cases the source
 * node is a <p> that already exists in the transplanted markup
 * (<p class="lede rv d2"> / <p class="consent">), while ACF runs the value through wpautop
 * and hands back "<p>…</p>". Echoing that inside the source <p> would nest paragraphs and the
 * browser would split the element, dropping the source's classes off the copy.
 *
 * So: a single-paragraph value is unwrapped and rendered INSIDE the source <p>, keeping the
 * transplanted node byte-identical. A multi-paragraph value is returned whole (the editor
 * deliberately added structure; the extra paragraphs then render as siblings and lose the
 * source class — documented in the partials that use this).
 *
 * @param mixed $value Raw wysiwyg value.
 * @return string Sanitized HTML.
 */
function vst_wysiwyg_inline_html( $value ) {
	$html = wp_kses_post( (string) $value );
	$trim = trim( $html );
	if ( '' === $trim ) {
		return '';
	}
	if ( preg_match( '#^<p>(.*)</p>$#s', $trim, $m ) && false === strpos( $m[1], '<p' ) ) {
		return $m[1];
	}
	return $html;
}

/* -------------------------------------------------------------------------
 * Images — value helpers so each partial keeps its own verbatim <img> tag
 * ---------------------------------------------------------------------- */

/**
 * URL for an attachment-ID image field.
 *
 * @param mixed  $id   Attachment ID (image fields all use return_format: id).
 * @param string $size Registered image size.
 * @return string URL or '' when unset/missing.
 */
function vst_image_url( $id, $size = 'full' ) {
	$id = absint( $id );
	if ( ! $id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, $size );
	return $url ? $url : '';
}

/**
 * Alt text for an attachment-ID image field, falling back to the attachment title.
 *
 * @param mixed  $id       Attachment ID.
 * @param string $fallback Used when the attachment has no alt text at all.
 * @return string Alt text (unescaped — escape at the point of output).
 */
function vst_image_alt( $id, $fallback = '' ) {
	$id = absint( $id );
	if ( ! $id ) {
		return $fallback;
	}
	$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
	if ( '' === trim( (string) $alt ) ) {
		$alt = get_the_title( $id );
	}
	return '' === trim( (string) $alt ) ? $fallback : (string) $alt;
}

/**
 * Optional <picture>/<source> opener for a Media Block clone that has a mobile image.
 *
 * Emits nothing (so the transplanted single <img> stands alone, exactly as in the
 * source) unless the editor uploaded a separate mobile image.
 *
 * @param mixed  $mobile_id Attachment ID of the mobile image.
 * @param string $media     Media query for the mobile source.
 * @return string HTML.
 */
function vst_picture_open_html( $mobile_id, $media = '(max-width: 640px)' ) {
	$url = vst_image_url( $mobile_id );
	if ( '' === $url ) {
		return '';
	}
	return '<picture><source media="' . esc_attr( $media ) . '" srcset="' . esc_url( $url ) . '">';
}

/**
 * Closer for vst_picture_open_html().
 *
 * @param mixed $mobile_id Attachment ID of the mobile image.
 * @return string HTML.
 */
function vst_picture_close_html( $mobile_id ) {
	return '' === vst_image_url( $mobile_id ) ? '' : '</picture>';
}

/* -------------------------------------------------------------------------
 * Links / buttons — value helpers (the <a> markup itself stays in the partial)
 * ---------------------------------------------------------------------- */

/**
 * URL of an ACF link field (return_format: array).
 *
 * @param mixed $link Link field value.
 * @return string URL or ''.
 */
function vst_link_url( $link ) {
	return ( is_array( $link ) && ! empty( $link['url'] ) ) ? (string) $link['url'] : '';
}

/**
 * Label of an ACF link field.
 *
 * @param mixed  $link     Link field value.
 * @param string $fallback Label to use when the editor left the title empty.
 * @return string Label (unescaped).
 */
function vst_link_label( $link, $fallback = '' ) {
	return ( is_array( $link ) && ! empty( $link['title'] ) ) ? (string) $link['title'] : $fallback;
}

/**
 * Target of an ACF link field.
 *
 * The source markup for every CTA in this design is
 * `target="_blank" rel="noopener"`, so the default keeps byte-parity with the source
 * when the editor does not tick "open in new tab".
 *
 * @param mixed  $link    Link field value.
 * @param string $default Default target.
 * @return string Target value.
 */
function vst_link_target( $link, $default = '_blank' ) {
	if ( is_array( $link ) && ! empty( $link['target'] ) ) {
		return (string) $link['target'];
	}
	return $default;
}

/* -------------------------------------------------------------------------
 * Section settings (the common_settings clone)
 * ---------------------------------------------------------------------- */

/**
 * True when the current flexible-content row is flagged hidden.
 *
 * `hide_section` is a checkbox (return_format: value, single choice `hidden`), so the
 * stored value is an array.
 *
 * @return bool
 */
function vst_section_hidden() {
	$hide = get_sub_field( 'hide_section' );
	return in_array( 'hidden', (array) $hide, true );
}

/**
 * Whitelist a colour value coming from a color_picker field (return_format: string).
 *
 * @param mixed $value Raw value.
 * @return string Safe CSS colour or ''.
 */
function vst_safe_color( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
		return $value;
	}
	if ( preg_match( '/^rgba?\(\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*(?:,\s*(?:0|1|0?\.[0-9]+)\s*)?\)$/i', $value ) ) {
		return $value;
	}
	return '';
}

/**
 * Whitelist a spacing value coming from a number field.
 *
 * @param mixed $value Raw value.
 * @return string Numeric string or '' when the field is empty (0 IS a valid override).
 */
function vst_safe_spacing( $value ) {
	if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
		return '';
	}
	return (string) ( 0 + $value );
}

/**
 * Build the opening-tag attributes + optional scoped CSS for a section partial.
 *
 * TRANSPLANT CONTRACT — this is the only helper that replaces source markup, because
 * the common_settings clone exists precisely to override it:
 *   - `id`      : the source's hardcoded id (#what, #room, #schedule, #speakers,
 *                 #calendar) is replaced by common_settings.sec_id. Empty => NO id
 *                 attribute at all (never a hardcoded fallback).
 *   - `class`   : the source's classes are passed in verbatim via $args['class'] and
 *                 always emitted first, in source order; sec_classes is appended.
 *   - overrides : colours + per-breakpoint padding are emitted as a scoped <style>
 *                 block keyed on a generated instance class, and ONLY for the values
 *                 the editor actually set. With everything empty the returned 'css' is
 *                 '' and the attribute string is byte-equivalent to the source's — the
 *                 transplanted design CSS wins untouched.
 *   - data-section-id : the FC layout name, for QA/JS targeting.
 *
 * Breakpoints follow project-config.json ([375, 640, 1024, 1920]) and the source CSS's
 * real media queries (max-width:640 / max-width:1024 / min-width:1025).
 *
 * @param array $args {
 *     @type string $class       Source classes, verbatim (e.g. 'sec', 'hero', 'ticker').
 *     @type string $layout      FC layout name for data-section-id.
 *     @type string $extra_attrs Pre-escaped extra attributes copied from the source tag.
 * }
 * @return array{attrs:string,css:string} 'attrs' is a pre-escaped attribute string
 *                                        (leading space included); 'css' is '' or a
 *                                        complete <style> element.
 */
function vst_section_setup( $args = array() ) {
	static $instance = 0;

	$args = array_merge(
		array(
			'class'       => '',
			'layout'      => '',
			'extra_attrs' => '',
		),
		$args
	);

	++$instance;
	$scope = 'vst-sec-' . $instance;

	$classes = array();
	if ( '' !== $args['class'] ) {
		$classes[] = $args['class'];
	}

	$editor_classes = trim( (string) get_sub_field( 'sec_classes' ) );
	if ( '' !== $editor_classes ) {
		$classes[] = $editor_classes;
	}

	// --- colour overrides -------------------------------------------------
	$rules   = array();
	$bg      = vst_safe_color( get_sub_field( 'sec_bg_color' ) );
	$text    = vst_safe_color( get_sub_field( 'section_text_color' ) );
	$heads   = vst_safe_color( get_sub_field( 'section_headings_color' ) );
	$btn_bg  = vst_safe_color( get_sub_field( 'section_button_bg_color' ) );
	$btn_col = vst_safe_color( get_sub_field( 'section_button_color' ) );

	$self = array();
	if ( '' !== $bg ) {
		$self[] = 'background-color:' . $bg;
	}
	if ( '' !== $text ) {
		$self[] = 'color:' . $text;
	}
	if ( $self ) {
		$rules[] = '.' . $scope . '{' . implode( ';', $self ) . '}';
	}
	if ( '' !== $heads ) {
		$sel = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$sel[] = '.' . $scope . ' ' . $tag;
		}
		$rules[] = implode( ',', $sel ) . '{color:' . $heads . '}';
	}
	if ( '' !== $btn_bg || '' !== $btn_col ) {
		$decl = array();
		if ( '' !== $btn_bg ) {
			$decl[] = 'background:' . $btn_bg;
		}
		if ( '' !== $btn_col ) {
			$decl[] = 'color:' . $btn_col;
		}
		$rules[] = '.' . $scope . ' .btn{' . implode( ';', $decl ) . '}';
	}

	// --- per-breakpoint padding overrides ---------------------------------
	$queries = array(
		'mobile'      => '(max-width: 640px)',
		'tablet'      => '(min-width: 641px) and (max-width: 1024px)',
		'desktop'     => '(min-width: 1025px)',
		'big_desktop' => '(min-width: 1920px)',
	);
	foreach ( $queries as $bp => $query ) {
		$decl = array();
		$top  = vst_safe_spacing( get_sub_field( 'sec_top_' . $bp ) );
		$btm  = vst_safe_spacing( get_sub_field( 'sec_btm_' . $bp ) );
		if ( '' !== $top ) {
			$decl[] = 'padding-top:' . $top . 'px';
		}
		if ( '' !== $btm ) {
			$decl[] = 'padding-bottom:' . $btm . 'px';
		}
		if ( $decl ) {
			$rules[] = '@media ' . $query . '{.' . $scope . '{' . implode( ';', $decl ) . '}}';
		}
	}

	$css = '';
	if ( $rules ) {
		$classes[] = $scope;
		$css       = '<style>' . implode( '', $rules ) . '</style>';
	}

	// --- assemble the attribute string (source order: class, then id) -----
	$attrs = '';
	if ( $classes ) {
		$attrs .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
	}

	$sec_id = sanitize_html_class( (string) get_sub_field( 'sec_id' ) );
	if ( '' !== $sec_id ) {
		$attrs .= ' id="' . esc_attr( $sec_id ) . '"';
	}

	if ( '' !== $args['extra_attrs'] ) {
		$attrs .= ' ' . $args['extra_attrs'];
	}

	if ( '' !== $args['layout'] ) {
		$attrs .= ' data-section-id="' . esc_attr( $args['layout'] ) . '"';
	}

	return array(
		'attrs' => $attrs,
		'css'   => $css,
	);
}

/* -------------------------------------------------------------------------
 * Media Block clone (media_type / video_type / custom_video / third_party_url)
 * ---------------------------------------------------------------------- */

/**
 * Render the VIDEO branch of a Media Block clone.
 *
 * The source fragments only ever ship an <img>, so the image branch stays verbatim in
 * each partial and this helper is used only in the `media_type === 'video'` else-branch.
 * Markup emitted here introduces no tag the fragment census depends on.
 *
 * @param array $media {
 *     @type string $video_type          'custom' | 'third_party'.
 *     @type string $custom_video        File URL (desktop).
 *     @type string $custom_video_mobile File URL (mobile).
 *     @type string $third_party_url     YouTube/Vimeo URL.
 *     @type int    $poster              Attachment ID used as the video poster.
 *     @type string $label               Accessible label / iframe title.
 * }
 * @return void
 */
function vst_media_video_html( $media = array() ) {
	$media = array_merge(
		array(
			'video_type'          => 'custom',
			'custom_video'        => '',
			'custom_video_mobile' => '',
			'third_party_url'     => '',
			'poster'              => 0,
			'label'               => '',
		),
		$media
	);

	if ( 'third_party' === $media['video_type'] ) {
		if ( '' === $media['third_party_url'] ) {
			return;
		}
		printf(
			'<iframe class="vst-embed" src="%1$s" title="%2$s" loading="lazy" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>',
			esc_url( $media['third_party_url'] ),
			esc_attr( $media['label'] )
		);
		return;
	}

	$desktop = (string) $media['custom_video'];
	$mobile  = (string) $media['custom_video_mobile'];
	$src     = ( '' !== $desktop ) ? $desktop : $mobile;
	if ( '' === $src ) {
		return;
	}
	$poster = vst_image_url( $media['poster'] );

	echo '<video class="vst-video" playsinline controls preload="metadata"';
	if ( '' !== $poster ) {
		echo ' poster="' . esc_url( $poster ) . '"';
	}
	if ( '' !== $media['label'] ) {
		echo ' aria-label="' . esc_attr( $media['label'] ) . '"';
	}
	echo '>';
	if ( '' !== $mobile && '' !== $desktop ) {
		echo '<source src="' . esc_url( $mobile ) . '" media="(max-width: 640px)">';
	}
	echo '<source src="' . esc_url( $src ) . '">';
	echo '</video>';
}
