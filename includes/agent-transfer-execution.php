<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-options.php';
require_once __DIR__ . '/agent-jobs.php';

function agent_transfer_response( $response, $expected = null ) {
	if ( is_wp_error( $response ) ) { return $response; }
	if ( ! array_key_exists( 'transfer', $response ) ) { return new \WP_Error( 'sunrise_transfer_response', 'Invalid execution response.' ); }
	$job = $response['transfer']; if ( null === $job && null === $expected ) { return null; }
	if ( ! is_array( $job ) || ! isset( $job['id'], $job['status'], $job['revision'], $job['plan_json'], $job['plan_hash'] ) || ! is_string( $job['id'] ) || ! wp_is_uuid( $job['id'], 4 ) || ! is_int( $job['revision'] ) || $job['revision'] < 1 || ! in_array( $job['status'], array( 'approved', 'running', 'succeeded', 'failed', 'uncertain', 'closed_unverified' ), true ) || ! is_string( $job['plan_json'] ) || strlen( $job['plan_json'] ) > 262144 || ! is_string( $job['plan_hash'] ) || ! hash_equals( hash( 'sha256', $job['plan_json'] ), $job['plan_hash'] ) || ( $expected && ( $job['id'] !== $expected['id'] || $job['plan_json'] !== $expected['plan_json'] || $job['plan_hash'] !== $expected['plan_hash'] ) ) ) { return new \WP_Error( 'sunrise_transfer_response', 'Invalid execution response.' ); }
	return $job;
}

