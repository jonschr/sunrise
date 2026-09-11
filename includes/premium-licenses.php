<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

function premium_license_plugins() {
	return array(
		'gp-premium'       => 'gp-premium/gp-premium.php',
		'generateblocks-pro' => 'generateblocks-pro/plugin.php',
		'admin-columns-pro' => 'admin-columns-pro/admin-columns-pro.php',
	);
}

function premium_license_metadata( $value ) {
	if ( ! is_array( $value ) || count( $value ) !== 2 || ! isset( $value['revision'], $value['configured'] ) || ! is_int( $value['revision'] ) || $value['revision'] < 0 || ! is_array( $value['configured'] ) || count( $value['configured'] ) > 3 ) { return false; }
	$supported = premium_license_plugins(); $seen = array();
	foreach ( $value['configured'] as $id ) {
		if ( ! is_string( $id ) || ! isset( $supported[ $id ] ) || isset( $seen[ $id ] ) ) { return false; }
		$seen[ $id ] = true;
	}
	return $value;
}

function premium_license_valid( $id ) {
	if ( 'gp-premium' === $id ) { return (bool) get_option( 'gen_premium_license_key' ) && 'valid' === get_option( 'gen_premium_license_key_status' ); }
	if ( 'generateblocks-pro' === $id ) { $value = get_option( 'generateblocks_pro_licensing', array() ); return ! empty( $value['key'] ) && isset( $value['status'] ) && 'valid' === $value['status']; }
	$key = get_option( 'acp_activation_key' ); $details = get_option( 'acp_subscription_details', array() );
	return is_string( $key ) && strlen( $key ) > 12 && false !== strpos( $key, '-' ) && $key === get_option( 'acp_subscription_details_key' ) && isset( $details['status'] ) && 'active' === $details['status'];
}

function premium_license_failure( $id, $state ) {
	$file = premium_license_plugins()[ $id ]; $events = array( isset( $state['premium_license_failures'][ $id ] ) ? $state['premium_license_failures'][ $id ] : '' );
	foreach ( get_option( 'sunrise_automatic_update_failures', array() ) as $failure ) {
		if ( isset( $failure['type'], $failure['installed_id'] ) && 'plugin' === $failure['type'] && $file === $failure['installed_id'] ) { $events[] = array( isset( $failure['failed_at'] ) ? (int) $failure['failed_at'] : 0, isset( $failure['version'] ) ? $failure['version'] : '' ); }
	}
	return hash( 'sha256', wp_json_encode( $events ) );
}

function premium_license_response( $value, $requested, $revision ) {
	if ( ! is_array( $value ) || count( $value ) !== 2 || ! isset( $value['revision'], $value['keys'] ) || $revision !== $value['revision'] || ! is_array( $value['keys'] ) || count( $value['keys'] ) > count( $requested ) ) { return false; }
	foreach ( $value['keys'] as $id => $key ) {
		if ( ! in_array( $id, $requested, true ) || ! is_string( $key ) || ! strlen( trim( $key ) ) || strlen( $key ) > 512 || preg_match( '/[\x00-\x1f\x7f]/', $key ) ) { return false; }
	}
	return $value;
}

function premium_license_sync( &$state, $metadata ) {
	$metadata = premium_license_metadata( $metadata );
	if ( ! $metadata ) { return new \WP_Error( 'sunrise_premium_license_schema', 'Invalid premium license settings.' ); }
	require_once ABSPATH . 'wp-admin/includes/plugin.php'; $installed = get_plugins(); $requested = array(); $attempts = isset( $state['premium_license_attempts'] ) && is_array( $state['premium_license_attempts'] ) ? $state['premium_license_attempts'] : array();
	foreach ( $metadata['configured'] as $id ) {
		if ( ! isset( $installed[ premium_license_plugins()[ $id ] ] ) ) { continue; }
		$failure = premium_license_failure( $id, $state ); $last = isset( $attempts[ $id ] ) ? $attempts[ $id ] : array();
		if ( isset( $last['revision'], $last['status'] ) && $last['revision'] === $metadata['revision'] && ( 'invalid' === $last['status'] || ( 'temporary' === $last['status'] && ! empty( $last['attempted_at'] ) && $last['attempted_at'] > time() - HOUR_IN_SECONDS ) ) ) { continue; }
		if ( isset( $last['revision'], $last['failure'], $last['status'] ) && $last['revision'] === $metadata['revision'] && $last['failure'] === $failure && 'success' === $last['status'] && premium_license_valid( $id ) ) { continue; }
		$requested[] = $id;
	}
	if ( ! $requested ) { return false; }
	$result = agent_http( 'agent/premium-licenses', array( 'revision' => $metadata['revision'], 'plugins' => $requested ), $state );
	if ( is_wp_error( $result ) || ! premium_license_response( $result, $requested, $metadata['revision'] ) ) {
		foreach ( $requested as $id ) { $attempts[ $id ] = array( 'revision' => $metadata['revision'], 'failure' => premium_license_failure( $id, $state ), 'status' => 'temporary', 'attempted_at' => time() ); }
		$state['premium_license_attempts'] = $attempts; return false;
	}
	$refreshed = false;
	foreach ( $requested as $id ) {
		$status = 'temporary';
		if ( isset( $result['keys'][ $id ] ) ) { $activated = premium_license_activate( $id, $result['keys'][ $id ] ); $status = is_wp_error( $activated ) ? ( 'sunrise_premium_license_invalid' === $activated->get_error_code() ? 'invalid' : 'temporary' ) : 'success'; $refreshed = $refreshed || ! is_wp_error( $activated ); }
		$attempts[ $id ] = array( 'revision' => $metadata['revision'], 'failure' => premium_license_failure( $id, $state ), 'status' => $status, 'attempted_at' => time() );
	}
	$state['premium_license_attempts'] = $attempts; return $refreshed;
}

