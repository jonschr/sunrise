<?php
/** Disposable local route check. All loopback/outbound HTTP is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
$owner = get_current_user_id(); $states = Sunrise\agent_states(); $state = Sunrise\agent_state();
if ( empty( $state['site_id'] ) ) { WP_CLI::error( 'Enrolled local fixture required.' ); }
$cron = get_option( 'cron' ); $name = 'sunrise_wake_' . $owner; $last = get_option( $name, null ); $cron_lock = get_transient( 'doing_cron' ); $calls = 0;
$routine_name = 'sunrise_routine_check_in_' . $owner; $routine_due = get_option( $routine_name, null );
$log = get_option( 'sunrise_agent_log', null ); $reports = array();
$document = $state['applied']['document']; $document['generation']++;
$wire = array( 'generation' => $document['generation'], 'document_json' => wp_json_encode( $document ) ); $wire['hash'] = hash( 'sha256', $wire['document_json'] );
$reply = function ( $pre, $args, $url ) use ( &$reports, $wire ) {
 if ( false === strpos( $url, '/v1/agent/check-in' ) ) { return new WP_Error( 'fixture_blocked', 'Other HTTP blocked' ); }
 $report = json_decode( $args['body'], true ); $reports[] = $report;
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'receipt_sequence' => $report['sequence'], 'policy' => $wire, 'check_in_cadence' => true, 'sunrise_latest_version' => Sunrise\VERSION ) ) );
};
$block = function () use ( &$calls ) { $calls++; return new WP_Error( 'fixture_blocked', 'Simulated loopback block' ); }; add_filter( 'pre_http_request', $block );
function wake_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
try {
 delete_option( $name ); delete_transient( 'doing_cron' ); wp_clear_scheduled_hook( 'sunrise_wake_check_in', array( $owner ) );
 $fixture = $state; $fixture['check_in_cadence'] = true; unset( $fixture['pending_report'], $fixture['remote_job'] ); Sunrise\agent_store( $fixture );
 $key = Sunrise\agent_wake_key( $state ); wake_check( is_string( $key ) && 64 === strlen( $key ), 'Separate wake key derives successfully' );
 $data = array( 'site_id' => $state['site_id'], 'generation' => $state['generation'], 'request_id' => wp_generate_uuid4(), 'issued_at' => time() );
 $request = function ( $body, $signature = null ) use ( $key ) { $r = new WP_REST_Request( 'POST', '/sunrise/v1/wake' ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); $r->set_header( 'x-sunrise-signature', null === $signature ? hash_hmac( 'sha256', $r->get_body(), $key ) : $signature ); return rest_do_request( $r ); };
 wp_set_current_user( 0 );
 wake_check( 403 === $request( $data, str_repeat( '0', 64 ) )->get_status(), 'Unsigned or altered requests cannot wake the site' );
 foreach ( array( array_merge( $data, array( 'issued_at' => time() - 121 ) ), array_merge( $data, array( 'generation' => $state['generation'] + 1 ) ), array_merge( $data, array( 'site_id' => wp_generate_uuid4() ) ) ) as $invalid ) { wake_check( 403 === $request( $invalid )->get_status(), 'Expired or differently scoped request denied' ); }
 $scheduled = time() + 300;
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); wp_schedule_single_event( $scheduled, 'sunrise_check_in', array( $owner ) );
 update_option( $routine_name, $scheduled, false );
 $result = $request( $data ); wake_check( 202 === $result->get_status(), 'Signed request accepted without a WordPress login' );
 wake_check( wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ) <= time(), 'Separate wake event becomes due immediately' );
 wake_check( $scheduled === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && $scheduled === get_option( $routine_name ), 'Signed wake preserves the hourly event and routine marker' );
 wake_check( false === $result->get_data()['cron_spawned'] && 1 === $calls, 'Blocked loopback is exposed without executing any outbound request' );
 $next = wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ); wake_check( 202 === $request( $data )->get_status() && $next === wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ) && 1 === $calls, 'Replay acknowledges the original request without repeating work' );
 wake_check( 429 === $request( array_merge( $data, array( 'request_id' => wp_generate_uuid4() ) ) )->get_status(), 'New requests are rate-limited' );
 $previous_wake = get_option( $name ); $previous_wake['received_at'] = time() - 31; update_option( $name, $previous_wake, false );
 wake_check( 202 === $request( array_merge( $data, array( 'request_id' => wp_generate_uuid4() ) ) )->get_status() && $next === wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ) && $scheduled === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && $scheduled === get_option( $routine_name ), 'Another accepted request coalesces its pending wake without changing the regular event or marker' );
 $paused = $states; $paused[ $owner ]['paused'] = true; update_option( 'sunrise_agents', $paused, false ); wake_check( 403 === $request( $data )->get_status(), 'Locally paused connection cannot be woken' );
 remove_filter( 'pre_http_request', $block ); add_filter( 'pre_http_request', $reply, 10, 3 );
 do_action( 'sunrise_wake_check_in', $owner ); wake_check( ! $reports, 'Pause after acceptance also prevents the queued wake from reporting' );
 Sunrise\agent_store_states( array( $owner => $fixture ) );
 // WP-Cron removes each one-off event before calling its hook.
 wp_clear_scheduled_hook( 'sunrise_wake_check_in', array( $owner ) ); do_action( 'sunrise_wake_check_in', $owner );
 wake_check( 0 === get_current_user_id() && count( $reports ) >= 1 && ! array_filter( $reports, function ( $report ) { return ! empty( $report['routine_check_in'] ); } ), 'Wake runs as its owner, reports only non-routine samples and restores anonymous context' );
 wake_check( $scheduled === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && $scheduled === get_option( $routine_name ) && ! wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ), 'Completed wake leaves the hourly event intact and does not repeat itself' );
 $reports = array(); do_action( 'sunrise_wake_check_in', $owner );
 wake_check( $scheduled === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && $scheduled === get_option( $routine_name ), 'Repeated wake execution cannot postpone the hourly event' );
 // A wake arriving while the regular event is already due must leave it routine.
 $due = time() - 2; wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); wp_schedule_single_event( $due, 'sunrise_check_in', array( $owner ) ); update_option( $routine_name, $due, false );
 $reports = array(); do_action( 'sunrise_wake_check_in', $owner );
 wake_check( $due === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && $due === get_option( $routine_name ) && false === $reports[0]['routine_check_in'], 'Already-due routine event survives wake execution unchanged' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); $reports = array(); do_action( 'sunrise_check_in', $owner );
 wake_check( true === $reports[0]['routine_check_in'] && wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) >= time() + Sunrise\AGENT_INTERVAL, 'Regular event still reports a routine sample and schedules its hourly successor' );
 $future = wp_next_scheduled( 'sunrise_check_in', array( $owner ) ); $marker = get_option( $routine_name );
 wp_set_current_user( $owner ); Sunrise\agent_schedule( 60, null, 'sunrise_wake_check_in' ); wp_set_current_user( 0 );
 wake_check( wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ) <= time() + 90 && $future === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && $marker === get_option( $routine_name ), 'Early wake follow-up has its own event and preserves the routine marker' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); delete_option( $routine_name ); do_action( 'sunrise_wake_check_in', $owner );
 wake_check( get_option( $routine_name ) === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && get_option( $routine_name ) >= time() + Sunrise\AGENT_INTERVAL, 'Wake restores a missing regular schedule without classifying itself as routine' );
 remove_filter( 'pre_http_request', $reply );
 $throttled = function () { return array( 'response' => array( 'code' => 429 ), 'headers' => array( 'retry-after' => 12 * HOUR_IN_SECONDS ), 'body' => '{"error":{"code":"rate_limited"}}' ); };
 add_filter( 'pre_http_request', $throttled );
 try { do_action( 'sunrise_wake_check_in', $owner ); } finally { remove_filter( 'pre_http_request', $throttled ); }
 wake_check( wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) >= time() + 12 * HOUR_IN_SECONDS, 'Explicit longer Retry-After still defers regular reports after a throttled wake' );
 add_filter( 'pre_http_request', $reply, 10, 3 );
 $revoked = $fixture; $revoked['revoked'] = true; Sunrise\agent_store_states( array( $owner => $revoked ) ); $reports = array(); do_action( 'sunrise_wake_check_in', $owner ); do_action( 'sunrise_wake_check_in', $owner + 100000 );
 wake_check( ! $reports, 'Revoked or missing owners cannot run a queued wake' );
} finally {
 wp_set_current_user( $owner ); remove_filter( 'pre_http_request', $block ); remove_filter( 'pre_http_request', $reply ); update_option( 'sunrise_agents', $states, false ); update_option( 'cron', $cron );
 if ( null === $last ) { delete_option( $name ); } else { update_option( $name, $last, false ); }
 if ( null === $routine_due ) { delete_option( $routine_name ); } else { update_option( $routine_name, $routine_due, false ); }
 if ( false === $cron_lock ) { delete_transient( 'doing_cron' ); } else { set_transient( 'doing_cron', $cron_lock ); }
 if ( null === $log ) { delete_option( 'sunrise_agent_log' ); } else { update_option( 'sunrise_agent_log', $log, false ); }
}
WP_CLI::success( 'Authenticated wake route checks passed.' );
