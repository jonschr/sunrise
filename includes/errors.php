<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

/** Default-on, location-only fatal summaries. Never install an error handler or send HTTP during shutdown. */
/** An explicit opt-out survives upgrades; unconnected installations do not collect. */
function error_reporting_enabled( $state ) {
	return ! empty( $state['site_id'] ) && ( ! isset( $state['errors_enabled'] ) || (bool) $state['errors_enabled'] );
}

function error_capture_enabled() {
	foreach ( agent_states() as $state ) {
		if ( error_reporting_enabled( $state ) && empty( $state['revoked'] ) && agent_owner_valid( $state ) && agent_service_matches( $state ) ) { return true; }
	}
	return false;
}

function error_capture_setting( $enabled ) {
	$access = agent_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
	try {
		$state = agent_state();
		if ( empty( $state['site_id'] ) || ! agent_owner_valid( $state ) || is_wp_error( installation_guard() ) ) { return new \WP_Error( 'sunrise_errors_forbidden', 'A valid enrolled administrator connection is required.' ); }
		$state['errors_enabled'] = (bool) $enabled;
		if ( ! agent_store( $state ) ) { return new \WP_Error( 'sunrise_agent_storage', 'Could not save diagnostic preference.' ); }
		return true;
	} finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
}

/** Paths outside known WordPress code roots, unusual names and dynamic eval locations are suppressed. */
function error_location( $file ) {
	if ( ! is_string( $file ) ) { return 'outside/unknown'; }
	$file = wp_normalize_path( $file );
	$roots = array( 'plugins' => WP_PLUGIN_DIR, 'mu-plugins' => WPMU_PLUGIN_DIR, 'themes' => get_theme_root(), 'content' => WP_CONTENT_DIR, 'wordpress' => ABSPATH );
	foreach ( $roots as $kind => $root ) {
		$root = trailingslashit( wp_normalize_path( $root ) );
		if ( 0 !== strpos( $file, $root ) ) { continue; }
		$relative = substr( $file, strlen( $root ) );
		if ( 'content' === $kind || ( 'wordpress' === $kind && ! preg_match( '#^(wp-admin/|wp-includes/|wp-[a-z-]+\.php$|index\.php$)#D', $relative ) ) ) { return 'outside/unknown'; }
		if ( strlen( $kind . '/' . $relative ) > 240 || ! preg_match( '#^[A-Za-z0-9_./-]+$#D', $relative ) || false !== strpos( $relative, '..' ) || false !== strpos( $relative, '//' ) ) { return 'outside/unknown'; }
		return $kind . '/' . $relative;
	}
	return 'outside/unknown';
}

function error_summaries() {
	$groups = get_option( 'sunrise_error_groups', array() ); $valid = array();
	foreach ( is_array( $groups ) ? array_slice( $groups, 0, 100, true ) : array() as $key => $group ) {
		if ( ! is_array( $group ) || count( $group ) !== 7 || ! isset( $group['id'], $group['code'], $group['file'], $group['line'], $group['count'], $group['first_at'], $group['last_at'] ) ) { continue; }
		if ( ! is_string( $group['id'] ) || ! wp_is_uuid( $group['id'], 4 ) || ! is_string( $group['file'] ) || ! is_int( $group['last_at'] ) || ! is_int( $group['first_at'] ) || $group['first_at'] > $group['last_at'] || $group['first_at'] < time() - 3 * DAY_IN_SECONDS || $group['last_at'] > time() + 300 ) { continue; }
		if ( ! in_array( $group['code'], array( 'fatal', 'parse', 'core', 'compile', 'user_fatal', 'recoverable' ), true ) || ! is_int( $group['line'] ) || $group['line'] < 0 || $group['line'] > 10000000 || ! is_int( $group['count'] ) || $group['count'] < 1 || $group['count'] > 1000000 ) { continue; }
		if ( strlen( $group['file'] ) > 240 || ! preg_match( '#^(wordpress|plugins|themes|mu-plugins|content|outside)/[A-Za-z0-9_./-]+$#D', $group['file'] ) || false !== strpos( $group['file'], '..' ) || false !== strpos( $group['file'], '//' ) ) { continue; }
		$valid[ $key ] = $group;
	}
	if ( $groups !== $valid ) { update_option( 'sunrise_error_groups', $valid, false ); }
	return $valid;
}

