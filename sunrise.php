<?php
/**
 * Plugin Name: Sunrise
 * Description: Authenticated update inventory, automatic-update policies, and remote update jobs.
 * Version: 0.2.11
 * Plugin URI: https://github.com/jonschr/sunrise
 * Update URI: https://github.com/jonschr/sunrise
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Elodin Design
 * License: GPL-2.0-or-later
 * Text Domain: sunrise
 */

namespace Sunrise;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.11';

// Discard old cached WordPress.org collisions before the GitHub checker adds its verified source.
add_filter( 'site_transient_update_plugins', function ( $updates ) {
	if ( is_object( $updates ) && isset( $updates->response[ plugin_basename( __FILE__ ) ] ) ) {
		$updates = clone $updates;
		unset( $updates->response[ plugin_basename( __FILE__ ) ] );
	}
	return $updates;
}, 9 );

require_once __DIR__ . '/includes/update-checker.php';

// Network-wide policies and shared files need a separate multisite permission model.
if ( is_multisite() ) {
	add_action( 'network_admin_notices', function () {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Sunrise supports single-site WordPress only; its API and update controls are disabled on Multisite.', 'sunrise' ) . '</p></div>';
	} );
	return;
}

require_once __DIR__ . '/includes/identity.php';
require_once __DIR__ . '/includes/policy.php';
require_once __DIR__ . '/includes/agent.php';
require_once __DIR__ . '/includes/agent-wake.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/update-failures.php';

add_action( 'sunrise_transfer_continue', function ( $owner ) {
	$previous = get_current_user_id(); wp_set_current_user( (int) $owner );
	try { require_once __DIR__ . '/includes/migrations.php'; migration_sync(); }
	finally { wp_set_current_user( $previous ); }
} );

add_action( 'deleted_user', function ( $user_id ) {
	delete_option( 'sunrise_error_ack_' . $user_id );
	$connections = get_option( 'sunrise_connections_' . $user_id, array() );
	foreach ( array_merge( array( 'local' ), array_keys( $connections ) ) as $site ) {
		delete_option( 'sunrise_last_job_' . $user_id . '_' . $site );
	}
	delete_option( 'sunrise_connections_' . $user_id );
} );

add_action( 'rest_api_init', function () {
	require_once __DIR__ . '/includes/api.php';
	register_routes();
} );

add_action( 'sunrise_run_job', function ( $id ) {
	require_once __DIR__ . '/includes/jobs.php';
	run_job( $id );
} );

if ( is_admin() && file_exists( __DIR__ . '/includes/admin.php' ) ) {
	require_once __DIR__ . '/includes/admin.php';
}

register_deactivation_hook( __FILE__, function () {
	delete_option( 'sunrise_agent_interval' );
	wp_unschedule_hook( 'sunrise_check_in' );
	wp_unschedule_hook( 'sunrise_transfer_continue' );
	foreach ( get_option( 'sunrise_job_ids', array() ) as $id ) {
		wp_clear_scheduled_hook( 'sunrise_run_job', array( $id ) );
		$job = get_option( 'sunrise_job_' . $id );
		if ( $job && 'queued' === $job['status'] ) {
			$job['status'] = 'cancelled';
			update_option( 'sunrise_job_' . $id, $job, false );
		}
	}
} );
