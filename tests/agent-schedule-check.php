<?php
/** wp --user=1 eval-file tests/agent-schedule-check.php on an enrolled disposable site. No HTTP. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
$cron = get_option( 'cron' ); $interval = get_option( 'sunrise_agent_interval', null ); $states = Sunrise\agent_states(); $owner = get_current_user_id();
function sunrise_schedule_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$block = function () { throw new RuntimeException( 'Scheduling must not contact any service' ); };add_filter( 'pre_http_request', $block );
try {
 sunrise_schedule_assert( isset( $states[ $owner ] ), 'Enrolled owner fixture exists' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) );wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, 'sunrise_check_in', array( $owner ) );update_option( 'sunrise_agent_interval', 1800 );
 Sunrise\agent_migrate_schedule();$next = wp_next_scheduled( 'sunrise_check_in', array( $owner ) );
 sunrise_schedule_assert( $next >= time() + 298 && $next <= time() + 330, 'Existing thirty-minute event moves to five minutes plus jitter' );
 sunrise_schedule_assert( $states === Sunrise\agent_states(), 'Connection identities and credentials remain unchanged' );
 Sunrise\agent_migrate_schedule();sunrise_schedule_assert( $next === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ), 'Repeated requests do not postpone check-in' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) );wp_schedule_single_event( time() + 60, 'sunrise_check_in', array( $owner ) );$soon = wp_next_scheduled( 'sunrise_check_in', array( $owner ) );delete_option( 'sunrise_agent_interval' );Sunrise\agent_migrate_schedule();
 sunrise_schedule_assert( $soon === wp_next_scheduled( 'sunrise_check_in', array( $owner ) ), 'Earlier queued-work events are preserved' );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( $owner ) );delete_option( 'sunrise_agent_interval' );Sunrise\agent_migrate_schedule();sunrise_schedule_assert( wp_next_scheduled( 'sunrise_check_in', array( $owner ) ) > time(), 'Reactivation restores a missing owner event' );
} finally {
 remove_filter( 'pre_http_request', $block );update_option( 'cron', $cron );
 if ( null === $interval ) { delete_option( 'sunrise_agent_interval' ); } else { update_option( 'sunrise_agent_interval', $interval, true ); }
}
WP_CLI::success( 'Schedule upgrade checks passed.' );
