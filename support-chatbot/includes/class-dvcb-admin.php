<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DVCB_Admin {

	const MENU_SLUG = 'dvcb';

	public static function cap() {
		return apply_filters( 'dvcb_capability', 'manage_options' );
	}

	public static function init() {
		// Priority 9 so our parent menu exists before core attaches the
		// post-type submenus (priority 10); otherwise the first of them
		// would take over the parent's landing page.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'submenus' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'tidy_menu' ), 999 );

		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_dvcb_settings_group', array( __CLASS__, 'cap' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . DVCB_Store::FAQ, array( __CLASS__, 'save_faq' ) );
		add_action( 'save_post_' . DVCB_Store::HANDOFF, array( __CLASS__, 'save_handoff' ) );

		add_filter( 'manage_' . DVCB_Store::FAQ . '_posts_columns', array( __CLASS__, 'faq_columns' ) );
		add_action( 'manage_' . DVCB_Store::FAQ . '_posts_custom_column', array( __CLASS__, 'faq_column' ), 10, 2 );
		add_filter( 'manage_' . DVCB_Store::HANDOFF . '_posts_columns', array( __CLASS__, 'handoff_columns' ) );
		add_action( 'manage_' . DVCB_Store::HANDOFF . '_posts_custom_column', array( __CLASS__, 'handoff_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );

		add_action( 'admin_post_dvcb_dismiss', array( __CLASS__, 'do_dismiss' ) );
		add_action( 'admin_post_dvcb_clear', array( __CLASS__, 'do_clear' ) );
		add_action( 'admin_post_dvcb_handled', array( __CLASS__, 'do_handled' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'default_title', array( __CLASS__, 'default_title' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'load-post-new.php', array( __CLASS__, 'block_new_handoff' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DVCB_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                                */
	/* ------------------------------------------------------------------ */

	private static function bubble( $n ) {
		$n = (int) $n;
		if ( $n < 1 ) {
			return '';
		}
		return ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $n ) ) . '</span></span>';
	}

	public static function menu() {
		add_menu_page(
			__( 'Chatbot', 'support-chatbot' ),
			__( 'Chatbot', 'support-chatbot' ) . self::bubble( DVCB_Store::new_handoff_count() ),
			self::cap(),
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-format-chat',
			58
		);
	}

	public static function submenus() {
		add_submenu_page( self::MENU_SLUG, __( 'Unanswered questions', 'support-chatbot' ), __( 'Unanswered', 'support-chatbot' ), self::cap(), 'dvcb-unanswered', array( __CLASS__, 'render_unanswered' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Chatbot settings', 'support-chatbot' ), __( 'Settings', 'support-chatbot' ), self::cap(), 'dvcb-settings', array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * Core auto-creates the first submenu item as a copy of the parent;
	 * relabel it, drop the pointless "Add New" for handoff requests (they're
	 * created by visitors, never by hand), and badge the new-request count.
	 */
	public static function tidy_menu() {
		global $submenu;

		remove_submenu_page( self::MENU_SLUG, 'post-new.php?post_type=' . DVCB_Store::HANDOFF );

		if ( empty( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}
		// For a CPT under a custom parent, core lists only "All items", so
		// add the "Add FAQ" entry ourselves, right after the FAQ list.
		$items = array_values( $submenu[ self::MENU_SLUG ] );
		foreach ( $items as $pos => $item ) {
			if ( 'edit.php?post_type=' . DVCB_Store::FAQ === $item[2] ) {
				array_splice( $items, $pos + 1, 0, array( array( __( 'Add FAQ', 'support-chatbot' ), self::cap(), 'post-new.php?post_type=' . DVCB_Store::FAQ ) ) );
				break;
			}
		}
		$submenu[ self::MENU_SLUG ] = $items;

		$new = DVCB_Store::new_handoff_count();
		foreach ( $submenu[ self::MENU_SLUG ] as $i => $item ) {
			if ( self::MENU_SLUG === $item[2] ) {
				$submenu[ self::MENU_SLUG ][ $i ][0] = __( 'Dashboard', 'support-chatbot' );
			}
			if ( 'edit.php?post_type=' . DVCB_Store::HANDOFF === $item[2] ) {
				$submenu[ self::MENU_SLUG ][ $i ][0] .= self::bubble( $new );
			}
		}
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dvcb-settings' ) ) . '">' . esc_html__( 'Settings', 'support-chatbot' ) . '</a>' );
		return $links;
	}

	/** Visitors create handoff requests; nobody adds one by hand. */
	public static function block_new_handoff() {
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( DVCB_Store::HANDOFF === $type ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . DVCB_Store::HANDOFF ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	private static function current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	public static function assets() {
		$page   = self::current_page();
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = $screen ? $screen->post_type : '';

		$ours = in_array( $page, array( 'dvcb', 'dvcb-unanswered', 'dvcb-settings' ), true )
			|| in_array( $type, array( DVCB_Store::FAQ, DVCB_Store::HANDOFF ), true );
		if ( ! $ours ) {
			return;
		}

		$css = DVCB_DIR . 'assets/css/admin.css';
		wp_enqueue_style( 'dvcb-admin', DVCB_URL . 'assets/css/admin.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : DVCB_VERSION );

		if ( 'dvcb' === $page ) {
			$js = DVCB_DIR . 'assets/js/admin.js';
			wp_enqueue_script( 'dvcb-admin', DVCB_URL . 'assets/js/admin.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : DVCB_VERSION, true );
			wp_localize_script(
				'dvcb-admin',
				'DVCB_ADMIN',
				array(
					'rest'  => esc_url_raw( rest_url( 'dvcb/v1/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'  => array(
						'answered'  => __( 'Would answer directly', 'support-chatbot' ),
						'suggest'   => __( 'Would ask "did you mean"', 'support-chatbot' ),
						'fallback'  => __( 'No good answer: would offer a person', 'support-chatbot' ),
						'handoff'   => __( 'Would open the "talk to a person" form', 'support-chatbot' ),
						'smalltalk' => __( 'Small talk reply', 'support-chatbot' ),
						'reply'     => __( 'Bot reply', 'support-chatbot' ),
						'matches'   => __( 'Closest FAQs', 'support-chatbot' ),
						'words'     => __( 'Words it matched on', 'support-chatbot' ),
						'none'      => __( 'No FAQ is even close.', 'support-chatbot' ),
						'error'     => __( 'The test request failed.', 'support-chatbot' ),
						'thresholds' => __( 'Direct answer at %1$s%, suggestions at %2$s%', 'support-chatbot' ),
					),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                             */
	/* ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}

		if ( get_transient( 'dvcb_welcome' ) ) {
			delete_transient( 'dvcb_welcome' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> &middot; <a href="%5$s">%6$s</a></p></div>',
				esc_html__( 'Support Chatbot is active.', 'support-chatbot' ),
				esc_html__( 'A few generic starter FAQs were added. Edit them to match your site before relying on the bot.', 'support-chatbot' ),
				esc_url( admin_url( 'edit.php?post_type=' . DVCB_Store::FAQ ) ),
				esc_html__( 'Review FAQs', 'support-chatbot' ),
				esc_url( admin_url( 'admin.php?page=dvcb-settings' ) ),
				esc_html__( 'Settings', 'support-chatbot' )
			);
		}

		if ( 'dvcb-unanswered' === self::current_page() && isset( $_GET['dvcb_msg'] ) ) {
			$msg = sanitize_key( wp_unslash( $_GET['dvcb_msg'] ) );
			$map = array(
				'dismissed' => __( 'Question dismissed.', 'support-chatbot' ),
				'cleared'   => __( 'Unanswered list cleared.', 'support-chatbot' ),
			);
			if ( isset( $map[ $msg ] ) ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $msg ] ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	public static function render_dashboard() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'support-chatbot' ) );
		}

		$stats    = DVCB_Store::stats( 7 );
		$answer   = isset( $stats['answer'] ) ? $stats['answer'] : 0;
		$suggest  = isset( $stats['suggest'] ) ? $stats['suggest'] : 0;
		$fallback = isset( $stats['fallback'] ) ? $stats['fallback'] : 0;
		$handoff  = isset( $stats['handoff'] ) ? $stats['handoff'] : 0;
		$asked    = $answer + $suggest + $fallback + $handoff;
		$rate     = $asked > 0 ? round( $answer / $asked * 100 ) : 0;
		$index    = DVCB_Store::get_index();
		$top      = DVCB_Store::top_faqs( 30, 5 );
		$gaps     = DVCB_Store::unanswered( 5 );
		$new      = DVCB_Store::new_handoff_count();
		?>
		<div class="wrap dvcb-admin">
			<h1><?php esc_html_e( 'Support Chatbot', 'support-chatbot' ); ?></h1>

			<?php if ( 0 === (int) $index['count'] ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'You have no published FAQs, so the bot can only offer to connect people with you.', 'support-chatbot' ); ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . DVCB_Store::FAQ ) ); ?>"><?php esc_html_e( 'Add your first FAQ', 'support-chatbot' ); ?></a>
				</p></div>
			<?php endif; ?>

			<div class="dvcb-cards">
				<div class="dvcb-card"><div class="dvcb-num"><?php echo esc_html( number_format_i18n( $asked ) ); ?></div><div class="dvcb-label"><?php esc_html_e( 'Questions, last 7 days', 'support-chatbot' ); ?></div></div>
				<div class="dvcb-card"><div class="dvcb-num"><?php echo esc_html( $rate ); ?>%</div><div class="dvcb-label"><?php esc_html_e( 'Answered directly', 'support-chatbot' ); ?></div></div>
				<div class="dvcb-card"><a href="<?php echo esc_url( admin_url( 'admin.php?page=dvcb-unanswered' ) ); ?>"><div class="dvcb-num"><?php echo esc_html( number_format_i18n( $fallback ) ); ?></div></a><div class="dvcb-label"><?php esc_html_e( 'Had no good answer', 'support-chatbot' ); ?></div></div>
				<div class="dvcb-card"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . DVCB_Store::HANDOFF ) ); ?>"><div class="dvcb-num"><?php echo esc_html( number_format_i18n( $new ) ); ?></div></a><div class="dvcb-label"><?php esc_html_e( 'New requests for a person', 'support-chatbot' ); ?></div></div>
			</div>

			<div class="dvcb-panel">
				<h2><?php esc_html_e( 'Test the bot', 'support-chatbot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Type something a visitor might ask. You will see exactly what the bot would do and which FAQs came closest. Tests are not logged.', 'support-chatbot' ); ?></p>
				<form id="dvcb-test-form" class="dvcb-test-form">
					<input type="text" id="dvcb-test-input" class="regular-text" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. how much does it cost', 'support-chatbot' ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Test', 'support-chatbot' ); ?></button>
				</form>
				<div id="dvcb-test-result" class="dvcb-test-result" aria-live="polite"></div>
			</div>

			<div class="dvcb-cols">
				<div class="dvcb-panel">
					<h2><?php esc_html_e( 'Most-used answers (30 days)', 'support-chatbot' ); ?></h2>
					<?php if ( $top ) : ?>
						<ol>
							<?php foreach ( $top as $row ) : ?>
								<li>
									<?php echo esc_html( isset( $index['meta'][ (int) $row->faq_id ] ) ? $index['meta'][ (int) $row->faq_id ]['q'] : '#' . (int) $row->faq_id ); ?>
									<span class="dvcb-muted">&times; <?php echo esc_html( (int) $row->c ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="dvcb-muted"><?php esc_html_e( 'Nothing yet.', 'support-chatbot' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="dvcb-panel">
					<h2><?php esc_html_e( 'Questions it could not answer well', 'support-chatbot' ); ?></h2>
					<?php if ( $gaps ) : ?>
						<ol>
							<?php foreach ( $gaps as $row ) : ?>
								<li>
									<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . DVCB_Store::FAQ . '&dvcb_q=' . rawurlencode( $row->q ) ) ); ?>"><?php echo esc_html( $row->q ); ?></a>
									<span class="dvcb-muted">&times; <?php echo esc_html( (int) $row->hits ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dvcb-unanswered' ) ); ?>"><?php esc_html_e( 'See all', 'support-chatbot' ); ?></a></p>
					<?php else : ?>
						<p class="dvcb-muted"><?php esc_html_e( 'Nothing unanswered yet. Questions it cannot answer will collect here so you know which FAQs to write next.', 'support-chatbot' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Unanswered                                                          */
	/* ------------------------------------------------------------------ */

	public static function render_unanswered() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'support-chatbot' ) );
		}
		$rows = DVCB_Store::unanswered( 100 );
		?>
		<div class="wrap dvcb-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Unanswered questions', 'support-chatbot' ); ?></h1>
			<?php if ( $rows ) : ?>
				<a class="page-title-action dvcb-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dvcb_clear' ), 'dvcb_clear' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear the whole list?', 'support-chatbot' ) ); ?>');"><?php esc_html_e( 'Clear all', 'support-chatbot' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'What visitors asked that no FAQ answered well, most frequent first. "No match" means nothing came close; "Unsure" means the bot only offered a guess. Turn a question into an FAQ (or add it as another phrasing of an existing one) and the bot will handle it from then on.', 'support-chatbot' ); ?></p>

			<table class="widefat striped dvcb-table">
				<thead><tr>
					<th><?php esc_html_e( 'Question', 'support-chatbot' ); ?></th>
					<th class="dvcb-col-narrow"><?php esc_html_e( 'Times asked', 'support-chatbot' ); ?></th>
					<th class="dvcb-col-narrow"><?php esc_html_e( 'Outcome', 'support-chatbot' ); ?></th>
					<th class="dvcb-col-mid"><?php esc_html_e( 'Last asked', 'support-chatbot' ); ?></th>
					<th class="dvcb-col-mid"><?php esc_html_e( 'Actions', 'support-chatbot' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing here. Either the bot is answering everything, or no one has asked yet.', 'support-chatbot' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->q ); ?></td>
						<td><?php echo esc_html( (int) $row->hits ); ?></td>
						<td><?php echo esc_html( (int) $row->nomatch === (int) $row->hits ? __( 'No match', 'support-chatbot' ) : __( 'Unsure', 'support-chatbot' ) ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row->last_seen, 'Y-m-d H:i' ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . DVCB_Store::FAQ . '&dvcb_q=' . rawurlencode( $row->q ) ) ); ?>"><?php esc_html_e( 'Create FAQ', 'support-chatbot' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dvcb_dismiss&qn=' . rawurlencode( $row->qn ) ), 'dvcb_dismiss' ) ); ?>"><?php esc_html_e( 'Dismiss', 'support-chatbot' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function do_dismiss() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'support-chatbot' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dvcb_dismiss' );
		$qn = isset( $_GET['qn'] ) ? sanitize_text_field( wp_unslash( $_GET['qn'] ) ) : '';
		if ( '' !== $qn ) {
			DVCB_Store::dismiss_unanswered( $qn );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dvcb-unanswered&dvcb_msg=dismissed' ) );
		exit;
	}

	public static function do_clear() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'support-chatbot' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dvcb_clear' );
		DVCB_Store::clear_unanswered();
		wp_safe_redirect( admin_url( 'admin.php?page=dvcb-unanswered&dvcb_msg=cleared' ) );
		exit;
	}

	/** Opening "Create FAQ" from the Unanswered list pre-fills the question. */
	public static function default_title( $title, $post ) {
		if ( $post && DVCB_Store::FAQ === $post->post_type && isset( $_GET['dvcb_q'] ) ) {
			return DVCB_Util::clean_line( wp_unslash( $_GET['dvcb_q'] ), 200 );
		}
		return $title;
	}

	public static function title_placeholder( $text, $post ) {
		if ( $post && DVCB_Store::FAQ === $post->post_type ) {
			return __( 'The question visitors ask, e.g. What are your opening hours?', 'support-chatbot' );
		}
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function register_settings() {
		register_setting(
			'dvcb_settings_group',
			DVCB_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'DVCB_Settings', 'sanitize' ),
				'default'           => DVCB_Settings::defaults(),
			)
		);
	}

	private static function field( $key, array $f, $value ) {
		$name = DVCB_Settings::OPTION . '[' . $key . ']';
		$id   = 'dvcb-' . $key;

		switch ( $f['type'] ) {
			case 'toggle':
				printf( '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( (int) $value, 1, false ), esc_html__( 'Yes', 'support-chatbot' ) );
				break;
			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				break;
			case 'number':
				printf( '<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" class="small-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), (int) $f['min'], (int) $f['max'] );
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $f['options'] as $opt => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'color':
				printf( '<input type="color" id="%1$s" name="%2$s" value="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
				break;
			case 'email':
				printf( '<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( get_option( 'admin_email' ) ) );
				break;
			default:
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
		}

		if ( ! empty( $f['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $f['desc'] ) );
		}
	}

	public static function render_settings() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'support-chatbot' ) );
		}
		$values = DVCB_Settings::all();
		?>
		<div class="wrap dvcb-admin">
			<h1><?php esc_html_e( 'Chatbot settings', 'support-chatbot' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'dvcb_settings_group' ); ?>
				<?php foreach ( DVCB_Settings::schema() as $section ) : ?>
					<h2 class="title"><?php echo esc_html( $section['title'] ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $section['fields'] as $key => $f ) : ?>
							<tr>
								<th scope="row"><label for="dvcb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f['label'] ); ?></label></th>
								<td><?php self::field( $key, $f, $values[ $key ] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* FAQ editor                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'dvcb_faq_fields', __( 'Answer & matching', 'support-chatbot' ), array( __CLASS__, 'faq_box' ), DVCB_Store::FAQ, 'normal', 'high' );
		add_meta_box( 'dvcb_faq_feedback', __( 'Visitor feedback', 'support-chatbot' ), array( __CLASS__, 'faq_feedback_box' ), DVCB_Store::FAQ, 'side', 'default' );
		add_meta_box( 'dvcb_handoff_details', __( 'Request details', 'support-chatbot' ), array( __CLASS__, 'handoff_box' ), DVCB_Store::HANDOFF, 'normal', 'high' );
	}

	public static function faq_box( $post ) {
		wp_nonce_field( 'dvcb_faq_save', 'dvcb_faq_nonce' );
		$g = function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		?>
		<div class="dvcb-fields">
			<p>
				<label for="dvcb_answer"><strong><?php esc_html_e( 'Answer', 'support-chatbot' ); ?></strong></label>
				<textarea id="dvcb_answer" name="dvcb_answer" rows="6" class="widefat"><?php echo esc_textarea( $g( '_dvcb_answer' ) ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Plain text works. You can also use <a href="…">, <strong>, <em>, <br>, <ul>/<ol>/<li>. Use full https:// addresses for links inside the answer text.', 'support-chatbot' ); ?></span>
			</p>
			<p>
				<label for="dvcb_variants"><strong><?php esc_html_e( 'Other ways people ask this', 'support-chatbot' ); ?></strong></label>
				<textarea id="dvcb_variants" name="dvcb_variants" rows="7" class="widefat"><?php echo esc_textarea( $g( '_dvcb_variants' ) ); ?></textarea>
				<span class="description"><?php esc_html_e( 'One per line. This is what makes matching accurate: add the real phrasings visitors use, including short keywords ("pricing"), typos are handled for you, and other languages or Roman Urdu ("kitna kharcha"). The title above is matched too.', 'support-chatbot' ); ?></span>
			</p>
			<div class="dvcb-row">
				<p>
					<label for="dvcb_btn_label"><strong><?php esc_html_e( 'Button text (optional)', 'support-chatbot' ); ?></strong></label>
					<input type="text" id="dvcb_btn_label" name="dvcb_btn_label" value="<?php echo esc_attr( $g( '_dvcb_btn_label' ) ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'Contact us', 'support-chatbot' ); ?>">
				</p>
				<p>
					<label for="dvcb_btn_url"><strong><?php esc_html_e( 'Button link', 'support-chatbot' ); ?></strong></label>
					<input type="text" id="dvcb_btn_url" name="dvcb_btn_url" value="<?php echo esc_attr( $g( '_dvcb_btn_url' ) ); ?>" class="widefat" placeholder="/contact/">
				</p>
			</div>
			<p class="description"><?php esc_html_e( 'Link examples: /services/ (a page on this site), #some-id (jumps to an element on the page), #dvcb-handoff (opens the chatbot\'s "talk to a person" form), or a full https:// address.', 'support-chatbot' ); ?></p>
			<div class="dvcb-row">
				<p>
					<label><input type="checkbox" name="dvcb_starter" value="1" <?php checked( (int) $g( '_dvcb_starter' ), 1 ); ?>> <strong><?php esc_html_e( 'Show as a starter question', 'support-chatbot' ); ?></strong></label><br>
					<span class="description"><?php esc_html_e( 'Appears as a button when the chat opens.', 'support-chatbot' ); ?></span>
				</p>
				<p>
					<label for="dvcb_priority"><strong><?php esc_html_e( 'Priority (0 to 10)', 'support-chatbot' ); ?></strong></label>
					<input type="number" id="dvcb_priority" name="dvcb_priority" value="<?php echo esc_attr( (int) $g( '_dvcb_priority' ) ); ?>" min="0" max="10" class="small-text"><br>
					<span class="description"><?php esc_html_e( 'Breaks ties and orders starter questions. Higher first.', 'support-chatbot' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	public static function faq_feedback_box( $post ) {
		$up   = (int) get_post_meta( $post->ID, '_dvcb_up', true );
		$down = (int) get_post_meta( $post->ID, '_dvcb_down', true );
		printf( '<p>%s <strong>%d</strong><br>%s <strong>%d</strong></p>', esc_html__( 'Helpful:', 'support-chatbot' ), $up, esc_html__( 'Not helpful:', 'support-chatbot' ), $down );
		if ( $down > $up && $down >= 3 ) {
			echo '<p class="dvcb-warn">' . esc_html__( 'More visitors found this unhelpful than helpful. Consider rewriting the answer.', 'support-chatbot' ) . '</p>';
		}
	}

	private static function sanitize_btn_url( $raw ) {
		$u = trim( sanitize_text_field( wp_unslash( $raw ) ) );
		if ( '' === $u ) {
			return '';
		}
		if ( '#' === $u[0] ) {
			return '#' . preg_replace( '/[^A-Za-z0-9_\-:.]/', '', substr( $u, 1 ) );
		}
		if ( '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ) {
			return $u;
		}
		return esc_url_raw( $u );
	}

	public static function save_faq( $post_id ) {
		if ( ! isset( $_POST['dvcb_faq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dvcb_faq_nonce'] ) ), 'dvcb_faq_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$answer = isset( $_POST['dvcb_answer'] ) ? wp_kses( wp_unslash( $_POST['dvcb_answer'] ), DVCB_Util::allowed_html() ) : '';
		update_post_meta( $post_id, '_dvcb_answer', $answer );

		$variants = isset( $_POST['dvcb_variants'] ) ? DVCB_Store::split_lines( wp_unslash( $_POST['dvcb_variants'] ) ) : array();
		update_post_meta( $post_id, '_dvcb_variants', implode( "\n", $variants ) );

		update_post_meta( $post_id, '_dvcb_btn_label', isset( $_POST['dvcb_btn_label'] ) ? sanitize_text_field( wp_unslash( $_POST['dvcb_btn_label'] ) ) : '' );
		update_post_meta( $post_id, '_dvcb_btn_url', isset( $_POST['dvcb_btn_url'] ) ? self::sanitize_btn_url( $_POST['dvcb_btn_url'] ) : '' );
		update_post_meta( $post_id, '_dvcb_starter', ! empty( $_POST['dvcb_starter'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_dvcb_priority', isset( $_POST['dvcb_priority'] ) ? max( 0, min( 10, (int) $_POST['dvcb_priority'] ) ) : 0 );
	}

	/* ------------------------------------------------------------------ */
	/* FAQ list columns                                                    */
	/* ------------------------------------------------------------------ */

	public static function faq_columns( $columns ) {
		return array(
			'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'      => __( 'Question', 'support-chatbot' ),
			'dvcb_ans'   => __( 'Answer', 'support-chatbot' ),
			'dvcb_var'   => __( 'Phrasings', 'support-chatbot' ),
			'dvcb_start' => __( 'Starter', 'support-chatbot' ),
			'dvcb_fb'    => __( 'Feedback', 'support-chatbot' ),
		);
	}

	public static function faq_column( $column, $post_id ) {
		switch ( $column ) {
			case 'dvcb_ans':
				echo esc_html( wp_trim_words( wp_strip_all_tags( (string) get_post_meta( $post_id, '_dvcb_answer', true ) ), 18 ) );
				break;
			case 'dvcb_var':
				echo esc_html( count( DVCB_Store::split_lines( get_post_meta( $post_id, '_dvcb_variants', true ) ) ) );
				break;
			case 'dvcb_start':
				echo get_post_meta( $post_id, '_dvcb_starter', true ) ? '<span aria-label="' . esc_attr__( 'Yes', 'support-chatbot' ) . '">&#10003;</span>' : '&mdash;';
				break;
			case 'dvcb_fb':
				printf( '+%d / &minus;%d', (int) get_post_meta( $post_id, '_dvcb_up', true ), (int) get_post_meta( $post_id, '_dvcb_down', true ) );
				break;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Handoff requests                                                    */
	/* ------------------------------------------------------------------ */

	private static function contact_link( $contact ) {
		if ( is_email( $contact ) ) {
			return '<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>';
		}
		$tel = preg_replace( '/[^\d+]/', '', $contact );
		return '<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $contact ) . '</a>';
	}

	public static function handoff_box( $post ) {
		wp_nonce_field( 'dvcb_handoff_save', 'dvcb_handoff_nonce' );
		$g      = function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$status = $g( '_dvcb_status' ) ? $g( '_dvcb_status' ) : 'new';
		$page   = (string) $g( '_dvcb_page' );
		?>
		<table class="widefat striped dvcb-details"><tbody>
			<tr><th><?php esc_html_e( 'Name', 'support-chatbot' ); ?></th><td><?php echo esc_html( $g( '_dvcb_name' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Contact', 'support-chatbot' ); ?></th><td><?php echo self::contact_link( (string) $g( '_dvcb_contact' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside contact_link(). ?></td></tr>
			<tr><th><?php esc_html_e( 'Message', 'support-chatbot' ); ?></th><td><?php echo nl2br( esc_html( $g( '_dvcb_message' ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Page', 'support-chatbot' ); ?></th><td><?php echo $page ? '<a href="' . esc_url( $page ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $page ) . '</a>' : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><?php esc_html_e( 'Recent chat', 'support-chatbot' ); ?></th><td><?php echo nl2br( esc_html( $g( '_dvcb_transcript' ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Email to you', 'support-chatbot' ); ?></th><td><?php echo esc_html( $g( '_dvcb_email_status' ) ? $g( '_dvcb_email_status' ) : '—' ); ?><?php echo $g( '_dvcb_email_error' ) ? ' (' . esc_html( $g( '_dvcb_email_error' ) ) . ')' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
		</tbody></table>
		<p>
			<label for="dvcb_status"><strong><?php esc_html_e( 'Status', 'support-chatbot' ); ?></strong></label>
			<select id="dvcb_status" name="dvcb_status">
				<option value="new" <?php selected( $status, 'new' ); ?>><?php esc_html_e( 'New', 'support-chatbot' ); ?></option>
				<option value="handled" <?php selected( $status, 'handled' ); ?>><?php esc_html_e( 'Handled', 'support-chatbot' ); ?></option>
			</select>
		</p>
		<?php
	}

	public static function save_handoff( $post_id ) {
		if ( ! isset( $_POST['dvcb_handoff_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dvcb_handoff_nonce'] ) ), 'dvcb_handoff_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = isset( $_POST['dvcb_status'] ) && 'handled' === $_POST['dvcb_status'] ? 'handled' : 'new';
		update_post_meta( $post_id, '_dvcb_status', $status );
		delete_transient( DVCB_Store::NEW_COUNT_KEY );
	}

	public static function handoff_columns( $columns ) {
		return array(
			'cb'           => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'        => __( 'Request', 'support-chatbot' ),
			'dvcb_contact' => __( 'Contact', 'support-chatbot' ),
			'dvcb_msg'     => __( 'Message', 'support-chatbot' ),
			'dvcb_status'  => __( 'Status', 'support-chatbot' ),
		);
	}

	public static function handoff_column( $column, $post_id ) {
		switch ( $column ) {
			case 'dvcb_contact':
				echo self::contact_link( (string) get_post_meta( $post_id, '_dvcb_contact', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside contact_link().
				break;
			case 'dvcb_msg':
				echo esc_html( wp_trim_words( (string) get_post_meta( $post_id, '_dvcb_message', true ), 14 ) );
				break;
			case 'dvcb_status':
				$status = get_post_meta( $post_id, '_dvcb_status', true );
				echo 'handled' === $status
					? '<span class="dvcb-pill dvcb-pill-ok">' . esc_html__( 'Handled', 'support-chatbot' ) . '</span>'
					: '<span class="dvcb-pill dvcb-pill-new">' . esc_html__( 'New', 'support-chatbot' ) . '</span>';
				break;
		}
	}

	/**
	 * Handoff requests aren't edited, they're read and closed: "Edit" reads
	 * "View", Quick Edit is dropped, and a one-click "Mark handled" is added.
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! $post || DVCB_Store::HANDOFF !== $post->post_type ) {
			return $actions;
		}
		if ( isset( $actions['edit'] ) ) {
			$link = get_edit_post_link( $post->ID );
			if ( $link ) {
				$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html__( 'View', 'support-chatbot' ) );
			}
		}
		unset( $actions['inline hide-if-no-js'] );

		if ( 'handled' !== get_post_meta( $post->ID, '_dvcb_status', true ) && current_user_can( self::cap() ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=dvcb_handled&post=' . (int) $post->ID ), 'dvcb_handled_' . (int) $post->ID );
			$handled = array( 'dvcb_handled' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Mark handled', 'support-chatbot' ) . '</a>' );
			$actions = array_merge( array_slice( $actions, 0, 1, true ), $handled, array_slice( $actions, 1, null, true ) );
		}
		return $actions;
	}

	public static function do_handled() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'support-chatbot' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dvcb_handled_' . $post_id );
		if ( DVCB_Store::HANDOFF === get_post_type( $post_id ) ) {
			update_post_meta( $post_id, '_dvcb_status', 'handled' );
			delete_transient( DVCB_Store::NEW_COUNT_KEY );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . DVCB_Store::HANDOFF ) );
		exit;
	}
}
