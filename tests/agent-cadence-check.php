<?php
/** Run on an enrolled disposable local WordPress site. All HTTP is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
$owner = get_current_user_id(); $states = Sunrise\agent_states(); $state = Sunrise\agent_state();
if ( empty( $state['applied'] ) ) { WP_CLI::error( 'Enrolled fixture with an applied policy required.' ); }
$cron = get_option( 'cron' ); $name = 'sunrise_routine_check_in_' . $owner; $saved_due = get_option( $name, null );
$log = get_option( 'sunrise_agent_log', null ); $reports = array(); $offline = false;
$log_version = get_option( 'sunrise_agent_log_version', null ); $recovery_errors = get_option( 'sunrise_reconnect_errors', null );
$fallback_name = 'sunrise_reconnect_fallback_' . $owner; $fallback = get_transient( $fallback_name );
$document = $state['applied']['document']; $document['generation']++;
$wire = array( 'generation' => $document['generation'], 'document_json' => wp_json_encode( $document ) ); $wire['hash'] = hash( 'sha256', $wire['document_json'] );
$reply = function ( $pre, $args, $url ) use ( &$reports, &$offline, $wire ) {
 if ( false === strpos( $url, '/v1/agent/check-in' ) ) { return new WP_Error( 'fixture_blocked', 'Other HTTP blocked' ); }
 $report = json_decode( $args['body'], true ); $reports[] = $report;
 if ( $offline ) { return new WP_Error( 'fixture_offline', 'Simulated outage' ); }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'receipt_sequence' => $report['sequence'], 'policy' => $wire, 'check_in_cadence' => true ) ) );
};
function cadence_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
add_filter( 'pre_http_request', $reply, 10, 3 );
try {
 $fixture = $state; $fixture['check_in_cadence'] = true; unset( $fixture['pending_report'], $fixture['remote_job'] ); Sunrise\agent_store( $fixture );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); Sunrise\agent_schedule( Sunrise\AGENT_INTERVAL );
 cadence_check( get_option( $name ) === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ), 'Routine schedule retains its due timestamp' );
 // Simulate WP-Cron removing a due event before invoking the callback.
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); update_option( $name, time() - 1, false ); do_action( 'sunrise_check_in', $owner );
 cadence_check( 2 === count( $reports ) && true === $reports[0]['routine_check_in'] && false === $reports[1]['routine_check_in'], 'Routine report counts once; policy acknowledgment is excluded' );
 cadence_check( Sunrise\AGENT_INTERVAL === $reports[0]['check_in_interval_seconds'], 'Configured interval is reported separately from observed activity' );
 $reports = array(); Sunrise\agent_sync(); cadence_check( false === $reports[0]['routine_check_in'], 'Manual check-in is excluded' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); Sunrise\agent_schedule( 60 ); cadence_check( false === get_option( $name ), 'Early scheduled check does not inherit the routine marker' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); $reports = array(); do_action( 'sunrise_check_in', $owner );
 cadence_check( false === $reports[0]['routine_check_in'], 'Bonus scheduled check is excluded' );
 $offline = true; $reports = array(); Sunrise\agent_check_in( true ); $pending = Sunrise\agent_state()['pending_report'];
 $offline = false; Sunrise\agent_check_in(); cadence_check( true === $reports[1]['routine_check_in'] && $reports[1] === $pending, 'Retry retains the original routine classification and exact report' );
 $overdue = Sunrise\agent_state(); $overdue['last_success'] = time() - Sunrise\AGENT_INTERVAL - 10; Sunrise\agent_store( $overdue ); delete_transient( $fallback_name );
 update_option( $name, time() - 1, false ); $reports = array(); Sunrise\agent_maybe_request_check_in();
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); do_action( 'sunrise_check_in', $owner );
 cadence_check( true === $reports[0]['routine_check_in'] && false === $reports[1]['routine_check_in'], 'Overdue traffic fallback counts once; the displaced cron report does not count again' );
 $legacy = Sunrise\agent_state(); unset( $legacy['check_in_cadence'] ); Sunrise\agent_store( $legacy ); $reports = array(); Sunrise\agent_check_in( true );
 cadence_check( ! isset( $reports[0]['routine_check_in'], $reports[0]['check_in_interval_seconds'] ), 'Older Control is supported until cadence reporting is negotiated' );
} finally {
 remove_filter( 'pre_http_request', $reply ); Sunrise\agent_store_states( $states ); update_option( 'cron', $cron );
 if ( null === $saved_due ) { delete_option( $name ); } else { update_option( $name, $saved_due, false ); }
 if ( null === $log ) { delete_option( 'sunrise_agent_log' ); } else { update_option( 'sunrise_agent_log', $log, false ); }
 if ( null === $log_version ) { delete_option( 'sunrise_agent_log_version' ); } else { update_option( 'sunrise_agent_log_version', $log_version, false ); }
 if ( null === $recovery_errors ) { delete_option( 'sunrise_reconnect_errors' ); } else { update_option( 'sunrise_reconnect_errors', $recovery_errors, false ); }
 if ( false === $fallback ) { delete_transient( $fallback_name ); } else { set_transient( $fallback_name, $fallback, Sunrise\AGENT_INTERVAL ); }
}
WP_CLI::success( 'Routine cadence classification checks passed.' );
