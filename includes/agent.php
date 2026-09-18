<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

const AGENT_INTERVAL = 5 * MINUTE_IN_SECONDS;

/** The staging origin is the default; a trusted wp-config.php override supports local development. */
function agent_control_origin( $value, $local ) {
	if ( ! is_string( $value ) ) { return false; }
	if ( $local && 'http://127.0.0.1:8787' === $value ) { return $value; }
	$parts = wp_parse_url( $value );
	if ( ! $parts || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== $parts['scheme']
		|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
		|| ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] )
		|| ! preg_match( '/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/iD', $parts['host'] ) ) { return false; }
	return 'https://' . strtolower( $parts['host'] );
}

function agent_url() {
	return agent_control_origin( defined( 'SUNRISE_CONTROL_URL' ) ? SUNRISE_CONTROL_URL : 'https://sunrise-staging.elod.in', 'local' === wp_get_environment_type() );
}

/** Navigation hints only; the Control origin separately authenticates and authorizes the human. */
function agent_dashboard_url() {
	$state = agent_state();
	if ( ! current_user_can( 'manage_options' ) || ! $state || ! empty( $state['revoked'] ) || ! agent_owner_valid( $state ) || ! agent_service_matches( $state ) || is_wp_error( installation_guard() ) ) { return false; }
	foreach ( array( 'account_id', 'network_id', 'site_id' ) as $key ) {
		if ( empty( $state[ $key ] ) || ! is_string( $state[ $key ] ) || ! wp_is_uuid( $state[ $key ], 4 ) ) { return false; }
	}
	$path = '/v1/accounts/' . $state['account_id'] . '/networks/' . $state['network_id'];
	return add_query_arg( 'network', $path, agent_url() . '/' );
}

function agent_service_matches( $state ) {
	// Existing pilot credentials belong only to the original loopback service.
	return agent_url() && ( isset( $state['control_url'] ) ? $state['control_url'] : 'http://127.0.0.1:8787' ) === agent_url();
}

function agent_owner_valid( $state ) {
	$user = get_user_by( 'id', $state['user_id'] );
	return $user && user_can( $user, 'manage_options' ) && user_can( $user, 'update_plugins' ) && user_can( $user, 'update_themes' ) && user_can( $user, 'update_core' );
}

/** Non-autoloaded state; a WordPress installation normally has only a few connecting administrators. */
function agent_states() {
	$states = get_option( 'sunrise_agents', array() );
	$legacy = get_option( 'sunrise_agent', array() );
	if ( $legacy && ! isset( $states[ $legacy['user_id'] ] ) ) {
		$legacy['paused'] = (bool) get_option( 'sunrise_agent_pause' );
		$legacy['revoked'] = (bool) get_option( 'sunrise_agent_revoked' );
		$states[ $legacy['user_id'] ] = $legacy;
	}
	return $states;
}

function agent_state() {
	$states = agent_states();
	return isset( $states[ get_current_user_id() ] ) ? $states[ get_current_user_id() ] : array();
}

/** Caller holds sunrise_agent lock. Migrate the legacy connection without rotating credentials. */
function agent_store_states( $states ) {
	if ( get_option( 'sunrise_agents', null ) !== $states && ! update_option( 'sunrise_agents', $states, false ) ) { return false; }
	$legacy = get_option( 'sunrise_agent', array() );
	if ( $legacy ) {
		$next = wp_next_scheduled( 'sunrise_check_in' );
		if ( isset( $states[ $legacy['user_id'] ] ) ) { agent_schedule( $next ? min( AGENT_INTERVAL, max( 30, $next - time() ) ) : AGENT_INTERVAL, $legacy['user_id'] ); }
		wp_clear_scheduled_hook( 'sunrise_check_in' );
	}
	delete_option( 'sunrise_agent' ); delete_option( 'sunrise_agent_pause' ); delete_option( 'sunrise_agent_revoked' );
	return true;
}

function agent_store( $state ) {
	$states = agent_states(); $states[ $state['user_id'] ] = $state;
	return agent_store_states( $states );
}

/** Retire migration work without deleting any private recovery files left by an older version. */
function agent_retire_migrations() {
	if ( get_option( 'sunrise_migrations_retired_0310' ) ) { return; }
	$fence = get_option( 'sunrise_remote_job_fence', array() );
	if ( isset( $fence['kind'] ) && 'transfer' === $fence['kind'] ) { delete_option( 'sunrise_remote_job_fence' ); }
	$states = agent_states();
	foreach ( $states as &$state ) {
		foreach ( array( 'remote_transfer', 'transfer_report', 'transfer_previews', 'transfer_execution', 'file_transfers' ) as $key ) { unset( $state[ $key ] ); }
	}
	unset( $state );
	agent_store_states( $states );
	wp_unschedule_hook( 'sunrise_transfer_continue' );
	delete_metadata( 'user', 0, 'sunrise_migration_draft', '', true );
	delete_metadata( 'user', 0, 'sunrise_migration_pair_pending', '', true );
	foreach ( array( 'sunrise_migration_pairs', 'sunrise_database_active', 'sunrise_database_last' ) as $name ) { delete_option( $name ); }
	update_option( 'sunrise_migrations_retired_0310', true, false );
}
add_action( 'init', __NAMESPACE__ . '\\agent_retire_migrations', 1 );

/** Enrollment is open to each administrator; connection operations require their own enrollment. */
function agent_access( $enrollment = false ) {
	if ( ! current_user_can( 'manage_options' ) || ( ! $enrollment && agent_states() && ! agent_state() ) ) {
		return new \WP_Error( 'sunrise_agent_forbidden', 'Connect this site with your own Sunrise account first.', array( 'status' => 403 ) );
	}
	return true;
}

