<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

/** Public identifier only: this file survives a database-only restore. Never execute its contents. */
function installation_anchor( $create_id = null ) {
	$path = WP_CONTENT_DIR . '/sunrise-installation.php';
	$prefix = "<?php exit; // Sunrise installation identity\n// ";
	if ( ! file_exists( $path ) && is_string( $create_id ) && wp_is_uuid( $create_id, 4 ) ) {
		$file = @fopen( $path, 'x' );
		if ( $file ) {
			$bytes = $prefix . $create_id . "\n";
			$written = fwrite( $file, $bytes ); fclose( $file ); @chmod( $path, 0640 );
			if ( $written !== strlen( $bytes ) ) { return null; }
		}
	}
	$bytes = @file_get_contents( $path, false, null, 0, 256 );
	if ( ! is_string( $bytes ) || 0 !== strpos( $bytes, $prefix ) ) { return null; }
	$id = trim( substr( $bytes, strlen( $prefix ) ) );
	return wp_is_uuid( $id, 4 ) ? $id : null;
}

function installation_write_anchor( $id ) {
	$path = WP_CONTENT_DIR . '/sunrise-installation.php';
	$temp = tempnam( WP_CONTENT_DIR, '.sunrise-identity-' );
	if ( false === $temp ) { return false; }
	$bytes = "<?php exit; // Sunrise installation identity\n// " . $id . "\n";
	$ok = strlen( $bytes ) === file_put_contents( $temp, $bytes ) && chmod( $temp, 0640 ) && rename( $temp, $path );
	if ( file_exists( $temp ) ) { unlink( $temp ); }
	return $ok;
}

function installation_markers( $id ) {
	return array(
		'url_hash' => hash( 'sha256', untrailingslashit( site_url() ) . "\n" . untrailingslashit( home_url() ) ),
		// Local change detector only. Never send this marker or WordPress salts to Control.
		'salt_check' => hash_hmac( 'sha256', 'sunrise-installation|' . $id, wp_salt( 'auth' ) ),
	);
}

/** Initialize once; never replace a saved identity just because its environment changed. */
function installation_identity() {
	$identity = get_option( 'sunrise_installation', null );
	if ( null === $identity ) {
		$anchor = installation_anchor();
		$id = $anchor ? $anchor : wp_generate_uuid4();
		$created = $anchor ? $anchor : installation_anchor( $id );
		$identity = array_merge( array( 'id' => $id, 'review' => (bool) $anchor || ! $created, 'anchor_required' => true ), installation_markers( $id ) );
		foreach ( agent_states() as $agent ) {
			require_once __DIR__ . '/controller.php';
			// Upgrade an existing enrollment only if its old binding can still be verified.
			if ( ! isset( $agent['url'], $agent['user_id'], $agent['secret'] ) || $agent['url'] !== untrailingslashit( site_url() )
				|| is_wp_error( credential( $agent['secret'], 'agent|' . $agent['url'] . '|' . $agent['user_id'], true ) ) ) { $identity['review'] = true; }
		}
		add_option( 'sunrise_installation', $identity, '', false );
		$identity = get_option( 'sunrise_installation', null );
	}
	if ( is_array( $identity ) && isset( $identity['id'] ) && empty( $identity['anchor_required'] ) && installation_anchor( $identity['id'] ) ) {
		$identity['anchor_required'] = true;
		update_option( 'sunrise_installation', $identity, false );
	}
	return $identity;
}

function installation_guard() {
	$identity = installation_identity();
	$valid = is_array( $identity ) && isset( $identity['id'], $identity['url_hash'], $identity['salt_check'], $identity['review'] )
		&& is_string( $identity['id'] ) && wp_is_uuid( $identity['id'], 4 ) && $identity['id'] === installation_anchor() && false === $identity['review'];
	if ( $valid ) {
		foreach ( installation_markers( $identity['id'] ) as $key => $value ) {
			if ( ! is_string( $identity[ $key ] ) || ! hash_equals( $value, $identity[ $key ] ) ) { $valid = false; break; }
		}
	}
	if ( $valid ) { return true; }
	if ( is_array( $identity ) && empty( $identity['review'] ) ) {
		$previous = $identity;
		$identity['review'] = true;
		// A slow request may still hold pre-recovery state. Never overwrite a newer identity.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name='sunrise_installation' AND option_value=%s", maybe_serialize( $identity ), maybe_serialize( $previous ) ) );
		wp_cache_delete( 'sunrise_installation', 'options' );
	}
	return new \WP_Error( 'sunrise_identity_review', 'This installation changed. Review its identity in Sunrise before reconnecting.', array( 'status' => 409 ) );
}

