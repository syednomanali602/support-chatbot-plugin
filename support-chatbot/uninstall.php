<?php
/**
 * Runs when the plugin is DELETED from the Plugins screen (not on
 * deactivation). Removes nothing unless "Delete all chatbot data when the
 * plugin is deleted" was switched on in the settings.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$dvcb_settings = get_option( 'dvcb_settings', array() );
if ( empty( $dvcb_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Posts first (wp_delete_post also removes each post's meta).
$dvcb_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s)",
		'dvcb_faq',
		'dvcb_handoff'
	)
);
foreach ( $dvcb_ids as $dvcb_id ) {
	wp_delete_post( (int) $dvcb_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dvcb_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'dvcb_settings', 'dvcb_db_version', 'dvcb_seeded' ) as $dvcb_option ) {
	delete_option( $dvcb_option );
}
foreach ( array( 'dvcb_index', 'dvcb_new_handoffs', 'dvcb_welcome' ) as $dvcb_transient ) {
	delete_transient( $dvcb_transient );
}

// Per-visitor rate-limit counters.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_dvcb_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_dvcb_rl_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'dvcb_daily_purge' );
