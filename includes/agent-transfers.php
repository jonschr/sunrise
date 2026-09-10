<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

function agent_database_cleanup( $id ) {
	require_once __DIR__ . '/transfer-files.php';
	$root = transfer_file_workspace( $id );
	foreach ( glob( $root . '/database-*.jsonl' ) ?: array() as $path ) { if ( ! is_link( $path ) && is_file( $path ) ) { unlink( $path ); } }
	if ( is_dir( $root ) && count( scandir( $root ) ) === 2 ) { rmdir( $root ); }
}

/** At most one read-only preparation per successful synchronization. Persist before sending for exact retries. */
function agent_prepare_transfer() {
	require_once __DIR__ . '/transfer-inventory.php';
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
	try {
		$state = agent_state();
		if ( empty( $state['transfer_previews'] ) ) { return false; }
		$pending = isset( $state['transfer_report'] ) ? $state['transfer_report'] : null;
		if ( $pending && ( ! is_array( $pending ) || ! isset( $pending['id'], $pending['phase'], $pending['deadline'], $pending['payload'] ) || ! is_string( $pending['id'] ) || ! wp_is_uuid( $pending['id'], 4 ) || ! in_array( $pending['phase'], array( 'source', 'destination' ), true ) || ! is_int( $pending['deadline'] ) || $pending['deadline'] < time() || strlen( wp_json_encode( $pending ) ) > 1572864 ) ) {
			if ( is_array( $pending ) && isset( $pending['id'], $pending['payload']['scope'] ) && 'database' === $pending['payload']['scope'] && is_string( $pending['id'] ) && wp_is_uuid( $pending['id'], 4 ) ) { agent_database_cleanup( $pending['id'] ); }
			unset( $state['transfer_report'] ); if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not clear expired transfer preparation.' ); } $pending = null;
		}
		if ( ! $pending ) {
			$result = agent_http( 'agent/transfers/claim', (object) array(), $state ); if ( is_wp_error( $result ) ) { return $result; }
			if ( ! array_key_exists( 'transfer', $result ) ) { return new \WP_Error( 'sunrise_transfer_response', 'Invalid transfer response.' ); }
			$task = $result['transfer']; if ( null === $task ) { return false; }
			if ( ! is_array( $task ) || ! isset( $task['id'], $task['phase'], $task['deadline'], $task['names'] ) || ! is_string( $task['id'] ) || ! wp_is_uuid( $task['id'], 4 ) || ! in_array( $task['phase'], array( 'source', 'destination' ), true ) || ! is_string( $task['deadline'] ) || ! is_array( $task['names'] ) || ! $task['names'] || count( $task['names'] ) > 7 || ( array( 'database' ) !== $task['names'] && array_diff( $task['names'], transfer_option_names() ) ) ) { return new \WP_Error( 'sunrise_transfer_response', 'Invalid transfer task.' ); }
			$deadline = strtotime( $task['deadline'] );
			if ( false === $deadline || $deadline <= time() || $deadline > time() + 73 * HOUR_IN_SECONDS ) { return new \WP_Error( 'sunrise_transfer_expired', 'Transfer task expired or has an invalid deadline.' ); }
			if ( isset( $task['file_selection'] ) ) {
				require_once __DIR__ . '/agent-file-transfers.php';
				$payload = 'source' === $task['phase'] ? agent_file_source( $task ) : transfer_file_preview( $task['snapshot'] ?? null );
				if ( false === $payload ) { agent_file_continue(); return false; }
				if ( is_wp_error( $payload ) && 'sunrise_agent_http' === $payload->get_error_code() ) { return $payload; }
			} elseif ( array( 'database' ) === $task['names'] ) {
				require_once __DIR__ . '/transfer-database.php';
				$payload = 'source' === $task['phase'] ? database_export( $task['id'] ) : database_preview( isset( $task['snapshot'] ) ? $task['snapshot'] : null );
			} else { $payload = 'source' === $task['phase'] ? transfer_option_snapshot( $task['names'] ) : transfer_option_preview( isset( $task['snapshot'] ) ? $task['snapshot'] : null ); }
			if ( is_wp_error( $payload ) ) { $payload = array( 'error' => 'preparation_failed' ); }
			if ( false === wp_json_encode( $payload ) ) { $payload = array( 'error' => 'preparation_failed' ); }
			$pending = array( 'id' => $task['id'], 'phase' => $task['phase'], 'deadline' => $deadline, 'payload' => $payload );
			$state['transfer_report'] = $pending;
			if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not persist transfer preparation.' ); }
		}
		$result = agent_http( 'agent/transfers/' . $pending['id'] . '/' . $pending['phase'], $pending['payload'], $state );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['remote_code'] ) && in_array( $data['remote_code'], array( 'transfer_expired', 'not_found', 'requester_access_revoked', 'enrollment_revoked' ), true ) ) { if ( 'source' === $pending['phase'] && ( $pending['payload']['scope'] ?? '' ) === 'database' ) { agent_database_cleanup( $pending['id'] ); } unset( $state['transfer_report'] ); agent_store( $state ); }
			return $result;
		}
		if ( empty( $result['transfer']['accepted'] ) || true !== $result['transfer']['accepted'] ) { return new \WP_Error( 'sunrise_transfer_response', 'Invalid transfer receipt.' ); }
		if ( 'source' === $pending['phase'] && ( $pending['payload']['scope'] ?? '' ) === 'files' ) { require_once __DIR__ . '/transfer-files.php'; $archive = transfer_file_workspace( $pending['id'] ) . '/source.zip'; if ( is_file( $archive ) ) { unlink( $archive ); } }
		if ( 'source' === $pending['phase'] && ( $pending['payload']['scope'] ?? '' ) === 'database' ) { agent_database_cleanup( $pending['id'] ); }
		unset( $state['transfer_report'] );
		$state['last_transfer_preparation'] = array( 'id' => $pending['id'], 'phase' => $pending['phase'], 'at' => time(), 'failed' => isset( $pending['payload']['error'] ) );
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not persist transfer receipt.' ); }
		return true;
	} finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}
