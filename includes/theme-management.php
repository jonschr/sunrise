<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/plugin-management.php';

/** Native deletion only supports ordinary themes in WordPress's standard theme directory. */
function theme_management_target( $id ) {
	if ( is_multisite() || ! is_string( $id ) || validate_file( $id ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $id ) ) { return job_error( 'theme_management_unsupported', 'Only ordinary installed themes on a single site can be deleted.' ); }
	$root = realpath( WP_CONTENT_DIR . '/themes' ); $path = $root . '/' . $id; $real = realpath( $path );
	if ( ! $root || realpath( get_theme_root( $id ) ) !== $root || is_link( $path ) || ( $real && $real !== $path ) ) { return job_error( 'theme_management_unsupported', 'Symlinked or external theme directories cannot be deleted remotely.' ); }
	// Theme switches may have happened since the job was approved or the worker bootstrapped.
	foreach ( array( 'stylesheet', 'template' ) as $name ) { if ( $id === get_option( $name ) || $id === ( 'stylesheet' === $name ? get_stylesheet() : get_template() ) ) { return job_error( 'theme_in_use', 'The active theme and its parent cannot be deleted.' ); } }
	return true;
}

function theme_management_permission( $task ) {
	foreach ( array( 'alloptions', 'stylesheet', 'template' ) as $name ) { wp_cache_delete( $name, 'options' ); }
	$guard = installation_guard(); if ( is_wp_error( $guard ) ) { return $guard; }
	if ( ! wp_is_file_mod_allowed( 'sunrise' ) ) { return job_error( 'file_modifications_disabled', 'File modifications are disabled.', 403 ); }
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_themes' ) ) { return job_error( 'theme_management_forbidden', 'The connection owner cannot delete themes.', 403 ); }
	$target = theme_management_target( $task['installed_id'] ); if ( is_wp_error( $target ) ) { return $target; }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( 'direct' !== get_filesystem_method( array(), WP_CONTENT_DIR . '/themes' ) ) { return job_error( 'filesystem_credentials_required', 'Automatic deletion requires direct filesystem access.' ); }
	return true;
}

function manage_theme( $task, &$fence ) {
	$allowed = theme_management_permission( $task ); if ( is_wp_error( $allowed ) ) { return $allowed; }
	$id = $task['installed_id']; wp_clean_themes_cache( false ); $theme = wp_get_theme( $id ); $path = WP_CONTENT_DIR . '/themes/' . $id;
	if ( ! $theme->exists() ) { return file_exists( $path ) ? job_error( 'component_changed', 'The theme is no longer a valid installed theme.' ) : array( 'code' => 'already_deleted' ); }
	if ( $theme->get( 'Version' ) !== $task['from_version'] ) { return job_error( 'installed_version_changed', 'The theme version changed after approval.' ); }
	if ( $task['identity'] != agent_item_identity( 'themes', $id ) ) { return job_error( 'component_identity_changed', 'The theme identity changed after approval.' ); }
	plugin_management_stage( $fence, 'deleting' );
	// Native delete_theme does not protect active themes; recheck after earlier deletion hooks too.
	$blocked = null;
	$guard = function ( $stylesheet ) use ( $id, $task, &$blocked ) {
		if ( $stylesheet !== $id ) { return; }
		$blocked = theme_management_permission( $task );
		wp_clean_themes_cache( false );
		if ( ! is_wp_error( $blocked ) && ( wp_get_theme( $id )->get( 'Version' ) !== $task['from_version'] || $task['identity'] != agent_item_identity( 'themes', $id ) ) ) { $blocked = job_error( 'component_changed', 'The theme changed during deletion hooks.' ); }
		if ( is_wp_error( $blocked ) ) { throw new \RuntimeException( 'Sunrise theme deletion blocked' ); }
	};
	add_action( 'delete_theme', $guard, PHP_INT_MAX );
	try { $result = delete_theme( $id ); }
	catch ( \Throwable $error ) { if ( is_wp_error( $blocked ) ) { return $blocked; } throw $error; }
	finally { remove_action( 'delete_theme', $guard, PHP_INT_MAX ); }
	wp_clean_themes_cache( false );
	if ( true !== $result || wp_get_theme( $id )->exists() || file_exists( $path ) ) { return job_error( 'theme_delete_failed', 'WordPress could not verify theme deletion. Inspect filesystem access.' ); }
	return array( 'code' => 'deleted' );
}
