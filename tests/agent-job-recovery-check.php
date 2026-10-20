<?php
/** Offline transport simulation; never installs a package or changes the service database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-jobs.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/jobs.php';
$cron = get_option( 'cron' );
$states = Sunrise\agent_states(); $state = Sunrise\agent_state(); $fence = get_option( 'sunrise_remote_job_fence', null );
$routine_name = 'sunrise_routine_check_in_' . get_current_user_id(); $routine_due = get_option( $routine_name, null );
$update_cache = get_option( 'external_updates-sunrise', null );
if ( empty( $state['site_id'] ) || ! empty( $state['remote_job'] ) || $fence ) { WP_CLI::error( 'Enrolled fixture with no pending installation required.' ); }
function sunrise_recovery_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$job = array( 'id' => wp_generate_uuid4(), 'status' => 'uncertain', 'revision' => 4, 'payload' => array( 'action' => 'install_update', 'type' => 'plugin', 'installed_id' => 'never-installed/fixture.php', 'version' => '2.0', 'from_version' => '1.0', 'identity' => null ) );
$record = array( 'token' => bin2hex( random_bytes( 32 ) ), 'phase' => 'executing', 'job' => $job );
$reports = array(); $offline = true; $closed = false;
$transport = function ( $pre, $options, $url ) use ( &$reports, &$offline, &$closed, &$job, &$state ) {
 if ( false === strpos( $url, '/v1/agent/' ) ) { return new WP_Error( 'fixture_blocked', 'External providers blocked during recovery checks' ); }
 if ( '/check-in' === substr( $url, -9 ) ) {
  $request = json_decode( $options['body'], true ); $document = $state['applied']['document']; ++$document['generation']; $wire = wp_json_encode( $document );
  return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'receipt_sequence' => $request['sequence'], 'policy' => array( 'generation' => $document['generation'], 'document_json' => $wire, 'hash' => hash( 'sha256', $wire ) ), 'update_jobs' => true, 'work_available' => true ) ) );
 }
 if ( '/events' === substr( $url, -7 ) ) {
  $reports[] = $options['body'];
  if ( $offline ) { return new WP_Error( 'fixture_offline', 'Lost result response' ); }
  $response = $job;
 } elseif ( '/status' === substr( $url, -7 ) ) { $response = $job; if ( $closed ) { $response['status'] = 'closed_unverified'; } }
 else { throw new RuntimeException( 'Unexpected execution or external request' ); }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'job' => $response ) ) );
};
add_filter( 'pre_http_request', $transport, 10, 3 );
try {
 $active = get_option( 'active_plugins' ); $cached = wp_load_alloptions(); $cached['active_plugins'] = maybe_serialize( array( 'stale/plugin.php' ) ); wp_cache_set( 'alloptions', $cached, 'options' ); wp_cache_set( 'active_plugins', array( 'stale/plugin.php' ), 'options' );
 sunrise_recovery_assert( Sunrise\agent_connection_lock() && get_option( 'active_plugins' ) === $active, 'Writer lock reloads autoloaded activation settings before reporting inventory' ); WP_Upgrader::release_lock( 'sunrise_agent' );
 $before = Sunrise\agent_states(); $state['remote_job'] = $record; Sunrise\agent_store( $state );
 wp_cache_set( 'sunrise_agents', $before, 'options' );
 sunrise_recovery_assert( empty( Sunrise\agent_state()['remote_job'] ), 'Fixture primes connection state cached before another worker committed' );
 sunrise_recovery_assert( Sunrise\agent_connection_lock() && Sunrise\agent_state()['remote_job'] === $record, 'Owning the connection lock reloads current state instead of overwriting a newer execution record' ); WP_Upgrader::release_lock( 'sunrise_agent' );
 WP_Upgrader::create_lock( 'sunrise_worker' ); WP_Upgrader::create_lock( 'auto_updater' );
 $owned_locks = array( 'sunrise_worker' => (int) get_option( 'sunrise_worker.lock' ), 'auto_updater' => (int) get_option( 'auto_updater.lock' ) );
 update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'locks' => $owned_locks ), false );
 $lock = Sunrise\agent_execution_lock(); if ( is_wp_error( $lock ) ) { throw new RuntimeException( 'File lock unavailable' ); }
 try { sunrise_recovery_assert( is_wp_error( Sunrise\agent_run_update_job() ) && ! $reports, 'Process-held lock blocks recovery while WordPress worker locks remain active' ); }
 finally { sunrise_recovery_assert( $owned_locks['sunrise_worker'] === (int) get_option( 'sunrise_worker.lock' ) && $owned_locks['auto_updater'] === (int) get_option( 'auto_updater.lock' ), 'A running process retains all of its expiring locks' ); flock( $lock, LOCK_UN ); fclose( $lock ); }
 sunrise_recovery_assert( is_wp_error( Sunrise\agent_run_update_job() ) && 'result' === Sunrise\agent_state()['remote_job']['phase'], 'Stopped interrupted worker persists its result across a transport outage' );
 sunrise_recovery_assert( ! get_option( 'sunrise_worker.lock' ) && ! get_option( 'auto_updater.lock' ), 'Stopped-process recovery releases only its recorded expiring locks without waiting an hour' );
 wp_clear_scheduled_hook( 'sunrise_wake_check_in', array( get_current_user_id() ) );
 Sunrise\agent_schedule_job_recovery(); $retry = wp_next_scheduled( 'sunrise_wake_check_in', array( get_current_user_id() ) );
 sunrise_recovery_assert( $retry >= time() + 60 && $retry <= time() + 95, 'A retained receipt retries on the separate wake hook soon' );
 Sunrise\agent_schedule_job_recovery( new WP_Error( 'limited', 'Retry later', array( 'retry_after' => 7200 ) ) );
 sunrise_recovery_assert( wp_next_scheduled( 'sunrise_wake_check_in', array( get_current_user_id() ) ) >= time() + 7200, 'Recovery honors a server backoff longer than the routine interval' );
 $retained = get_option( 'sunrise_remote_job_fence' );
 WP_Upgrader::create_lock( 'sunrise_worker' ); $foreign = Sunrise\agent_lock_value( 'sunrise_worker' ); $retained['locks']['sunrise_worker'] = $foreign - 1; update_option( 'sunrise_remote_job_fence', $retained, false );
 sunrise_recovery_assert( is_wp_error( Sunrise\agent_run_update_job() ) && $foreign === (int) get_option( 'sunrise_worker.lock' ), 'Recovery never clears a worker lock that differs from its retained ownership record' ); WP_Upgrader::release_lock( 'sunrise_worker' );
 $reported = json_decode( $reports[0], true );
 sunrise_recovery_assert( 'uncertain' === $reported['status'] && true === $reported['worker_stopped'], 'Recovery reports uncertainty and stopped-worker evidence, never success' );
 sunrise_recovery_assert( ! Sunrise\filter_core( true, 'minor' ) && 'off' === Sunrise\item_policy( 'plugins', 'any/plugin.php' ) && is_wp_error( Sunrise\job_permission( array( 'action' => 'auto_updates' ) ) ), 'Unreconciled installation blocks native automatic selections and legacy installation jobs' );
 $offline = false;
 sunrise_recovery_assert( true === Sunrise\agent_run_update_job() && $reports[0] === $reports[1] && 'uncertain' === Sunrise\agent_state()['remote_job']['phase'], 'Result retry is identical and acknowledgment retains the local recovery fence' );
 sunrise_recovery_assert( false === Sunrise\agent_run_update_job() && get_option( 'sunrise_remote_job_fence' ), 'Another sync does not rerun or release uncertain work' );
 $closed = true;
 sunrise_recovery_assert( true === Sunrise\agent_run_update_job() && ! get_option( 'sunrise_remote_job_fence' ) && empty( Sunrise\agent_state()['remote_job'] ), 'Explicit central reconciliation releases the local fence on its next sync' );
 $state['remote_job'] = $record; Sunrise\agent_store( $state ); $job['status'] = 'succeeded';
 update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'result' => array( 'status' => 'succeeded', 'code' => 'updated' ) ), false );
 sunrise_recovery_assert( true === Sunrise\agent_run_update_job() && 'succeeded' === json_decode( end( $reports ), true )['status'] && ! get_option( 'sunrise_remote_job_fence' ), 'An outcome retained while a concurrent report held the connection lock is acknowledged without reinstalling' );
 $state['remote_job'] = $record; Sunrise\agent_store( $state );
 update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'result' => array( 'status' => 'succeeded', 'code' => 'updated' ) ), false );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( get_current_user_id() ) ); Sunrise\agent_schedule( 5 * MINUTE_IN_SECONDS );
 $synced = Sunrise\agent_synchronize(); $next = wp_next_scheduled( 'sunrise_check_in', array( get_current_user_id() ) );
 sunrise_recovery_assert( ! is_wp_error( $synced ) && $next >= time() + 30 && $next <= time() + 95 && empty( Sunrise\agent_state()['remote_job'] ), 'Finishing a queued job schedules remaining explicit work soon, replacing the routine hourly event' );
 $state['remote_job'] = $record; Sunrise\agent_store( $state );
 update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'result' => array( 'status' => 'succeeded', 'code' => 'updated' ) ), false );
 wp_clear_scheduled_hook( 'sunrise_check_in', array( get_current_user_id() ) ); Sunrise\agent_schedule( Sunrise\AGENT_INTERVAL );
 $routine = wp_next_scheduled( 'sunrise_check_in', array( get_current_user_id() ) );
 wp_clear_scheduled_hook( 'sunrise_wake_check_in', array( get_current_user_id() ) );
 $synced = Sunrise\agent_synchronize( false, 'sunrise_wake_check_in' ); $next = wp_next_scheduled( 'sunrise_wake_check_in', array( get_current_user_id() ) );
 sunrise_recovery_assert( ! is_wp_error( $synced ) && $next >= time() + 30 && $next <= time() + 95 && $routine === wp_next_scheduled( 'sunrise_check_in', array( get_current_user_id() ) ) && $routine === get_option( $routine_name ), 'Wake job continuation runs soon on its separate hook without changing the regular event or routine marker' );
} finally {
 update_option( 'cron', $cron );
 remove_filter( 'pre_http_request', $transport ); Sunrise\agent_store_states( $states );
 if ( null === $fence ) { delete_option( 'sunrise_remote_job_fence' ); } else { update_option( 'sunrise_remote_job_fence', $fence, false ); }
 if ( null === $routine_due ) { delete_option( $routine_name ); } else { update_option( $routine_name, $routine_due, false ); }
 if ( null === $update_cache ) { delete_option( 'external_updates-sunrise' ); } else { update_option( 'external_updates-sunrise', $update_cache, false ); }
}
