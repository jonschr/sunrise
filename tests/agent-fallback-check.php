<?php
/** Disposable enrolled fixture only; no real HTTP, options and cadence restored. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
$owner = get_current_user_id(); $states = Sunrise\agent_states(); $state = Sunrise\agent_state();
if ( empty( $state['applied'] ) ) { throw new RuntimeException( 'Enrolled fixture required.' ); }
$names = array( 'cron', 'sunrise_agent_log', 'sunrise_agent_log_version', 'sunrise_reconnect_errors', 'sunrise_routine_check_in_' . $owner );
$saved = array(); foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$key = 'sunrise_reconnect_fallback_' . $owner; $fallback = get_transient( $key ); $calls = 0; $writes = 0; $mode = 'offline';
$http = function () use ( &$calls, &$mode ) {
 ++$calls;
 if ( 'offline' === $mode ) { return new WP_Error( 'fixture_offline' ); }
 return array( 'response' => array( 'code' => 'limited' === $mode ? 429 : 202 ), 'headers' => array( 'retry-after' => '900' ), 'body' => wp_json_encode( 'limited' === $mode ? array( 'error' => array( 'code' => 'enrollment_limit' ) ) : array( 'status' => 'pending' ) ) );
};
$counter = function ( $next ) use ( &$writes ) { ++$writes; return $next; };
$check = function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); };
add_filter( 'pre_http_request', $http ); add_filter( 'pre_update_option_sunrise_agent_log', $counter );
try {
 update_option( 'sunrise_agent_log_version', Sunrise\VERSION, false );
 $state['last_success'] = time() - Sunrise\AGENT_INTERVAL - 10; unset( $state['pending_report'], $state['remote_job'], $state['reconnect'] ); Sunrise\agent_store( $state );
 set_transient( $key, time(), Sunrise\AGENT_INTERVAL ); $writes = 0;
 for ( $i = 0; $i < 10; $i++ ) { Sunrise\agent_maybe_request_check_in(); }
 $check( 0 === $calls && 0 === $writes, 'Ten deferred visitor requests cause no HTTP or diagnostic log writes' );
 delete_transient( $key ); Sunrise\agent_maybe_request_check_in(); $after = $writes; $sent = $calls;
 for ( $i = 0; $i < 10; $i++ ) { Sunrise\agent_maybe_request_check_in(); }
 $check( $sent > 0 && $sent === $calls && $after === $writes, 'An overdue outage attempt logs once and retains the normal hourly throttle' );
 $state['reconnect'] = array( 'site_id' => $state['site_id'], 'generation' => $state['generation'], 'enrollment_id' => wp_generate_uuid4() ); Sunrise\agent_store( $state );
 set_transient( $key, time() - 301, Sunrise\AGENT_INTERVAL ); $mode = 'pending'; $sent = $calls; Sunrise\agent_maybe_request_check_in();
 $check( $calls > $sent && get_transient( $key )['retry_at'] <= time() + 300, 'Recovery shortens the previous hourly throttle to five minutes' );
 $sent = $calls; $after = $writes; Sunrise\agent_maybe_request_check_in();
 $check( $sent === $calls && $after === $writes, 'Pending recovery neither floods Control nor rewrites logs on visitor requests' );
 set_transient( $key, array( 'retry_at' => time() - 1 ), 300 ); $mode = 'limited'; Sunrise\agent_maybe_request_check_in();
 $check( get_transient( $key )['retry_at'] >= time() + 899, 'Recovery honors a longer server Retry-After' );
 WP_CLI::success( 'Traffic fallback and recovery retry checks passed.' );
} finally {
 remove_filter( 'pre_http_request', $http ); remove_filter( 'pre_update_option_sunrise_agent_log', $counter ); Sunrise\agent_store_states( $states );
 foreach ( $saved as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
 if ( false === $fallback ) { delete_transient( $key ); } else { set_transient( $key, $fallback, Sunrise\AGENT_INTERVAL ); }
}
