<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/controller.php';

const MIGRATION_PAIR_OPTION = 'sunrise_migration_pairs';

function migration_pair_encode( $value ) {
	return rtrim( strtr( base64_encode( wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
}

function migration_pair_decode( $value ) {
	if ( ! is_string( $value ) || strlen( $value ) > 8192 || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) { return null; }
	$json = base64_decode( strtr( $value, '-_', '+/' ), true );
	$data = false === $json ? null : json_decode( $json, true );
	return is_array( $data ) ? $data : null;
}

function migration_pair_context( $pair_id ) {
	$identity = installation_identity();
	return 'migration-pair|' . $identity['id'] . '|' . $pair_id;
}

function migration_pairs() {
	$pairs = get_option( MIGRATION_PAIR_OPTION, array() );
	return is_array( $pairs ) ? $pairs : array();
}

function migration_pair_public( $pair ) {
	return array_intersect_key( $pair, array_flip( array( 'pair_id', 'local_site_id', 'peer_site_id', 'peer_url', 'peer_name', 'created_at', 'last_used_at' ) ) );
}

function migration_pair_store( $pair, $secret ) {
	$keys = array( 'pair_id', 'local_site_id', 'peer_site_id', 'peer_url', 'peer_name', 'created_at' );
	if ( ! is_array( $pair ) || array_diff( $keys, array_keys( $pair ) ) || ! is_string( $pair['pair_id'] ) || ! wp_is_uuid( $pair['pair_id'], 4 ) || ! is_string( $pair['local_site_id'] ) || ! wp_is_uuid( $pair['local_site_id'], 4 ) || ! is_string( $pair['peer_site_id'] ) || ! wp_is_uuid( $pair['peer_site_id'], 4 ) || $pair['local_site_id'] === $pair['peer_site_id'] || ! is_string( $pair['peer_name'] ) || strlen( $pair['peer_name'] ) > 255 || ! is_int( $pair['created_at'] ) || $pair['created_at'] < time() - HOUR_IN_SECONDS || $pair['created_at'] > time() + 300 || ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) { return new \WP_Error( 'sunrise_pair_invalid', 'Invalid migration pairing.', array( 'status' => 400 ) ); }
	$url = migration_pair_url( $pair['peer_url'] );
	if ( is_wp_error( $url ) ) { return $url; }
	$pairs = migration_pairs();
	if ( ! isset( $pairs[ $pair['peer_site_id'] ] ) && count( $pairs ) >= 50 ) { return new \WP_Error( 'sunrise_pair_limit', 'This site already has 50 migration pairings.', array( 'status' => 409 ) ); }
	$encrypted = credential( $secret, migration_pair_context( $pair['pair_id'] ) );
	if ( is_wp_error( $encrypted ) ) { return $encrypted; }
	$pair['peer_url'] = $url;
	$pair['peer_name'] = sanitize_text_field( $pair['peer_name'] );
	$pair['local_installation_id'] = installation_identity()['id'];
	$pair['secret'] = $encrypted;
	$pairs[ $pair['peer_site_id'] ] = $pair;
	if ( get_option( MIGRATION_PAIR_OPTION, null ) !== $pairs && ! update_option( MIGRATION_PAIR_OPTION, $pairs, false ) ) { return new \WP_Error( 'sunrise_pair_storage', 'Could not save the migration pairing.', array( 'status' => 503 ) ); }
	return migration_pair_public( $pair );
}

function migration_pair_url( $url ) {
	$url = is_string( $url ) ? untrailingslashit( esc_url_raw( $url ) ) : '';
	$parts = wp_parse_url( $url ); $local = migration_local_url( $url );
	if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || isset( $parts['user'], $parts['pass'], $parts['query'], $parts['fragment'] ) || ! in_array( strtolower( $parts['scheme'] ), $local ? array( 'http', 'https' ) : array( 'https' ), true ) ) { return new \WP_Error( 'sunrise_pair_url', 'Migration peers require HTTPS, except for an explicitly local site.', array( 'status' => 400 ) ); }
	return $url;
}

function migration_local_url( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) || '.localhost' === substr( $host, -10 );
}

function migration_pair_find( $peer_site_id = null, $pair_id = null, $with_secret = false ) {
	foreach ( migration_pairs() as $pair ) {
		if ( ( null !== $peer_site_id && ( $pair['peer_site_id'] ?? null ) !== $peer_site_id ) || ( null !== $pair_id && ( $pair['pair_id'] ?? null ) !== $pair_id ) ) { continue; }
		if ( ( $pair['local_site_id'] ?? null ) !== migration_site_id() || ( $pair['local_installation_id'] ?? null ) !== installation_identity()['id'] || is_wp_error( migration_pair_url( $pair['peer_url'] ?? null ) ) ) { return null; }
		if ( $with_secret ) {
			$secret = credential( $pair['secret'] ?? '', migration_pair_context( $pair['pair_id'] ), true );
			if ( is_wp_error( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) { return null; }
			$pair['plain_secret'] = $secret;
		}
		return $pair;
	}
	return null;
}

function migration_site_id() {
	$state = migration_control_state(); if ( ! empty( $state['site_id'] ) ) { return $state['site_id']; }
	$ids = array_unique( array_filter( array_column( migration_pairs(), 'local_site_id' ) ) ); return 1 === count( $ids ) && wp_is_uuid( reset( $ids ), 4 ) ? reset( $ids ) : null;
}

function migration_control_state() {
	$current = agent_state();
	if ( ! empty( $current['site_id'] ) && wp_is_uuid( $current['site_id'], 4 ) && agent_owner_valid( $current ) && agent_service_matches( $current ) ) { return $current; }
	foreach ( agent_states() as $candidate ) { if ( ! empty( $candidate['site_id'] ) && wp_is_uuid( $candidate['site_id'], 4 ) && agent_owner_valid( $candidate ) && agent_service_matches( $candidate ) ) { return $candidate; } }
	return array();
}

/** Read-only Control directory request delegated by the connected site, never a migration data relay. */
function migration_control_sites( $state ) {
	if ( ! current_user_can( 'manage_options' ) || ! agent_owner_valid( $state ) || ! agent_service_matches( $state ) || ( $state['url'] ?? null ) !== untrailingslashit( site_url() ) ) { return array(); }
	$secret = credential( $state['secret'] ?? '', 'agent|' . $state['url'] . '|' . $state['user_id'], true ); if ( is_wp_error( $secret ) ) { return array(); }
	$path = '/v1/accounts/' . $state['account_id'] . '/networks/' . $state['network_id'] . '/sites?limit=100';
	$request = 'http://127.0.0.1:8787' === agent_url() ? 'wp_remote_request' : 'wp_safe_remote_request';
	$response = $request( agent_url() . '/v1/agent/dashboard', array( 'method' => 'POST', 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES, 'headers' => array( 'Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'path' => $path, 'method' => 'GET' ) ) ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return array(); }
	$value = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $value ) && 200 === ( $value['status'] ?? 0 ) && is_array( $value['body']['items'] ?? null ) ? $value['body']['items'] : array();
}

function migration_access() {
	if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) { return new \WP_Error( 'sunrise_https_required', 'HTTPS is required outside an explicitly local environment.', array( 'status' => 403 ) ); }
	if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'sunrise_forbidden', 'An authorized WordPress administrator is required.', array( 'status' => is_user_logged_in() ? 403 : 401 ) ); }
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	return migration_site_id() ? true : new \WP_Error( 'sunrise_pair_control', 'Connect this site to Sunrise Control before pairing it.', array( 'status' => 403 ) );
}

/** Use Control only as the connected-site directory. A saved pair remains usable by every current administrator. */
function migration_connected_peers() {
	$items = array();
	$state = migration_control_state();
	foreach ( migration_control_sites( $state ) as $site ) { if ( ! empty( $site['active'] ) && $site['id'] !== $state['site_id'] ) { $items[ $site['id'] ] = array( 'id' => $site['id'], 'url' => $site['url'], 'name' => $site['site_profile']['name'] ?? $site['url'] ); } }
	foreach ( migration_pairs() as $pair ) { if ( ( $pair['local_site_id'] ?? null ) === migration_site_id() ) { $items[ $pair['peer_site_id'] ] = array( 'id' => $pair['peer_site_id'], 'url' => $pair['peer_url'], 'name' => $pair['peer_name'], 'paired' => true ); } }
	foreach ( $items as &$item ) { $item['paired'] = (bool) migration_pair_find( $item['id'] ); } unset( $item );
	return array_values( $items );
}

function migration_pair_start( $peer_site_id ) {
	$access = migration_access(); if ( is_wp_error( $access ) ) { return $access; }
	$peer = null; foreach ( migration_connected_peers() as $item ) { if ( $item['id'] === $peer_site_id ) { $peer = $item; break; } }
	if ( ! $peer || ! migration_control_state() ) { return new \WP_Error( 'sunrise_pair_peer', 'Choose a connected Sunrise site.', array( 'status' => 400 ) ); }
	$challenge = bin2hex( random_bytes( 32 ) );
	$pending = array( 'challenge' => hash( 'sha256', $challenge ), 'peer' => $peer, 'expires' => time() + 15 * MINUTE_IN_SECONDS );
	if ( ! update_user_meta( get_current_user_id(), 'sunrise_migration_pair_pending', $pending ) && get_user_meta( get_current_user_id(), 'sunrise_migration_pair_pending', true ) !== $pending ) { return new \WP_Error( 'sunrise_pair_storage', 'Could not start the migration pairing.', array( 'status' => 503 ) ); }
	$payload = array( 'schema' => 1, 'challenge' => $challenge, 'source_site_id' => migration_site_id(), 'source_url' => untrailingslashit( site_url( '/' ) ), 'source_name' => get_bloginfo( 'name' ), 'return_url' => add_query_arg( 'page', 'sunrise-migrations', admin_url( 'admin.php' ) ) );
	$url = add_query_arg( array( 'page' => 'sunrise-migrations', 'sunrise_pair_request' => migration_pair_encode( $payload ) ), trailingslashit( $peer['url'] ) . 'wp-admin/admin.php' );
	return array( 'approval_url' => $url );
}

function migration_pair_complete( $value ) {
	$access = migration_access(); if ( is_wp_error( $access ) ) { return $access; }
	$data = migration_pair_decode( $value ); $pending = get_user_meta( get_current_user_id(), 'sunrise_migration_pair_pending', true );
	if ( ! is_array( $data ) || count( $data ) !== 9 || ! is_string( $data['challenge'] ?? null ) || ! is_array( $pending ) || ( $pending['expires'] ?? 0 ) < time() || ! hash_equals( $pending['challenge'] ?? '', hash( 'sha256', $data['challenge'] ) ) || ( $pending['peer']['id'] ?? null ) !== ( $data['peer_site_id'] ?? null ) || ! is_string( $data['peer_url'] ?? null ) || untrailingslashit( $pending['peer']['url'] ?? '' ) !== untrailingslashit( $data['peer_url'] ) || ( $data['local_site_id'] ?? null ) !== migration_site_id() ) { return new \WP_Error( 'sunrise_pair_expired', 'This pairing request expired or does not match the selected sites.', array( 'status' => 400 ) ); }
	$pair = array( 'pair_id' => $data['pair_id'], 'local_site_id' => $data['local_site_id'], 'peer_site_id' => $data['peer_site_id'], 'peer_url' => $data['peer_url'], 'peer_name' => $data['peer_name'], 'created_at' => $data['created_at'], 'approved_by' => get_current_user_id() );
	$result = migration_pair_store( $pair, $data['secret'] );
	if ( ! is_wp_error( $result ) ) { delete_user_meta( get_current_user_id(), 'sunrise_migration_pair_pending' ); }
	return $result;
}

function migration_pair_remove( $peer_site_id ) {
	$access = migration_access(); if ( is_wp_error( $access ) ) { return $access; }
	$pairs = migration_pairs();
	if ( ! is_string( $peer_site_id ) || ! isset( $pairs[ $peer_site_id ] ) ) { return new \WP_Error( 'sunrise_pair_missing', 'That migration pairing does not exist.', array( 'status' => 404 ) ); }
	$active = get_option( 'sunrise_database_active' ); $job = $active && function_exists( __NAMESPACE__ . '\\database_job' ) ? database_job( $active ) : array();
	if ( ( $job['peer_site_id'] ?? null ) === $peer_site_id ) { return new \WP_Error( 'sunrise_pair_busy', 'Finish or cancel the active database migration before removing this pair.', array( 'status' => 409 ) ); }
	$remote = migration_pair_request( $peer_site_id, '/sunrise/v1/migrations/direct/revoke', array() );
	unset( $pairs[ $peer_site_id ] );
	if ( ! update_option( MIGRATION_PAIR_OPTION, $pairs, false ) && migration_pairs() !== $pairs ) { return new \WP_Error( 'sunrise_pair_storage', 'Could not remove the migration pairing.', array( 'status' => 503 ) ); }
	return array( 'removed' => true, 'remote_removed' => ! is_wp_error( $remote ) );
}

function migration_pair_revoke_remote( $peer_site_id ) {
	$pairs = migration_pairs(); if ( ! isset( $pairs[ $peer_site_id ] ) ) { return array( 'removed' => true ); }
	unset( $pairs[ $peer_site_id ] );
	if ( ! update_option( MIGRATION_PAIR_OPTION, $pairs, false ) && migration_pairs() !== $pairs ) { return new \WP_Error( 'sunrise_pair_storage', 'Could not remove the migration pairing.', array( 'status' => 503 ) ); }
	return array( 'removed' => true );
}

function migration_pair_approval_page( $encoded ) {
	$request = migration_pair_decode( $encoded );
	if ( ! $request || count( $request ) !== 6 || ( $request['schema'] ?? null ) !== 1 || ! is_string( $request['source_site_id'] ?? null ) || ! wp_is_uuid( $request['source_site_id'], 4 ) || ! is_string( $request['challenge'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $request['challenge'] ) || ! is_string( $request['source_url'] ?? null ) || ! is_string( $request['source_name'] ?? null ) || strlen( $request['source_name'] ) > 255 || ! is_string( $request['return_url'] ?? null ) ) { echo '<div class="notice notice-error"><p>Invalid migration pairing request.</p></div>'; return true; }
	$source_url = migration_pair_url( $request['source_url'] ?? null ); $return = wp_parse_url( $request['return_url'] ?? '' ); $source = is_wp_error( $source_url ) ? false : wp_parse_url( $source_url );
	if ( is_wp_error( $source_url ) || ! $return || ! $source || strtolower( $return['host'] ?? '' ) !== strtolower( $source['host'] ?? '' ) || strtolower( $return['scheme'] ?? '' ) !== strtolower( $source['scheme'] ?? '' ) || ( $return['port'] ?? null ) !== ( $source['port'] ?? null ) || ! preg_match( '#/wp-admin/admin\.php$#D', $return['path'] ?? '' ) || isset( $return['user'], $return['pass'], $return['fragment'] ) ) { echo '<div class="notice notice-error"><p>Invalid source callback.</p></div>'; return true; }
	$connected = null; foreach ( migration_connected_peers() as $peer ) { if ( $peer['id'] === $request['source_site_id'] && untrailingslashit( $peer['url'] ) === $source_url ) { $connected = $peer; break; } }
	if ( ! $connected ) { echo '<div class="notice notice-error"><p>The requesting site is not an active site in this Sunrise network. Sign in to Sunrise Control as this administrator and retry.</p></div>'; return true; }
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['sunrise_pair_approve'] ) ) {
		check_admin_referer( 'sunrise_pair_approve' ); $secret = bin2hex( random_bytes( 32 ) ); $pair_id = wp_generate_uuid4(); $created = time();
		$pair = array( 'pair_id' => $pair_id, 'local_site_id' => migration_site_id(), 'peer_site_id' => $request['source_site_id'], 'peer_url' => $source_url, 'peer_name' => sanitize_text_field( $request['source_name'] ?? $source_url ), 'created_at' => $created, 'approved_by' => get_current_user_id() );
		$result = migration_pair_store( $pair, $secret );
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; return true; }
		$response = array( 'schema' => 1, 'challenge' => $request['challenge'], 'pair_id' => $pair_id, 'secret' => $secret, 'local_site_id' => $request['source_site_id'], 'peer_site_id' => migration_site_id(), 'peer_url' => untrailingslashit( site_url( '/' ) ), 'peer_name' => get_bloginfo( 'name' ), 'created_at' => $created );
		$url = $request['return_url'] . '#sunrise-pair=' . migration_pair_encode( $response );
		echo '<div class="notice notice-success"><p>Connection approved. Finish pairing on ' . esc_html( $request['source_name'] ?? $source_url ) . '.</p></div><p><a class="button button-primary" href="' . esc_url( $url ) . '">Return and finish pairing</a></p>'; return true;
	}
	echo '<div class="migration-card"><h2>Approve migration connection</h2><p><strong>' . esc_html( $request['source_name'] ?? $source_url ) . '</strong><br><code>' . esc_html( $source_url ) . '</code></p><p>This creates a reusable, site-specific connection. Any current administrator on either site can push or pull through this pair. It does not grant access to other Sunrise sites.</p><form method="post">';
	wp_nonce_field( 'sunrise_pair_approve' ); echo '<input type="hidden" name="sunrise_pair_approve" value="1">'; submit_button( 'Approve this site pair', 'primary', 'submit', false ); echo '</form></div>'; return true;
}

