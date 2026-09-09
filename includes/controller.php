<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

function connections() {
	if ( is_wp_error( installation_guard() ) ) { return array(); }
	if ( agent_states() ) { return array(); }
	return get_option( 'sunrise_connections_' . get_current_user_id(), array() );
}

function local_url( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return 'local' === wp_get_environment_type() && ( in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) || '.localhost' === substr( $host, -10 ) );
}

/** Encrypt saved credentials with the controller's salts; changing salts requires reconnecting. */
function credential( $value, $context, $decrypt = false ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return new \WP_Error( 'sunrise_crypto_unavailable', __( 'The controller needs the PHP OpenSSL extension to store connections.', 'sunrise' ) );
	}
	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	if ( $decrypt ) {
		$bytes = base64_decode( $value, true );
		if ( false === $bytes || strlen( $bytes ) < 29 ) {
			return new \WP_Error( 'sunrise_credential_invalid', __( 'Reconnect this site to replace its saved credential.', 'sunrise' ) );
		}
		$plain = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), $context );
		return false === $plain ? new \WP_Error( 'sunrise_credential_invalid', __( 'Reconnect this site; the saved credential cannot be decrypted.', 'sunrise' ) ) : $plain;
	}
	$iv = random_bytes( 12 );
	$tag = '';
	$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context );
	return false === $cipher ? new \WP_Error( 'sunrise_credential_invalid', __( 'Could not encrypt the credential.', 'sunrise' ) ) : base64_encode( $iv . $tag . $cipher );
}

function connection_context( $connection ) {
	return get_current_user_id() . '|' . $connection['url'] . '|' . $connection['username'];
}

function controller_request( $connection, $path, $method = 'GET', $body = null ) {
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'sunrise_forbidden', __( 'Administrator access is required.', 'sunrise' ) );
	}
	$url = $connection['url'];
	$local = local_url( $url );
	$parts = wp_parse_url( $url );
	if ( ! $parts || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['scheme'], $local ? array( 'http', 'https' ) : array( 'https' ), true ) ) {
		return new \WP_Error( 'sunrise_invalid_url', __( 'Use an HTTPS WordPress URL, or a loopback/localhost HTTP URL in a local environment.', 'sunrise' ) );
	}
	$encoded_body = null === $body ? '' : wp_json_encode( $body );
	if ( agent_states() ) {
		return new \WP_Error( 'sunrise_managed', 'Peer connections are disabled while enrolled with the central service.' );
	}
	$password = credential( $connection['secret'], connection_context( $connection ), true );
	if ( is_wp_error( $password ) ) { return $password; }
	$auth_headers = array( 'Authorization' => 'Basic ' . base64_encode( $connection['username'] . ':' . $password ) );
	$args = array(
		'method' => $method, 'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
		'headers' => array_merge( $auth_headers, array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ) ),
		'user-agent' => 'SunriseController/' . VERSION . ' (+' . home_url( '/' ) . ')',
		'sslverify' => ! ( $local && ! empty( $connection['trust_local_certificate'] ) ),
	);
	if ( null !== $body ) {
		$args['body'] = $encoded_body;
	}
	$endpoint = add_query_arg( 'rest_route', '/sunrise/v1/' . $path, trailingslashit( $url ) . 'index.php' );
	$response = $local ? wp_remote_request( $endpoint, $args ) : wp_safe_remote_request( $endpoint, $args );
	if ( is_wp_error( $response ) ) {
		return new \WP_Error( 'sunrise_connection_failed', __( 'Connection failed or timed out. Check the URL, TLS certificate, and firewall. A submitted job may still be running; check its status before submitting another.', 'sunrise' ) );
	}
	$status = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
		$code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? sanitize_key( $data['code'] ) : 'non_json_response';
		return new \WP_Error( 'sunrise_remote_error', sprintf( __( 'Remote HTTP %1$d (%2$s). Check authentication or host firewall logs. For 429, honor Retry-After before retrying.', 'sunrise' ), $status, $code ) );
	}
	return $data;
}

function connect_site( $url, $username, $password, $trust_local_certificate = false ) {
	$connection = array( 'url' => untrailingslashit( esc_url_raw( $url ) ), 'username' => $username, 'trust_local_certificate' => (bool) $trust_local_certificate );
	if ( ! $username || ! $password || preg_match( '/[:\r\n]/', $username ) ) {
		return new \WP_Error( 'sunrise_credentials_required', __( 'Enter a WordPress username and its application password.', 'sunrise' ) );
	}
	$connection['secret'] = credential( $password, connection_context( $connection ) );
	if ( is_wp_error( $connection['secret'] ) ) {
		return $connection['secret'];
	}
	$inventory = controller_request( $connection, 'inventory' );
	if ( is_wp_error( $inventory ) ) {
		return $inventory;
	}
	if ( ! isset( $inventory['api_version'] ) || 1 !== $inventory['api_version'] ) {
		return new \WP_Error( 'sunrise_api_mismatch', __( 'The target did not return a supported Sunrise inventory.', 'sunrise' ) );
	}
	if ( $connection['url'] === untrailingslashit( home_url( '/' ) ) ) {
		return job_error( 'self_connection', __( 'This site is already available locally.', 'sunrise' ), 400 );
	}
	$connections = connections();
	$id = substr( hash( 'sha256', $connection['url'] ), 0, 16 );
	if ( ! isset( $connections[ $id ] ) && count( $connections ) >= 50 ) {
		return new \WP_Error( 'sunrise_limit', 'The legacy controller supports 50 connections.' );
	}
	$connections[ $id ] = $connection;
	update_option( 'sunrise_connections_' . get_current_user_id(), $connections, false );
	update_option( snapshot_key( $id ), array( 'inventory' => $inventory, 'checked_at' => time(), 'error' => null ), false );
	return $id;
}

function snapshot_key( $id ) { return 'sunrise_snapshot_' . get_current_user_id() . '_' . $id; }

function remove_peer( $id ) {
	$connections = connections();
	unset( $connections[ $id ] );
	update_option( 'sunrise_connections_' . get_current_user_id(), $connections, false );
	delete_option( snapshot_key( $id ) );
	return true;
}

function refresh_peer( $id ) {
	$peers = connections();
	if ( ! current_user_can( 'manage_options' ) || ! isset( $peers[ $id ] ) ) {
		return job_error( 'unknown_peer', __( 'The site is not an authorized network member.', 'sunrise' ), 403 );
	}
	$snapshot = get_option( snapshot_key( $id ), array() );
	if ( ! empty( $snapshot['attempted_at'] ) && time() - $snapshot['attempted_at'] < 15 ) {
		return $snapshot;
	}
	$snapshot['attempted_at'] = time();
	update_option( snapshot_key( $id ), $snapshot, false );
	$result = controller_request( $peers[ $id ], 'inventory' );
	$snapshot['error'] = is_wp_error( $result ) ? $result->get_error_message() : null;
	if ( ! is_wp_error( $result ) ) {
		$snapshot['inventory'] = $result;
		$snapshot['checked_at'] = time();
	}
	update_option( snapshot_key( $id ), $snapshot, false );
	return $snapshot;
}
