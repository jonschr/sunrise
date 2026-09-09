<?php
/** Location-only collector check; requires the disposable enrolled local administrator. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
$states = Sunrise\agent_states(); $saved = array();
foreach ( array( 'sunrise_error_groups', 'sunrise_error_capture_lock', 'sunrise_error_ack_' . get_current_user_id() ) as $key ) { $saved[ $key ] = get_option( $key, null ); delete_option( $key ); }
function sunrise_errors_assert( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } WP_CLI::line( 'PASS: ' . $label ); }
$requests = array();$success = false;
$transport = function ( $pre, $args ) use ( &$requests, &$success ) { $requests[] = json_decode( $args['body'], true ); return $success ? array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '{"accepted":true}' ) : new WP_Error( 'offline', 'Fixture outage' ); };
try {
 $state = Sunrise\agent_state();unset( $state['errors_enabled'] );Sunrise\agent_store( $state );sunrise_errors_assert( Sunrise\error_capture_enabled(), 'Connected sites capture by default without a per-site opt-in' );
 sunrise_errors_assert( ! Sunrise\error_reporting_enabled( array() ), 'Unconnected sites do not capture' );
 $state['errors_enabled'] = false;Sunrise\agent_store( $state );
 $error = array( 'type' => E_ERROR, 'file' => WP_PLUGIN_DIR . '/example/example.php', 'line' => 42, 'message' => 'password=fixture-secret email=private@example.invalid SQL SELECT private FROM users' );
 Sunrise\capture_error_summary( $error );sunrise_errors_assert( ! Sunrise\error_summaries(), 'An explicit opt-out blocks capture' );
 sunrise_errors_assert( true === Sunrise\error_capture_setting( true ), 'The connection owner can enable capture' );
 Sunrise\capture_error_summary( $error );$groups = Sunrise\error_summaries();
 sunrise_errors_assert( 1 === count( $groups ) && false === strpos( wp_json_encode( $groups ), 'fixture-secret' ) && false === strpos( wp_json_encode( $groups ), 'private@example.invalid' ) && false === strpos( wp_json_encode( $groups ), 'SQL' ) && false === strpos( wp_json_encode( $groups ), ABSPATH ), 'Only bounded relative location and counts are stored, never messages or absolute paths' );
 Sunrise\capture_error_summary( $error );sunrise_errors_assert( 1 === array_values( Sunrise\error_summaries() )[0]['count'], 'Error storms are sampled at most once per ten seconds' );
 delete_option( 'sunrise_error_capture_lock' );Sunrise\capture_error_summary( $error );sunrise_errors_assert( 2 === array_values( Sunrise\error_summaries() )[0]['count'], 'A later captured occurrence increments the same stable group' );
 foreach ( array( '/private/secrets.php', WP_CONTENT_DIR . '/uploads/private-file.php', WP_PLUGIN_DIR . '/example/eval.php(1) : eval() code', WP_PLUGIN_DIR . '/../wp-config.php' ) as $path ) { sunrise_errors_assert( 'outside/unknown' === Sunrise\error_location( $path ), 'Unsupported or sensitive locations are suppressed' ); }
 for ( $i = 0; $i < 105; $i++ ) { delete_option( 'sunrise_error_capture_lock' );Sunrise\capture_error_summary( array_merge( $error, array( 'line' => $i + 100 ) ) ); }
 sunrise_errors_assert( 100 === count( Sunrise\error_summaries() ), 'The local group cap holds during a storm' );
 $recent = Sunrise\error_summaries();$expired = $recent;foreach ( $expired as &$entry ) { $entry['first_at'] = time() - 4 * DAY_IN_SECONDS; $entry['last_at'] = $entry['first_at']; }unset( $entry );update_option( 'sunrise_error_groups', $expired, false );sunrise_errors_assert( ! Sunrise\error_summaries(), 'Summaries older than three days are excluded' );update_option( 'sunrise_error_groups', $recent, false );
 $state = Sunrise\agent_state();$state['errors_supported'] = true;Sunrise\agent_store( $state );
 add_filter( 'pre_http_request', $transport, 10, 2 );
 sunrise_errors_assert( is_wp_error( Sunrise\agent_report_errors() ) && ! get_option( 'sunrise_error_ack_' . get_current_user_id() ), 'Failed delivery retains unacknowledged summaries' );
 $success = true;sunrise_errors_assert( true === Sunrise\agent_report_errors() && $requests[0] === $requests[1], 'Retry delivers the same cumulative groups' );
 Sunrise\agent_report_errors();sunrise_errors_assert( 2 === count( $requests ), 'Unchanged acknowledged groups add no HTTP request' );
 Sunrise\error_capture_setting( false );Sunrise\agent_report_errors();sunrise_errors_assert( 2 === count( $requests ), 'Disabling the connection stops its uploads' );
} finally {
 remove_filter( 'pre_http_request', $transport );update_option( 'sunrise_agents', $states, false );
 foreach ( $saved as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
}
WP_CLI::success( 'Location-only error collection and delivery checks passed.' );
