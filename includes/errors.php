<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/error-context.php';

/** Default-on fatal diagnostics. Never replace error handlers or send HTTP during shutdown. */
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
	if ( ! agent_connection_lock() ) { return new \WP_Error( 'sunrise_agent_busy', 'Connection work is running. Retry.' ); }
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

/** Redact credentials even when another plugin's exception contains no key=value label. */
function error_redact_secrets( $message ) {
	$message = preg_replace( '~\b(?:sk|rk)[_-](?:live|test|proj|ant|or)[A-Za-z0-9_-]*|\b(?:gh[pousr]_|github_pat_)[A-Za-z0-9_]+|\bxox[baprs]-[A-Za-z0-9-]+|\b(?:AKIA|ASIA)[A-Z0-9]{16}\b|\bAIza[A-Za-z0-9_-]{20,}~', '[redacted]', $message );
	$message = preg_replace( '~\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b~', '[redacted]', $message );
	$message = preg_replace( '~\b[a-f0-9]{64}\b~i', '[redacted]', $message );
	$message = preg_replace( '~\b(?:Bearer|Basic)\s+[A-Za-z0-9+/_.=-]+~i', '[redacted]', $message );
	return preg_replace( '~\b(password|passwd|secret|token|api[_ -]?key|authorization|licen[cs]e(?:[_ -]?key)?)(?:\s+(?:provided|received|value|is|was))*\s*[:=]\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;]+)~i', '$1=[redacted]', $message );
}

/** Keep the first message line and code-only frames; never retain trace arguments or objects. */
function error_diagnostics( $error ) {
	$raw = isset( $error['message'] ) && is_string( $error['message'] ) ? substr( $error['message'], 0, 65536 ) : '';
	$message = error_redact_secrets( preg_split( '/[\r\n]/', $raw, 2 )[0] );
	if ( ! empty( $error['file'] ) && is_string( $error['file'] ) ) { $message = str_replace( array( $error['file'], wp_normalize_path( $error['file'] ) ), error_location( $error['file'] ), $message ); }
	$message = preg_replace( '~\bhttps?://[^\s<>]+~i', '[URL]', $message );
	$message = preg_replace( '~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i', '[email]', $message );
	$message = preg_replace( '~\bAuthorization\s*[:=]\s*(?:Bearer|Basic)\s+\S+~i', 'Authorization=[redacted]', $message );
	$message = preg_replace( '~\b(?:Cookie|Set-Cookie)\s*[:=].*$~i', 'Cookie=[redacted]', $message );
	$message = preg_replace( '~\b(password|passwd|secret|token|api[_ -]?key|authorization|licen[cs]e(?:[_ -]?key)?)\s*[:=]\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;]+)~i', '$1=[redacted]', $message );
	$message = preg_replace( '~\b(?:SQL\s+)?(?:SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+.+$~i', '[query redacted]', $message );
	$message = preg_replace( '~(["\x27])(?:[A-Z]:[\\\\/]|/)[^"\x27]+\1~i', '[path]', $message );
	$message = preg_replace( '~(?<![\w/])(?:[A-Z]:[\\\\/]|/)[^\s<>"\x27]+~i', '[path]', $message );
	$message = preg_replace( '/[\x00-\x1f\x7f]/', ' ', $message );
	$message = wp_check_invalid_utf8( substr( trim( $message ), 0, 2000 ), true );
	$source = isset( $error['trace'] ) && is_array( $error['trace'] ) ? array_slice( $error['trace'], 0, 33 ) : array();
	$truncated = ( isset( $error['trace'] ) && is_array( $error['trace'] ) && count( $error['trace'] ) > 32 ) || ( isset( $error['message'] ) && is_string( $error['message'] ) && strlen( $error['message'] ) > 65536 );
	if ( ! $source && preg_match_all( '/^#\d+\s+(.+?)\((\d+)\):\s+([^\r\n(]*)\(/m', $raw, $matches, PREG_SET_ORDER ) ) {
		$truncated = $truncated || count( $matches ) > 32;
		foreach ( array_slice( $matches, 0, 32 ) as $match ) { $source[] = array( 'file' => $match[1], 'line' => (int) $match[2], 'function' => trim( $match[3] ) ); }
	}
	$trace = array();
	foreach ( array_slice( $source, 0, 32 ) as $frame ) {
		if ( ! is_array( $frame ) ) { continue; }
		$function = ( isset( $frame['class'] ) && is_string( $frame['class'] ) ? $frame['class'] . ( ( $frame['type'] ?? null ) === '->' ? '->' : '::' ) : '' ) . ( isset( $frame['function'] ) && is_string( $frame['function'] ) ? $frame['function'] : '{unknown}' );
		if ( strlen( $function ) > 200 || ! preg_match( '/^[A-Za-z0-9_\\\\:{}>\-]+$/D', $function ) ) { $function = '{unknown}'; }
		$trace[] = array( 'file' => error_location( $frame['file'] ?? null ), 'line' => isset( $frame['line'] ) && is_int( $frame['line'] ) ? max( 0, min( 10000000, $frame['line'] ) ) : 0, 'function' => $function );
	}
	return array( 'message' => $message, 'stack_trace' => $trace, 'trace_truncated' => $truncated );
}

