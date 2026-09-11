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

/** Enrollment is open to each administrator; connection operations require their own enrollment. */
function agent_access( $enrollment = false ) {
	if ( ! current_user_can( 'manage_options' ) || ( ! $enrollment && agent_states() && ! agent_state() ) ) {
		return new \WP_Error( 'sunrise_agent_forbidden', 'Connect this site with your own Sunrise account first.', array( 'status' => 403 ) );
	}
	return true;
}

function agent_http( $path, $data, $state, $method = 'POST' ) {
	if ( ! empty( $state['revoked'] ) ) { return new \WP_Error( 'sunrise_disconnected', 'This connection was disconnected. Reconnect this administrator to continue.', array( 'status' => 401 ) ); }
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	if ( ! agent_owner_valid( $state ) || (int) $state['user_id'] !== get_current_user_id() ) { return new \WP_Error( 'sunrise_agent_owner', 'Connection owner access required.' ); }
	if ( ! agent_service_matches( $state ) || $state['url'] !== untrailingslashit( site_url() ) ) {
		return new \WP_Error( 'sunrise_agent_environment', 'The enrolled site URL or Control address changed. Reconnect this installation.' );
	}
	require_once __DIR__ . '/controller.php';
	$secret = credential( $state['secret'], 'agent|' . $state['url'] . '|' . $state['user_id'], true );
	if ( is_wp_error( $secret ) ) { return $secret; }
	$request = 'http://127.0.0.1:8787' === agent_url() ? 'wp_remote_request' : 'wp_safe_remote_request';
	$response = $request( agent_url() . '/v1/' . $path, array(
		'method' => $method, 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
		'headers' => array( 'Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json' ),
		'user-agent' => 'SunriseAgent/' . VERSION . ' (+' . home_url( '/' ) . ')',
		'body' => wp_json_encode( $data ),
	) );
	if ( is_wp_error( $response ) ) { return new \WP_Error( 'sunrise_agent_unreachable', 'Central service unavailable. The last applied policy is retained.' ); }
	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
		$remote_code = isset( $body['error']['code'] ) && is_string( $body['error']['code'] ) && preg_match( '/^[a-z_]{1,80}$/D', $body['error']['code'] ) ? $body['error']['code'] : null;
		return new \WP_Error( 'sunrise_agent_http', 'Central service rejected the request.', array( 'status' => $code, 'remote_code' => $remote_code, 'retry_after' => max( 60, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) ) );
	}
	return $body;
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

