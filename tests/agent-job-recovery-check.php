<?php
/** Offline transport simulation; never installs a package or changes the service database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-jobs.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/jobs.php';
$states = Sunrise\agent_states(); $state = Sunrise\agent_state(); $fence = get_option( 'sunrise_remote_job_fence', null );
if ( empty( $state['site_id'] ) || ! empty( $state['remote_job'] ) || $fence ) { WP_CLI::error( 'Enrolled fixture with no pending installation required.' ); }
function sunrise_recovery_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$job = array( 'id' => wp_generate_uuid4(), 'status' => 'uncertain', 'revision' => 4, 'payload' => array( 'action' => 'install_update', 'type' => 'plugin', 'installed_id' => 'never-installed/fixture.php', 'version' => '2.0', 'from_version' => '1.0', 'identity' => null ) );
$record = array( 'token' => bin2hex( random_bytes( 32 ) ), 'phase' => 'executing', 'job' => $job );
$reports = array(); $offline = true; $closed = false;
$transport = function ( $pre, $options, $url ) use ( &$reports, &$offline, &$closed, &$job ) {
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
 $state['remote_job'] = $record; Sunrise\agent_store( $state );
 update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'] ), false );
 $lock = Sunrise\agent_execution_lock(); if ( is_wp_error( $lock ) ) { throw new RuntimeException( 'File lock unavailable' ); }
 try { sunrise_recovery_assert( is_wp_error( Sunrise\agent_run_update_job() ) && ! $reports, 'Process-held lock blocks recovery even without an unexpired WordPress worker lock' ); }
 finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
 sunrise_recovery_assert( is_wp_error( Sunrise\agent_run_update_job() ) && 'result' === Sunrise\agent_state()['remote_job']['phase'], 'Stopped interrupted worker persists its result across a transport outage' );
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
} finally {
 remove_filter( 'pre_http_request', $transport ); Sunrise\agent_store_states( $states );
 if ( null === $fence ) { delete_option( 'sunrise_remote_job_fence' ); } else { update_option( 'sunrise_remote_job_fence', $fence, false ); }
}
