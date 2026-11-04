<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers. Nothing here touches the database.
 */
class DVCB_Util {

	/* ---------- string helpers (mbstring is common but not guaranteed) --- */

	public static function lower( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	public static function len( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	public static function sub( $s, $start, $length = null ) {
		$s = (string) $s;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, $start, $length, 'UTF-8' );
		}
		return null === $length ? substr( $s, $start ) : substr( $s, $start, $length );
	}

	public static function is_ascii( $s ) {
		return (bool) preg_match( '/^[\x00-\x7F]*$/', (string) $s );
	}

	/**
	 * Visitor-supplied single-line text: strip tags, collapse whitespace,
	 * cap the length.
	 */
	public static function clean_line( $value, $max = 500 ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( (string) $value );
		return self::sub( $value, 0, $max );
	}

	/**
	 * Visitor-supplied multi-line text (handoff message): keep line breaks.
	 */
	public static function clean_block( $value, $max = 1500 ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_strip_all_tags( $value );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( "/\n{3,}/", "\n\n", $value );
		$value = trim( (string) $value );
		return self::sub( $value, 0, $max );
	}

	/* ---------- validation ------------------------------------------------ */

	/**
	 * Format-agnostic phone check: people type spaces, dashes, brackets and
	 * country prefixes, so only the digit count is checked. 7 is about the
	 * shortest real national number; 15 is the E.164 maximum.
	 */
	public static function is_valid_phone( $value ) {
		$digits = preg_replace( '/\D/', '', (string) $value );
		$length = strlen( (string) $digits );
		return ( $length >= 7 && $length <= 15 );
	}

	public static function is_valid_contact( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}
		return (bool) is_email( $value ) || self::is_valid_phone( $value );
	}

	/* ---------- URLs ------------------------------------------------------ */

	/**
	 * Admin-entered button/link URL -> something safe to put in an href.
	 *
	 *  - "#contact-form" stays as an in-page anchor
	 *  - "/services/"    resolves against home_url(), so it keeps working on
	 *                    subdirectory installs and after a domain change
	 *  - anything else   goes through esc_url_raw() (which also drops
	 *                    disallowed protocols such as javascript:)
	 */
	public static function resolve_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( '#' === $url[0] ) {
			return '#' . preg_replace( '/[^A-Za-z0-9_\-:.]/', '', substr( $url, 1 ) );
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return esc_url_raw( home_url( $url ) );
		}
		return esc_url_raw( $url );
	}

	/**
	 * The small HTML subset allowed in admin-authored answers.
	 */
	public static function allowed_html() {
		return array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'code'   => array(),
		);
	}

	/* ---------- abuse control --------------------------------------------- */

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// X-Forwarded-For is client-controlled unless a trusted proxy sets
		// it, so it's only used if a site opts in through this filter.
		return (string) apply_filters( 'dvcb_client_ip', $ip );
	}

	/**
	 * Fixed-window rate limit per visitor, no database table needed.
	 *
	 * @return bool True when the request may proceed.
	 */
	public static function rate_limit( $bucket, $limit, $window ) {
		$limit  = max( 1, (int) $limit );
		$window = max( 1, (int) $window );
		$key    = 'dvcb_rl_' . preg_replace( '/[^a-z_]/', '', (string) $bucket ) . '_' . substr( md5( self::client_ip() . wp_salt() ), 0, 20 );
		$now    = time();
		$data   = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data[0], $data[1] ) || ( $now - (int) $data[1] ) >= $window ) {
			$data = array( 0, $now );
		}
		if ( (int) $data[0] >= $limit ) {
			return false;
		}
		$data[0] = (int) $data[0] + 1;
		set_transient( $key, $data, $window * 2 );
		return true;
	}

	/* ---------- colour ---------------------------------------------------- */

	/**
	 * Readable text colour (dark or white) for a given hex background.
	 */
	public static function on_colour( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return '#06121B';
		}
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		$lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		return $lum > 0.55 ? '#06121B' : '#FFFFFF';
	}
}
