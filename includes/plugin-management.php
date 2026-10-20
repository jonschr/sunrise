<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/jobs.php';

function plugin_management_action( $action ) {
	return in_array( $action, array( 'deactivate_plugin', 'delete_plugin', 'deactivate_delete_plugin' ), true );
}

/** Only ordinary, isolated plugin files can be removed by WordPress's directory-based uninstaller. */
function plugin_management_target( $id, $deleting = false, $directory_counts = null ) {
	if ( is_multisite() ) { return job_error( 'plugin_management_unsupported', 'Multisite plugin management is not supported.' ); }
	if ( ! is_string( $id ) || validate_file( $id ) || false !== strpos( $id, '\\' ) || ! preg_match( '#^[^/]+(?:/[^/]+)?\.php$#D', $id ) ) { return job_error( 'plugin_management_unsupported', 'Only ordinary installed plugins can be managed.' ); }
	$path = WP_PLUGIN_DIR . '/' . $id; $root = realpath( WP_PLUGIN_DIR ); $real = realpath( $path );
	if ( $real === realpath( dirname( __DIR__ ) . '/sunrise.php' ) || 'sunrise/sunrise.php' === $id || dirname( $path ) === dirname( __DIR__ ) ) { return job_error( 'protected_plugin', 'Sunrise cannot deactivate or delete itself.' ); }
	if ( ! $root || ( $real && ( $real !== $path || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) ) || is_link( $path ) || is_link( dirname( $path ) ) ) { return job_error( 'plugin_management_unsupported', 'Symlinked or external plugin paths cannot be managed remotely.' ); }
	if ( $deleting && '.' !== dirname( $id ) ) {
		if ( null === $directory_counts ) { $directory_counts = array_count_values( array_map( 'dirname', array_keys( get_plugins() ) ) ); }
		if ( ( $directory_counts[ dirname( $id ) ] ?? 0 ) > 1 ) { return job_error( 'plugin_management_unsupported', 'This plugin shares a directory with another plugin.' ); }
	}
	return true;
}

function plugin_management_permission( $task ) {
	$guard = installation_guard(); if ( is_wp_error( $guard ) ) { return $guard; }
	$deleting = 'deactivate_plugin' !== $task['action'];
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) || ( $deleting && ! current_user_can( 'delete_plugins' ) ) ) { return job_error( 'plugin_management_forbidden', 'The connection owner cannot manage this plugin.', 403 ); }
	$target = plugin_management_target( $task['installed_id'], $deleting ); if ( is_wp_error( $target ) ) { return $target; }
	if ( $deleting && ! wp_is_file_mod_allowed( 'sunrise' ) ) { return job_error( 'file_modifications_disabled', 'File modifications are disabled.', 403 ); }
	if ( $deleting ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// An asynchronous request cannot present WordPress's FTP/SSH credentials form.
		if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) ) { return job_error( 'filesystem_credentials_required', 'Automatic deletion requires direct filesystem access.' ); }
	}
	return true;
}

function plugin_management_stage( &$fence, $stage ) {
	$fence['management_stage'] = $stage;
	if ( ! update_option( 'sunrise_remote_job_fence', $fence, false ) ) { throw new \RuntimeException( 'Could not persist plugin management stage' ); }
}

/** One fenced request, with deactivation verified before any uninstall routine runs. */
function manage_plugin( $task, &$fence ) {
	$allowed = plugin_management_permission( $task ); if ( is_wp_error( $allowed ) ) { return $allowed; }
	$id = $task['installed_id']; wp_clean_plugins_cache( false ); $plugins = get_plugins();
	if ( ! isset( $plugins[ $id ] ) ) { return 'deactivate_plugin' === $task['action'] || file_exists( WP_PLUGIN_DIR . '/' . $id ) ? job_error( 'component_changed', 'The plugin is no longer installed.' ) : array( 'code' => 'already_deleted' ); }
	if ( $plugins[ $id ]['Version'] !== $task['from_version'] ) { return job_error( 'installed_version_changed', 'The plugin version changed after approval.' ); }
	if ( $task['identity'] != agent_item_identity( 'plugins', $id ) ) { return job_error( 'component_identity_changed', 'The plugin identity changed after approval.' ); }
	// Read fresh headers without initializing WordPress's cached dependency/API lookup.
	$slug = 'hello.php' === $id ? 'hello-dolly' : ( '.' === dirname( $id ) ? basename( $id, '.php' ) : dirname( $id ) );
	foreach ( $plugins as $dependent => $header ) {
		if ( ! is_plugin_active( $dependent ) ) { continue; }
		foreach ( explode( ',', $header['RequiresPlugins'] ?? '' ) as $required ) {
			if ( $slug === apply_filters( 'wp_plugin_dependencies_slug', trim( $required ) ) ) { return job_error( 'plugin_required', 'An active plugin requires this plugin. Deactivate its dependents first.' ); }
		}
	}
	if ( 'delete_plugin' === $task['action'] && is_plugin_active( $id ) ) { return job_error( 'plugin_active', 'Deactivate this plugin before deleting it.' ); }
	$was_active = is_plugin_active( $id );
	if ( 'delete_plugin' !== $task['action'] && $was_active ) {
		plugin_management_stage( $fence, 'deactivating' );
		deactivate_plugins( array( $id ), false, false );
		if ( is_plugin_active( $id ) ) { return job_error( 'deactivation_failed', 'WordPress still reports this plugin active; nothing was deleted.' ); }
	}
	if ( 'deactivate_plugin' === $task['action'] ) { return array( 'code' => $was_active ? 'deactivated' : 'already_inactive' ); }
	plugin_management_stage( $fence, 'deleting' );
	// Recheck after deactivation hooks, which can change state or permissions.
	$allowed = plugin_management_permission( $task ); if ( is_wp_error( $allowed ) ) { return $was_active ? job_error( 'deactivated_delete_failed', 'Deactivated, but deletion was blocked. Inspect the plugin on the site.' ) : $allowed; }
	wp_clean_plugins_cache( false ); $current = get_plugins();
	if ( is_plugin_active( $id ) || ! isset( $current[ $id ] ) || $current[ $id ]['Version'] !== $task['from_version'] || $task['identity'] != agent_item_identity( 'plugins', $id ) ) { return job_error( 'deactivated_delete_failed', 'Plugin state changed during deactivation; deletion was not attempted.' ); }
	$result = delete_plugins( array( $id ) );
	wp_clean_plugins_cache( false ); $remaining = get_plugins();
	if ( true !== $result || isset( $remaining[ $id ] ) || file_exists( WP_PLUGIN_DIR . '/' . $id ) ) { return job_error( $was_active ? 'deactivated_delete_failed' : 'plugin_delete_failed', 'WordPress could not verify deletion. Inspect filesystem access and uninstall hooks.' ); }
	return array( 'code' => 'deleted' );
}