function agent_http( $path, $data, $state, $method = 'POST', $identity_required = true ) {
	$endpoint = preg_replace( '/[0-9a-f]{8}-[0-9a-f-]{27}/i', '{id}', $path );
	if ( ! empty( $state['revoked'] ) ) { agent_log( 'request_blocked', array( 'endpoint' => $endpoint, 'code' => 'sunrise_disconnected' ) ); return new \WP_Error( 'sunrise_disconnected', 'This connection was disconnected. Reconnect this administrator to continue.', array( 'status' => 401 ) ); }
	if ( $identity_required ) { $identity = installation_guard(); if ( is_wp_error( $identity ) ) { agent_log( 'request_blocked', array( 'endpoint' => $endpoint, 'code' => $identity->get_error_code() ) ); return $identity; } }
	if ( ! agent_owner_valid( $state ) || (int) $state['user_id'] !== get_current_user_id() ) { agent_log( 'request_blocked', array( 'endpoint' => $endpoint, 'code' => 'sunrise_agent_owner' ) ); return new \WP_Error( 'sunrise_agent_owner', 'Connection owner access required.' ); }
	if ( ! agent_service_matches( $state ) || $state['url'] !== untrailingslashit( site_url() ) ) {
		agent_log( 'request_blocked', array( 'endpoint' => $endpoint, 'code' => 'sunrise_agent_environment' ) );
		return new \WP_Error( 'sunrise_agent_environment', 'The enrolled site URL or Control address changed. Reconnect this installation.' );
	}
	require_once __DIR__ . '/controller.php';
	$secret = credential( $state['secret'], 'agent|' . $state['url'] . '|' . $state['user_id'], true );
	if ( is_wp_error( $secret ) ) { agent_log( 'request_authentication_failed', array_merge( array( 'endpoint' => $endpoint ), agent_error_details( $secret ) ) ); return $secret; }
	$request = 'http://127.0.0.1:8787' === agent_url() ? 'wp_remote_request' : 'wp_safe_remote_request';
	$response = $request( agent_url() . '/v1/' . $path, array(
		'method' => $method, 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
		'headers' => array( 'Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json' ),
		'user-agent' => 'SunriseAgent/' . VERSION . ' (+' . home_url( '/' ) . ')',
		'body' => wp_json_encode( $data ),
	) );
	if ( is_wp_error( $response ) ) { agent_log( 'request_transport_failed', array( 'endpoint' => $endpoint, 'method' => $method, 'transport_code' => $response->get_error_code() ) ); return new \WP_Error( 'sunrise_agent_unreachable', 'Central service unavailable. The last applied policy is retained.' ); }
	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
		$remote_code = isset( $body['error']['code'] ) && is_string( $body['error']['code'] ) && preg_match( '/^[a-z_]{1,80}$/D', $body['error']['code'] ) ? $body['error']['code'] : null;
		agent_log( 'request_rejected', array( 'endpoint' => $endpoint, 'method' => $method, 'status' => $code, 'remote_code' => $remote_code ) );
		$error_data = array( 'status' => $code, 'remote_code' => $remote_code, 'retry_after' => max( 60, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) );
		if ( 'sequence_conflict' === $remote_code && isset( $body['error']['server_sequence'] ) && is_int( $body['error']['server_sequence'] ) && $body['error']['server_sequence'] >= 0 ) { $error_data['server_sequence'] = $body['error']['server_sequence']; }
		return new \WP_Error( 'sunrise_agent_http', 'Central service rejected the request.', $error_data );
	}
	return $body;
}