function migration_pair_signature( $secret, $method, $route, $timestamp, $nonce, $body ) {
	return hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body ), $secret );
}

function migration_pair_request( $peer_site_id, $route, $data ) {
	$pair = migration_pair_find( $peer_site_id, null, true );
	if ( ! $pair || ! preg_match( '#^/sunrise/v1/migrations/direct/[a-z/-]+$#D', $route ) ) { return new \WP_Error( 'sunrise_pair_required', 'Approve a direct connection between these sites first.', array( 'status' => 403 ) ); }
	$body = wp_json_encode( $data, JSON_UNESCAPED_SLASHES ); if ( false === $body ) { return new \WP_Error( 'sunrise_pair_request', 'Could not encode the direct migration request.', array( 'status' => 400 ) ); } $timestamp = (string) time(); $nonce = bin2hex( random_bytes( 16 ) );
	$headers = array( 'Content-Type' => 'application/json', 'X-Sunrise-Pair' => $pair['pair_id'], 'X-Sunrise-Site' => $pair['local_site_id'], 'X-Sunrise-Time' => $timestamp, 'X-Sunrise-Nonce' => $nonce, 'X-Sunrise-Signature' => migration_pair_signature( $pair['plain_secret'], 'POST', $route, $timestamp, $nonce, $body ) );
	$args = array( 'method' => 'POST', 'timeout' => 30, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES, 'headers' => $headers, 'body' => $body, 'user-agent' => 'SunriseMigration/' . VERSION . ' (+' . home_url( '/' ) . ')' );
	$local = migration_local_url( $pair['peer_url'] );
	if ( $local && 'local' !== wp_get_environment_type() ) { return new \WP_Error( 'sunrise_pair_direction', 'A local site must initiate migrations involving a local URL.', array( 'status' => 409 ) ); }
	$request = $local ? 'wp_remote_request' : 'wp_safe_remote_request'; if ( $local ) { $args['sslverify'] = false; }
	$response = $request( trailingslashit( $pair['peer_url'] ) . 'wp-json' . $route, $args );
	if ( ! is_wp_error( $response ) && 404 === wp_remote_retrieve_response_code( $response ) ) { $response = $request( add_query_arg( 'rest_route', $route, trailingslashit( $pair['peer_url'] ) ), $args ); }
	if ( is_wp_error( $response ) ) { return new \WP_Error( 'sunrise_pair_unreachable', 'The paired site could not be reached directly.', array( 'status' => 502 ) ); }
	$status = wp_remote_retrieve_response_code( $response ); $value = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $status < 200 || $status >= 300 || ! is_array( $value ) ) { return new \WP_Error( 'sunrise_pair_remote', is_array( $value ) && isset( $value['message'] ) ? $value['message'] : 'The paired site rejected the direct request.', array( 'status' => $status ?: 502 ) ); }
	return $value;
}

