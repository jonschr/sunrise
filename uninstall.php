<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'puc_cron_check_updates-sunrise' );
delete_site_option( 'external_updates-sunrise' );

if ( is_multisite() ) {
	return;
}

foreach ( get_option( 'sunrise_job_ids', array() ) as $id ) {
	wp_clear_scheduled_hook( 'sunrise_run_job', array( $id ) );
}

global $wpdb;
wp_unschedule_hook( 'sunrise_check_in' );
foreach ( array( 'sunrise_job_', 'sunrise_connections_', 'sunrise_snapshot_', 'sunrise_error_ack_', 'sunrise_last_job_', '_transient_sunrise_notice_', '_transient_timeout_sunrise_notice_' ) as $prefix ) {
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $names as $name ) {
		delete_option( $name );
	}
}
foreach ( array( 'sunrise_agent_interval', 'sunrise_error_groups', 'sunrise_error_capture_lock', 'sunrise_installation', 'sunrise_agents', 'sunrise_agent', 'sunrise_agent_pause', 'sunrise_agent_revoked', 'sunrise_agent.lock', 'sunrise_policy', 'sunrise_last_refresh', 'sunrise_queue.lock', 'sunrise_worker.lock', 'sunrise_policy.lock' ) as $name ) {
	delete_option( $name );
}