/** One short settings transaction. No retry may cross the persisted executing boundary. */
function agent_run_transfer() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_transfer_busy', 'Connection work is running.' ); }
	$file = null;
	try {
		$file = agent_execution_lock(); if ( is_wp_error( $file ) ) { return $file; }
		$state = agent_state(); $site = $state['site_id'];
		$save = function ( $record ) use ( &$state, $site ) {
			// A later check-in must never have its transport sequence replaced by an older execution snapshot.
			$latest = agent_state(); if ( empty( $latest['site_id'] ) || $latest['site_id'] !== $site ) { throw new \RuntimeException( 'Connection changed during execution' ); }
			$latest['remote_transfer'] = $record;
			if ( ! agent_store( $latest ) ) { throw new \RuntimeException( 'Could not persist execution state' ); } $state = $latest;
		};
		$clear = function () use ( &$state, $save, $site ) {
			$fence = get_option( 'sunrise_remote_job_fence' );
			if ( $fence && isset( $fence['kind'], $fence['site_id'], $fence['job_id'] ) && 'transfer' === $fence['kind'] && $fence['site_id'] === $site && $fence['job_id'] === $state['remote_transfer']['job']['id'] && ! delete_option( 'sunrise_remote_job_fence' ) ) { throw new \RuntimeException( 'Could not release transfer fence' ); }
			$save( null );
		};
		$record = isset( $state['remote_transfer'] ) ? $state['remote_transfer'] : null;
		$fence = get_option( 'sunrise_remote_job_fence' );
		if ( $fence && ( ! $record || empty( $fence['kind'] ) || 'transfer' !== $fence['kind'] || $fence['site_id'] !== $site || $fence['job_id'] !== $record['job']['id'] ) ) { return new \WP_Error( 'sunrise_transfer_busy', 'An existing execution needs recovery.' ); }
		if ( ! $record ) {
			if ( empty( $state['transfer_execution'] ) || ! empty( $state['remote_job'] ) ) { return false; }
			foreach ( agent_states() as $connection ) { if ( ! empty( $connection['paused'] ) && agent_owner_valid( $connection ) ) { return false; } }
			$job = agent_transfer_response( agent_http( 'agent/transfers/execution/claim', (object) array(), $state ) );
			if ( is_wp_error( $job ) || null === $job ) { return $job; }
			if ( 'approved' !== $job['status'] ) { return new \WP_Error( 'sunrise_transfer_response', 'Expected a fresh approved transfer.' ); }
			$record = array( 'phase' => 'starting', 'token' => bin2hex( random_bytes( 32 ) ), 'job' => $job ); $save( $record );
		}
		$job = agent_transfer_response( array( 'transfer' => isset( $record['job'] ) ? $record['job'] : null ) );
		if ( is_wp_error( $job ) || ! $job || empty( $record['phase'] ) || ! in_array( $record['phase'], array( 'starting', 'executing', 'result', 'uncertain' ), true ) || ! isset( $record['token'] ) || ! is_string( $record['token'] ) || ! preg_match( '/^[0-9a-f]{64}$/D', $record['token'] ) ) { throw new \RuntimeException( 'Invalid retained execution' ); }
		$body = array( 'execution_token' => $record['token'], 'plan_hash' => $job['plan_hash'] );
		$path = 'agent/transfers/execution/' . $job['id'] . '/';
		if ( 'uncertain' === $record['phase'] ) {
			$current = agent_transfer_response( agent_http( $path . 'status', $body, $state ), $job );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( 'closed_unverified' === $current['status'] && ! empty( $current['worker_stopped'] ) ) { $clear(); return true; } return false;
		}
		if ( 'executing' === $record['phase'] ) {
			// The process lock proves the previous worker stopped. Only its committed journal proves a write.
			$journals = transfer_option_journals( $job['id'] ); if ( is_wp_error( $journals ) ) { return $journals; }
			$record['outcome'] = 'unknown';
			foreach ( $journals as $journal ) { if ( $journal['id'] === $job['id'] && $journal['plan_hash'] === $job['plan_hash'] ) { $record['outcome'] = $journal['state']; foreach ( $journal['changes'] as $change ) { wp_cache_delete( $change['name'], 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); break; } }
			$record['phase'] = 'result'; $save( $record );
		}
		if ( 'starting' === $record['phase'] ) {
			if ( ! $fence ) {
				$fence = array( 'kind' => 'transfer', 'site_id' => $site, 'job_id' => $job['id'], 'plan_hash' => $job['plan_hash'], 'start_until' => 0 );
				if ( ! update_option( 'sunrise_remote_job_fence', $fence, false ) ) { throw new \RuntimeException( 'Could not retain the start fence' ); }
			}
			$current = agent_transfer_response( agent_http( $path . 'start', $body + array( 'revision' => $job['revision'] ), $state ), $job );
			if ( is_wp_error( $current ) ) {
				$info = $current->get_error_data();
				if ( is_array( $info ) && isset( $info['remote_code'] ) && in_array( $info['remote_code'], array( 'execution_not_allowed', 'execution_conflict', 'not_found' ), true ) ) { $clear(); }
				return $current;
			}
			$until = isset( $current['start_until'] ) && is_string( $current['start_until'] ) ? strtotime( $current['start_until'] ) : false;
			// A retained starting record has never called the writer. An expired or revoked start is safely not-applied.
			if ( ! in_array( $current['status'], array( 'running', 'uncertain' ), true ) ) { throw new \RuntimeException( 'Unexpected execution outcome' ); }
			if ( 'running' !== $current['status'] || ! $until || $until <= time() || $until > time() + 120 ) { $record['phase'] = 'result'; $record['outcome'] = 'not_applied'; $save( $record ); }
			else {
				$fence = array( 'kind' => 'transfer', 'site_id' => $site, 'job_id' => $job['id'], 'plan_hash' => $job['plan_hash'], 'start_until' => $until );
				if ( ! update_option( 'sunrise_remote_job_fence', $fence, false ) && get_option( 'sunrise_remote_job_fence' ) !== $fence ) { throw new \RuntimeException( 'Could not persist execution fence' ); }
				$record['phase'] = 'executing'; $save( $record );
				$result = transfer_options_commit( $job['id'], $job['plan_json'], $job['plan_hash'], false, $file );
				$record['outcome'] = is_wp_error( $result ) ? ( 'sunrise_transfer_commit_unknown' === $result->get_error_code() ? 'unknown' : 'not_applied' ) : $result['state'];
				$record['phase'] = 'result'; $save( $record );
			}
		}
		if ( 'result' === $record['phase'] ) {
			$current = agent_transfer_response( agent_http( $path . 'result', $body + array( 'outcome' => $record['outcome'], 'worker_stopped' => true ), $state ), $job );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! isset( $current['outcome'], $current['worker_stopped'] ) || $current['outcome'] !== $record['outcome'] || true !== $current['worker_stopped'] || ! in_array( $current['status'], array( 'succeeded', 'failed', 'uncertain' ), true ) ) { throw new \RuntimeException( 'Invalid outcome receipt' ); }
			if ( 'uncertain' === $current['status'] ) { $record['phase'] = 'uncertain'; $save( $record ); } else { $clear(); }
			return true;
		}
		return false;
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_transfer_interrupted', 'Transfer execution needs inspection. Retained execution state will not be reapplied automatically.' ); }
	finally { if ( is_resource( $file ) ) { flock( $file, LOCK_UN ); fclose( $file ); } \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}
