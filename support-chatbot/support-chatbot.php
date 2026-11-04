<?php
/**
 * Plugin Name:       Support Chatbot
 * Plugin URI:        https://syednomanali.vercel.app/
 * Description:       A FAQ-driven support chatbot with smart matching, human handoff and a built-in admin. Runs entirely on your own WordPress site — no third-party AI or chat services.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Syed Noman Ali
 * Author URI:        https://syednomanali.vercel.app/
 * License:           GPL-2.0-or-later
 * Text Domain:       support-chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DVCB_VERSION', '1.0.0' );
define( 'DVCB_FILE', __FILE__ );
define( 'DVCB_DIR', plugin_dir_path( __FILE__ ) );
define( 'DVCB_URL', plugin_dir_url( __FILE__ ) );

require_once DVCB_DIR . 'includes/class-dvcb-util.php';
require_once DVCB_DIR . 'includes/class-dvcb-settings.php';
require_once DVCB_DIR . 'includes/class-dvcb-matcher.php';
require_once DVCB_DIR . 'includes/class-dvcb-store.php';
require_once DVCB_DIR . 'includes/class-dvcb-bot.php';
require_once DVCB_DIR . 'includes/class-dvcb-rest.php';
require_once DVCB_DIR . 'includes/class-dvcb-frontend.php';
require_once DVCB_DIR . 'includes/class-dvcb-admin.php';
require_once DVCB_DIR . 'includes/class-dvcb-privacy.php';

register_activation_hook( __FILE__, array( 'DVCB_Store', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DVCB_Store', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'support-chatbot', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		DVCB_Store::maybe_upgrade();
	}
);

DVCB_Store::init();
DVCB_REST::init();
DVCB_Frontend::init();
DVCB_Admin::init();
DVCB_Privacy::init();
