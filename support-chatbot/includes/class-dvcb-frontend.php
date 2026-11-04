<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DVCB_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	public static function should_render() {
		if ( is_admin() || is_feed() || wp_doing_ajax() ) {
			return false;
		}
		if ( ! DVCB_Settings::get( 'enabled' ) ) {
			return false;
		}

		$excluded = array_filter( array_map( 'absint', explode( ',', (string) DVCB_Settings::get( 'exclude_ids' ) ) ) );
		if ( $excluded ) {
			$current = (int) get_queried_object_id();
			if ( $current && in_array( $current, $excluded, true ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'dvcb_should_render', true );
	}

	private static function version( $relative ) {
		$file = DVCB_DIR . $relative;
		return file_exists( $file ) ? (string) filemtime( $file ) : DVCB_VERSION;
	}

	public static function enqueue() {
		if ( ! self::should_render() ) {
			return;
		}

		wp_enqueue_style( 'dvcb-chatbot', DVCB_URL . 'assets/css/chatbot.css', array(), self::version( 'assets/css/chatbot.css' ) );
		wp_enqueue_script(
			'dvcb-chatbot',
			DVCB_URL . 'assets/js/chatbot.js',
			array(),
			self::version( 'assets/js/chatbot.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'dvcb-chatbot', 'window.DVCB_CONFIG = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Everything the widget needs, so it never has to make a request just to
	 * open.
	 */
	private static function config() {
		$d = 'support-chatbot';
		$s = DVCB_Settings::all();

		return array(
			'rest'         => esc_url_raw( rest_url( 'dvcb/v1/' ) ),
			'name'         => $s['bot_name'],
			'subtitle'     => $s['bot_subtitle'],
			'greeting'     => $s['greeting'],
			'placeholder'  => $s['placeholder'],
			'handoffLabel'  => $s['handoff_button'],
			'handoffPrompt' => $s['handoff_prompt'],
			'chips'        => DVCB_Store::starter_chips( $s['chips_limit'] ),
			'mode'         => $s['theme_mode'],
			'l10n'         => array(
				'open'         => __( 'Open chat', $d ),
				'close'        => __( 'Close chat', $d ),
				'send'         => __( 'Send message', $d ),
				'typing'       => __( 'Typing', $d ),
				'helpful'      => __( 'Was this helpful?', $d ),
				'yes'          => __( 'Yes', $d ),
				'no'           => __( 'No', $d ),
				'thanksFb'     => __( 'Thanks for the feedback!', $d ),
				'sorry'        => __( 'Sorry about that. Would you like to talk to a person?', $d ),
				'error'        => __( 'Something went wrong. Please try again.', $d ),
				'offline'      => __( "I can't reach the server right now. Please check your connection and try again.", $d ),
				'nameLabel'    => __( 'Your name', $d ),
				'contactLabel' => __( 'Email or phone', $d ),
				'messageLabel' => __( 'Message (optional)', $d ),
				'submit'       => __( 'Send', $d ),
				'sending'      => __( 'Sending…', $d ),
				'errName'      => __( 'Please enter your name.', $d ),
				'errContact'   => __( 'Please enter a valid email address or phone number.', $d ),
				'chatLog'      => __( 'Chat messages', $d ),
				'suggestions'  => __( 'Suggested questions', $d ),
			),
		);
	}

	public static function render() {
		if ( ! self::should_render() ) {
			return;
		}

		$accent = sanitize_hex_color( DVCB_Settings::get( 'accent' ) );
		$accent = $accent ? $accent : '#2563EB';
		$side   = 'left' === DVCB_Settings::get( 'position' ) ? 'left' : 'right';

		$style = sprintf(
			'--dvcb-accent:%1$s;--dvcb-on-accent:%2$s;--dvcb-bottom:%3$dpx;--dvcb-side:%4$dpx;',
			$accent,
			DVCB_Util::on_colour( $accent ),
			(int) DVCB_Settings::get( 'offset_bottom' ),
			(int) DVCB_Settings::get( 'offset_side' )
		);

		printf(
			'<div id="dvcb-root" class="dvcb" data-position="%1$s" style="%2$s"></div>',
			esc_attr( $side ),
			esc_attr( $style )
		);
	}
}