function capture_error_summary( $error ) {
	$codes = array( E_ERROR => 'fatal', E_PARSE => 'parse', E_CORE_ERROR => 'core', E_COMPILE_ERROR => 'compile', E_USER_ERROR => 'user_fatal', E_RECOVERABLE_ERROR => 'recoverable' );
	if ( ! is_array( $error ) || ! isset( $error['type'], $codes[ $error['type'] ] ) || ! error_capture_enabled() || is_wp_error( installation_guard() ) ) { return; }
	$now = time();
	// ponytail: capture at most once per 10 seconds per installation; counts are sampled, not total failures.
	$previous = get_option( 'sunrise_error_capture_lock', false );
	if ( $previous > $now ) { return; }
	if ( false === $previous ) { if ( ! add_option( 'sunrise_error_capture_lock', $now + 10, '', false ) ) { return; } }
	else {
		global $wpdb;
		if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name='sunrise_error_capture_lock' AND option_value=%s", (string) ( $now + 10 ), (string) $previous ) ) ) { return; }
		wp_cache_delete( 'sunrise_error_capture_lock', 'options' );
	}
	$groups = error_summaries();
	$file = error_location( isset( $error['file'] ) ? $error['file'] : null );
	$line = isset( $error['line'] ) && is_int( $error['line'] ) ? max( 0, min( 10000000, $error['line'] ) ) : 0;
	$code = $codes[ $error['type'] ]; $key = hash( 'sha256', $code . '|' . $file . '|' . $line );
	if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'id' => wp_generate_uuid4(), 'code' => $code, 'file' => $file, 'line' => $line, 'count' => 0, 'first_at' => $now ); }
	$groups[ $key ]['count'] = min( 1000000, $groups[ $key ]['count'] + 1 ); $groups[ $key ]['last_at'] = $now;
	uasort( $groups, function ( $a, $b ) { return $b['last_at'] <=> $a['last_at']; } );
	update_option( 'sunrise_error_groups', array_slice( $groups, 0, 100, true ), false );
}

// Native recovery (and WP-CLI's wp_die handler) may exit before later shutdown callbacks.
add_filter( 'wp_php_error_message', function ( $message, $error ) {
	try { capture_error_summary( $error ); } catch ( \Throwable $failure ) { /* Preserve the native response. */ }
	return $message;
}, 10, 2 );

register_shutdown_function( function () {
	try { capture_error_summary( error_get_last() ); } catch ( \Throwable $error ) { /* Diagnostics cannot replace WordPress/PHP recovery. */ }
} );

/** Separate, coalesced delivery after normal synchronization; no job or report sequence is changed. */
function agent_report_errors() {
	$state = agent_state();
	$groups = array_values( error_summaries() );
	if ( ! error_reporting_enabled( $state ) || empty( $state['errors_supported'] ) || ! agent_owner_valid( $state ) || ! empty( $state['revoked'] ) ) { return true; }
	if ( ! $groups ) { return true; }
	$hash = hash( 'sha256', wp_json_encode( array( $state['site_id'], $groups ) ) );
	$key = 'sunrise_error_ack_' . get_current_user_id();
	if ( get_option( $key ) === $hash ) { return true; }
	$result = agent_http( 'agent/errors', array( 'groups' => $groups ), $state );
	if ( ! is_wp_error( $result ) && isset( $result['accepted'] ) && true === $result['accepted'] ) { update_option( $key, $hash, false ); return true; }
	return is_wp_error( $result ) ? $result : new \WP_Error( 'sunrise_errors_receipt', 'Invalid diagnostic receipt.' );
}