/** Explicit local recovery. Never revoke remotely using credentials copied from another site. */
function installation_resolve( $kind, $expected_id, $confirmed = false ) {
	// After a database replacement its recorded connection owner is also untrusted/copied.
	if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'sunrise_identity_forbidden', 'Administrator access required.', array( 'status' => 403 ) ); }
	$access = agent_access(); if ( is_wp_error( $access ) && ! is_wp_error( installation_guard() ) ) { return $access; }
	if ( true !== $confirmed || ! in_array( $kind, array( 'clone', 'same' ), true ) || ! is_string( $expected_id ) ) {
		return new \WP_Error( 'sunrise_identity_confirmation', 'Confirm whether this is a clone or the existing site.', array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$locks = array(); $execution = null;
	try {
		foreach ( array( 'sunrise_agent', 'sunrise_worker', 'sunrise_queue', 'sunrise_policy' ) as $lock ) {
			if ( ! \WP_Upgrader::create_lock( $lock ) ) { return new \WP_Error( 'sunrise_identity_busy', 'Sunrise work is running. Wait before changing installation identity.', array( 'status' => 409 ) ); }
			$locks[] = $lock;
		}
		require_once __DIR__ . '/agent-jobs.php';
		$execution = agent_execution_lock(); if ( is_wp_error( $execution ) ) { return $execution; }
		wp_cache_delete( 'sunrise_installation', 'options' );
		$identity = installation_identity();
		$anchor = installation_anchor();
		$current_id = $anchor ? $anchor : ( is_array( $identity ) && isset( $identity['id'] ) ? $identity['id'] : null );
		if ( ! is_string( $current_id ) || ! hash_equals( $current_id, $expected_id ) || ! is_array( $identity ) ) {
			return new \WP_Error( 'sunrise_identity_stale', 'The installation identity changed. Reload Sunrise before continuing.', array( 'status' => 409 ) );
		}
		if ( ! $anchor && 'same' === $kind ) { return new \WP_Error( 'sunrise_identity_missing', 'Restore the installation identity file, or reconnect with a new identity.', array( 'status' => 409 ) ); }
		// Persist the pause before removing anything; an interrupted cleanup remains paused.
		$identity['review'] = true;
		if ( get_option( 'sunrise_installation' ) !== $identity && ! update_option( 'sunrise_installation', $identity, false ) ) { throw new \RuntimeException( 'Could not pause identity' ); }
		wp_unschedule_hook( 'sunrise_check_in' );
		foreach ( get_option( 'sunrise_job_ids', array() ) as $id ) { wp_clear_scheduled_hook( 'sunrise_run_job', array( $id ) ); }
		global $wpdb;
		$names = array( 'sunrise_agents', 'sunrise_agent', 'sunrise_agent_pause', 'sunrise_agent_revoked', 'sunrise_policy', 'sunrise_last_refresh', 'sunrise_remote_job_fence' );
		foreach ( array( 'sunrise_connections_', 'sunrise_snapshot_', 'sunrise_last_job_', 'sunrise_job_' ) as $prefix ) {
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Could not enumerate local state' ); }
			$names = array_merge( $names, $found );
		}
		foreach ( $names as $name ) {
			if ( null !== get_option( $name, null ) && ! delete_option( $name ) ) { throw new \RuntimeException( 'Could not remove local state' ); }
		}
		$id = 'clone' === $kind ? wp_generate_uuid4() : $anchor;
		if ( ! installation_write_anchor( $id ) ) { throw new \RuntimeException( 'Could not save installation anchor' ); }
		$next = array_merge( array( 'id' => $id, 'review' => false, 'anchor_required' => true ), installation_markers( $id ) );
		if ( ! update_option( 'sunrise_installation', $next, false ) ) { throw new \RuntimeException( 'Could not save identity' ); }
		return array( 'installation_id' => $id, 'reconnect_required' => true );
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'sunrise_identity_storage', 'Identity recovery did not finish. Sunrise remains paused; retry after checking database access.', array( 'status' => 500 ) );
	} finally {
		if ( is_resource( $execution ) ) { flock( $execution, LOCK_UN ); fclose( $execution ); }
		foreach ( array_reverse( $locks ) as $lock ) { \WP_Upgrader::release_lock( $lock ); }
	}
}
