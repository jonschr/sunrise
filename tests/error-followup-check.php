<?php
/** Diagnostic-only follow-up; all HTTP is intercepted on a disposable site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
$owner = get_current_user_id();$saved = array();
foreach ( array( 'sunrise_agents', 'sunrise_error_groups', 'sunrise_error_capture_lock', 'sunrise_error_ack_' . $owner, 'sunrise_error_report_retry_at', 'cron' ) as $key ) { $saved[ $key ] = get_option( $key, null ); }
$check = function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); };
$requests = array();$limited = false;
$transport = function ( $pre, $args, $url ) use ( &$requests, &$limited, $check, $owner ) {
 $check( false !== strpos( $url, '/v1/agent/errors' ), 'Follow-up only uses the existing diagnostic endpoint' );
 $check( get_current_user_id() === $owner, 'Diagnostic delivery runs as the enrolled owner' );
 $requests[] = $args;
 return array( 'response' => array( 'code' => $limited ? 429 : 200 ), 'headers' => array( 'retry-after' => '300' ), 'body' => $limited ? '{}' : '{"accepted":true}' );
};
try {
 $state = Sunrise\agent_state();$state['errors_supported'] = true;$state['errors_enabled'] = true;Sunrise\agent_store( $state );
 delete_option( 'sunrise_error_groups' );delete_option( 'sunrise_error_capture_lock' );delete_option( 'sunrise_error_report_retry_at' );delete_option( 'sunrise_error_ack_' . $owner );wp_clear_scheduled_hook( 'sunrise_error_report' );
 add_filter( 'pre_http_request', $transport, PHP_INT_MAX, 3 );
 $error = array( 'type' => E_ERROR, 'file' => WP_PLUGIN_DIR . '/fixture/fixture.php', 'line' => 76, 'message' => 'Fixture error' );
 Sunrise\capture_error_summary( $error );$at = wp_next_scheduled( 'sunrise_error_report' );
 $check( $at >= time() + 25 && $at <= time() + 35 && ! $requests, 'Capture schedules a short follow-up without making shutdown HTTP requests' );
 delete_option( 'sunrise_error_capture_lock' );Sunrise\capture_error_summary( $error );
 $check( wp_next_scheduled( 'sunrise_error_report' ) === $at, 'Repeated captures coalesce into one scheduled report' );
 wp_clear_scheduled_hook( 'sunrise_error_report' );wp_set_current_user( 0 );Sunrise\error_report_followup();
 $check( 0 === get_current_user_id() && 1 === count( $requests ), 'Anonymous cron restores its identity after report-only delivery' );
 wp_set_current_user( $owner );$check( Sunrise\agent_state() === $state, 'Follow-up does not run synchronization, jobs or automatic updates' );
 Sunrise\error_report_followup();$check( 1 === count( $requests ), 'Acknowledged diagnostics do not generate duplicate HTTP requests' );
 delete_option( 'sunrise_error_capture_lock' );Sunrise\capture_error_summary( $error );wp_clear_scheduled_hook( 'sunrise_error_report' );$limited = true;Sunrise\error_report_followup();
 $retry = wp_next_scheduled( 'sunrise_error_report' );$check( $retry >= time() + 295, 'Provider Retry-After is honored by persistent follow-up scheduling' );
 delete_option( 'sunrise_error_capture_lock' );Sunrise\capture_error_summary( $error );$check( $retry === wp_next_scheduled( 'sunrise_error_report' ), 'Another fatal cannot shorten an existing provider backoff' );
 $limited = false;wp_clear_scheduled_hook( 'sunrise_error_report' );$before = count( $requests );
 require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
 $locked = WP_Upgrader::create_lock( 'sunrise_agent', 120 );$check( $locked, 'Fixture takes the shared worker lock' );
 Sunrise\error_report_followup();$check( count( $requests ) === $before && wp_next_scheduled( 'sunrise_error_report' ), 'Busy connection work defers diagnostics without running a second worker' );WP_Upgrader::release_lock( 'sunrise_agent' );
 wp_clear_scheduled_hook( 'sunrise_error_report' );$groups = Sunrise\error_summaries();foreach ( $groups as &$group ) { $group['first_at'] = time() - 1200;$group['last_at'] = time() - 1000; }unset( $group );update_option( 'sunrise_error_groups', $groups, false );
 Sunrise\error_report_followup();$check( count( $requests ) === $before && ! wp_next_scheduled( 'sunrise_error_report' ), 'Old errors do not keep a diagnostic retry loop alive' );
 $state['errors_enabled'] = false;Sunrise\agent_store( $state );delete_option( 'sunrise_error_capture_lock' );Sunrise\capture_error_summary( $error );Sunrise\error_report_followup();
 $check( count( $requests ) === $before && ! wp_next_scheduled( 'sunrise_error_report' ), 'Opt-out blocks both follow-up capture and delivery' );
} finally {
 remove_filter( 'pre_http_request', $transport, PHP_INT_MAX );wp_set_current_user( $owner );WP_Upgrader::release_lock( 'sunrise_agent' );
 foreach ( $saved as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
}
WP_CLI::success( 'Diagnostic-only fatal follow-up checks passed.' );
