<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a visitor message into a response payload. The public REST route and
 * the admin tester both call respond(), so what an admin sees while tuning is
 * exactly what visitors get.
 */
class DVCB_Bot {

	/**
	 * Plain admin-authored text (greeting, fallback...) -> safe HTML.
	 */
	public static function text_html( $text ) {
		return wpautop( esc_html( (string) $text ) );
	}

	/**
	 * Rich FAQ answer -> sanitised HTML (small allow-list, links get
	 * protocol-checked by kses).
	 */
	public static function answer_html( $text ) {
		return wpautop( wp_kses( (string) $text, DVCB_Util::allowed_html() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Intent                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Does the message ask for a human? Phrase match on normalised text, with
	 * word boundaries, so "a human" doesn't fire on "human resources".
	 */
	public static function wants_human( $message ) {
		$haystack = ' ' . DVCB_Matcher::normalize( $message ) . ' ';
		$lines    = preg_split( '/\R/', (string) DVCB_Settings::get( 'human_keywords' ) );

		foreach ( (array) $lines as $line ) {
			$needle = DVCB_Matcher::normalize( $line );
			if ( '' !== $needle && false !== strpos( $haystack, ' ' . $needle . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Greeting / thanks / goodbye. A message only counts when EVERY word is
	 * small talk, so "hi pricing" is still a pricing question.
	 *
	 * @return string '' | 'greeting' | 'thanks' | 'bye'
	 */
	public static function smalltalk( $message ) {
		$words = array_values( array_filter( explode( ' ', DVCB_Matcher::normalize( $message ) ) ) );
		if ( empty( $words ) || count( $words ) > 6 ) {
			return '';
		}

		$fill = array( 'there', 'team', 'support', 'sir', 'madam', 'bhai', 'everyone', 'again', 'dear', 'so', 'very', 'much', 'a', 'lot', 'you', 'for', 'your', 'the', 'help', 'that', 'great', 'awesome', 'perfect', 'ok', 'okay', 'nice', 'cool', 'good', 'take', 'care', 'see', 'later', 'soon', 'have', 'day', 'o', 'wa' );

		$sets = array(
			'greeting' => array( 'hi', 'hello', 'hey', 'hola', 'salam', 'salaam', 'assalam', 'assalamualaikum', 'assalamu', 'alaikum', 'aoa', 'morning', 'afternoon', 'evening', 'namaste', 'greetings' ),
			'thanks'   => array( 'thanks', 'thank', 'thx', 'ty', 'shukriya', 'shukria', 'jazakallah', 'mehrbani', 'appreciated', 'appreciate' ),
			'bye'      => array( 'bye', 'goodbye', 'cya', 'khuda', 'hafiz', 'allah', 'tata' ),
		);

		foreach ( $sets as $type => $vocab ) {
			$allowed = array_flip( array_merge( $vocab, $fill ) );
			$hit     = false;
			$all     = true;
			foreach ( $words as $w ) {
				if ( ! isset( $allowed[ $w ] ) ) {
					$all = false;
					break;
				}
				if ( in_array( $w, $vocab, true ) ) {
					$hit = true;
				}
			}
			if ( $all && $hit ) {
				return $type;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Responses                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $message Visitor text (already cleaned).
	 * @param bool   $log     False for the admin tester.
	 * @return array Payload for the widget.
	 */
	public static function respond( $message, $log = true ) {
		if ( self::wants_human( $message ) ) {
			if ( $log ) {
				DVCB_Store::log( $message, 'handoff' );
			}
			return array(
				'type' => 'handoff',
				'html' => self::text_html( DVCB_Settings::get( 'handoff_prompt' ) ),
			);
		}

		$talk = self::smalltalk( $message );
		if ( '' !== $talk ) {
			$replies = array(
				'greeting' => __( 'Hi! What would you like to know?', 'support-chatbot' ),
				'thanks'   => DVCB_Settings::get( 'thanks_reply' ),
				'bye'      => DVCB_Settings::get( 'bye_reply' ),
			);
			return array(
				'type' => 'smalltalk',
				'html' => self::text_html( apply_filters( 'dvcb_smalltalk_reply', $replies[ $talk ], $talk ) ),
			);
		}

		$index    = DVCB_Store::get_index();
		$decision = DVCB_Matcher::decide(
			$message,
			$index,
			(int) DVCB_Settings::get( 'confident' ) / 100,
			(int) DVCB_Settings::get( 'suggest_min' ) / 100
		);

		if ( 'answer' === $decision['type'] ) {
			if ( $log ) {
				DVCB_Store::log( $message, 'answer', $decision['faq_id'], $decision['score'] );
			}
			return self::answer_payload( $decision['faq_id'], $index );
		}

		if ( 'suggest' === $decision['type'] ) {
			if ( $log ) {
				DVCB_Store::log( $message, 'suggest', 0, $decision['score'] );
			}
			$suggestions = array();
			foreach ( $decision['candidates'] as $cand ) {
				if ( isset( $index['meta'][ $cand[0] ] ) ) {
					$suggestions[] = array(
						'id' => $cand[0],
						'q'  => $index['meta'][ $cand[0] ]['q'],
					);
				}
			}
			return array(
				'type'        => 'suggest',
				'html'        => self::text_html( DVCB_Settings::get( 'suggest_intro' ) ),
				'suggestions' => $suggestions,
			);
		}

		if ( $log ) {
			DVCB_Store::log( $message, 'fallback', 0, $decision['score'] );
		}
		return array(
			'type'          => 'fallback',
			'html'          => self::text_html( DVCB_Settings::get( 'fallback' ) ),
			'offer_handoff' => true,
		);
	}

	/**
	 * A specific FAQ's answer (used for direct matches and when a visitor
	 * clicks a starter question or a suggestion).
	 */
	public static function answer_payload( $faq_id, array $index ) {
		$m = isset( $index['meta'][ $faq_id ] ) ? $index['meta'][ $faq_id ] : null;
		if ( ! $m ) {
			return array(
				'type'          => 'fallback',
				'html'          => self::text_html( DVCB_Settings::get( 'fallback' ) ),
				'offer_handoff' => true,
			);
		}

		$action = null;
		$url    = DVCB_Util::resolve_url( $m['btn_u'] );
		$label  = DVCB_Util::clean_line( $m['btn_l'], 60 );
		if ( '' !== $url && '' !== $label ) {
			$action = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		return array(
			'type'   => 'answer',
			'faq_id' => (int) $faq_id,
			'html'   => self::answer_html( $m['a'] ),
			'action' => $action,
		);
	}
}
