<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One schema drives the defaults, the settings screen and the sanitiser, so
 * the three can never drift apart.
 */
class DVCB_Settings {

	const OPTION = 'dvcb_settings';

	private static $cache = null;

	/**
	 * Built lazily (not as a constant) so labels go through translation
	 * after the text domain has loaded.
	 */
	public static function schema() {
		$d = 'support-chatbot';

		return array(
			'general'  => array(
				'title'  => __( 'General', $d ),
				'fields' => array(
					'enabled'       => array(
						'type'    => 'toggle',
						'label'   => __( 'Show the chatbot', $d ),
						'default' => 1,
					),
					'exclude_ids'   => array(
						'type'    => 'ids',
						'label'   => __( 'Hide on these pages', $d ),
						'desc'    => __( 'Comma-separated page/post IDs, e.g. 12, 48.', $d ),
						'default' => '',
					),
					'position'      => array(
						'type'    => 'select',
						'label'   => __( 'Side of the screen', $d ),
						'options' => array(
							'right' => __( 'Right', $d ),
							'left'  => __( 'Left', $d ),
						),
						'default' => 'right',
					),
					'offset_bottom' => array(
						'type'    => 'number',
						'label'   => __( 'Distance from bottom (px)', $d ),
						'desc'    => __( 'Raise this to sit above another floating button, such as a chat or WhatsApp button.', $d ),
						'min'     => 0,
						'max'     => 400,
						'default' => 100,
					),
					'offset_side'   => array(
						'type'    => 'number',
						'label'   => __( 'Distance from the side (px)', $d ),
						'min'     => 0,
						'max'     => 200,
						'default' => 28,
					),
					'accent'        => array(
						'type'    => 'color',
						'label'   => __( 'Accent colour', $d ),
						'default' => '#2563EB',
					),
					'theme_mode'    => array(
						'type'    => 'select',
						'label'   => __( 'Colour mode', $d ),
						'desc'    => __( '"Follow site" switches with a site light/dark toggle that adds an "light" class to the <html> element.', $d ),
						'options' => array(
							'auto'  => __( 'Follow site', $d ),
							'dark'  => __( 'Always dark', $d ),
							'light' => __( 'Always light', $d ),
						),
						'default' => 'auto',
					),
				),
			),
			'messages' => array(
				'title'  => __( 'Messages', $d ),
				'fields' => array(
					'bot_name'      => array(
						'type'    => 'text',
						'label'   => __( 'Chat title', $d ),
						'default' => __( 'Support', $d ),
					),
					'bot_subtitle'  => array(
						'type'    => 'text',
						'label'   => __( 'Subtitle', $d ),
						'desc'    => __( 'Keep this honest: the bot answers from your FAQs, it is not generative AI.', $d ),
						'default' => __( 'Support assistant', $d ),
					),
					'greeting'      => array(
						'type'    => 'textarea',
						'label'   => __( 'Greeting', $d ),
						'default' => __( "Hi! I'm the support assistant. Ask me about our services, pricing or how we work, or ask for a person.", $d ),
					),
					'placeholder'   => array(
						'type'    => 'text',
						'label'   => __( 'Input placeholder', $d ),
						'default' => __( 'Type your message…', $d ),
					),
					'suggest_intro' => array(
						'type'    => 'text',
						'label'   => __( 'Intro when offering suggestions', $d ),
						'default' => __( 'I\'m not sure which you mean. Did you mean one of these?', $d ),
					),
					'fallback'      => array(
						'type'    => 'textarea',
						'label'   => __( 'When there is no good answer', $d ),
						'default' => __( "I couldn't find a good answer to that. Would you like to talk to a person?", $d ),
					),
					'thanks_reply'  => array(
						'type'    => 'text',
						'label'   => __( 'Reply to "thanks"', $d ),
						'default' => __( "You're welcome! Anything else I can help with?", $d ),
					),
					'bye_reply'     => array(
						'type'    => 'text',
						'label'   => __( 'Reply to "bye"', $d ),
						'default' => __( 'Thanks for stopping by!', $d ),
					),
				),
			),
			'matching' => array(
				'title'  => __( 'Matching', $d ),
				'fields' => array(
					'confident'      => array(
						'type'    => 'number',
						'label'   => __( 'Answer directly at or above (%)', $d ),
						'desc'    => __( 'Higher = fewer wrong answers but more "did you mean". Use the Dashboard tester to tune this.', $d ),
						'min'     => 30,
						'max'     => 95,
						'default' => 60,
					),
					'suggest_min'    => array(
						'type'    => 'number',
						'label'   => __( 'Offer suggestions at or above (%)', $d ),
						'desc'    => __( 'Below this the bot says it has no good answer.', $d ),
						'min'     => 10,
						'max'     => 90,
						'default' => 30,
					),
					'chips_limit'    => array(
						'type'    => 'number',
						'label'   => __( 'Starter questions shown', $d ),
						'desc'    => __( 'Tick "Show as a starter question" on an FAQ to include it. 0 hides them.', $d ),
						'min'     => 0,
						'max'     => 8,
						'default' => 4,
					),
					'human_keywords' => array(
						'type'    => 'textarea',
						'label'   => __( 'Phrases that ask for a person', $d ),
						'desc'    => __( 'One per line. If a message contains one, the bot goes straight to the handoff form.', $d ),
						'default' => "a human\nreal human\nreal person\nlive agent\nspeak to someone\ntalk to someone\ntalk to a person\nspeak to a person\ncustomer support\ncall me\ninsaan\nkisi se baat\nbaat karni\nbaat karna",
					),
					'rate_ask'       => array(
						'type'    => 'number',
						'label'   => __( 'Max messages per visitor per minute', $d ),
						'min'     => 5,
						'max'     => 200,
						'default' => 40,
					),
				),
			),
			'handoff'  => array(
				'title'  => __( 'Talk to a person', $d ),
				'fields' => array(
					'handoff_button'  => array(
						'type'    => 'text',
						'label'   => __( 'Button label', $d ),
						'default' => __( 'Talk to a person', $d ),
					),
					'handoff_prompt'  => array(
						'type'    => 'textarea',
						'label'   => __( 'Message above the form', $d ),
						'default' => __( 'Leave your details and our team will get back to you.', $d ),
					),
					'handoff_success' => array(
						'type'    => 'textarea',
						'label'   => __( 'Message after sending', $d ),
						'default' => __( "Thanks! We've got your message and will reply soon.", $d ),
					),
					'recipient'       => array(
						'type'    => 'email',
						'label'   => __( 'Send requests to', $d ),
						'desc'    => __( 'Leave blank to use the site admin email.', $d ),
						'default' => '',
					),
					'notify'          => array(
						'type'    => 'toggle',
						'label'   => __( 'Email me when someone asks for a person', $d ),
						'default' => 1,
					),
					'rate_handoff'    => array(
						'type'    => 'number',
						'label'   => __( 'Max handoff requests per visitor per 10 minutes', $d ),
						'min'     => 1,
						'max'     => 20,
						'default' => 3,
					),
				),
			),
			'data'     => array(
				'title'  => __( 'Data & privacy', $d ),
				'fields' => array(
					'log_enabled'         => array(
						'type'    => 'toggle',
						'label'   => __( 'Keep a log of questions asked', $d ),
						'desc'    => __( 'Powers the Unanswered list and Dashboard stats. Stores the text of the question only, no IP address.', $d ),
						'default' => 1,
					),
					'log_retention'       => array(
						'type'    => 'number',
						'label'   => __( 'Delete the question log after (days)', $d ),
						'min'     => 7,
						'max'     => 730,
						'default' => 90,
					),
					'handoff_retention'   => array(
						'type'    => 'number',
						'label'   => __( 'Delete handoff requests after (days)', $d ),
						'desc'    => __( '0 keeps them until you delete them.', $d ),
						'min'     => 0,
						'max'     => 3650,
						'default' => 0,
					),
					'delete_on_uninstall' => array(
						'type'    => 'toggle',
						'label'   => __( 'Delete all chatbot data when the plugin is deleted', $d ),
						'default' => 0,
					),
				),
			),
		);
	}

	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$out[ $key ] = $field['default'];
			}
		}
		return $out;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			self::$cache = array_merge( self::defaults(), $saved );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * register_setting() sanitise callback.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( self::schema() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$raw = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : null;

				switch ( $field['type'] ) {
					case 'toggle':
						$clean[ $key ] = empty( $raw ) ? 0 : 1;
						break;
					case 'textarea':
						$clean[ $key ] = null === $raw ? $field['default'] : sanitize_textarea_field( $raw );
						break;
					case 'email':
						$email         = null === $raw ? '' : sanitize_email( $raw );
						$clean[ $key ] = is_email( $email ) ? $email : '';
						break;
					case 'number':
						$value         = null === $raw ? $field['default'] : (int) $raw;
						$clean[ $key ] = max( $field['min'], min( $field['max'], $value ) );
						break;
					case 'select':
						$clean[ $key ] = ( null !== $raw && isset( $field['options'][ $raw ] ) ) ? $raw : $field['default'];
						break;
					case 'color':
						$hex           = null === $raw ? '' : sanitize_hex_color( $raw );
						$clean[ $key ] = $hex ? $hex : $field['default'];
						break;
					case 'ids':
						$ids           = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $raw ) ) );
						$clean[ $key ] = implode( ',', array_unique( $ids ) );
						break;
					default:
						$clean[ $key ] = null === $raw ? $field['default'] : sanitize_text_field( $raw );
				}
			}
		}

		// Suggestions can't start above the direct-answer line.
		if ( $clean['suggest_min'] >= $clean['confident'] ) {
			$clean['suggest_min'] = max( 10, $clean['confident'] - 10 );
		}

		self::flush_cache();
		return $clean;
	}
}
