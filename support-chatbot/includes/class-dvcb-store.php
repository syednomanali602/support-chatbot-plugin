<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that touches the database: the FAQ and handoff post types, the
 * question log table, the cached match index, first-run seeding, retention.
 */
class DVCB_Store {

	const FAQ           = 'dvcb_faq';
	const HANDOFF       = 'dvcb_handoff';
	const DB_VERSION    = '1';
	const INDEX_KEY     = 'dvcb_index';
	const INDEX_VERSION = 1;
	const NEW_COUNT_KEY = 'dvcb_new_handoffs';
	const CRON          = 'dvcb_daily_purge';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );

		// Any change to an FAQ invalidates the cached match index. Priority 99
		// so the metabox save (priority 10) has already written its meta.
		add_action( 'save_post_' . self::FAQ, array( __CLASS__, 'flush_index' ), 99 );
		add_action( 'deleted_post', array( __CLASS__, 'flush_index' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush_index' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush_index' ) );

		add_action( self::CRON, array( __CLASS__, 'purge' ) );

		add_action( 'update_option_' . DVCB_Settings::OPTION, array( 'DVCB_Settings', 'flush_cache' ) );
		add_action( 'add_option_' . DVCB_Settings::OPTION, array( 'DVCB_Settings', 'flush_cache' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dvcb_log';
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	public static function activate() {
		self::register_types();
		self::create_table();

		if ( false === get_option( DVCB_Settings::OPTION, false ) ) {
			add_option( DVCB_Settings::OPTION, DVCB_Settings::defaults() );
		}

		self::seed();
		self::schedule();
		set_transient( 'dvcb_welcome', 1, 10 * MINUTE_IN_SECONDS );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'dvcb_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
		}
		self::schedule();
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	private static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, one column per line.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			q varchar(255) NOT NULL DEFAULT '',
			qn varchar(191) NOT NULL DEFAULT '',
			result varchar(12) NOT NULL DEFAULT '',
			faq_id bigint(20) unsigned NOT NULL DEFAULT 0,
			score decimal(4,3) NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY result_created (result,created),
			KEY qn (qn),
			KEY created (created)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'dvcb_db_version', self::DB_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Post types                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Primitive capabilities for both post types, all pointing at the
	 * plugin's one management capability (manage_options by default).
	 *
	 * Without this, a CPT's capabilities come from "post": anyone who can
	 * edit posts, including Authors and Contributors, could add or publish
	 * FAQs (changing what the bot says on the live site), and any Editor
	 * could open a handoff request, which holds a visitor's name, email and
	 * phone, by going straight to its edit URL. Hiding the menu does not stop
	 * either; the screen's own capability check is what decides.
	 *
	 * Only PRIMITIVE caps are listed here. The meta caps (edit_post,
	 * read_post, delete_post) are deliberately NOT mapped by hand:
	 * map_meta_cap => true lets WordPress resolve them down to these
	 * primitives itself, which is the supported way to do it.
	 */
	private static function capabilities() {
		$cap = DVCB_Admin::cap();
		return array(
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_private_posts'   => $cap,
			'delete_published_posts' => $cap,
			'create_posts'           => $cap,
		);
	}

	public static function register_types() {
		$d = 'support-chatbot';

		register_post_type(
			self::FAQ,
			array(
				'labels'              => array(
					'name'               => __( 'FAQs', $d ),
					'singular_name'      => __( 'FAQ', $d ),
					'add_new'            => __( 'Add New', $d ),
					'add_new_item'       => __( 'Add FAQ', $d ),
					'edit_item'          => __( 'Edit FAQ', $d ),
					'new_item'           => __( 'New FAQ', $d ),
					'view_item'          => __( 'View FAQ', $d ),
					'search_items'       => __( 'Search FAQs', $d ),
					'not_found'          => __( 'No FAQs yet. Add the questions your visitors ask most.', $d ),
					'not_found_in_trash' => __( 'No FAQs in the trash.', $d ),
					'all_items'          => __( 'FAQs', $d ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => DVCB_Admin::MENU_SLUG,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => self::capabilities(),
				'map_meta_cap'        => true,
			)
		);

		register_post_type(
			self::HANDOFF,
			array(
				'labels'              => array(
					'name'               => __( 'Handoff requests', $d ),
					'singular_name'      => __( 'Handoff request', $d ),
					'edit_item'          => __( 'Handoff request', $d ),
					'search_items'       => __( 'Search requests', $d ),
					'not_found'          => __( 'No one has asked for a person yet.', $d ),
					'not_found_in_trash' => __( 'Nothing in the trash.', $d ),
					'all_items'          => __( 'Handoff requests', $d ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => DVCB_Admin::MENU_SLUG,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => self::capabilities(),
				'map_meta_cap'        => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Match index                                                         */
	/* ------------------------------------------------------------------ */

	public static function split_lines( $text ) {
		$lines = preg_split( '/\R/', (string) $text );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' !== $line ) {
				$out[] = DVCB_Util::sub( $line, 0, 200 );
			}
			if ( count( $out ) >= 40 ) {
				break;
			}
		}
		return $out;
	}

	public static function flush_index() {
		delete_transient( self::INDEX_KEY );
	}

	/**
	 * The compiled match index plus each FAQ's display data, cached until an
	 * FAQ changes, so answering a message never re-reads every FAQ.
	 */
	public static function get_index() {
		$cached = get_transient( self::INDEX_KEY );
		if ( is_array( $cached ) && isset( $cached['v'] ) && self::INDEX_VERSION === $cached['v'] ) {
			return $cached;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::FAQ,
				'post_status'    => 'publish',
				'numberposts'    => 500,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$faqs = array();
		$meta = array();
		foreach ( $posts as $post ) {
			$id       = (int) $post->ID;
			$priority = (int) get_post_meta( $id, '_dvcb_priority', true );
			$faqs[]   = array(
				'id'       => $id,
				'question' => $post->post_title,
				'variants' => self::split_lines( get_post_meta( $id, '_dvcb_variants', true ) ),
				'priority' => $priority,
			);
			$meta[ $id ] = array(
				'q'       => $post->post_title,
				'a'       => (string) get_post_meta( $id, '_dvcb_answer', true ),
				'btn_l'   => (string) get_post_meta( $id, '_dvcb_btn_label', true ),
				'btn_u'   => (string) get_post_meta( $id, '_dvcb_btn_url', true ),
				'starter' => (bool) get_post_meta( $id, '_dvcb_starter', true ),
				'prio'    => $priority,
			);
		}

		$index         = DVCB_Matcher::build_index( $faqs );
		$index['meta'] = $meta;
		$index['v']    = self::INDEX_VERSION;

		set_transient( self::INDEX_KEY, $index, 7 * DAY_IN_SECONDS );
		return $index;
	}

	/**
	 * Starter questions for the chat's opening screen.
	 */
	public static function starter_chips( $limit ) {
		$limit = (int) $limit;
		if ( $limit < 1 ) {
			return array();
		}
		$index = self::get_index();
		$rows  = array();
		foreach ( $index['meta'] as $id => $m ) {
			if ( $m['starter'] ) {
				$rows[] = array(
					'id'   => (int) $id,
					'q'    => $m['q'],
					'prio' => $m['prio'],
				);
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['prio'] <=> $a['prio'] ?: $a['id'] <=> $b['id'];
			}
		);
		$rows = array_slice( $rows, 0, $limit );
		return array_map(
			function ( $r ) {
				return array(
					'id' => $r['id'],
					'q'  => $r['q'],
				);
			},
			$rows
		);
	}

	/* ------------------------------------------------------------------ */
	/* Question log                                                        */
	/* ------------------------------------------------------------------ */

	public static function log( $question, $result, $faq_id = 0, $score = 0.0 ) {
		if ( ! DVCB_Settings::get( 'log_enabled' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'q'       => DVCB_Util::sub( $question, 0, 255 ),
				'qn'      => DVCB_Util::sub( DVCB_Matcher::normalize( $question ), 0, 191 ),
				'result'  => $result,
				'faq_id'  => (int) $faq_id,
				'score'   => round( (float) $score, 3 ),
				'created' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%f', '%s' )
		);
	}

	/**
	 * Questions the bot didn't really answer, grouped so ten people asking
	 * the same thing show up as one row.
	 *
	 * Includes "unsure" results (it only offered "did you mean...") as well
	 * as outright "no match": a visitor who gets a weak suggestion hasn't had
	 * their question answered either, and those are often the most useful
	 * ones, since they point at an FAQ that needs another phrasing.
	 * `nomatch` is how many of the hits found nothing at all.
	 */
	public static function unanswered( $limit = 100 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT qn, MAX(q) AS q, COUNT(*) AS hits,
				        SUM(CASE WHEN result = %s THEN 1 ELSE 0 END) AS nomatch,
				        MAX(created) AS last_seen
				 FROM {$table} WHERE result IN (%s, %s) AND qn <> %s
				 GROUP BY qn ORDER BY hits DESC, last_seen DESC LIMIT %d",
				'fallback',
				'fallback',
				'suggest',
				'',
				(int) $limit
			)
		);
	}

	public static function dismiss_unanswered( $qn ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE result IN (%s, %s) AND qn = %s", 'fallback', 'suggest', $qn )
		);
	}

	public static function clear_unanswered() {
		global $wpdb;
		$table = self::table();
		return $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE result IN (%s, %s)", 'fallback', 'suggest' )
		);
	}

	/**
	 * @return array result => count since $days days ago.
	 */
	public static function stats( $days = 7 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT result, COUNT(*) AS c FROM {$table} WHERE created >= %s GROUP BY result", $since )
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->result ] = (int) $row->c;
		}
		return $out;
	}

	public static function top_faqs( $days = 30, $limit = 5 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT faq_id, COUNT(*) AS c FROM {$table}
				 WHERE result = %s AND faq_id > 0 AND created >= %s
				 GROUP BY faq_id ORDER BY c DESC LIMIT %d",
				'answer',
				$since,
				(int) $limit
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Handoffs                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $data name, contact, message, page, transcript.
	 * @return int Post ID, or 0 on failure.
	 */
	public static function create_handoff( array $data ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::HANDOFF,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s — %s', $data['name'], current_time( 'Y-m-d H:i' ) ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		update_post_meta( $id, '_dvcb_name', $data['name'] );
		update_post_meta( $id, '_dvcb_contact', $data['contact'] );
		update_post_meta( $id, '_dvcb_message', $data['message'] );
		update_post_meta( $id, '_dvcb_page', $data['page'] );
		update_post_meta( $id, '_dvcb_transcript', $data['transcript'] );
		update_post_meta( $id, '_dvcb_status', 'new' );

		delete_transient( self::NEW_COUNT_KEY );
		return (int) $id;
	}

	public static function new_handoff_count() {
		$cached = get_transient( self::NEW_COUNT_KEY );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				 WHERE p.post_type = %s AND p.post_status = %s",
				'_dvcb_status',
				'new',
				self::HANDOFF,
				'publish'
			)
		);
		set_transient( self::NEW_COUNT_KEY, $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* Seeding & retention                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * First activation only, and only if no FAQs exist. A flag means that
	 * deleting every FAQ later doesn't bring the starter set back.
	 */
	public static function seed() {
		if ( get_option( 'dvcb_seeded' ) ) {
			return;
		}
		$existing = wp_count_posts( self::FAQ );
		$total    = 0;
		foreach ( (array) $existing as $status => $n ) {
			if ( 'auto-draft' !== $status ) {
				$total += (int) $n;
			}
		}
		if ( $total > 0 ) {
			update_option( 'dvcb_seeded', 1 );
			return;
		}

		$rows = include DVCB_DIR . 'includes/seed-data.php';
		foreach ( (array) $rows as $row ) {
			$id = wp_insert_post(
				array(
					'post_type'   => self::FAQ,
					'post_status' => 'publish',
					'post_title'  => $row['question'],
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_dvcb_answer', $row['answer'] );
			update_post_meta( $id, '_dvcb_variants', implode( "\n", $row['variants'] ) );
			update_post_meta( $id, '_dvcb_btn_label', $row['btn_label'] );
			update_post_meta( $id, '_dvcb_btn_url', $row['btn_url'] );
			update_post_meta( $id, '_dvcb_starter', ! empty( $row['starter'] ) ? 1 : 0 );
			update_post_meta( $id, '_dvcb_priority', (int) $row['priority'] );
		}
		update_option( 'dvcb_seeded', 1 );
		self::flush_index();
	}

	public static function purge() {
		global $wpdb;

		$days = max( 7, (int) DVCB_Settings::get( 'log_retention' ) );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created < %s',
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);

		$keep = (int) DVCB_Settings::get( 'handoff_retention' );
		if ( $keep > 0 ) {
			$old = get_posts(
				array(
					'post_type'   => self::HANDOFF,
					'post_status' => 'any',
					'numberposts' => 200,
					'fields'      => 'ids',
					'date_query'  => array(
						array( 'before' => gmdate( 'Y-m-d H:i:s', time() - $keep * DAY_IN_SECONDS ) ),
					),
				)
			);
			foreach ( $old as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			delete_transient( self::NEW_COUNT_KEY );
		}
	}
}
