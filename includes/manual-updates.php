<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

/** Only native, authenticated dashboard updater calls establish a manual source. */
function manual_update_context() {
	if ( ! empty( $GLOBALS['sunrise_manual_observing'] ) || ! is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! current_user_can( 'manage_options' ) ) { return null; }
	$page = $GLOBALS['pagenow'] ?? ''; $action = $_REQUEST['action'] ?? '';
	$actions = array( 'admin-ajax.php' => array( 'update-plugin', 'update-theme' ), 'update.php' => array( 'upgrade-plugin', 'upgrade-theme', 'update-selected', 'update-selected-themes' ), 'update-core.php' => array( 'do-core-upgrade', 'do-core-reinstall', 'do-plugin-upgrade', 'do-theme-upgrade' ) );
	if ( ! is_string( $action ) || ! in_array( $action, $actions[ $page ] ?? array(), true ) ) { return null; }
	$context = null;
	// Inspect locally only; arguments, objects and paths never enter the journal or report.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 24 ) as $frame ) {
		if ( in_array( $frame['function'] ?? '', array( 'wp_update_plugins', 'wp_update_themes', 'wp_version_check', 'translations_api' ), true ) ) { return null; }
		if ( ( isset( $frame['object'] ) && $frame['object'] instanceof \WP_Automatic_Updater ) || ( isset( $frame['function'] ) && __NAMESPACE__ . '\\install_update' === $frame['function'] ) ) { return null; }
		if ( ! isset( $frame['object'] ) || ! $frame['object'] instanceof \WP_Upgrader || ! in_array( $frame['function'], array( 'upgrade', 'bulk_upgrade', 'run' ), true ) ) { continue; }
		$upgrader = $frame['object'];
		if ( $upgrader instanceof \Language_Pack_Upgrader ) { return null; }
		if ( $upgrader->skin instanceof \Automatic_Upgrader_Skin && ! $upgrader->skin instanceof \WP_Ajax_Upgrader_Skin ) { return null; }
		if ( $upgrader instanceof \Core_Upgrader && 'upgrade' === $frame['function'] ) {
			$offer = $frame['args'][0] ?? null;
			if ( is_object( $offer ) && isset( $offer->current, $offer->response ) && in_array( $offer->response, array( 'upgrade', 'reinstall' ), true ) ) { $context = array( $upgrader, 'core', 'wordpress', $offer->current ); }
		} elseif ( ! $context && in_array( $frame['function'], array( 'run', 'bulk_upgrade' ), true ) ) {
			$extra = $frame['args'][0]['hook_extra'] ?? array();
			$type = $extra['type'] ?? ( $upgrader instanceof \Plugin_Upgrader ? 'plugin' : ( $upgrader instanceof \Theme_Upgrader ? 'theme' : '' ) );
			$id = $extra[ $type ] ?? '';
			if ( 'bulk_upgrade' === $frame['function'] && ! empty( $upgrader->update_current ) ) { $id = $frame['args'][0][ $upgrader->update_current - 1 ] ?? ''; }
			$bulk = 'bulk_upgrade' === $frame['function'] || ! empty( $frame['args'][0]['is_multi'] );
			if ( 'update' === ( $extra['action'] ?? ( $bulk ? 'update' : '' ) ) && in_array( $type, array( 'plugin', 'theme' ), true ) && is_string( $id ) ) {
				$offers = get_site_transient( 'update_' . $type . 's' );
				$offer = is_object( $offers ) ? ( $offers->response[ $id ] ?? null ) : null;
				$version = is_object( $offer ) ? ( $offer->new_version ?? '' ) : ( is_array( $offer ) ? ( $offer['new_version'] ?? '' ) : '' );
				$context = array( $upgrader, $type, $id, $version );
			}
		}
	}
	return $context;
}

function manual_installed_version( $type, $id ) {
	if ( 'core' === $type ) { $wp_version = ''; include ABSPATH . WPINC . '/version.php'; return $wp_version; }
	if ( 'plugin' === $type ) { return is_file( WP_PLUGIN_DIR . '/' . $id ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $id, false, false )['Version'] : ''; }
	wp_clean_themes_cache( false ); return wp_get_theme( $id )->get( 'Version' );
}

function manual_update_begin( $context, $new_run = false ) {
	if ( ! $context ) { return null; }
	list( $upgrader, $type, $id, $version ) = $context;
	if ( ! current_user_can( 'update_' . ( 'core' === $type ? 'core' : $type . 's' ) ) || ! is_string( $id ) || ! $id || strlen( $id ) > 255 || preg_match( '/[\x00-\x1f\x7f]/', $id ) || ! is_string( $version ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $version ) ) { return null; }
	$GLOBALS['sunrise_manual_observing'] = true;
	try { $lock = native_update_execution_lock( null ); } finally { $GLOBALS['sunrise_manual_observing'] = false; }
	if ( is_wp_error( $lock ) ) { return null; }
	$key = spl_object_id( $upgrader );
	$active = $GLOBALS['sunrise_manual_updates'][ $key ] ?? null;
	if ( $active && $active['event']['installed_id'] === $id && $active['event']['version'] === $version && ! ( $new_run && ! empty( $active['entered_run'] ) ) ) { if ( $new_run ) { $GLOBALS['sunrise_manual_updates'][ $key ]['entered_run'] = true; } return $key; }
	if ( $active ) { manual_update_finish( $key ); }
	$GLOBALS['sunrise_manual_observing'] = true;
	try {
		$event = array( 'id' => wp_generate_uuid4(), 'type' => $type, 'installed_id' => $id, 'version' => $version, 'status' => 'pending', 'code' => 'execution_interrupted', 'updated_at' => time() );
		// Distinct option rows keep concurrent dashboard requests from overwriting one another.
		if ( ! add_option( 'sunrise_manual_update_' . $event['id'], $event, '', false ) ) { return null; }
		$GLOBALS['sunrise_manual_updates'][ $key ] = array( 'event' => $event, 'upgrader' => $upgrader, 'from' => manual_installed_version( $type, $id ), 'complete' => false, 'error' => null, 'entered_run' => $new_run );
		add_option( manual_update_guard_name( $type, $id, $version ), time(), '', false );
		return $key;
	} finally { $GLOBALS['sunrise_manual_observing'] = false; }
}

