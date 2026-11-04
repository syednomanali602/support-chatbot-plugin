<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handoff requests store a name and an email/phone, so they take part in
 * WordPress's Tools > Export / Erase Personal Data. Looked up by the
 * visitor's email address (a phone number can't be matched to a WordPress
 * account, so those are removed manually from Handoff requests).
 */
class DVCB_Privacy {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<h3>' . esc_html__( 'Support chatbot', 'support-chatbot' ) . '</h3>';
		$text .= '<p>' . esc_html__( 'Our chat assistant answers questions from a list of FAQs on this site; messages are not sent to any third-party AI service. If question logging is enabled, the text of questions you type is stored without your IP address so we can improve our answers, and is deleted automatically after a set period.', 'support-chatbot' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you ask to speak to a person, we store the name, email address or phone number, message and page you were on that you submit, and recent chat messages for context, so we can reply to you.', 'support-chatbot' ) . '</p>';
		wp_add_privacy_policy_content( 'Support Chatbot', wp_kses_post( $text ) );
	}

	private static function requests_for( $email ) {
		return get_posts(
			array(
				'post_type'   => DVCB_Store::HANDOFF,
				'post_status' => 'any',
				'numberposts' => 200,
				'meta_query'  => array(
					array(
						'key'     => '_dvcb_contact',
						'value'   => $email,
						'compare' => '=',
					),
				),
			)
		);
	}

	public static function register_exporter( $exporters ) {
		$exporters['support-chatbot'] = array(
			'exporter_friendly_name' => __( 'Support chatbot requests', 'support-chatbot' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function export( $email, $page = 1 ) {
		$items = array();
		foreach ( self::requests_for( $email ) as $post ) {
			$items[] = array(
				'group_id'    => 'dvcb-requests',
				'group_label' => __( 'Support chatbot requests', 'support-chatbot' ),
				'item_id'     => 'dvcb-request-' . $post->ID,
				'data'        => array(
					array(
						'name'  => __( 'Name', 'support-chatbot' ),
						'value' => get_post_meta( $post->ID, '_dvcb_name', true ),
					),
					array(
						'name'  => __( 'Contact', 'support-chatbot' ),
						'value' => get_post_meta( $post->ID, '_dvcb_contact', true ),
					),
					array(
						'name'  => __( 'Message', 'support-chatbot' ),
						'value' => get_post_meta( $post->ID, '_dvcb_message', true ),
					),
					array(
						'name'  => __( 'Page', 'support-chatbot' ),
						'value' => get_post_meta( $post->ID, '_dvcb_page', true ),
					),
					array(
						'name'  => __( 'Date', 'support-chatbot' ),
						'value' => $post->post_date,
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function register_eraser( $erasers ) {
		$erasers['support-chatbot'] = array(
			'eraser_friendly_name' => __( 'Support chatbot requests', 'support-chatbot' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function erase( $email, $page = 1 ) {
		$removed = 0;
		foreach ( self::requests_for( $email ) as $post ) {
			if ( wp_delete_post( $post->ID, true ) ) {
				$removed++;
			}
		}
		delete_transient( DVCB_Store::NEW_COUNT_KEY );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
