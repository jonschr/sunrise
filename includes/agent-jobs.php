<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

/** Process-held lock, without time expiry. File contains no credentials and is not transferable state. */
function agent_execution_lock() {
	$path = WP_CONTENT_DIR . '/.sunrise-execution.php';
	$handle = @fopen( $path, 'c+' );
	if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
		if ( $handle ) { fclose( $handle ); }
		return new \WP_Error( 'sunrise_execution_busy', 'The execution lock is unavailable. Retry after the running operation finishes.' );
	}
	if ( 0 === fstat( $handle )['size'] ) { fwrite( $handle, '<?php exit;' ); fflush( $handle ); @chmod( $path, 0600 ); }
	return $handle;
}
function agent_job_save( &$state, $record ) {
	$state['remote_job'] = $record;
	if ( ! agent_store( $state ) ) { throw new \RuntimeException( 'Could not persist execution state' ); }
}
function agent_job_clear( &$state ) {
	$fence = get_option( 'sunrise_remote_job_fence', array() );
	if ( $fence && $fence['site_id'] === $state['site_id'] && ! delete_option( 'sunrise_remote_job_fence' ) ) { throw new \RuntimeException( 'Could not release execution fence' ); }
	agent_job_save( $state, null );
}
function agent_job_response( $response, $id = null ) {
	if ( is_wp_error( $response ) ) { return $response; }
	if ( ! array_key_exists( 'job', $response ) ) { return new \WP_Error( 'sunrise_job_schema', 'Invalid job response.' ); }
	$job = $response['job'];
	if ( null === $job && null === $id ) { return null; }
	if ( ! is_array( $job ) || ! isset( $job['id'], $job['status'], $job['revision'], $job['payload'] ) || ! wp_is_uuid( $job['id'], 4 ) || ( $id && $id !== $job['id'] ) || ! is_int( $job['revision'] ) || $job['revision'] < 1
		|| ! in_array( $job['status'], array( 'queued', 'reserved', 'running', 'uncertain', 'succeeded', 'failed', 'cancelled', 'expired', 'closed_unverified' ), true ) ) { return new \WP_Error( 'sunrise_job_schema', 'Invalid job response.' ); }
	$task = $job['payload'];
	if ( ! is_array( $task ) || count( $task ) !== 6 || ! isset( $task['action'], $task['type'], $task['installed_id'], $task['version'], $task['from_version'] ) || ! array_key_exists( 'identity', $task )
		|| 'install_update' !== $task['action'] || ! in_array( $task['type'], array( 'plugin', 'theme', 'core' ), true ) || ! is_string( $task['installed_id'] ) || ! strlen( $task['installed_id'] ) || strlen( $task['installed_id'] ) > 255 || preg_match( '/[\x00-\x1f\x7f]/', $task['installed_id'] ) || ( 'core' === $task['type'] && 'wordpress' !== $task['installed_id'] )
		|| ! is_string( $task['version'] ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $task['version'] ) || ! is_string( $task['from_version'] ) || strlen( $task['from_version'] ) > 128 ) { return new \WP_Error( 'sunrise_job_schema', 'Invalid installation task.' ); }
	if ( null !== $task['identity'] ) {
		if ( ! is_array( $task['identity'] ) || count( $task['identity'] ) !== 5 ) { return new \WP_Error( 'sunrise_job_schema', 'Invalid component identity.' ); }
		foreach ( array( 'name', 'update_uri', 'project_uri', 'author_uri', 'text_domain' ) as $field ) { if ( ! isset( $task['identity'][ $field ] ) || ! is_string( $task['identity'][ $field ] ) || strlen( $task['identity'][ $field ] ) > 512 ) { return new \WP_Error( 'sunrise_job_schema', 'Invalid component identity.' ); } }
	}
	return $job;
}
function agent_job_result( &$state, $record ) {
	$job = agent_job_response( agent_http( 'agent/jobs/' . $record['job']['id'] . '/events', array(
		'execution_token' => $record['token'], 'sequence' => 1, 'status' => $record['status'], 'code' => $record['code'], 'worker_stopped' => true,
	), $state ), $record['job']['id'] );
	if ( is_wp_error( $job ) ) { return $job; }
	if ( 'uncertain' === $job['status'] ) { $record['phase'] = 'uncertain'; agent_job_save( $state, $record ); }
	else { agent_job_clear( $state ); }
	return true;
}

