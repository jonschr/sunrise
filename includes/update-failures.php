<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

function automatic_failure_reason( $error ) {
	if ( ! is_wp_error( $error ) ) { return 'unknown'; }
	$code = $error->get_error_code();
	// Classify locally; never transmit messages, package URLs, API keys, or error data.
	$message = strtolower( implode( ' ', $error->get_error_messages() ) );
	if ( preg_match( '/licen[sc]e|api[ _-]?key|subscription|unauthori[sz]ed|forbidden|401|403/', $message ) ) { return 'license_required'; }
	if ( preg_match( '/rollback|rolled.back|fatal.error/', $code . ' ' . $message ) ) { return 'rollback'; }
	if ( preg_match( '/incompatible|requires.php|requires.wordpress/', $code ) ) { return 'incompatible'; }
	if ( preg_match( '/no_package|package_not_available|not_found/', $code ) ) { return 'package_unavailable'; }
	if ( preg_match( '/fs_unavailable|filesystem/', $code ) ) { return 'filesystem_unavailable'; }
	if ( preg_match( '/mkdir|copy_failed|remove_old|writ|disk_full/', $code ) ) { return 'files_not_writable'; }
	if ( preg_match( '/download|http_request/', $code ) ) { return 'download_failed'; }
	return 'unknown';
}
function capture_automatic_update_failures( $results ) {
	$failures = get_option( 'sunrise_automatic_update_failures', array() );
	foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
		foreach ( isset( $results[ $type ] ) && is_array( $results[ $type ] ) ? $results[ $type ] : array() as $result ) {
			if ( ! is_object( $result ) || ! isset( $result->item ) || ! is_object( $result->item ) || ! property_exists( $result, 'result' ) ) { continue; }
			$id = 'core' === $type ? 'wordpress' : ( isset( $result->item->$type ) ? $result->item->$type : '' );
			$version = 'core' === $type && isset( $result->item->current ) ? $result->item->current : ( isset( $result->item->new_version ) ? $result->item->new_version : '' );
			if ( ! is_string( $id ) || ! $id || strlen( $id ) > 255 || preg_match( '/[\x00-\x1f\x7f]/', $id ) || ! is_string( $version ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $version ) ) { continue; }
			$key = $type . ':' . $id;
			if ( $result->result && ! is_wp_error( $result->result ) ) {
				if ( isset( $failures[ $key ] ) && version_compare( $version, $failures[ $key ]['version'], '>=' ) ) { unset( $failures[ $key ] ); }
			} else {
				$failures[ $key ] = array( 'type' => $type, 'installed_id' => $id, 'version' => $version, 'code' => automatic_failure_reason( $result->result ), 'failed_at' => time() );
			}
		}
	}
	usort( $failures, function ( $a, $b ) { return $b['failed_at'] - $a['failed_at']; } );
	$stored = array();
	// ponytail: keep the 100 most recent distinct automatic failures per installation; raise the cap if needed.
	foreach ( array_slice( $failures, 0, 100 ) as $failure ) { $stored[ $failure['type'] . ':' . $failure['installed_id'] ] = $failure; }
	update_option( 'sunrise_automatic_update_failures', $stored, false );
}
add_action( 'automatic_updates_complete', __NAMESPACE__ . '\\capture_automatic_update_failures' );

function failure_installed_versions( $inventory ) {
	$versions = array();
	foreach ( $inventory as $item ) { $type = 'core' === $item['type'] ? 'core' : substr( $item['type'], 0, -1 ); $versions[ $type . ':' . $item['installed_id'] ] = $item['version']; }
	return $versions;
}
function automatic_update_failures( $inventory ) {
	$failures = get_option( 'sunrise_automatic_update_failures', array() ); $versions = failure_installed_versions( $inventory ); $remaining = $failures;
	foreach ( $remaining as $key => $failure ) {
		if ( isset( $versions[ $key ] ) && version_compare( $versions[ $key ], $failure['version'], '>=' ) ) { unset( $remaining[ $key ] ); }
	}
	// Filter the report without overwriting a failure captured by a concurrent automatic updater.
	return array_values( $remaining );
}
function failure_resolutions( $checks, $inventory ) {
	$versions = failure_installed_versions( $inventory ); $resolved = array();
	foreach ( $checks as $check ) {
		$key = $check['type'] . ':' . $check['installed_id'];
		if ( isset( $versions[ $key ] ) && version_compare( $versions[ $key ], $check['version'], '>=' ) ) { $resolved[] = $check['id']; }
	}
	return $resolved;
}
function validate_failure_checks( $checks ) {
	if ( ! is_array( $checks ) || count( $checks ) > 5000 ) { return false; }
	foreach ( $checks as $check ) {
		if ( ! is_array( $check ) || count( $check ) !== 4 || ! isset( $check['id'], $check['type'], $check['installed_id'], $check['version'] ) || ! is_string( $check['id'] ) || ! wp_is_uuid( $check['id'], 4 ) || ! in_array( $check['type'], array( 'core', 'plugin', 'theme' ), true ) || ! is_string( $check['installed_id'] ) || ! $check['installed_id'] || preg_match( '/[\x00-\x1f\x7f]/', $check['installed_id'] ) || ( 'core' === $check['type'] && 'wordpress' !== $check['installed_id'] ) || strlen( $check['installed_id'] ) > 255 || ! is_string( $check['version'] ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $check['version'] ) ) { return false; }
	}
	return true;
}
