<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

function hourly_update_target( $type, $item ) {
	if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) || ! is_object( $item ) ) { return false; }
	$id = 'core' === $type ? 'wordpress' : ( isset( $item->$type ) ? $item->$type : '' );
	$version = 'core' === $type ? ( isset( $item->current ) ? $item->current : '' ) : ( isset( $item->new_version ) ? $item->new_version : '' );
	if ( ! is_string( $id ) || ! $id || strlen( $id ) > 255 || preg_match( '/[\x00-\x1f\x7f]/', $id ) || ! is_string( $version ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $version ) ) { return false; }
	return array( 'type' => $type, 'installed_id' => $id, 'version' => $version, 'code' => 'unknown', 'failed_at' => time() );
}

/** Extra-pass exclusions only. The normal native updater and explicit retries keep their existing behavior. */
function hourly_update_allowed( $update, $item, $type ) {
	if ( ! $update ) { return false; }
	$target = hourly_update_target( $type, $item ); if ( ! $target ) { return false; }
	if ( (int) get_option( manual_update_guard_name( $type, $target['installed_id'], $target['version'] ) ) > time() - 90 * DAY_IN_SECONDS ) { return false; }
	$blocked = array();
	foreach ( array( 'sunrise_automatic_update_failures', 'sunrise_hourly_update_attempts' ) as $name ) {
		foreach ( get_option( $name, array() ) as $failure ) {
			if ( $type === $failure['type'] && $target['installed_id'] === failure_installed_id( $failure['type'], $failure['installed_id'] ) ) { $blocked[] = $failure; }
		}
	}
	foreach ( agent_states() as $connection ) {
		if ( ! agent_owner_valid( $connection ) || ! empty( $connection['revoked'] ) ) { continue; }
		foreach ( isset( $connection['failure_checks'] ) ? $connection['failure_checks'] : array() as $failure ) {
			if ( $type === $failure['type'] && $target['installed_id'] === $failure['installed_id'] ) { $blocked[] = $failure; }
		}
	}
	foreach ( $blocked as $failure ) { if ( version_compare( $target['version'], $failure['version'], '<=' ) ) { return false; } }
	return $update;
}

/** One installation-wide bonus pass per hour, after a successful policy sync, in a background cron request. */
function agent_run_hourly_updates() {
	if ( ! wp_doing_cron() || time() - (int) get_option( 'sunrise_hourly_update_at', 0 ) < AGENT_INTERVAL ) { return false; }
	require_once __DIR__ . '/agent-jobs.php';
	require_once __DIR__ . '/jobs.php';
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_worker' ) ) { return false; }
	$file = null; $auto_lock = null; $filters = array(); $priority = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ); $buffer = ob_get_level();
	$record = function ( $type, $item ) use ( &$auto_lock ) {
		$target = hourly_update_target( $type, $item ); if ( ! $target ) { return; }
		$auto_lock = get_option( 'auto_updater.lock' );
		$attempts = get_option( 'sunrise_hourly_update_attempts', array() ); $attempts[ $type . ':' . $target['installed_id'] ] = $target;
		// Retain intent before native installation, including fatal exits that never reach its completion hook.
		if ( ! update_option( 'sunrise_hourly_update_attempts', $attempts, false ) ) { throw new \RuntimeException( 'Could not retain automatic update intent' ); }
	};
	try {
		$file = agent_execution_lock(); if ( is_wp_error( $file ) ) { return false; }
		$GLOBALS['sunrise_execution_active'] = true;
		if ( time() - (int) get_option( 'sunrise_hourly_update_at', 0 ) < AGENT_INTERVAL || is_wp_error( job_permission( array( 'action' => 'auto_updates' ) ) ) ) { return false; }
		$state = agent_state();
		if ( empty( $state['applied'] ) || ! empty( $state['pending_report'] ) || ! agent_owner_valid( $state ) || ! empty( $state['revoked'] ) || ! agent_service_matches( $state ) ) { return false; }
		foreach ( agent_states() as $connection ) {
			if ( ! empty( $connection['remote_job'] ) || ( agent_owner_valid( $connection ) && empty( $connection['revoked'] ) && ! empty( $connection['paused'] ) ) ) { return false; }
		}
		$updater = new \WP_Automatic_Updater(); if ( $updater->is_disabled() ) { return false; }
		$inventory = agent_inventory(); $installed = failure_installed_versions( $inventory ); $attempts = get_option( 'sunrise_hourly_update_attempts', array() );
		foreach ( $attempts as $key => $attempt ) { if ( ! isset( $installed[ $key ] ) || version_compare( $installed[ $key ], $attempt['version'], '>=' ) ) { unset( $attempts[ $key ] ); } }
		if ( $attempts !== get_option( 'sunrise_hourly_update_attempts', array() ) && ! update_option( 'sunrise_hourly_update_attempts', $attempts, false ) ) { return false; }
		if ( ! update_option( 'sunrise_hourly_update_at', time(), false ) ) { return false; }
		foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
			$filters[ $type ] = function ( $update, $item ) use ( $type ) { return hourly_update_allowed( $update, $item, $type ); };
			add_filter( 'auto_update_' . $type, $filters[ $type ], PHP_INT_MAX, 2 );
		}
		add_action( 'pre_auto_update', $record, 10, 2 );
		ob_start();
		// Refresh providers without incidentally starting the unguarded native updater.
		if ( false !== $priority ) { remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $priority ); }
		execute_task( array( 'action' => 'refresh' ) );
		if ( false !== $priority ) { add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $priority ); }
		$updater->run();
		agent_log( 'hourly_update_pass_finished' );
		return true;
	} catch ( \Throwable $error ) {
		if ( $auto_lock && get_option( 'auto_updater.lock' ) === $auto_lock ) { \WP_Upgrader::release_lock( 'auto_updater' ); }
		agent_log( 'hourly_update_pass_interrupted', array( 'code' => agent_job_exception_code( $error ) ) );
		return true; // The retained attempt is reported immediately when PHP is still able to continue.
	} finally {
		remove_action( 'pre_auto_update', $record, 10 );
		foreach ( $filters as $type => $filter ) { remove_filter( 'auto_update_' . $type, $filter, PHP_INT_MAX ); }
		if ( false !== $priority && false === has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ) ) { add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $priority ); }
		while ( ob_get_level() > $buffer ) { ob_end_clean(); }
		unset( $GLOBALS['sunrise_execution_active'] );
		if ( is_resource( $file ) ) { flock( $file, LOCK_UN ); fclose( $file ); }
		\WP_Upgrader::release_lock( 'sunrise_worker' );
	}
}
