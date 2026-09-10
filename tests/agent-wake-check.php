<?php
/** Disposable local route check. All loopback/outbound HTTP is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
$owner = get_current_user_id(); $states = Sunrise\agent_states(); $state = Sunrise\agent_state();
if ( empty( $state['site_id'] ) ) { WP_CLI::error( 'Enrolled local fixture required.' ); }
$cron = get_option( 'cron' ); $name = 'sunrise_wake_' . $owner; $last = get_option( $name, null ); $cron_lock = get_transient( 'doing_cron' ); $calls = 0;
$block = function () use ( &$calls ) { $calls++; return new WP_Error( 'fixture_blocked', 'Simulated loopback block' ); }; add_filter( 'pre_http_request', $block );
function wake_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
try {
 delete_option( $name ); delete_transient( 'doing_cron' );
 $key = Sunrise\agent_wake_key( $state ); wake_check( is_string( $key ) && 64 === strlen( $key ), 'Separate wake key derives successfully' );
 $data = array( 'site_id' => $state['site_id'], 'generation' => $state['generation'], 'request_id' => wp_generate_uuid4(), 'issued_at' => time() );
 $request = function ( $body, $signature = null ) use ( $key ) { $r = new WP_REST_Request( 'POST', '/sunrise/v1/wake' ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); $r->set_header( 'x-sunrise-signature', null === $signature ? hash_hmac( 'sha256', $r->get_body(), $key ) : $signature ); return rest_do_request( $r ); };
 wp_set_current_user( 0 );
 wake_check( 403 === $request( $data, str_repeat( '0', 64 ) )->get_status(), 'Unsigned or altered requests cannot wake the site' );
 foreach ( array( array_merge( $data, array( 'issued_at' => time() - 121 ) ), array_merge( $data, array( 'generation' => $state['generation'] + 1 ) ), array_merge( $data, array( 'site_id' => wp_generate_uuid4() ) ) ) as $invalid ) { wake_check( 403 === $request( $invalid )->get_status(), 'Expired or differently scoped request denied' ); }
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) ); wp_schedule_single_event( time() + 300, 'sunrise_check_in', array( $owner ) );
 $result = $request( $data ); wake_check( 202 === $result->get_status(), 'Signed request accepted without a WordPress login' );
 wake_check( wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) <= time(), 'Future check-in becomes due immediately' );
 wake_check( false === $result->get_data()['cron_spawned'] && 1 === $calls, 'Blocked loopback is exposed without executing any outbound request' );
 $next = wp_next_scheduled( 'sunrise_check_in', array( $owner ) ); wake_check( 202 === $request( $data )->get_status() && $next === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) && 1 === $calls, 'Replay acknowledges the original request without repeating work' );
 wake_check( 429 === $request( array_merge( $data, array( 'request_id' => wp_generate_uuid4() ) ) )->get_status(), 'New requests are rate-limited' );
 $paused = $states; $paused[ $owner ]['paused'] = true; update_option( 'sunrise_agents', $paused, false ); wake_check( 403 === $request( $data )->get_status(), 'Locally paused connection cannot be woken' );
} finally {
 wp_set_current_user( $owner ); remove_filter( 'pre_http_request', $block ); update_option( 'sunrise_agents', $states, false ); update_option( 'cron', $cron );
 if ( null === $last ) { delete_option( $name ); } else { update_option( $name, $last, false ); }
 if ( false === $cron_lock ) { delete_transient( 'doing_cron' ); } else { set_transient( 'doing_cron', $cron_lock ); }
}
WP_CLI::success( 'Authenticated wake route checks passed.' );