function migration_pair_permission( $request ) {
	if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) { return new \WP_Error( 'sunrise_https_required', 'HTTPS is required.', array( 'status' => 403 ) ); }
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	$pair_id = $request->get_header( 'X-Sunrise-Pair' ); $sender = $request->get_header( 'X-Sunrise-Site' ); $timestamp = $request->get_header( 'X-Sunrise-Time' ); $nonce = $request->get_header( 'X-Sunrise-Nonce' ); $signature = $request->get_header( 'X-Sunrise-Signature' );
	$pair = migration_pair_find( $sender, $pair_id, true );
	if ( ! $pair || ! preg_match( '/^[0-9]{10}$/D', $timestamp ) || abs( time() - (int) $timestamp ) > 300 || ! preg_match( '/^[a-f0-9]{32}$/D', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) { return new \WP_Error( 'sunrise_pair_forbidden', 'Invalid migration pair authorization.', array( 'status' => 403 ) ); }
	$expected = migration_pair_signature( $pair['plain_secret'], $request->get_method(), $request->get_route(), $timestamp, $nonce, $request->get_body() );
	if ( ! hash_equals( $expected, $signature ) ) { return new \WP_Error( 'sunrise_pair_forbidden', 'Invalid migration pair signature.', array( 'status' => 403 ) ); }
	$key = 'sunrise_migration_nonce_' . hash( 'sha256', $pair_id . '|' . $nonce );
	if ( ! add_option( $key, time(), '', false ) ) { return new \WP_Error( 'sunrise_pair_replay', 'This migration request was already used.', array( 'status' => 409 ) ); }
	if ( 0 === hexdec( substr( $nonce, 0, 2 ) ) % 64 ) { global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d LIMIT 1000", $wpdb->esc_like( 'sunrise_migration_nonce_' ) . '%', time() - 10 * MINUTE_IN_SECONDS ) ); }
	return true;
}
