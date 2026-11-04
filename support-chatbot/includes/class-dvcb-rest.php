<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public routes (ask, feedback, handoff) deliberately do NOT use a WordPress
 * nonce. A nonce baked into a page-cached HTML file goes stale and the
 * chatbot would silently stop working for everyone served that copy — the
 * most common way chat widgets break on cached sites. Abuse is handled with
 * per-visitor rate limits, a honeypot, strict length caps and validation
 * instead. The admin tester route is logged-in only and does use a nonce.
 */
class DVCB_REST {

	const NS = 'dvcb/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ask' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'feedback' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/handoff',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handoff' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/admin/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_test' ),
				'permission_callback' => function () {
					return current_user_can( DVCB_Admin::cap() );
				},
			)
		);
	}

	/* ------------------------------------------------------------------ */

	private static function params( WP_REST_Request $req ) {
		$json = $req->get_json_params();
		return is_array( $json ) ? $json : (array) $req->get_params();
	}

	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function reply( $data ) {
		$response = rest_ensure_response( $data );
		// These are per-visitor answers; never let a CDN or proxy reuse them.
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function disabled() {
		return DVCB_Settings::get( 'enabled' ) ? null : self::error( 'dvcb_disabled', __( 'The chatbot is switched off.', 'support-chatbot' ), 503 );
	}

	/* ------------------------------------------------------------------ */

	public static function ask( WP_REST_Request $req ) {
		if ( $off = self::disabled() ) {
			return $off;
		}
		if ( ! DVCB_Util::rate_limit( 'ask', DVCB_Settings::get( 'rate_ask' ), 60 ) ) {
			return self::error( 'dvcb_rate', __( 'You are sending messages too quickly. Please wait a moment.', 'support-chatbot' ), 429 );
		}

		$p      = self::params( $req );
		$faq_id = isset( $p['faq_id'] ) ? absint( $p['faq_id'] ) : 0;

		// A click on a starter question or a suggestion names the FAQ
		// directly, so there's nothing to match.
		if ( $faq_id ) {
			$index = DVCB_Store::get_index();
			if ( isset( $index['meta'][ $faq_id ] ) ) {
				DVCB_Store::log( $index['meta'][ $faq_id ]['q'], 'answer', $faq_id, 1.0 );
				return self::reply( DVCB_Bot::answer_payload( $faq_id, $index ) );
			}
		}

		$message = DVCB_Util::clean_line( isset( $p['message'] ) ? $p['message'] : '', 500 );
		if ( '' === $message ) {
			return self::error( 'dvcb_empty', __( 'Please type a message.', 'support-chatbot' ), 400 );
		}

		return self::reply( DVCB_Bot::respond( $message, true ) );
	}

	public static function feedback( WP_REST_Request $req ) {
		if ( $off = self::disabled() ) {
			return $off;
		}
		if ( ! DVCB_Util::rate_limit( 'fb', 30, 60 ) ) {
			return self::error( 'dvcb_rate', __( 'Too many requests.', 'support-chatbot' ), 429 );
		}

		$p      = self::params( $req );
		$faq_id = isset( $p['faq_id'] ) ? absint( $p['faq_id'] ) : 0;
		$up     = ! empty( $p['helpful'] );

		if ( $faq_id && DVCB_Store::FAQ === get_post_type( $faq_id ) && 'publish' === get_post_status( $faq_id ) ) {
			$key = $up ? '_dvcb_up' : '_dvcb_down';
			update_post_meta( $faq_id, $key, (int) get_post_meta( $faq_id, $key, true ) + 1 );
		}
		return self::reply( array( 'ok' => true ) );
	}

	public static function handoff( WP_REST_Request $req ) {
		if ( $off = self::disabled() ) {
			return $off;
		}

		$p       = self::params( $req );
		$success = DVCB_Bot::text_html( DVCB_Settings::get( 'handoff_success' ) );

		// Honeypot: a real visitor never sees or fills this field. Bots get a
		// success response so they have no signal to adapt to, and nothing
		// is stored or emailed.
		if ( ! empty( $p['website'] ) ) {
			return self::reply(
				array(
					'ok'   => true,
					'html' => $success,
				)
			);
		}

		// Two layers. This loose one counts every attempt, valid or not, and
		// only stops hammering. The strict limit further down counts only
		// requests that would really be saved: counting typos against it
		// locked out genuine visitors after fixing a mistyped email twice.
		if ( ! DVCB_Util::rate_limit( 'handoff_try', 20, 600 ) ) {
			return self::error( 'dvcb_rate', __( 'Too many attempts. Please wait a few minutes and try again.', 'support-chatbot' ), 429 );
		}

		$name    = DVCB_Util::clean_line( isset( $p['name'] ) ? $p['name'] : '', 100 );
		$contact = DVCB_Util::clean_line( isset( $p['contact'] ) ? $p['contact'] : '', 120 );
		$message = DVCB_Util::clean_block( isset( $p['message'] ) ? $p['message'] : '', 1500 );
		$page    = esc_url_raw( DVCB_Util::clean_line( isset( $p['page'] ) ? $p['page'] : '', 500 ) );

		if ( '' === $name ) {
			return self::error( 'dvcb_name', __( 'Please enter your name.', 'support-chatbot' ), 400 );
		}
		if ( ! DVCB_Util::is_valid_contact( $contact ) ) {
			return self::error( 'dvcb_contact', __( 'Please enter a valid email address or phone number so we can reply.', 'support-chatbot' ), 400 );
		}

		if ( ! DVCB_Util::rate_limit( 'handoff', DVCB_Settings::get( 'rate_handoff' ), 600 ) ) {
			return self::error( 'dvcb_rate', __( 'You have already sent a few requests. Please wait a few minutes and try again.', 'support-chatbot' ), 429 );
		}

		$data = array(
			'name'       => $name,
			'contact'    => $contact,
			'message'    => $message,
			'page'       => $page,
			'transcript' => self::clean_transcript( isset( $p['transcript'] ) ? $p['transcript'] : array() ),
		);

		$id = DVCB_Store::create_handoff( $data );
		if ( ! $id ) {
			return self::error( 'dvcb_save', __( 'Sorry, we could not save your request. Please try again.', 'support-chatbot' ), 500 );
		}

		if ( DVCB_Settings::get( 'notify' ) ) {
			self::notify( $id, $data );
		}

		/**
		 * Fires after a handoff request is stored. Hook here to push it to a
		 * CRM, Slack, etc.
		 */
		do_action( 'dvcb_handoff_created', $id, $data );

		return self::reply(
			array(
				'ok'   => true,
				'html' => $success,
			)
		);
	}

	/**
	 * The visitor's recent messages, flattened to "Visitor:/Bot:" lines.
	 * Everything is untrusted client input, so it's re-cleaned and capped.
	 */
	private static function clean_transcript( $raw ) {
		if ( ! is_array( $raw ) ) {
			return '';
		}
		$lines = array();
		foreach ( array_slice( $raw, -12 ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['t'] ) ) {
				continue;
			}
			$who     = ( isset( $row['r'] ) && 'u' === $row['r'] ) ? __( 'Visitor', 'support-chatbot' ) : __( 'Bot', 'support-chatbot' );
			$text    = DVCB_Util::clean_line( $row['t'], 400 );
			if ( '' !== $text ) {
				$lines[] = $who . ': ' . $text;
			}
		}
		return DVCB_Util::sub( implode( "\n", $lines ), 0, 4000 );
	}

	private static function notify( $id, array $data ) {
		$to = DVCB_Settings::get( 'recipient' );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( /* translators: 1: site name, 2: visitor name */ __( '[%1$s] Chat request from %2$s', 'support-chatbot' ), $site, $data['name'] );

		$body  = sprintf( "%s: %s\n", __( 'Name', 'support-chatbot' ), $data['name'] );
		$body .= sprintf( "%s: %s\n", __( 'Contact', 'support-chatbot' ), $data['contact'] );
		$body .= sprintf( "%s: %s\n", __( 'Page', 'support-chatbot' ), $data['page'] );
		if ( '' !== $data['message'] ) {
			$body .= "\n" . __( 'Message', 'support-chatbot' ) . ":\n" . $data['message'] . "\n";
		}
		if ( '' !== $data['transcript'] ) {
			$body .= "\n" . __( 'Recent conversation', 'support-chatbot' ) . ":\n" . $data['transcript'] . "\n";
		}
		$body .= "\n" . __( 'Open in WordPress:', 'support-chatbot' ) . ' ' . admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) . "\n";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( is_email( $data['contact'] ) ) {
			// Display name is stripped to plain characters so it can't inject
			// extra header lines.
			$safe_name = trim( preg_replace( '/[^\p{L}\p{N} .\'-]/u', '', $data['name'] ) );
			$headers[] = 'Reply-To: ' . ( '' !== $safe_name ? $safe_name . ' ' : '' ) . '<' . $data['contact'] . '>';
		}

		$error   = '';
		$capture = function ( $wp_error ) use ( &$error ) {
			$error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : 'unknown error';
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $capture );

		update_post_meta( $id, '_dvcb_email_status', $sent ? 'sent' : 'failed' );
		if ( ! $sent ) {
			update_post_meta( $id, '_dvcb_email_error', $error );
			error_log( 'support-chatbot: handoff notification email failed — ' . $error );
		}
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Admin tester: shows what the bot would do and why, without logging.
	 */
	public static function admin_test( WP_REST_Request $req ) {
		$p       = self::params( $req );
		$message = DVCB_Util::clean_line( isset( $p['message'] ) ? $p['message'] : '', 500 );
		if ( '' === $message ) {
			return self::error( 'dvcb_empty', __( 'Type a message to test.', 'support-chatbot' ), 400 );
		}

		$index   = DVCB_Store::get_index();
		$conf    = (int) DVCB_Settings::get( 'confident' ) / 100;
		$sug     = (int) DVCB_Settings::get( 'suggest_min' ) / 100;
		$scores  = DVCB_Matcher::score( $message, $index );
		$payload = DVCB_Bot::respond( $message, false );

		$top = array();
		foreach ( array_slice( $scores, 0, 5, true ) as $id => $score ) {
			$top[] = array(
				'q'     => isset( $index['meta'][ $id ] ) ? $index['meta'][ $id ]['q'] : '#' . $id,
				'score' => round( $score * 100 ),
			);
		}

		return rest_ensure_response(
			array(
				'type'      => $payload['type'],
				'reply'     => wp_strip_all_tags( $payload['html'] ),
				'tokens'    => DVCB_Matcher::tokenize( $message ),
				'top'       => $top,
				'confident' => (int) round( $conf * 100 ),
				'suggest'   => (int) round( $sug * 100 ),
				'handoff'   => DVCB_Bot::wants_human( $message ),
			)
		);
	}
}