function error_summaries() {
	$groups = get_option( 'sunrise_error_groups', array() ); $valid = array();
	foreach ( is_array( $groups ) ? array_slice( $groups, 0, 100, true ) : array() as $key => $group ) {
		if ( ! is_array( $group ) || array_diff( array_keys( $group ), array( 'id', 'code', 'file', 'line', 'count', 'first_at', 'last_at', 'details', 'occurrences' ) ) || ! isset( $group['id'], $group['code'], $group['file'], $group['line'], $group['count'], $group['first_at'], $group['last_at'] ) ) { continue; }
		if ( ! is_string( $group['id'] ) || ! wp_is_uuid( $group['id'], 4 ) || ! is_string( $group['file'] ) || ! is_int( $group['last_at'] ) || ! is_int( $group['first_at'] ) || $group['first_at'] > $group['last_at'] || $group['first_at'] < time() - 3 * DAY_IN_SECONDS || $group['last_at'] > time() + 300 ) { continue; }
		if ( ! in_array( $group['code'], array( 'fatal', 'parse', 'core', 'compile', 'user_fatal', 'recoverable' ), true ) || ! is_int( $group['line'] ) || $group['line'] < 0 || $group['line'] > 10000000 || ! is_int( $group['count'] ) || $group['count'] < 1 || $group['count'] > 1000000 ) { continue; }
		if ( strlen( $group['file'] ) > 240 || ! preg_match( '#^(wordpress|plugins|themes|mu-plugins|content|outside)/[A-Za-z0-9_./-]+$#D', $group['file'] ) || false !== strpos( $group['file'], '..' ) || false !== strpos( $group['file'], '//' ) ) { continue; }
		if ( array_key_exists( 'details', $group ) && ( ! is_array( $group['details'] ) || array_diff( array_keys( $group['details'] ), array( 'message', 'stack_trace', 'trace_truncated' ) ) || ! isset( $group['details']['message'], $group['details']['stack_trace'] ) || ! is_string( $group['details']['message'] ) || strlen( $group['details']['message'] ) > 2000 || ! is_array( $group['details']['stack_trace'] ) || count( $group['details']['stack_trace'] ) > 32 || ( isset( $group['details']['trace_truncated'] ) && ! is_bool( $group['details']['trace_truncated'] ) ) ) ) { continue; }
		if ( isset( $group['details']['message'] ) ) { $group['details']['message'] = error_redact_secrets( $group['details']['message'] ); }
		foreach ( $group['details']['stack_trace'] ?? array() as $frame ) {
			if ( ! is_array( $frame ) || count( $frame ) !== 3 || ! isset( $frame['file'], $frame['line'], $frame['function'] ) || ! is_string( $frame['file'] ) || strlen( $frame['file'] ) > 240 || ! preg_match( '#^(wordpress|plugins|themes|mu-plugins|content|outside)/[A-Za-z0-9_./-]+$#D', $frame['file'] ) || false !== strpos( $frame['file'], '..' ) || false !== strpos( $frame['file'], '//' ) || ! is_int( $frame['line'] ) || $frame['line'] < 0 || $frame['line'] > 10000000 || ! is_string( $frame['function'] ) || strlen( $frame['function'] ) > 200 || ! preg_match( '/^[A-Za-z0-9_\\\\:{}>\-]+$/D', $frame['function'] ) ) { continue 2; }
		}
		if ( isset( $group['occurrences'] ) ) {
			if ( ! is_array( $group['occurrences'] ) || count( $group['occurrences'] ) > 3 ) { continue; }
			$previous_at = 0;
			foreach ( $group['occurrences'] as $occurrence ) {
				if ( ! is_array( $occurrence ) || count( $occurrence ) !== 2 || ! isset( $occurrence['captured_at'], $occurrence['context'] ) || ! is_int( $occurrence['captured_at'] ) || $occurrence['captured_at'] < $group['first_at'] || $occurrence['captured_at'] > $group['last_at'] || $occurrence['captured_at'] <= $previous_at || ! error_context_valid( $occurrence['context'] ) ) { continue 2; }
				$previous_at = $occurrence['captured_at'];
			}
		}
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
	$groups[ $key ]['details'] = error_diagnostics( $error );
	try {
		$context = error_context();
		if ( error_context_valid( $context ) ) {
			$occurrences = array_values( array_filter( $groups[ $key ]['occurrences'] ?? array(), function ( $occurrence ) use ( $now ) { return $occurrence['captured_at'] < $now; } ) );
			$occurrences[] = array( 'captured_at' => $now, 'context' => $context );
			$groups[ $key ]['occurrences'] = array_slice( $occurrences, -3 );
		}
	} catch ( \Throwable $failure ) { /* Keep the baseline report if context collection fails. */ }
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
	foreach ( $groups as &$group ) {
		if ( empty( $state['error_context_supported'] ) ) {
			unset( $group['occurrences'] );
			if ( isset( $group['details'] ) ) { unset( $group['details']['trace_truncated'] ); $group['details']['stack_trace'] = array_slice( $group['details']['stack_trace'], 0, 12 ); }
		}
		if ( empty( $state['error_details_supported'] ) ) { unset( $group['details'] ); }
	} unset( $group );
	$hash = hash( 'sha256', wp_json_encode( array( $state['site_id'], $groups ) ) );
	$key = 'sunrise_error_ack_' . get_current_user_id();
	if ( get_option( $key ) === $hash ) { return true; }
	// Bound delivery independently of the 100-group local retention limit.
	$batches = array(); $batch = array(); $bytes = 0;
	foreach ( $groups as $group ) {
		$size = strlen( wp_json_encode( $group ) ) + 1;
		if ( $batch && $bytes + $size > 524288 ) { $batches[] = $batch; $batch = array(); $bytes = 0; }
		$batch[] = $group; $bytes += $size;
	}
	if ( $batch ) { $batches[] = $batch; }
	foreach ( $batches as $batch ) {
		$result = agent_http( 'agent/errors', array( 'groups' => $batch ), $state );
		if ( is_wp_error( $result ) || ! isset( $result['accepted'] ) || true !== $result['accepted'] ) { return is_wp_error( $result ) ? $result : new \WP_Error( 'sunrise_errors_receipt', 'Invalid diagnostic receipt.' ); }
	}
	update_option( $key, $hash, false ); return true;
}
