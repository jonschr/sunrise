<?php
/** Disposable owner only. Intercepted HTTP; real MySQL commits and recovery. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-transfer-execution.php';
function execution_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
if ( get_option( 'sunrise_remote_job_fence' ) ) { throw new RuntimeException( 'Existing execution requires review.' ); }
$states = Sunrise\agent_states(); $identity = Sunrise\installation_identity(); $original = get_option( 'blogdescription' ); $ids = array(); $job = null; $calls = array(); $lost_start = false; $lost_result = false; $expire = false;
$make = function () use ( &$job, &$ids, $original ) {
	update_option( 'blogdescription', $original );
	$source = Sunrise\transfer_option_snapshot( array( 'blogdescription' ) );
	$source['site_id'] = wp_generate_uuid4(); $source['site_url'] = $source['home_url'] = 'https://source.example/';
	$source['items'][0]['value'] = 'Sunrise execution fixture'; $source['items'][0]['fingerprint'] = hash( 'sha256', $source['items'][0]['value'] );
	$preview = Sunrise\transfer_option_preview( $source ); if ( is_wp_error( $preview ) ) { throw new RuntimeException( 'Preview failed' ); }
	$id = wp_generate_uuid4(); $ids[] = $id; $json = wp_json_encode( $preview['plan'] );
	$job = array( 'id' => $id, 'status' => 'approved', 'revision' => 1, 'plan_json' => $json, 'plan_hash' => hash( 'sha256', $json ), 'start_until' => null, 'outcome' => null, 'worker_stopped' => false );
	return $job;
};
$http = function ( $pre, $args, $url ) use ( &$job, &$calls, &$lost_start, &$lost_result, &$expire ) {
	$action = basename( $url ); $body = json_decode( $args['body'], true ); $calls[] = array( $action, $body );
	if ( 'claim' === $action ) { $response = 'approved' === $job['status'] ? $job : null; }
	elseif ( 'start' === $action ) {
		if ( 'approved' === $job['status'] ) { $job['status'] = 'running'; $job['start_until'] = gmdate( 'c', time() + ( $expire ? -1 : 60 ) ); ++$job['revision']; }
		if ( $lost_start ) { $lost_start = false; return new WP_Error( 'fixture_lost_start' ); } $response = $job;
	} elseif ( 'result' === $action ) {
		$job['outcome'] = $body['outcome']; $job['worker_stopped'] = true; $job['status'] = 'unknown' === $body['outcome'] ? 'uncertain' : ( 'not_applied' === $body['outcome'] ? 'failed' : 'succeeded' );
		if ( $lost_result ) { $lost_result = false; return new WP_Error( 'fixture_lost_result' ); } $response = $job;
	} elseif ( 'status' === $action ) { $response = $job; }
	else { throw new RuntimeException( 'Unexpected HTTP request' ); }
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'transfer' => $response ) ) );
};
add_filter( 'pre_http_request', $http, 10, 3 );
try {
	$state = Sunrise\agent_state(); $state['transfer_execution'] = true; $state['remote_transfer'] = null; Sunrise\agent_store( $state );
	$make(); $lost_start = true;
	execution_check( is_wp_error( Sunrise\agent_run_transfer() ) && get_option( 'blogdescription' ) === $original && get_option( 'sunrise_remote_job_fence' ) && 'starting' === Sunrise\agent_state()['remote_transfer']['phase'], 'Lost start response retains a fence and never writes before authorization arrives' );
	$first = $calls[1][1]; $lost_result = true;
	execution_check( is_wp_error( Sunrise\agent_run_transfer() ) && $calls[2][1] === $first && 'Sunrise execution fixture' === get_option( 'blogdescription' ) && 'result' === Sunrise\agent_state()['remote_transfer']['phase'], 'Exact start retry commits once and persists the outcome before reporting' );
	$executed = Sunrise\agent_state()['remote_transfer']; $last_body = end( $calls )[1];
	update_option( 'blogdescription', 'Newer administrator edit' );
	execution_check( true === Sunrise\agent_run_transfer() && end( $calls )[1] === $last_body && 'Newer administrator edit' === get_option( 'blogdescription' ) && ! get_option( 'sunrise_remote_job_fence' ) && empty( Sunrise\agent_state()['remote_transfer'] ), 'A lost result receipt retries only its report and preserves newer edits' );
	// Simulate process death after COMMIT and before saving the outcome.
	$executed['phase'] = 'executing'; unset( $executed['outcome'] ); $state = Sunrise\agent_state(); $state['remote_transfer'] = $executed; Sunrise\agent_store( $state );
	update_option( 'sunrise_remote_job_fence', array( 'kind' => 'transfer', 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'plan_hash' => $job['plan_hash'], 'start_until' => 0 ), false );
	execution_check( true === Sunrise\agent_run_transfer() && 'Newer administrator edit' === get_option( 'blogdescription' ), 'Crash recovery reads the committed journal and does not repeat a write' );
	$make(); $expire = true;
	execution_check( true === Sunrise\agent_run_transfer() && 'not_applied' === $job['outcome'] && get_option( 'blogdescription' ) === $original, 'An expired start is reported not-applied without writing' ); $expire = false;
	$make(); $state = Sunrise\agent_state(); $state['remote_transfer'] = array( 'phase' => 'executing', 'token' => bin2hex( random_bytes( 32 ) ), 'job' => $job ); Sunrise\agent_store( $state ); $job['status'] = 'running';
	update_option( 'sunrise_remote_job_fence', array( 'kind' => 'transfer', 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'plan_hash' => $job['plan_hash'], 'start_until' => 0 ), false );
	execution_check( true === Sunrise\agent_run_transfer() && 'unknown' === $job['outcome'] && get_option( 'sunrise_remote_job_fence' ) && 'uncertain' === Sunrise\agent_state()['remote_transfer']['phase'], 'An interrupted execution without a journal stays fenced and uncertain' );
	execution_check( false === Sunrise\agent_run_transfer() && 'status' === end( $calls )[0], 'Unknown execution is inspected rather than automatically retried' );
	$job['status'] = 'closed_unverified'; execution_check( true === Sunrise\agent_run_transfer() && ! get_option( 'sunrise_remote_job_fence' ), 'Explicit closure after worker stop releases the fence' );
	$make(); $job['plan_json'] = wp_json_encode( array( 'schema' => 1, 'scope' => 'files', 'destination_site_id' => Sunrise\agent_state()['site_id'], 'source_site_id' => wp_generate_uuid4(), 'changes' => array() ) ); $job['plan_hash'] = hash( 'sha256', $job['plan_json'] ); $job['archive'] = array( 'bytes' => -1, 'sha256' => 'invalid' );
	execution_check( true === Sunrise\agent_run_transfer() && 'failed' === $job['status'] && 'not_applied' === $job['outcome'] && ! get_option( 'sunrise_remote_job_fence' ) && empty( Sunrise\agent_state()['remote_transfer'] ), 'Unverifiable file download reports failure without entering the writer or retrying forever' );
	$make(); $state = Sunrise\agent_state(); $state['paused'] = true; Sunrise\agent_store( $state ); $count = count( $calls );
	execution_check( false === Sunrise\agent_run_transfer() && count( $calls ) === $count, 'A local pause prevents new transfer work without HTTP calls' );
	execution_check( $identity === Sunrise\installation_identity(), 'Execution and recovery preserve destination installation identity' );
} finally {
	remove_filter( 'pre_http_request', $http, 10 ); Sunrise\agent_store_states( $states ); update_option( 'blogdescription', $original );
	foreach ( $ids as $id ) { delete_option( 'sunrise_transfer_backup_' . get_current_user_id() . '_' . $id ); }
	delete_option( 'sunrise_remote_job_fence' );
}
WP_CLI::success( 'Durable transfer runner checked; local settings and connection restored.' );