function premium_license_clear_edd_cache( $id, $key ) {
	$slug = 'gp-premium' === $id ? 'gp-premium' : 'plugin';
	foreach ( array( false, true ) as $beta ) {
		$hash = md5( serialize( $slug . $key . $beta ) );
		delete_option( 'edd_sl_' . $hash ); delete_option( 'edd_api_request_' . $hash );
	}
	delete_option( 'edd_sl_failed_http_' . md5( trailingslashit( 'gp-premium' === $id ? 'https://generatepress.com' : 'https://generateblocks.com' ) ) );
}

function premium_license_activate( $id, $key ) {
	if ( 'admin-columns-pro' === $id ) { return premium_license_activate_admin_columns( trim( $key ) ); }
	$key = sanitize_key( $key ); $gp = 'gp-premium' === $id; $response = wp_remote_post( $gp ? 'https://generatepress.com' : 'https://generateblocks.com', array(
		'timeout' => 15, 'redirection' => 0, 'body' => array( 'edd_action' => 'activate_license', 'license' => $key, 'item_name' => rawurlencode( $gp ? 'GP Premium' : 'GenerateBlocks Pro' ), 'url' => home_url() ),
	) );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 400 ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'License service unavailable.' ); }
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! isset( $data['license'] ) || ! is_string( $data['license'] ) ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'Invalid license service response.' ); }
	$status = sanitize_key( $data['license'] );
	if ( $gp ) { update_option( 'gen_premium_license_key', $key ); update_option( 'gen_premium_license_key_status', $status ); }
	else { $saved = get_option( 'generateblocks_pro_licensing', array() ); $saved['key'] = $key; $saved['status'] = $status; update_option( 'generateblocks_pro_licensing', $saved ); }
	if ( 'valid' !== $status ) { return new \WP_Error( 'sunrise_premium_license_invalid', 'License key was not accepted.' ); }
	premium_license_clear_edd_cache( $id, $key );
	return true;
}

function premium_license_acp_request( $command, $body, $version ) {
	$body['command'] = $command; $body['meta'] = array( 'php_version' => PHP_VERSION, 'acp_version' => $version, 'is_network' => false );
	if ( 'local' === wp_get_environment_type() ) { $body['meta']['ip'] = '127.0.0.1'; }
	$response = wp_remote_post( 'https://api.admincolumns.com', array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'X-AC-Version' => $version, 'X-AC-Command' => $command ), 'body' => $body ) );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 400 ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'License service unavailable.' ); }
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! $data ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'Invalid license service response.' ); }
	$permissions = isset( $data['permissions'] ) ? $data['permissions'] : ( isset( $data['data']['permissions'] ) ? $data['data']['permissions'] : null );
	if ( is_array( $permissions ) ) { update_option( '_acp_access_permissions', array_values( array_intersect( $permissions, array( 'usage', 'update' ) ) ) ); }
	return ! empty( $data['error'] ) ? new \WP_Error( 'sunrise_premium_license_invalid', 'License key was not accepted.' ) : $data;
}

function premium_license_activate_admin_columns( $key ) {
	if ( strlen( $key ) <= 12 || false === strpos( $key, '-' ) ) { return new \WP_Error( 'sunrise_premium_license_invalid', 'License key is invalid.' ); }
	require_once ABSPATH . 'wp-admin/includes/plugin.php'; $plugins = get_plugins(); $version = isset( $plugins['admin-columns-pro/admin-columns-pro.php']['Version'] ) ? $plugins['admin-columns-pro/admin-columns-pro.php']['Version'] : '';
	$activated = premium_license_acp_request( 'activate', array( 'subscription_key' => $key, 'activation_url' => site_url() ), $version );
	if ( is_wp_error( $activated ) ) { return $activated; }
	$activation_key = isset( $activated['activation_key'] ) ? $activated['activation_key'] : '';
	if ( ! is_string( $activation_key ) || strlen( $activation_key ) <= 12 || false === strpos( $activation_key, '-' ) ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'Invalid license service response.' ); }
	update_option( 'acp_activation_key', $activation_key );
	$details = premium_license_acp_request( 'subscription_details', array( 'activation_url' => site_url(), 'activation_key' => $activation_key ), $version );
	if ( is_wp_error( $details ) ) { return $details; }
	if ( ! isset( $details['status'], $details['renewal_method'] ) || ! in_array( $details['status'], array( 'active', 'cancelled', 'expired' ), true ) || ! in_array( $details['renewal_method'], array( 'auto', 'manual' ), true ) ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'Invalid license service response.' ); }
	$expiry = null;
	if ( ! empty( $details['expiry_date'] ) ) { $date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $details['expiry_date'], new \DateTimeZone( 'Europe/Amsterdam' ) ); if ( ! $date ) { return new \WP_Error( 'sunrise_premium_license_temporary', 'Invalid license service response.' ); } if ( $date <= \DateTime::createFromFormat( 'Y-m-d', '2037-12-30' ) ) { $expiry = $date->getTimestamp(); } }
	update_option( 'acp_subscription_details', array( 'status' => $details['status'], 'renewal_method' => $details['renewal_method'], 'expiry_date' => $expiry ) ); update_option( 'acp_subscription_details_key', $activation_key ); delete_option( 'acp_subscription_key' );
	if ( 'active' !== $details['status'] ) { return new \WP_Error( 'sunrise_premium_license_invalid', 'License key is not active.' ); }
	$products = premium_license_acp_request( 'products_update', array( 'activation_url' => site_url(), 'activation_key' => $activation_key ), $version ); if ( ! is_wp_error( $products ) ) { update_option( 'acp_update_plugins_data', $products ); }
	return true;
}