function manual_update_finish( $key ) {
	$active = $GLOBALS['sunrise_manual_updates'][ $key ] ?? null; if ( ! $active ) { return; }
	$GLOBALS['sunrise_manual_observing'] = true;
	try {
		unset( $GLOBALS['sunrise_manual_updates'][ $key ] );
		$event = $active['event'];
		$error = $active['error'];
		$event['status'] = 'closed_unverified'; $event['code'] = 'execution_interrupted';
		if ( $error ) { $event['status'] = 'failed'; $event['code'] = is_string( $error ) ? $error : automatic_failure_reason( $error ); }
		elseif ( $active['complete'] && manual_installed_version( $event['type'], $event['installed_id'] ) === $event['version'] ) {
			$event['status'] = $active['from'] === $event['version'] ? 'unchanged' : 'succeeded';
			$event['code'] = 'unchanged' === $event['status'] ? 'already_current' : 'updated';
		}
		if ( in_array( $event['status'], array( 'succeeded', 'unchanged' ), true ) ) { delete_option( manual_update_guard_name( $event['type'], $event['installed_id'], $event['version'] ) ); }
		$event['updated_at'] = time();
		update_option( 'sunrise_manual_update_' . $event['id'], $event, false );
	} finally { $GLOBALS['sunrise_manual_observing'] = false; }
}

add_filter( 'upgrader_package_options', function ( $options ) {
	manual_update_begin( manual_update_context(), true ); return $options;
}, PHP_INT_MAX );
add_filter( 'upgrader_pre_download', function ( $reply, $package, $upgrader ) {
	manual_update_begin( manual_update_context() ); return $reply;
}, 1, 3 );
add_action( 'wp_error_added', function ( $code, $message, $data, $error ) {
	$key = manual_update_begin( manual_update_context() );
	if ( null !== $key && ! $error->get_error_data( 'softfail-filename' ) && 'signature_verification_no_signature' !== $code ) { $GLOBALS['sunrise_manual_updates'][ $key ]['error'] = automatic_failure_reason( $error ); }
}, 10, 4 );
add_filter( 'upgrader_install_package_result', function ( $result ) {
	$context = manual_update_context(); $key = manual_update_begin( $context );
	if ( null !== $key ) { $GLOBALS['sunrise_manual_updates'][ $key ]['complete'] = true; $GLOBALS['sunrise_manual_updates'][ $key ]['error'] = is_wp_error( $result ) ? automatic_failure_reason( $result ) : null; }
	return $result;
}, PHP_INT_MAX );
add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
	$key = spl_object_id( $upgrader );
	if ( isset( $GLOBALS['sunrise_manual_updates'][ $key ] ) && 'update' === ( $extra['action'] ?? '' ) && $extra['type'] === $GLOBALS['sunrise_manual_updates'][ $key ]['event']['type'] ) {
		$GLOBALS['sunrise_manual_updates'][ $key ]['complete'] = true;
		// Finalize after native shutdown rollback handlers, not while files may still be restored.
	}
}, PHP_INT_MAX, 2 );
add_action( 'shutdown', function () {
	foreach ( array_keys( $GLOBALS['sunrise_manual_updates'] ?? array() ) as $key ) { manual_update_finish( $key ); }
}, PHP_INT_MAX );

function manual_update_guard_name( $type, $id, $version ) { return 'sunrise_manual_guard_' . hash( 'sha256', $type . ':' . $id . ':' . $version ); }

/** Read an acknowledged cursor independently for every enrolled account connection. */
function manual_update_activity( $cursor = 0 ) {
	global $wpdb;
	$prefix = $wpdb->esc_like( 'sunrise_manual_update_' ) . '%';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id,option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id>%d ORDER BY option_id LIMIT 100", $prefix, $cursor ) );
	$events = array();
	foreach ( $rows as $row ) {
		$event = get_option( $row->option_name );
		if ( ! is_array( $event ) ) { $cursor = (int) $row->option_id; continue; }
		if ( 'pending' === $event['status'] ) {
			if ( $event['updated_at'] > time() - HOUR_IN_SECONDS ) { break; }
			$event['status'] = 'closed_unverified'; update_option( $row->option_name, $event, false );
		}
		if ( $event['updated_at'] > time() - 90 * DAY_IN_SECONDS ) { $events[] = $event; }
		$cursor = (int) $row->option_id;
	}
	// Bounded cleanup; aggregate history lives in Control, outside this local journal.
	$old = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT 100", $prefix ) );
	foreach ( $old as $name ) { $event = get_option( $name ); if ( is_array( $event ) && $event['updated_at'] < time() - 90 * DAY_IN_SECONDS ) { delete_option( $name ); } }
	$guards = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT 100", $wpdb->esc_like( 'sunrise_manual_guard_' ) . '%' ) );
	foreach ( $guards as $name ) { if ( (int) get_option( $name ) < time() - 90 * DAY_IN_SECONDS ) { delete_option( $name ); } }
	return array( $events, $cursor );
}
