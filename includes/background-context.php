<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

function background_connection_fingerprint( $state ) {
	$fields = array(); foreach ( array( 'user_id', 'url', 'control_url', 'enrollment_id', 'site_id', 'generation', 'digest' ) as $name ) { $fields[ $name ] = $state[ $name ] ?? null; }
	return hash( 'sha256', wp_json_encode( $fields ) );
}

/** Use a normal admin bootstrap: providers may check the user before Sunrise's cron callback starts. */
function agent_background_dispatch( $hook, $user_id ) {
	if ( ! empty( $GLOBALS['sunrise_background_context'] ) || ! wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI && defined( 'SUNRISE_TEST_SITE' ) && SUNRISE_TEST_SITE ) ) { return false; }
	if ( ! in_array( $hook, array( 'sunrise_check_in', 'sunrise_wake_check_in' ), true ) ) { return false; }
	$states = agent_states(); $state = $states[ $user_id ] ?? array();
	if ( ! $state || ! agent_owner_valid( $state ) || ! empty( $state['revoked'] ) ) { return true; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$name = 'sunrise_background_' . (int) $user_id;
	// Coalesce concurrent triggers without replacing an outstanding one-time request.
	if ( ! \WP_Upgrader::create_lock( $name, 30 ) ) { return true; }
	$manager = null; $session = null;
	$previous = get_current_user_id(); wp_set_current_user( $user_id );
	try {
		wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
		$pending = get_option( $name, array() );
		if ( $pending && $pending['expires_at'] > time() ) { return true; }
		if ( $pending ) { agent_log( 'background_context_failed', array( 'code' => 'worker_not_received' ) ); }
		delete_option( $name );
		$ticket = bin2hex( random_bytes( 32 ) ); $expires = time() + 120;
		$args = array( (int) $user_id ); $retry = wp_next_scheduled( 'sunrise_wake_check_in', $args ); $reserved = false;
		if ( ! $retry ) { $retry = $expires + 1; $reserved = wp_schedule_single_event( $retry, 'sunrise_wake_check_in', $args ); }
		$pending = array( 'digest' => hash( 'sha256', $ticket ), 'expires_at' => $expires, 'hook' => $hook, 'connection' => background_connection_fingerprint( $state ), 'retry_at' => $reserved ? $retry : null );
		if ( ! add_option( $name, $pending, '', false ) ) { return true; }
		$url = admin_url( 'admin-ajax.php' );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && 'local' !== wp_get_environment_type() ) { throw new \RuntimeException( 'background_https_required' ); }
		$target = wp_parse_url( $url ); $origin = wp_parse_url( installation_site_url() );
		if ( ! $target || ! $origin || isset( $target['user'] ) || isset( $target['pass'] ) || isset( $target['query'] ) || isset( $target['fragment'] ) || $target['scheme'] !== $origin['scheme'] || strtolower( $target['host'] ) !== strtolower( $origin['host'] ) || ( $target['port'] ?? ( 'https' === $target['scheme'] ? 443 : 80 ) ) !== ( $origin['port'] ?? ( 'https' === $origin['scheme'] ? 443 : 80 ) ) || $target['path'] !== wp_parse_url( site_url( 'wp-admin/admin-ajax.php', 'admin' ), PHP_URL_PATH ) ) { throw new \RuntimeException( 'background_origin_changed' ); }
		// Short-lived, server-only native cookies authenticate plugins from the start of WordPress loading.
		$manager = \WP_Session_Tokens::get_instance( $user_id ); $session = $manager->create( $expires );
		$pending['session_hash'] = hash( 'sha256', $session );
		if ( ! update_option( $name, $pending, false ) ) { throw new \RuntimeException( 'background_storage_failed' ); }
		$secure = 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
		$cookies = array( new \WP_Http_Cookie( array( 'name' => $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expires, $secure ? 'secure_auth' : 'auth', $session ) ) ), new \WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $session ) ) ) );
		$result = wp_remote_post( $url, array( 'timeout' => 1, 'blocking' => false, 'redirection' => 0, 'cookies' => $cookies, 'body' => array( 'action' => 'sunrise_background', 'ticket' => $ticket ) ) );
		if ( is_wp_error( $result ) ) { throw new \RuntimeException( $result->get_error_code() ); }
		agent_log( 'background_context_dispatched', array( 'hook' => $hook ) );
	} catch ( \Throwable $error ) {
		if ( $manager && $session ) { $manager->destroy( $session ); }
		delete_option( $name );
		// Keep the reserved retry. Running in the anonymous bootstrap would recreate the provider mismatch.
		$code = $error->getMessage();
		agent_log( 'background_context_failed', array( 'code' => in_array( $code, array( 'background_https_required', 'background_origin_changed', 'background_storage_failed', 'http_request_failed', 'http_request_not_executed' ), true ) ? $code : 'loopback_unavailable' ) );
	} finally { wp_set_current_user( $previous ); \WP_Upgrader::release_lock( $name ); }
	return true;
}

/** Native authentication is required as well as a scoped, expiring, atomically consumed ticket. */
function agent_background_claim( $ticket ) {
	if ( ! is_string( $ticket ) || ! preg_match( '/^[a-f0-9]{64}$/D', $ticket ) || ! current_user_can( 'manage_options' ) ) { return false; }
	$name = 'sunrise_background_' . get_current_user_id();
	wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
	$pending = get_option( $name, array() );
	$state = agent_state();
	if ( ! $pending || $pending['expires_at'] < time() || ! hash_equals( $pending['digest'], hash( 'sha256', $ticket ) )
		|| empty( $pending['session_hash'] ) || ! hash_equals( $pending['session_hash'], hash( 'sha256', wp_get_session_token() ) )
		|| ! in_array( $pending['hook'], array( 'sunrise_check_in', 'sunrise_wake_check_in' ), true ) || ! $state || ! agent_owner_valid( $state ) || ! empty( $state['revoked'] )
		|| ! hash_equals( $pending['connection'], background_connection_fingerprint( $state ) ) || ! delete_option( $name ) ) { return false; }
	return $pending;
}

function agent_background_run() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( ! is_ssl() && 'local' !== wp_get_environment_type() ) ) { wp_send_json_error( array( 'code' => 'background_denied' ), 403 ); }
	$pending = agent_background_claim( isset( $_POST['ticket'] ) ? wp_unslash( $_POST['ticket'] ) : null );
	if ( ! $pending ) { wp_send_json_error( array( 'code' => 'background_denied' ), 403 ); }
	$user_id = get_current_user_id(); $session = wp_get_session_token();
	$cleanup = function () use ( $user_id, $session ) { if ( $session ) { \WP_Session_Tokens::get_instance( $user_id )->destroy( $session ); } };
	register_shutdown_function( $cleanup );
	ignore_user_abort( true ); if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
	wp_raise_memory_limit( 'cron' );
	if ( $pending['retry_at'] ) { wp_unschedule_event( $pending['retry_at'], 'sunrise_wake_check_in', array( $user_id ) ); }
	$GLOBALS['sunrise_background_context'] = true;
	$cron = function () { return true; }; add_filter( 'wp_doing_cron', $cron );
	try { do_action( $pending['hook'], $user_id ); }
	finally { remove_filter( 'wp_doing_cron', $cron ); unset( $GLOBALS['sunrise_background_context'] ); $cleanup(); }
	wp_send_json_success();
}
add_action( 'wp_ajax_sunrise_background', __NAMESPACE__ . '\\agent_background_run' );
add_action( 'deleted_user', function ( $user_id ) { delete_option( 'sunrise_background_' . (int) $user_id ); } );