/** Rotate a credential only after Control approves the existing site record. */
function agent_reconnect( $state ) {
	require_once __DIR__ . '/controller.php';
	if ( ! agent_reconnectable( $state ) || empty( $state['site_id'] ) || empty( $state['generation'] ) || empty( $state['account_id'] ) || empty( $state['network_id'] ) ) {
		agent_log( 'recovery_blocked', array_merge( array( 'reason' => 'connection_or_identity_mismatch' ), agent_installation_diagnostics() ) );
		return new \WP_Error( 'sunrise_identity_review', 'This installation changed. Review its identity in Sunrise before reconnecting.', array( 'status' => 409 ) );
	}
	if ( empty( $state['reconnect'] ) ) {
		$secret = bin2hex( random_bytes( 32 ) );
		$encrypted = credential( $secret, 'agent|' . $state['url'] . '|' . $state['user_id'] );
		if ( is_wp_error( $encrypted ) ) { return $encrypted; }
		$state['secret'] = $encrypted;
		$state['digest'] = hash( 'sha256', $secret );
		$state['reconnect'] = array( 'site_id' => $state['site_id'], 'generation' => (int) $state['generation'] );
		unset( $state['pending_report'] );
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not save the reconnection request.' ); }
		agent_log( 'recovery_started', array( 'site_id' => $state['site_id'], 'base_generation' => (int) $state['generation'] ) );
	}
	if ( empty( $state['reconnect']['enrollment_id'] ) ) {
		$identity = installation_identity();
		$result = agent_http( 'enrollments', array(
			'credential_digest' => $state['digest'], 'url' => $state['url'], 'local_user_id' => (int) $state['user_id'], 'environment' => wp_get_environment_type(),
			'reconnect_site_id' => $state['reconnect']['site_id'], 'reconnect_generation' => $state['reconnect']['generation'], 'installation_id' => $identity['id'],
		), $state, 'POST', false );
		if ( is_wp_error( $result ) ) { agent_log( 'recovery_enrollment_failed', agent_error_details( $result ) ); return $result; }
		$state['reconnect']['enrollment_id'] = $result['id'];
		$state['reconnect']['approval_url'] = $result['approval_url'] ?? null;
		$state['reconnect']['phrase'] = $result['phrase'] ?? null;
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not save the reconnection request.' ); }
		agent_log( 'recovery_enrollment_saved', array( 'enrollment_id' => $result['id'] ) );
	}
	$assignment = agent_http( 'enrollments/' . $state['reconnect']['enrollment_id'] . '/exchange', array(), $state, 'POST', false );
	if ( is_wp_error( $assignment ) ) {
		agent_log( 'recovery_exchange_failed', array_merge( array( 'enrollment_id' => $state['reconnect']['enrollment_id'] ), agent_error_details( $assignment ) ) );
		$data = $assignment->get_error_data();
		if ( is_array( $data ) && 410 === ( $data['status'] ?? null ) ) { unset( $state['reconnect']['enrollment_id'] ); agent_store( $state ); }
		return $assignment;
	}
	if ( 'approved' !== ( $assignment['status'] ?? null ) ) { agent_log( 'recovery_waiting_for_approval', array( 'enrollment_id' => $state['reconnect']['enrollment_id'] ) ); return new \WP_Error( 'sunrise_reconnect_pending', 'Approve this reconnection in Sunrise Control.', array( 'status' => 202 ) ); }
	if ( $assignment['site_id'] !== $state['reconnect']['site_id'] || (int) $assignment['generation'] <= $state['reconnect']['generation']
		|| $assignment['account_id'] !== $state['account_id'] || $assignment['network_id'] !== $state['network_id'] ) {
		agent_log( 'recovery_assignment_rejected', array( 'expected_site_id' => $state['reconnect']['site_id'], 'received_site_id' => $assignment['site_id'] ?? null, 'base_generation' => (int) $state['reconnect']['generation'], 'received_generation' => isset( $assignment['generation'] ) ? (int) $assignment['generation'] : null ) );
		return new \WP_Error( 'sunrise_reconnect_invalid', 'Sunrise Control returned an invalid reconnection.' );
	}
	$identity = installation_identity();
	if ( ! installation_anchor() && ! installation_write_anchor( $identity['id'] ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not restore the installation identity.' ); }
	$identity = array_merge( $identity, installation_markers( $identity['id'] ), array( 'review' => false, 'anchor_required' => true ) );
	if ( ! update_option( 'sunrise_installation', $identity, false ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not confirm the installation identity.' ); }
	$state['enrollment_id'] = $state['reconnect']['enrollment_id'];
	$state['generation'] = (int) $assignment['generation'];
	unset( $state['reconnect'] );
	if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not finish the reconnection.' ); }
	agent_log( 'recovery_completed', array( 'site_id' => $state['site_id'], 'generation' => (int) $state['generation'], 'enrollment_id' => $state['enrollment_id'] ) );
	return true;
}

/** A started reconnection may finish after migration cleanup changes its original local markers. */
function agent_reconnectable( $state ) {
	if ( empty( $state['url'] ) || $state['url'] !== untrailingslashit( site_url() ) ) { return false; }
	$identity = installation_identity(); $anchor = installation_anchor();
	$markers = is_array( $identity ) && ! empty( $identity['id'] ) ? installation_markers( $identity['id'] ) : array();
	return is_array( $identity ) && ! empty( $identity['id'] ) && wp_is_uuid( $identity['id'], 4 ) && isset( $identity['url_hash'] ) && hash_equals( $identity['url_hash'], $markers['url_hash'] ) && ( ! $anchor || hash_equals( $identity['id'], $anchor ) );
}

function agent_enroll( $intent = null ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
	try { return agent_enroll_locked( $intent ); } finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}

function agent_enroll_locked( $intent = null ) {
	if ( ! agent_url() || ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'sunrise_agent_forbidden', 'Administrator access and a trusted SUNRISE_CONTROL_URL are required.' ); }
	$access = agent_access( true ); if ( is_wp_error( $access ) ) { return $access; }
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	$state = agent_state();
	if ( ! empty( $state['revoked'] ) ) {
		$cleared = agent_disconnect_locked(); if ( is_wp_error( $cleared ) ) { return $cleared; } $state = array();
	}
	if ( ! $state ) {
		require_once __DIR__ . '/controller.php';
		$state = array( 'control_url' => agent_url(), 'url' => untrailingslashit( site_url() ), 'user_id' => get_current_user_id(), 'sequence' => 0 );
		if ( ! agent_owner_valid( $state ) ) { return new \WP_Error( 'sunrise_agent_forbidden', 'The enrolling administrator needs all update capabilities.' ); }
		$secret = bin2hex( random_bytes( 32 ) );
		$state['secret'] = credential( $secret, 'agent|' . $state['url'] . '|' . $state['user_id'] );
		if ( is_wp_error( $state['secret'] ) ) { return $state['secret']; }
		$state['digest'] = hash( 'sha256', $secret );
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Enrollment already started. Retry.' ); }
	}
	if ( (int) $state['user_id'] !== get_current_user_id() ) { return new \WP_Error( 'sunrise_agent_forbidden', 'The enrolling administrator must finish or disconnect this enrollment.' ); }
	if ( empty( $state['site_id'] ) ) {
		$previous = $state;
		$request = array( 'credential_digest' => $state['digest'], 'url' => $state['url'], 'local_user_id' => $state['user_id'], 'environment' => wp_get_environment_type() );
		if ( is_string( $intent ) && preg_match( '/^[a-f0-9]{64}$/D', $intent ) ) { $request['intent_digest'] = hash( 'sha256', $intent ); }
		$result = agent_http( 'enrollments', $request, $state );
		if ( is_wp_error( $result ) ) { return $result; }
		$state['enrollment_id'] = $result['id'];
		$state['approval_url'] = $result['approval_url'];
		$state['phrase'] = $result['phrase'];
		if ( $previous !== $state && ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not persist enrollment.' ); }
	}
	agent_schedule( 60 );
	return array( 'approval_url' => $intent ? add_query_arg( 'intent', $intent, $state['approval_url'] ) : $state['approval_url'], 'phrase' => $state['phrase'] );
}

function agent_schedule( $delay, $user_id = null ) {
	$args = array( null === $user_id ? get_current_user_id() : (int) $user_id );
	if ( ! wp_next_scheduled( 'sunrise_check_in', $args ) ) { wp_schedule_single_event( time() + max( 30, $delay ) + wp_rand( 0, 30 ), 'sunrise_check_in', $args ); }
}

/** Bounded, non-secret operational history for support copy/paste. */
function agent_log( $event, $details = array() ) {
	$cutoff = time() - 14 * DAY_IN_SECONDS;
	$entries = array_values( array_filter( get_option( 'sunrise_agent_log', array() ), function ( $entry ) use ( $cutoff ) { return isset( $entry['last_at'] ) && $entry['last_at'] >= $cutoff; } ) );
	$entry = array( 'event' => sanitize_key( $event ), 'user_id' => get_current_user_id(), 'details' => $details );
	$last = $entries ? count( $entries ) - 1 : null;
	if ( null !== $last && $entries[ $last ]['event'] === $entry['event'] && ( $entries[ $last ]['user_id'] ?? null ) === $entry['user_id'] && $entries[ $last ]['details'] === $details ) {
		$entries[ $last ]['last_at'] = time(); $entries[ $last ]['count']++;
	} else {
		$entry['first_at'] = time(); $entry['last_at'] = time(); $entry['count'] = 1; $entries[] = $entry;
	}
	update_option( 'sunrise_agent_log', array_slice( $entries, -500 ), false );
}

function agent_error_details( $error ) {
	$details = array( 'code' => $error->get_error_code() ); $data = $error->get_error_data();
	if ( is_array( $data ) && isset( $data['status'] ) ) { $details['status'] = (int) $data['status']; }
	if ( is_array( $data ) && isset( $data['remote_code'] ) ) { $details['remote_code'] = $data['remote_code']; }
	return $details;
}

function agent_installation_diagnostics() {
	$identity = installation_identity(); $anchor = installation_anchor();
	$id = is_array( $identity ) && isset( $identity['id'] ) && is_string( $identity['id'] ) ? $identity['id'] : null;
	$markers = $id && wp_is_uuid( $id, 4 ) ? installation_markers( $id ) : array();
	return array(
		'installation_id' => $id, 'anchor_id' => $anchor, 'review' => is_array( $identity ) ? (bool) ( $identity['review'] ?? true ) : true,
		'anchor_matches' => $id && $anchor ? hash_equals( $id, $anchor ) : false,
		'site_url_matches' => is_array( $identity ) && isset( $identity['url_hash'], $markers['url_hash'] ) && is_string( $identity['url_hash'] ) && hash_equals( $identity['url_hash'], $markers['url_hash'] ),
		'security_keys_match' => is_array( $identity ) && isset( $identity['salt_check'], $markers['salt_check'] ) && is_string( $identity['salt_check'] ) && hash_equals( $identity['salt_check'], $markers['salt_check'] ),
	);
}

function agent_connection_diagnostics( $state, $user_id ) {
	$next = wp_next_scheduled( 'sunrise_check_in', array( (int) $user_id ) );
	return array(
		'user_id' => (int) $user_id, 'owner_valid' => agent_owner_valid( $state ), 'connected' => ! empty( $state['site_id'] ), 'revoked' => ! empty( $state['revoked'] ), 'paused' => ! empty( $state['paused'] ),
		'control_url' => $state['control_url'] ?? null, 'site_url' => $state['url'] ?? null, 'current_site_url' => untrailingslashit( site_url() ),
		'control_matches' => agent_service_matches( $state ), 'site_url_matches' => isset( $state['url'] ) && $state['url'] === untrailingslashit( site_url() ),
		'account_id' => $state['account_id'] ?? null, 'network_id' => $state['network_id'] ?? null, 'site_id' => $state['site_id'] ?? null,
		'enrollment_id' => $state['enrollment_id'] ?? null, 'generation' => isset( $state['generation'] ) ? (int) $state['generation'] : null, 'sequence' => isset( $state['sequence'] ) ? (int) $state['sequence'] : null,
		'last_success' => isset( $state['last_success'] ) ? gmdate( 'c', $state['last_success'] ) : null, 'next_scheduled_check_in' => $next ? gmdate( 'c', $next ) : null,
		'pending_report_sequence' => isset( $state['pending_report']['sequence'] ) ? (int) $state['pending_report']['sequence'] : null,
		'recovery' => empty( $state['reconnect'] ) ? null : array( 'site_id' => $state['reconnect']['site_id'] ?? null, 'base_generation' => isset( $state['reconnect']['generation'] ) ? (int) $state['reconnect']['generation'] : null, 'enrollment_id' => $state['reconnect']['enrollment_id'] ?? null ),
		'traffic_check_in_throttled' => (bool) get_transient( 'sunrise_reconnect_fallback_' . (int) $user_id ),
	);
}

function agent_diagnostic_log() {
	$state = agent_state(); $connections = $state ? array( agent_connection_diagnostics( $state, get_current_user_id() ) ) : array();
	$cutoff = time() - 14 * DAY_IN_SECONDS;
	$events = array_filter( get_option( 'sunrise_agent_log', array() ), function ( $entry ) use ( $cutoff ) { return isset( $entry['last_at'] ) && $entry['last_at'] >= $cutoff; } );
	$events = array_map( function ( $entry ) { $entry['first_at'] = gmdate( 'c', $entry['first_at'] ); $entry['last_at'] = gmdate( 'c', $entry['last_at'] ); return $entry; }, array_reverse( $events ) );
	return array(
		'generated_at' => gmdate( 'c' ), 'sunrise_version' => VERSION, 'wordpress_version' => get_bloginfo( 'version' ), 'php_version' => PHP_VERSION,
		'environment' => wp_get_environment_type(), 'timezone' => wp_timezone_string(), 'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'installation' => agent_installation_diagnostics(), 'connections' => $connections, 'events' => $events,
	);
}

/** Keep connected sites moving on ordinary traffic when the host disables traffic-triggered WP-Cron. */
function agent_maybe_request_check_in() {
	$states = agent_states();
	if ( ! $states ) { return false; }
	$recorded_version = get_option( 'sunrise_agent_log_version' );
	if ( VERSION !== $recorded_version ) { agent_log( 'plugin_version_loaded', array( 'from' => $recorded_version ?: null, 'to' => VERSION ) ); update_option( 'sunrise_agent_log_version', VERSION, false ); }
	$attempted = false; $previous = get_current_user_id();
	try {
		foreach ( $states as $user_id => $state ) {
			$key = 'sunrise_reconnect_fallback_' . (int) $user_id;
			$identity_error = is_wp_error( installation_guard() );
			$recovery = ( $identity_error || ! empty( $state['reconnect'] ) ) && agent_reconnectable( $state );
			$overdue = ! empty( $state['site_id'] ) && ! $identity_error
				&& ( empty( $state['last_success'] ) || $state['last_success'] <= time() - AGENT_INTERVAL );
			wp_set_current_user( (int) $user_id );
			if ( $identity_error && ! $recovery ) { agent_log( 'recovery_blocked', agent_installation_diagnostics() ); continue; }
			if ( ( ! $recovery && ! $overdue ) || ! agent_owner_valid( $state ) || ! empty( $state['revoked'] ) ) { continue; }
			if ( get_transient( $key ) ) { agent_log( 'traffic_check_in_deferred', array( 'mode' => $recovery ? 'recovery' : 'overdue', 'reason' => 'five_minute_throttle' ) ); continue; }
			set_transient( $key, time(), AGENT_INTERVAL );
			agent_log( 'traffic_check_in_started', array( 'mode' => $recovery ? 'recovery' : 'overdue' ) );
			$result = agent_check_in();
			$errors = get_option( 'sunrise_reconnect_errors', array() );
			if ( is_wp_error( $result ) ) {
				agent_log( 'traffic_check_in_failed', array_merge( array( 'mode' => $recovery ? 'recovery' : 'overdue' ), agent_error_details( $result ) ) );
				if ( $recovery ) { $errors[ $user_id ] = array( 'code' => $result->get_error_code(), 'at' => time() ); }
			} else { agent_log( 'traffic_check_in_succeeded', array( 'mode' => $recovery ? 'recovery' : 'overdue' ) ); unset( $errors[ $user_id ] ); }
			if ( $errors ) { update_option( 'sunrise_reconnect_errors', $errors, false ); } else { delete_option( 'sunrise_reconnect_errors' ); }
			$attempted = true;
		}
	} finally { wp_set_current_user( $previous ); }
	return $attempted;
}
add_action( 'init', __NAMESPACE__ . '\\agent_maybe_request_check_in', 20 );

/** Apply a cadence change once on upgrade; retain earlier enrollment/job continuation events. */
function agent_migrate_schedule() {
	if ( AGENT_INTERVAL === (int) get_option( 'sunrise_agent_interval', 0 ) ) {
		foreach ( agent_states() as $user_id => $state ) {
			if ( agent_owner_valid( $state ) && empty( $state['revoked'] ) ) { agent_schedule( AGENT_INTERVAL, $user_id ); }
		}
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return; }
	try {
		$states = agent_states();
		if ( ! agent_store_states( $states ) ) { return; }
		foreach ( $states as $user_id => $state ) {
			if ( ! agent_owner_valid( $state ) || ! empty( $state['revoked'] ) ) { continue; }
			$args = array( (int) $user_id ); $next = wp_next_scheduled( 'sunrise_check_in', $args );
			if ( $next && $next <= time() + AGENT_INTERVAL + 30 ) { continue; }
			if ( $next && ! wp_unschedule_event( $next, 'sunrise_check_in', $args ) ) { return; }
			agent_schedule( AGENT_INTERVAL, $user_id );
			if ( ! wp_next_scheduled( 'sunrise_check_in', $args ) ) {
				if ( $next ) { wp_schedule_single_event( $next, 'sunrise_check_in', $args ); }
				return;
			}
		}
		update_option( 'sunrise_agent_interval', AGENT_INTERVAL, true );
	} finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}
add_action( 'init', __NAMESPACE__ . '\\agent_migrate_schedule' );

/** Header hints only: no version, secrets or remote lookups. Read fresh before an update decision. */
function agent_item_identity( $type, $id ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( 'plugins' === $type ) {
		if ( ! isset( get_plugins()[ $id ] ) ) { return null; }
		$headers = get_plugin_data( WP_PLUGIN_DIR . '/' . $id, false, false );
	} else {
		$theme = wp_get_theme( $id );
		if ( ! $theme->exists() ) { return null; }
		$headers = get_file_data( $theme->get_stylesheet_directory() . '/style.css', array( 'Name' => 'Theme Name', 'PluginURI' => 'Theme URI', 'AuthorURI' => 'Author URI', 'TextDomain' => 'Text Domain', 'UpdateURI' => 'Update URI' ) );
	}
	$identity = array();
	foreach ( array( 'name' => 'Name', 'update_uri' => 'UpdateURI', 'project_uri' => 'PluginURI', 'author_uri' => 'AuthorURI', 'text_domain' => 'TextDomain' ) as $field => $header ) {
		$value = isset( $headers[ $header ] ) ? trim( (string) $headers[ $header ] ) : '';
		$value = preg_replace( '/[\x00-\x1f\x7f]/', '', $value );
		if ( false !== strpos( $field, '_uri' ) && '' !== $value ) {
			if ( 'false' === strtolower( $value ) ) { $value = 'false'; }
			else {
				$parts = wp_parse_url( $value );
				if ( ! filter_var( $value, FILTER_VALIDATE_URL ) || ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || strlen( $value ) > 512 ) { $value = '[redacted]'; }
			}
		}
		$identity[ $field ] = wp_check_invalid_utf8( substr( $value, 0, 512 ), true );
	}
	return $identity;
}

/** Mirror standard Plugin Update Checker cache entries even when its owner only loads in wp-admin. */
function agent_puc_update_offers( $updates ) {
	if ( ! is_object( $updates ) ) { return $updates; }
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	global $wpdb; $installed = get_plugins(); $changed = false;
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'external_updates-' ) . '%' ) );
	foreach ( $rows as $row ) {
		$state = maybe_unserialize( $row ); $offer = is_object( $state ) && isset( $state->update ) && is_object( $state->update ) ? (array) $state->update : array();
		$id = isset( $offer['filename'] ) && is_string( $offer['filename'] ) ? $offer['filename'] : '';
		$version = isset( $offer['version'] ) && is_string( $offer['version'] ) ? $offer['version'] : '';
		if ( ! isset( $installed[ $id ] ) || ! isset( $state->checkedVersion ) || $installed[ $id ]['Version'] !== $state->checkedVersion || ! version_compare( $version, $state->checkedVersion, '>' ) || isset( $updates->response[ $id ] ) ) { continue; }
		if ( ! $changed ) { $updates = clone $updates; $updates->response = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array(); $updates->no_update = isset( $updates->no_update ) && is_array( $updates->no_update ) ? $updates->no_update : array(); $changed = true; }
		$offer['plugin'] = $id; $offer['new_version'] = $version; $offer['package'] = isset( $offer['download_url'] ) ? $offer['download_url'] : ''; $offer['url'] = isset( $offer['homepage'] ) ? $offer['homepage'] : '';
		$updates->response[ $id ] = (object) $offer; unset( $updates->no_update[ $id ] );
	}
	return $updates;
}
add_filter( 'site_transient_update_plugins', __NAMESPACE__ . '\\agent_puc_update_offers', 100 );

function agent_inventory() {
	require_once __DIR__ . '/api.php';
	$data = inventory();
	$restrictions = array();
	if ( $data['capabilities']['automatic_updater_disabled'] ) { $restrictions[] = 'automatic_updater_disabled'; }
	if ( ! $data['capabilities']['file_modifications_allowed'] ) { $restrictions[] = 'file_modifications_disabled'; }
	$items = array( array( 'type' => 'core', 'installed_id' => 'wordpress', 'version' => $data['core']['version'], 'active' => true,
		'update_available' => $data['core']['update_available'], 'observed_auto_update' => isset( $data['core']['updates'][0]['auto_update_selected'] ) ? $data['core']['updates'][0]['auto_update_selected'] : null, 'restrictions' => $restrictions ) );
	foreach ( array( 'plugins', 'themes' ) as $type ) {
		foreach ( $data[ $type ] as $item ) {
			$identity = agent_item_identity( $type, $item['id'] );
			$items[] = array( 'type' => $type, 'installed_id' => $item['id'], 'version' => $item['version'], 'active' => (bool) $item['active'],
				'update_available' => $item['update_available'], 'observed_auto_update' => (bool) $item['auto_update_selected'], 'restrictions' => $restrictions, 'identity' => $identity );
			if ( null === $identity ) { unset( $items[ count( $items ) - 1 ]['identity'] ); }
		}
	}
	// Negotiate the extension before sending it; older Control deployments reject unknown report fields.
	if ( ! empty( agent_state()['update_jobs'] ) ) {
		$items[0]['offered_version'] = isset( $data['core']['updates'][0]['version'] ) ? $data['core']['updates'][0]['version'] : null;
		$index = 1;
		foreach ( array( 'plugins', 'themes' ) as $type ) {
			foreach ( $data[ $type ] as $item ) { $items[ $index++ ]['offered_version'] = true === $item['update_available'] && isset( $item['update']['version'] ) ? $item['update']['version'] : null; }
		}
		foreach ( $items as &$item ) { if ( ! is_string( $item['offered_version'] ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $item['offered_version'] ) ) { $item['offered_version'] = null; } } unset( $item );
	}
	return $items;
}

function agent_accept_policy( $wire, $state ) {
	if ( ! is_array( $wire ) || ! isset( $wire['document_json'], $wire['hash'], $wire['generation'] ) || ! is_string( $wire['document_json'] ) || ! is_string( $wire['hash'] ) || ! hash_equals( hash( 'sha256', $wire['document_json'] ), $wire['hash'] ) ) {
		return new \WP_Error( 'sunrise_policy_hash', 'Policy hash mismatch.' );
	}
	$document = json_decode( $wire['document_json'], true );
	$generation = $wire['generation'];
	if ( ! is_array( $document ) || ! is_int( $generation ) || $generation < 1 || ! isset( $document['schema_version'], $document['generation'], $document['policy'], $document['category_defaults'] ) || 2 !== $document['schema_version'] || $generation !== $document['generation'] ) {
		return new \WP_Error( 'sunrise_policy_schema', 'Unsupported policy document.' );
	}
	$old = isset( $state['applied'] ) ? $state['applied'] : null;
	if ( $old && ( $generation < $old['generation'] || ( $generation === $old['generation'] && $wire['hash'] !== $old['hash'] ) ) ) {
		return new \WP_Error( 'sunrise_policy_replay', 'Obsolete or conflicting policy generation.' );
	}
	$policy = $document['policy'];
	if ( ! is_array( $policy ) || array_diff( array_keys( $policy ), array( 'site', 'core', 'plugins', 'themes' ) ) || ! isset( $policy['site'], $policy['core'], $policy['plugins'], $policy['themes'] ) || 'inherit' !== $policy['site'] || ! in_array( $policy['core'], array( 'inherit', 'off', 'minor', 'all' ), true ) ) {
		return new \WP_Error( 'sunrise_policy_schema', 'Invalid resolved policy.' );
	}
	foreach ( array( 'plugins', 'themes' ) as $type ) {
		if ( ! is_array( $policy[ $type ] ) || count( $policy[ $type ] ) > 5000 || ! isset( $document['category_defaults'][ $type ] ) || ! in_array( $document['category_defaults'][ $type ], array( 'inherit', 'on', 'off' ), true ) ) {
			return new \WP_Error( 'sunrise_policy_schema', 'Invalid category policy.' );
		}
		if ( isset( $document['identities'] ) ) {
			if ( ! is_array( $document['identities'] ) || ! isset( $document['identities'][ $type ] ) || ! is_array( $document['identities'][ $type ] ) || count( $document['identities'][ $type ] ) > 5000 ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid component identities.' ); }
			foreach ( $document['identities'][ $type ] as $id => $identity ) {
				if ( ! isset( $policy[ $type ][ $id ] ) || ! is_array( $identity ) || ! isset( $identity['status'], $identity['component_id'] ) || ! is_string( $identity['component_id'] ) || ! in_array( $identity['status'], array( 'matched', 'changed' ), true ) || ! array_key_exists( 'headers', $identity ) ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid component identity.' ); }
				if ( 'matched' === $identity['status'] ) {
					if ( ! is_array( $identity['headers'] ) || count( $identity['headers'] ) !== 5 ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid identity headers.' ); }
					foreach ( array( 'name', 'update_uri', 'project_uri', 'author_uri', 'text_domain' ) as $field ) {
						if ( ! isset( $identity['headers'][ $field ] ) || ! is_string( $identity['headers'][ $field ] ) || strlen( $identity['headers'][ $field ] ) > 2048 ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid identity header.' ); }
					}
				}
			}
		}
		foreach ( $policy[ $type ] as $id => $mode ) {
			if ( ! is_string( $id ) || strlen( $id ) > 255 || ! in_array( $mode, array( 'inherit', 'on', 'off' ), true ) ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid item policy.' ); }
		}
	}
	$decisions = isset( $document['decisions'] ) ? $document['decisions'] : null;
	if ( ! is_array( $decisions ) || ! isset( $decisions['core'], $decisions['category_defaults'], $decisions['plugins'], $decisions['themes'] ) ) { return new \WP_Error( 'sunrise_policy_schema', 'Missing policy change order.' ); }
	$valid = function ( $decision ) {
		return is_array( $decision ) && isset( $decision['revision'], $decision['specificity'] ) && is_string( $decision['revision'] )
			&& preg_match( '/^(0|[1-9][0-9]{0,18})$/D', $decision['revision'] ) && is_int( $decision['specificity'] ) && $decision['specificity'] >= -1 && $decision['specificity'] <= 3;
	};
	if ( ! $valid( $decisions['core'] ) ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid core change order.' ); }
	foreach ( array( 'plugins', 'themes' ) as $type ) {
		if ( ! isset( $decisions['category_defaults'][ $type ] ) || ! $valid( $decisions['category_defaults'][ $type ] ) || ! is_array( $decisions[ $type ] ) || count( $decisions[ $type ] ) !== count( $policy[ $type ] ) ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid category change order.' ); }
		foreach ( $policy[ $type ] as $id => $mode ) {
			if ( ! isset( $decisions[ $type ][ $id ] ) || ! $valid( $decisions[ $type ][ $id ] ) ) { return new \WP_Error( 'sunrise_policy_schema', 'Invalid item change order.' ); }
		}
	}
	return array( 'generation' => $generation, 'hash' => $wire['hash'], 'document' => $document );
}

/** Refresh offers only; persist an outcome before work so a crash cannot replay the request. */
function agent_refresh_inventory( $request, &$state ) {
	if ( ! $request || ( isset( $state['refresh_result']['id'] ) && $state['refresh_result']['id'] === $request['id'] ) ) { return false; }
	if ( time() > $request['expires_at'] || ! agent_owner_valid( $state ) ) { return false; }
	require_once __DIR__ . '/jobs.php';
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_worker' ) ) { return false; }
	$native_auto_priority = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
	if ( false !== $native_auto_priority ) { remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $native_auto_priority ); }
	try {
		if ( time() > $request['expires_at'] || ! agent_owner_valid( $state ) ) { return false; }
		$state['refresh_result'] = array( 'id' => $request['id'], 'code' => 'refresh_interrupted' );
		$state['refresh_ack_pending'] = true;
		if ( ! agent_store( $state ) ) { return false; }
		try {
			$result = execute_task( array( 'action' => 'refresh' ) );
			if ( ! is_wp_error( $result ) && in_array( $result['code'], array( 'refresh_attempted', 'refresh_throttled' ), true ) ) { $state['refresh_result']['code'] = $result['code']; }
		} catch ( \Throwable $error ) { /* The persisted interrupted outcome remains reportable. */ }
		agent_store( $state );
		return true;
	} finally {
		if ( false !== $native_auto_priority ) { add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $native_auto_priority ); }
		\WP_Upgrader::release_lock( 'sunrise_worker' );
	}
}

/** Small local thumbnail, sent only when changed; Control never needs to fetch a site's favicon. */
function agent_site_profile() {
	$profile = array( 'name' => wp_html_excerpt( html_entity_decode( sanitize_text_field( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' ), 200, '' ), 'icon' => null );
	$id = (int) get_option( 'site_icon' );
	$original = $id ? get_attached_file( $id ) : false;
	$size = $id ? image_get_intermediate_size( $id, array( 32, 32 ) ) : false;
	$file = $original ? realpath( $size ? dirname( $original ) . '/' . basename( $size['file'] ) : $original ) : false;
	$uploads = wp_get_upload_dir(); $base = realpath( $uploads['basedir'] );
	if ( $file && $base && 0 === strpos( $file, $base . DIRECTORY_SEPARATOR ) && is_readable( $file ) && filesize( $file ) <= 16384 ) {
		$image = wp_getimagesize( $file );
		if ( $image && $image[0] <= 256 && $image[1] <= 256 && in_array( $image['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			$bytes = file_get_contents( $file );
			if ( false !== $bytes ) { $profile['icon'] = 'data:' . $image['mime'] . ';base64,' . base64_encode( $bytes ); }
		}
	}
	return $profile;
}

function agent_check_in() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'A check-in is already running.' ); }
	$previous = get_current_user_id();
	try {
		$state = agent_state();
		if ( ! $state || empty( $state['enrollment_id'] ) ) { return new \WP_Error( 'sunrise_not_enrolled', 'Start enrollment first.' ); }
		if ( ! empty( $state['revoked'] ) ) { return new \WP_Error( 'sunrise_disconnected', 'This connection was disconnected. Reconnect this administrator to continue.' ); }
		$identity = installation_guard();
		if ( is_wp_error( $identity ) || ! empty( $state['reconnect'] ) ) {
			$reconnected = agent_reconnect( $state ); if ( is_wp_error( $reconnected ) ) { return $reconnected; }
			$state = agent_state();
		}
		if ( ! agent_owner_valid( $state ) ) { return new \WP_Error( 'sunrise_agent_owner', 'The enrolling user no longer has update capabilities.' ); }
		wp_set_current_user( $state['user_id'] );
		if ( empty( $state['site_id'] ) ) {
			$assignment = agent_http( 'enrollments/' . $state['enrollment_id'] . '/exchange', array(), $state );
			if ( is_wp_error( $assignment ) ) { return $assignment; }
			if ( 'approved' !== $assignment['status'] ) { return new \WP_Error( 'sunrise_approval_pending', 'Approve this site in Sunrise Control before finishing the connection.' ); }
			foreach ( array( 'account_id', 'network_id', 'site_id', 'generation' ) as $key ) { $state[ $key ] = $assignment[ $key ]; }
		}
		$sequence_rebased = false;
		while ( true ) {
			// Retain the exact report before sending so a lost HTTP response can be retried safely.
			if ( empty( $state['pending_report'] ) ) {
				plugin_update_check();
				$state['pending_report'] = array( 'protocol_version' => 1, 'sequence' => $state['sequence'] + 1,
					'inventory' => agent_inventory(), 'local_pause' => ! empty( $state['paused'] ) );
				if ( ! empty( $state['update_failures'] ) ) {
					$failures = automatic_update_failures( $state['pending_report']['inventory'] );
					if ( hash( 'sha256', wp_json_encode( $failures ) ) !== ( isset( $state['update_failure_hash'] ) ? $state['update_failure_hash'] : '' ) ) { $state['pending_report']['automatic_update_failures'] = $failures; }
					$resolved = failure_resolutions( isset( $state['failure_checks'] ) ? $state['failure_checks'] : array(), $state['pending_report']['inventory'] );
					if ( $resolved ) { $state['pending_report']['resolved_failures'] = $resolved; }
				}
				if ( ! empty( $state['update_activity'] ) ) { $state['pending_report']['automatic_update_activity'] = automatic_update_activity(); }
				if ( ! empty( $state['wake_requests'] ) && empty( $state['wake_registered'] ) ) {
					$key = agent_wake_key( $state ); if ( is_wp_error( $key ) ) { return $key; } $state['pending_report']['wake_key'] = $key;
				}
				if ( ! empty( $state['site_profiles'] ) ) {
					$profile = agent_site_profile();
					if ( hash( 'sha256', wp_json_encode( $profile ) ) !== ( isset( $state['site_profile_hash'] ) ? $state['site_profile_hash'] : '' ) ) { $state['pending_report']['site_profile'] = $profile; }
				}
				if ( ! empty( $state['refresh_ack_pending'] ) && ! empty( $state['refresh_result'] ) ) { $state['pending_report']['refresh_ack'] = $state['refresh_result']; }
				if ( ! empty( $state['applied'] ) ) {
					$conflict = agent_policy_conflict( $state );
					$state['pending_report']['policy_ack'] = array( 'generation' => $state['applied']['generation'], 'hash' => $state['applied']['hash'], 'status' => $conflict ? 'rejected' : 'applied', 'reason_code' => $conflict ? 'local_policy_override' : null );
				}
				if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not persist check-in.' ); }
			}
			$response = agent_http( 'agent/check-in', $state['pending_report'], $state );
			if ( is_wp_error( $response ) ) {
				$data = $response->get_error_data();
				if ( ! $sequence_rebased && is_array( $data ) && 'sequence_conflict' === ( $data['remote_code'] ?? null ) && isset( $data['server_sequence'] ) && is_int( $data['server_sequence'] ) && $data['server_sequence'] >= (int) $state['sequence'] ) {
					$from = (int) $state['sequence']; $state['sequence'] = $data['server_sequence']; unset( $state['pending_report'] );
					if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not recover check-in sequence.' ); }
					agent_log( 'check_in_sequence_rebased', array( 'from' => $from, 'to' => $state['sequence'] ) );
					$sequence_rebased = true; continue;
				}
				if ( is_array( $data ) && 401 === ( $data['status'] ?? null ) ) {
					$state['revoked'] = true; unset( $state['pending_report'] ); agent_store( $state );
					wp_clear_scheduled_hook( 'sunrise_check_in', array( (int) $state['user_id'] ) );
				}
				return $response;
			}
			break;
		}
		if ( ! isset( $response['receipt_sequence'] ) || $response['receipt_sequence'] !== $state['pending_report']['sequence'] ) { return new \WP_Error( 'sunrise_agent_receipt', 'Invalid check-in receipt.' ); }
		$refresh = isset( $response['refresh_request'] ) ? $response['refresh_request'] : null;
		if ( null !== $refresh && ( ! is_array( $refresh ) || array_diff( array_keys( $refresh ), array( 'id', 'expires_at' ) ) || ! isset( $refresh['id'], $refresh['expires_at'] ) || ! is_string( $refresh['id'] ) || ! wp_is_uuid( $refresh['id'], 4 ) || ! is_int( $refresh['expires_at'] ) || $refresh['expires_at'] > time() + 120 ) ) { return new \WP_Error( 'sunrise_refresh_schema', 'Invalid inventory refresh request.' ); }
		$applied = agent_accept_policy( $response['policy'], $state );
		if ( is_wp_error( $applied ) ) { return $applied; }
		if ( ! agent_owner_valid( $state ) ) { return new \WP_Error( 'sunrise_agent_owner', 'Connection owner no longer has update capabilities.' ); }
		$state['applied'] = $applied;
		$state['sequence'] = $response['receipt_sequence'];
		$state['last_success'] = time();
		$state['wake_requests'] = isset( $response['wake_requests'] ) && true === $response['wake_requests'];
		if ( isset( $state['pending_report']['wake_key'] ) && $state['wake_requests'] ) { $state['wake_registered'] = true; }
		$state['update_jobs'] = isset( $response['update_jobs'] ) && true === $response['update_jobs'];
		if ( isset( $response['failure_checks'] ) && ! validate_failure_checks( $response['failure_checks'] ) ) { return new \WP_Error( 'sunrise_failure_checks', 'Invalid failure-resolution checks.' ); }
		$state['update_failures'] = isset( $response['update_failures'] ) && true === $response['update_failures'];
		$state['update_activity'] = isset( $response['update_activity'] ) && true === $response['update_activity'];
		$state['failure_checks'] = isset( $response['failure_checks'] ) ? $response['failure_checks'] : array();
		if ( isset( $response['sunrise_latest_version'] ) ) {
			$latest = $response['sunrise_latest_version'];
			if ( ! is_string( $latest ) || ! preg_match( '/^\d+\.\d+\.\d+$/D', $latest ) ) { return new \WP_Error( 'sunrise_release_schema', 'Invalid Sunrise release version.' ); }
			if ( ( $state['sunrise_latest_version'] ?? null ) !== $latest ) {
				$offer = version_compare( VERSION, $latest, '>=' ) ? null : plugin_update_check( true );
				if ( version_compare( VERSION, $latest, '>=' ) || ( is_object( $offer ) && isset( $offer->version ) && version_compare( $offer->version, $latest, '>=' ) ) ) { $state['sunrise_latest_version'] = $latest; }
			}
		}
		if ( isset( $state['pending_report']['automatic_update_failures'] ) ) { $state['update_failure_hash'] = hash( 'sha256', wp_json_encode( $state['pending_report']['automatic_update_failures'] ) ); }
		$state['site_profiles'] = isset( $response['site_profiles'] ) && true === $response['site_profiles'];
		if ( isset( $state['pending_report']['site_profile'] ) ) { $state['site_profile_hash'] = hash( 'sha256', wp_json_encode( $state['pending_report']['site_profile'] ) ); }
		$state['errors_supported'] = isset( $response['error_reports'] ) && true === $response['error_reports'];
		$license_refreshed = false;
		if ( isset( $response['premium_licenses'] ) ) { $licenses = premium_license_sync( $state, $response['premium_licenses'] ); if ( is_wp_error( $licenses ) ) { return $licenses; } $license_refreshed = $licenses; }
		if ( isset( $state['pending_report']['refresh_ack']['id'], $state['refresh_result']['id'] ) && $state['pending_report']['refresh_ack']['id'] === $state['refresh_result']['id'] ) { $state['refresh_ack_pending'] = false; }
		unset( $state['pending_report'] );
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not persist applied policy.' ); }
		delete_option( 'sunrise_control_reauth_required' );
		return array( 'profile_pending' => ( ! empty( $state['wake_requests'] ) && empty( $state['wake_registered'] ) ) || ( ! empty( $state['site_profiles'] ) && empty( $state['site_profile_hash'] ) ) || ( ! empty( $state['update_failures'] ) && empty( $state['update_failure_hash'] ) ), 'site_id' => $state['site_id'], 'policy_generation' => $applied['generation'], 'receipt_sequence' => $state['sequence'], 'refreshed' => agent_refresh_inventory( $refresh, $state ), 'license_refreshed' => $license_refreshed, 'work_available' => $state['update_jobs'] && ! empty( $response['work_available'] ) );
	} finally {
		wp_set_current_user( $previous );
		\WP_Upgrader::release_lock( 'sunrise_agent' );
	}
}

function agent_disconnect() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
	try { return agent_disconnect_locked(); } finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}

function agent_disconnect_locked() {
	$access = agent_access(); if ( is_wp_error( $access ) ) { return $access; }
	$state = agent_state();
	if ( ! empty( $state['remote_job'] ) ) { return new \WP_Error( 'sunrise_job_pending', 'Finish or reconcile the pending update before reconnecting or clearing this connection.' ); }
	if ( $state && ! empty( $state['site_id'] ) && empty( $state['revoked'] ) ) {
		$result = agent_http( 'agent/enrollment', array(), $state, 'DELETE' );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! in_array( $data['status'], array( 401, 410 ), true ) ) { return $result; }
		}
	}
	$states = agent_states(); unset( $states[ get_current_user_id() ] );
	if ( ! agent_store_states( $states ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not remove connection.' ); }
	wp_clear_scheduled_hook( 'sunrise_check_in', array( get_current_user_id() ) );
	return array( 'disconnected' => true );
}

/** Acknowledge a newly received policy now, rather than waiting for the next routine report. */
function agent_sync() {
	$access = agent_access(); if ( is_wp_error( $access ) ) { return $access; }
	$result = agent_synchronize();
	agent_log( is_wp_error( $result ) ? 'manual_check_in_failed' : 'manual_check_in_succeeded', is_wp_error( $result ) ? agent_error_details( $result ) : array() );
	return $result;
}

/** Start a fresh Control credential while retaining this approved site record. */
function agent_reauthenticate() {
	$access = agent_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
	try {
		$state = agent_state();
		if ( empty( $state['site_id'] ) ) { return new \WP_Error( 'sunrise_not_enrolled', 'Connect this site first.' ); }
		$result = agent_reconnect( $state );
		if ( ! is_wp_error( $result ) || 'sunrise_reconnect_pending' === $result->get_error_code() ) { agent_schedule( 60 ); }
		return $result;
	} finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}

/** Internal background work runs as the enrolled owner after validating their capabilities. */
function agent_synchronize() {
	$state = agent_state();
	$generation = isset( $state['applied']['generation'] ) ? $state['applied']['generation'] : 0;
	$result = agent_check_in();
	if ( ! is_wp_error( $result ) && ! empty( $result['license_refreshed'] ) ) { wp_clear_scheduled_hook( 'sunrise_check_in', array( get_current_user_id() ) ); agent_schedule( 60 ); return $result; }
	if ( ! is_wp_error( $result ) && ( $result['policy_generation'] !== $generation || ! empty( $result['refreshed'] ) || ! empty( $result['profile_pending'] ) ) ) { $result = agent_check_in(); }
	if ( ! is_wp_error( $result ) && ( ! empty( $result['work_available'] ) || ! empty( agent_state()['remote_job'] ) ) ) {
		require_once __DIR__ . '/agent-jobs.php';
		$job = agent_run_update_job();
		if ( is_wp_error( $job ) ) { return $job; }
		if ( $job ) {
			$result = agent_check_in();
			// Continue explicitly queued work, without increasing the routine inventory cadence.
			if ( ! is_wp_error( $result ) && ! empty( $result['work_available'] ) && empty( agent_state()['remote_job'] ) && ! get_option( 'sunrise_remote_job_fence' ) ) {
				wp_clear_scheduled_hook( 'sunrise_check_in', array( get_current_user_id() ) );
				agent_schedule( 60 );
			}
		}
	}
	if ( ! is_wp_error( $result ) ) { agent_report_errors(); }
	return $result;
}

add_action( 'sunrise_check_in', function ( $user_id = 0 ) {
	$previous = get_current_user_id();
	$states = agent_states();
	// An old no-argument cron event belongs to the original legacy connection only.
	if ( ! $user_id ) { $legacy = get_option( 'sunrise_agent', array() ); $user_id = isset( $legacy['user_id'] ) ? $legacy['user_id'] : 0; }
	if ( ! isset( $states[ $user_id ] ) || ! agent_owner_valid( $states[ $user_id ] ) || ! empty( $states[ $user_id ]['revoked'] ) ) { return; }
	try {
		wp_set_current_user( $user_id );
		// WP-Cron removes a single event before invoking it; reserve its successor before work that may stop the request.
		agent_schedule( AGENT_INTERVAL );
		$result = agent_synchronize();
		agent_log( is_wp_error( $result ) ? 'scheduled_check_in_failed' : 'scheduled_check_in_succeeded', is_wp_error( $result ) ? agent_error_details( $result ) : array() );
		$delay = AGENT_INTERVAL;
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['retry_after'] ) ) { $delay = max( $delay, $data['retry_after'] ); }
		}
		if ( $delay > AGENT_INTERVAL ) { wp_clear_scheduled_hook( 'sunrise_check_in', array( $user_id ) ); }
		$state = agent_state();
		if ( $state && empty( $state['revoked'] ) ) { agent_schedule( $delay ); }
	} finally { wp_set_current_user( $previous ); }
}, 10, 1 );

function agent_pause( $paused ) {
	$access = agent_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
	try {
		$state = agent_state(); if ( ! $state ) { return new \WP_Error( 'sunrise_not_enrolled', 'Connect this site first.' ); }
		$state['paused'] = (bool) $paused;
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not save local pause.' ); }
		return array( 'paused' => $state['paused'] );
	} finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'sunrise agent', function ( $args ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		if ( ! current_user_can( 'manage_options' ) ) { \WP_CLI::error( 'Use --user=<local administrator>.' ); }
		$access = agent_access( 'enroll' === $action ); if ( is_wp_error( $access ) ) { \WP_CLI::error( $access->get_error_message() ); }
		if ( 'enroll' === $action ) { $result = agent_enroll(); }
		elseif ( 'sync' === $action ) { $result = agent_sync(); }
		elseif ( 'disconnect' === $action ) { $result = agent_disconnect(); }
		elseif ( 'pause' === $action || 'resume' === $action ) { $result = agent_pause( 'pause' === $action ); }
		elseif ( 'status' === $action ) {
			$state = agent_state();
			$result = array( 'enrolled' => ! empty( $state['site_id'] ), 'site_id' => isset( $state['site_id'] ) ? $state['site_id'] : null,
				'policy_generation' => isset( $state['applied'] ) ? $state['applied']['generation'] : null,
				'last_success' => isset( $state['last_success'] ) ? $state['last_success'] : null, 'paused' => ! empty( $state['paused'] ) );
		} else { \WP_CLI::error( 'Use enroll, sync, status, pause, resume, or disconnect.' ); }
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::line( wp_json_encode( $result ) );
	} );
}