/** Apply a cadence change once on upgrade; retain earlier enrollment/job continuation events. */
function agent_migrate_schedule() {
	if ( AGENT_INTERVAL === (int) get_option( 'sunrise_agent_interval', 0 ) ) { return; }
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
		$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
		if ( ! agent_owner_valid( $state ) ) { return new \WP_Error( 'sunrise_agent_owner', 'The enrolling user no longer has update capabilities.' ); }
		wp_set_current_user( $state['user_id'] );
		if ( empty( $state['site_id'] ) ) {
			$assignment = agent_http( 'enrollments/' . $state['enrollment_id'] . '/exchange', array(), $state );
			if ( is_wp_error( $assignment ) ) { return $assignment; }
			if ( 'approved' !== $assignment['status'] ) { return new \WP_Error( 'sunrise_approval_pending', 'Approve this site in Sunrise Control before finishing the connection.' ); }
			foreach ( array( 'account_id', 'network_id', 'site_id', 'generation' ) as $key ) { $state[ $key ] = $assignment[ $key ]; }
		}
		// Retain the exact report before sending so a lost HTTP response can be retried safely.
		if ( empty( $state['pending_report'] ) ) {
			$state['pending_report'] = array( 'protocol_version' => 1, 'sequence' => $state['sequence'] + 1,
				'inventory' => agent_inventory(), 'local_pause' => ! empty( $state['paused'] ) );
			if ( ! empty( $state['update_failures'] ) ) {
				$failures = automatic_update_failures( $state['pending_report']['inventory'] );
				if ( hash( 'sha256', wp_json_encode( $failures ) ) !== ( isset( $state['update_failure_hash'] ) ? $state['update_failure_hash'] : '' ) ) { $state['pending_report']['automatic_update_failures'] = $failures; }
				$resolved = failure_resolutions( isset( $state['failure_checks'] ) ? $state['failure_checks'] : array(), $state['pending_report']['inventory'] );
				if ( $resolved ) { $state['pending_report']['resolved_failures'] = $resolved; }
			}
			if ( ! empty( $state['wake_requests'] ) && empty( $state['wake_registered'] ) ) {
				$key = agent_wake_key( $state ); if ( is_wp_error( $key ) ) { return $key; } $state['pending_report']['wake_key'] = $key;
			}
			if ( ! empty( $state['file_transfers'] ) ) { $state['pending_report']['file_transfer_version'] = class_exists( 'ZipArchive' ) ? 1 : 0; }
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
			if ( is_array( $data ) && 401 === $data['status'] ) {
				$state['revoked'] = true; unset( $state['pending_report'] ); agent_store( $state );
				wp_clear_scheduled_hook( 'sunrise_check_in', array( (int) $state['user_id'] ) );
			}
			return $response;
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
		$state['failure_checks'] = isset( $response['failure_checks'] ) ? $response['failure_checks'] : array();
		if ( isset( $state['pending_report']['automatic_update_failures'] ) ) { $state['update_failure_hash'] = hash( 'sha256', wp_json_encode( $state['pending_report']['automatic_update_failures'] ) ); }
		$state['site_profiles'] = isset( $response['site_profiles'] ) && true === $response['site_profiles'];
		if ( isset( $state['pending_report']['site_profile'] ) ) { $state['site_profile_hash'] = hash( 'sha256', wp_json_encode( $state['pending_report']['site_profile'] ) ); }
		$state['errors_supported'] = isset( $response['error_reports'] ) && true === $response['error_reports'];
		$state['transfer_previews'] = isset( $response['transfer_previews'] ) && true === $response['transfer_previews'];
		$state['transfer_execution'] = isset( $response['transfer_execution'] ) && true === $response['transfer_execution'];
		$state['file_transfers'] = isset( $response['file_transfers'] ) && true === $response['file_transfers'];
		if ( isset( $response['premium_licenses'] ) ) { $licenses = premium_license_sync( $state, $response['premium_licenses'] ); if ( is_wp_error( $licenses ) ) { return $licenses; } }
		if ( isset( $state['pending_report']['refresh_ack']['id'], $state['refresh_result']['id'] ) && $state['pending_report']['refresh_ack']['id'] === $state['refresh_result']['id'] ) { $state['refresh_ack_pending'] = false; }
		unset( $state['pending_report'] );
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not persist applied policy.' ); }
		return array( 'profile_pending' => ( ! empty( $state['wake_requests'] ) && empty( $state['wake_registered'] ) ) || ( ! empty( $state['site_profiles'] ) && empty( $state['site_profile_hash'] ) ) || ( ! empty( $state['update_failures'] ) && empty( $state['update_failure_hash'] ) ), 'site_id' => $state['site_id'], 'policy_generation' => $applied['generation'], 'receipt_sequence' => $state['sequence'], 'refreshed' => agent_refresh_inventory( $refresh, $state ), 'work_available' => $state['update_jobs'] && ! empty( $response['work_available'] ), 'transfer_work_available' => $state['transfer_previews'] && ! empty( $response['transfer_work_available'] ), 'transfer_execution_available' => $state['transfer_execution'] && ! empty( $response['transfer_execution_available'] ) );
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
	if ( ! empty( $state['remote_job'] ) || ! empty( $state['remote_transfer'] ) ) { return new \WP_Error( 'sunrise_job_pending', 'Finish or reconcile the pending update or migration before reconnecting or clearing this connection.' ); }
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
	return agent_synchronize();
}

/** Internal background work runs as the enrolled owner after validating their capabilities. */
function agent_synchronize() {
	$state = agent_state();
	$generation = isset( $state['applied']['generation'] ) ? $state['applied']['generation'] : 0;
	$result = agent_check_in();
	if ( ! is_wp_error( $result ) && ( $result['policy_generation'] !== $generation || ! empty( $result['refreshed'] ) || ! empty( $result['profile_pending'] ) ) ) { $result = agent_check_in(); }
	if ( ! is_wp_error( $result ) && ( ! empty( $result['transfer_execution_available'] ) || ! empty( agent_state()['remote_transfer'] ) ) ) {
		require_once __DIR__ . '/agent-transfer-execution.php';
		$transfer = agent_run_transfer(); if ( is_wp_error( $transfer ) ) { return $transfer; }
		if ( $transfer ) { $result = agent_check_in(); }
	}
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
	if ( ! is_wp_error( $result ) && ( ! empty( $result['transfer_work_available'] ) || ! empty( agent_state()['transfer_report'] ) ) ) { require_once __DIR__ . '/agent-transfers.php'; agent_prepare_transfer(); }
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
		$result = agent_synchronize();
		$delay = AGENT_INTERVAL;
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['retry_after'] ) ) { $delay = max( $delay, $data['retry_after'] ); }
		}
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