/** Only a trusted service job can reach the native updater; no inbound wake installs anything. */
function agent_run_update_job() {
	require_once __DIR__ . '/jobs.php';
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return job_error( 'busy', 'Connection work is running.' ); }
	$worker = false; $auto = false; $file = null; $agent_locked = true;
	$priority = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
	$buffer = ob_get_level();
	try {
		if ( ! ( $worker = \WP_Upgrader::create_lock( 'sunrise_worker' ) ) ) { return job_error( 'busy', 'A local worker is running.' ); }
		$file = agent_execution_lock(); if ( is_wp_error( $file ) ) { return $file; }
		$state = agent_state();
		if ( empty( $state['site_id'] ) || ! agent_owner_valid( $state ) || is_wp_error( installation_guard() ) ) { return job_error( 'forbidden', 'Reconnect this installation before running jobs.' ); }
		$fence = get_option( 'sunrise_remote_job_fence', array() );
		if ( $fence && $fence['site_id'] !== $state['site_id'] ) { return job_error( 'busy', 'Another connection has an installation awaiting recovery.' ); }
		$record = isset( $state['remote_job'] ) ? $state['remote_job'] : null;
		if ( $fence && ! $record ) { return job_error( 'execution_interrupted', 'Execution state is missing; inspect this installation before reconnecting.' ); }
		if ( $fence && ( empty( $record['job']['id'] ) || $fence['job_id'] !== $record['job']['id'] ) ) { return job_error( 'execution_interrupted', 'Execution records disagree; inspect this installation before reconnecting.' ); }
		if ( $record && 'executing' === $record['phase'] ) {
			// Acquiring the process-held file lock proves the old Sunrise executor released it or exited.
			$record['phase'] = 'result';
			$record['status'] = isset( $fence['result']['status'] ) ? $fence['result']['status'] : 'uncertain';
			$record['code'] = isset( $fence['result']['code'] ) ? $fence['result']['code'] : 'execution_interrupted'; agent_job_save( $state, $record );
		}
		if ( $record && 'result' === $record['phase'] ) { return agent_job_result( $state, $record ); }
		if ( $record && 'uncertain' === $record['phase'] ) {
			$job = agent_job_response( agent_http( 'agent/jobs/' . $record['job']['id'] . '/status', array( 'execution_token' => $record['token'] ), $state ), $record['job']['id'] );
			if ( is_wp_error( $job ) ) { return $job; }
			if ( 'closed_unverified' === $job['status'] ) { agent_job_clear( $state ); return true; }
			return false;
		}
		if ( ! $record ) { $record = array( 'token' => bin2hex( random_bytes( 32 ) ), 'phase' => 'claiming' ); agent_job_save( $state, $record ); }
		if ( 'claiming' === $record['phase'] ) {
			$job = agent_job_response( agent_http( 'agent/jobs/claim', array( 'execution_token' => $record['token'] ), $state ) );
			if ( is_wp_error( $job ) ) { return $job; }
			if ( ! $job ) { agent_job_clear( $state ); return false; }
			if ( 'reserved' !== $job['status'] ) { return job_error( 'execution_interrupted', 'Unexpected existing execution. Inspect the installation.' ); }
			$record['job'] = $job; $record['phase'] = 'starting'; agent_job_save( $state, $record );
		}
		// Avoid granting a start window while WordPress itself owns the automatic-updater lock.
		if ( ! ( $auto = \WP_Upgrader::create_lock( 'auto_updater' ) ) ) { return job_error( 'busy', 'WordPress automatic updates are running.' ); }
		$response = agent_http( 'agent/jobs/' . $record['job']['id'] . '/start', array( 'execution_token' => $record['token'], 'revision' => $record['job']['revision'] ), $state );
		$job = agent_job_response( $response, $record['job']['id'] );
		if ( is_wp_error( $job ) ) {
			$info = $job->get_error_data();
			if ( ! is_array( $info ) || ! isset( $info['status'] ) || ! in_array( $info['status'], array( 403, 409 ), true ) ) { return $job; }
			$status = agent_job_response( agent_http( 'agent/jobs/' . $record['job']['id'] . '/status', array( 'execution_token' => $record['token'] ), $state ), $record['job']['id'] );
			if ( ! is_wp_error( $status ) && in_array( $status['status'], array( 'running', 'uncertain' ), true ) ) {
				$record['phase'] = 'result'; $record['status'] = 'uncertain'; $record['code'] = 'start_response_lost'; agent_job_save( $state, $record ); return agent_job_result( $state, $record );
			}
			if ( ! is_wp_error( $status ) && in_array( $status['status'], array( 'cancelled', 'expired' ), true ) ) { agent_job_clear( $state ); return false; }
			// An expired unstarted reservation loses its token; no start remains authorized for this token.
			$detail = is_wp_error( $status ) ? $status->get_error_data() : null;
			if ( is_array( $detail ) && isset( $detail['status'] ) && 409 === $detail['status'] ) { agent_job_clear( $state ); return false; }
			return $job;
		}
		$until = isset( $job['start_until'] ) && is_string( $job['start_until'] ) ? strtotime( $job['start_until'] ) : false;
		if ( 'running' !== $job['status'] || ! $until || $until <= time() || $until > time() + 120 || $job['payload'] !== $record['job']['payload'] ) { return job_error( 'execution_window', 'The start authorization is invalid or expired.' ); }
		$fence = array( 'site_id' => $state['site_id'], 'job_id' => $job['id'] );
		if ( get_option( 'sunrise_remote_job_fence' ) !== $fence && ! update_option( 'sunrise_remote_job_fence', $fence, false ) ) { return job_error( 'storage_failed', 'Could not reserve local execution.' ); }
		$record['phase'] = 'executing'; agent_job_save( $state, $record );
		// File operations may exceed the connection lock's lifetime. Allow reporting, then reload its latest sequence.
		\WP_Upgrader::release_lock( 'sunrise_agent' ); $agent_locked = false;
		if ( false !== $priority ) { remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $priority ); }
		ob_start();
		try {
			$task = $job['payload']; $native = array( 'action' => 'update', 'type' => $task['type'], 'id' => $task['installed_id'], 'version' => $task['version'] );
			$result = job_permission( $native, true );
			foreach ( agent_states() as $connection ) { if ( ! empty( $connection['paused'] ) && agent_owner_valid( $connection ) ) { $result = job_error( 'site_paused', 'This installation is paused.' ); } }
			if ( ! agent_owner_valid( $state ) || $until <= time() ) { $result = job_error( 'execution_window', 'Start authorization expired.' ); }
			if ( 'core' === $task['type'] ) { $current = get_bloginfo( 'version' ); }
			elseif ( 'plugin' === $task['type'] ) { wp_clean_plugins_cache( false ); $plugins = get_plugins(); $current = isset( $plugins[ $task['installed_id'] ] ) ? $plugins[ $task['installed_id'] ]['Version'] : ''; }
			else { wp_clean_themes_cache( false ); $current = wp_get_theme( $task['installed_id'] )->get( 'Version' ); }
			if ( $current !== $task['from_version'] && $current !== $task['version'] ) { $result = job_error( 'installed_version_changed', 'The installed version changed after approval.' ); }
			if ( 'core' !== $task['type'] && $task['identity'] != agent_item_identity( $task['type'] . 's', $task['installed_id'] ) ) { $result = job_error( 'component_identity_changed', 'The component identity changed after approval.' ); }
			if ( ! is_wp_error( $result ) ) { $result = install_update( $native ); }
			$record['status'] = is_wp_error( $result ) ? 'failed' : 'succeeded';
			$record['code'] = is_wp_error( $result ) ? preg_replace( '/[^a-z_]/', '', substr( $result->get_error_code(), 0, 80 ) ) : $result['code'];
			if ( ! $record['code'] ) { $record['code'] = 'update_failed'; }
		} catch ( \Throwable $error ) { $record['status'] = 'uncertain'; $record['code'] = 'execution_interrupted'; }
		$fence['result'] = array( 'status' => $record['status'], 'code' => $record['code'] );
		if ( ! update_option( 'sunrise_remote_job_fence', $fence, false ) ) { return job_error( 'storage_failed', 'Could not persist the installation outcome; inspect the site.' ); }
		if ( ! ( $agent_locked = \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) ) { return job_error( 'busy', 'The installation result is retained; another report is running.' ); }
		$latest = agent_state();
		if ( empty( $latest['site_id'] ) || $latest['site_id'] !== $state['site_id'] ) { return job_error( 'execution_interrupted', 'The connection changed during installation.' ); }
		$state = $latest;
		$record['phase'] = 'result'; agent_job_save( $state, $record );
		return agent_job_result( $state, $record );
	} catch ( \Throwable $error ) { return job_error( 'execution_interrupted', 'Inspect this installation; the stored execution will not be repeated automatically.' ); }
	finally {
		while ( ob_get_level() > $buffer ) { ob_end_clean(); }
		if ( false !== $priority ) { add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $priority ); }
		if ( $auto ) { \WP_Upgrader::release_lock( 'auto_updater' ); }
		if ( is_resource( $file ) ) { flock( $file, LOCK_UN ); fclose( $file ); }
		if ( $worker ) { \WP_Upgrader::release_lock( 'sunrise_worker' ); }
		if ( $agent_locked ) { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
	}
}
