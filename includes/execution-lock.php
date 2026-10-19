<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

/** Process-held lock, without time expiry. File contains no credentials and is not transferable state. */
function agent_execution_lock() {
	$path = WP_CONTENT_DIR . '/.sunrise-execution.php';
	$handle = @fopen( $path, 'c+' );
	if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
		if ( $handle ) { fclose( $handle ); }
		return new \WP_Error( 'sunrise_execution_busy', 'The execution lock is unavailable. Retry after the running operation finishes.' );
	}
	if ( 0 === fstat( $handle )['size'] ) { fwrite( $handle, '<?php exit;' ); fflush( $handle ); @chmod( $path, 0600 ); }
	return $handle;
}
/** Native installs hold the same lock through shutdown, including bulk work and rollback. */
function native_update_execution_lock( $reply ) {
	if ( is_wp_error( $reply ) || ! empty( $GLOBALS['sunrise_execution_active'] ) || is_resource( $GLOBALS['sunrise_native_execution_lock'] ?? null ) ) { return $reply; }
	// Direct internal installers are guarded by their worker entry point; exclude them from the native request lock.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 ) as $frame ) {
		if ( __NAMESPACE__ . '\\install_update' === ( $frame['function'] ?? '' ) ) { return $reply; }
	}
	if ( get_option( 'sunrise_remote_job_fence' ) || (int) get_option( 'sunrise_worker.lock' ) > time() - HOUR_IN_SECONDS ) { return new \WP_Error( 'sunrise_execution_busy', 'A Sunrise update is running or awaiting recovery. Wait for it to finish before updating in WordPress.' ); }
	$lock = agent_execution_lock(); if ( is_wp_error( $lock ) ) { return $lock; }
	$GLOBALS['sunrise_native_execution_lock'] = $lock;
	return $reply;
}
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\\native_update_execution_lock', PHP_INT_MAX );
add_filter( 'upgrader_pre_install', __NAMESPACE__ . '\\native_update_execution_lock', PHP_INT_MAX );
function native_update_execution_unlock() {
	$lock = $GLOBALS['sunrise_native_execution_lock'] ?? null;
	if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
	unset( $GLOBALS['sunrise_native_execution_lock'] );
}
add_action( 'automatic_updates_complete', __NAMESPACE__ . '\\native_update_execution_unlock', PHP_INT_MAX );
add_action( 'shutdown', __NAMESPACE__ . '\\native_update_execution_unlock', PHP_INT_MAX );
