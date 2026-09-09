<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-inventory.php';

function transfer_native_value( $name, $value ) {
	if ( ! in_array( $name, transfer_option_names(), true ) || ! is_string( $value ) || strlen( $value ) > 8192 || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) || is_serialized( $value ) ) { throw new \InvalidArgumentException( 'sunrise_transfer_value' ); }
	if ( 'start_of_week' === $name && ! preg_match( '/^[0-6]$/D', $value ) ) { throw new \InvalidArgumentException( 'sunrise_transfer_value' ); }
	if ( 'gmt_offset' === $name && ( ! preg_match( '/^-?(?:0|[1-9][0-9]?)(?:\.[0-9]{1,2})?$/D', $value ) || abs( (float) $value ) > 14 ) ) { throw new \InvalidArgumentException( 'sunrise_transfer_value' ); }
	if ( 'timezone_string' === $name && '' !== $value && ! in_array( $value, timezone_identifiers_list( \DateTimeZone::ALL_WITH_BC ), true ) ) { throw new \InvalidArgumentException( 'sunrise_transfer_value' ); }
	if ( in_array( $name, array( 'date_format', 'time_format' ), true ) && strlen( $value ) > 128 ) { throw new \InvalidArgumentException( 'sunrise_transfer_value' ); }
	$sanitized = sanitize_option( $name, $value );
	if ( ! is_scalar( $sanitized ) || (string) $sanitized !== $value ) { throw new \InvalidArgumentException( 'sunrise_transfer_value' ); }
	return $value;
}

/** Read only the current owner's recovery records; copied installation records stay inert. */
function transfer_option_journals() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	global $wpdb; $owner = get_current_user_id(); $identity = installation_identity();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND LENGTH(option_value)<=1048576 ORDER BY option_id DESC LIMIT 50", $wpdb->esc_like( 'sunrise_transfer_backup_' . $owner . '_' ) . '%' ), ARRAY_A );
	if ( $wpdb->last_error ) { return new \WP_Error( 'sunrise_transfer_journal', 'Recovery records could not be read.' ); }
	$journals = array();
	foreach ( $rows as $row ) {
		$journal = json_decode( $row['option_value'], true, 32 );
		if ( ! is_array( $journal ) || ! isset( $journal['schema'], $journal['id'], $journal['owner'], $journal['installation_id'], $journal['plan_json'], $journal['plan_hash'], $journal['state'], $journal['at'] ) || 1 !== $journal['schema'] || $journal['owner'] !== $owner || $journal['installation_id'] !== $identity['id'] || ! is_string( $journal['id'] ) || ! wp_is_uuid( $journal['id'], 4 ) || $row['option_name'] !== 'sunrise_transfer_backup_' . $owner . '_' . $journal['id'] || ! is_string( $journal['plan_json'] ) || strlen( $journal['plan_json'] ) > 262144 || ! is_string( $journal['plan_hash'] ) || ! hash_equals( hash( 'sha256', $journal['plan_json'] ), $journal['plan_hash'] ) || ! in_array( $journal['state'], array( 'committed', 'restored' ), true ) || ! is_int( $journal['at'] ) ) { continue; }
		$plan = json_decode( $journal['plan_json'], true, 32 );
		if ( ! is_array( $plan ) || ! isset( $plan['changes'] ) || ! is_array( $plan['changes'] ) || count( $plan['changes'] ) > 7 ) { continue; }
		foreach ( $plan['changes'] as $change ) { if ( ! is_array( $change ) || ! isset( $change['name'], $change['before'], $change['after'] ) || ! is_string( $change['name'] ) || ! in_array( $change['name'], transfer_option_names(), true ) || ! is_string( $change['before'] ) || ! is_string( $change['after'] ) ) { continue 2; } }
		$journal['changes'] = $plan['changes']; $journals[] = $journal;
	}
	return $journals;
}

function transfer_recovery_page() {
	$journals = transfer_option_journals();
	echo '<h3>' . esc_html__( 'Restore transferred settings', 'sunrise' ) . '</h3>';
	if ( is_wp_error( $journals ) ) { echo '<p>' . esc_html( $journals->get_error_message() ) . '</p>'; return; }
	if ( ! $journals ) { echo '<p>' . esc_html__( 'No settings transfers have been recorded for your connection on this installation.', 'sunrise' ) . '</p>'; return; }
	echo '<p>' . esc_html__( 'Review the original and transferred values below. Restore applies only while every selected setting still matches its transferred value; newer edits are protected. Recovery records are retained locally, up to 50 per administrator.', 'sunrise' ) . '</p>';
	foreach ( $journals as $journal ) {
		echo '<details><summary>' . esc_html( $journal['id'] . ' — ' . $journal['state'] . ' — ' . wp_date( 'Y-m-d H:i:s', $journal['at'] ) ) . '</summary><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Setting', 'sunrise' ) . '</th><th scope="col">' . esc_html__( 'Original value', 'sunrise' ) . '</th><th scope="col">' . esc_html__( 'Transferred value', 'sunrise' ) . '</th></tr></thead><tbody>';
		foreach ( $journal['changes'] as $change ) { echo '<tr><th scope="row">' . esc_html( $change['name'] ) . '</th><td><pre>' . esc_html( $change['before'] ) . '</pre></td><td><pre>' . esc_html( $change['after'] ) . '</pre></td></tr>'; }
		echo '</tbody></table>';
		if ( 'committed' === $journal['state'] ) {
			form_start( 'local', 'transfer_restore' );
			echo '<input type="hidden" name="transfer_id" value="' . esc_attr( $journal['id'] ) . '"><input type="hidden" name="plan_hash" value="' . esc_attr( $journal['plan_hash'] ) . '">';
			submit_button( __( 'Restore these original values', 'sunrise' ), 'secondary', 'submit', false ); echo '</form>';
		}
		echo '</details>';
	}
}

/** Separate native connection: no nested application transaction and no wpdb automatic reconnect/retry. */
function transfer_mysql() {
	global $wpdb;
	if ( 'wpdb' !== get_class( $wpdb ) || file_exists( WP_CONTENT_DIR . '/db.php' ) || ! extension_loaded( 'mysqli' ) || ! function_exists( 'mysqli_stmt_get_result' ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ) { throw new \RuntimeException( 'sunrise_transfer_database_unsupported' ); }
	$host = $wpdb->parse_db_host( DB_HOST );
	if ( ! $host || 0 === strpos( $host[0], 'p:' ) ) { throw new \RuntimeException( 'sunrise_transfer_database_unsupported' ); }
	$conn = mysqli_init();
	try {
		$conn->options( MYSQLI_OPT_CONNECT_TIMEOUT, 3 ); $conn->options( MYSQLI_OPT_READ_TIMEOUT, 10 );
		$hostname = $host[3] && extension_loaded( 'mysqlnd' ) ? '[' . $host[0] . ']' : $host[0];
		if ( ! @$conn->real_connect( $hostname, DB_USER, DB_PASSWORD, DB_NAME, $host[1] ? (int) $host[1] : null, $host[2], defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 ) || ! $conn->set_charset( $wpdb->charset ) || ! $conn->query( 'SET SESSION innodb_lock_wait_timeout=5' ) ) { throw new \RuntimeException( 'sunrise_transfer_database' ); }
		return $conn;
	} catch ( \Throwable $error ) { try { $conn->close(); } catch ( \Throwable $ignored ) {} throw new \RuntimeException( 'sunrise_transfer_database' ); }
}

/** Internal primitive. Apply requires an executor-owned fence; restore is reachable only through an owner's nonce-protected action. */
function transfer_options_commit( $id, $plan_json, $plan_hash, $restore = false ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) { return new \WP_Error( 'sunrise_transfer_invalid', 'Invalid transfer identifier.' ); }
	require_once __DIR__ . '/agent-jobs.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_worker' ) ) { return new \WP_Error( 'sunrise_transfer_busy', 'A Sunrise worker is running.' ); }
	$file = agent_execution_lock(); if ( is_wp_error( $file ) ) { \WP_Upgrader::release_lock( 'sunrise_worker' ); return $file; }
	$conn = null; $committing = false; $names = array();
	$automatic = false;
	try {
		if ( ! ( $automatic = \WP_Upgrader::create_lock( 'auto_updater' ) ) ) { throw new \RuntimeException( 'sunrise_transfer_busy' ); }
		$state = agent_state(); $identity = installation_identity(); $owner = get_current_user_id();
		$fence = get_option( 'sunrise_remote_job_fence' );
		if ( $fence && ( empty( $fence['kind'] ) || 'transfer' !== $fence['kind'] || $fence['job_id'] !== $id || $fence['site_id'] !== $state['site_id'] ) ) { throw new \RuntimeException( 'sunrise_transfer_busy' ); }
		if ( ! $restore && ( ! $fence || empty( $fence['plan_hash'] ) || $fence['plan_hash'] !== $plan_hash || empty( $fence['start_until'] ) || ! is_int( $fence['start_until'] ) || $fence['start_until'] <= time() || $fence['start_until'] > time() + 120 ) ) { throw new \RuntimeException( 'sunrise_transfer_authorization' ); }
		foreach ( agent_states() as $connection ) { if ( ! $restore && ! empty( $connection['paused'] ) && agent_owner_valid( $connection ) ) { throw new \RuntimeException( 'sunrise_transfer_paused' ); } }
		$conn = transfer_mysql();
		$run = function ( $sql, $values = array() ) use ( $conn ) {
			$statement = @$conn->prepare( $sql );
			if ( ! $statement || ( $values && ! $statement->bind_param( str_repeat( 's', count( $values ) ), ...$values ) ) || ! @$statement->execute() ) { throw new \RuntimeException( 'sunrise_transfer_database' ); }
			return $statement;
		};
		global $wpdb; $table = '`' . $wpdb->options . '`'; $key = 'sunrise_transfer_backup_' . $owner . '_' . $id;
		$engine = $run( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $wpdb->options ) )->get_result()->fetch_assoc();
		if ( ! $engine || 'innodb' !== strtolower( $engine['ENGINE'] ) ) { throw new \RuntimeException( 'sunrise_transfer_database_unsupported' ); }
		if ( ! $conn->begin_transaction() ) { throw new \RuntimeException( 'sunrise_transfer_database' ); }
		$stored = $run( "SELECT option_value FROM {$table} WHERE option_name=? FOR UPDATE", array( $key ) )->get_result()->fetch_assoc();
		$journal = $stored ? json_decode( $stored['option_value'], true, 32 ) : null;
		if ( $stored && ( ! is_array( $journal ) || ! isset( $journal['installation_id'], $journal['owner'], $journal['id'], $journal['plan_json'], $journal['plan_hash'], $journal['state'] ) || ! in_array( $journal['state'], array( 'committed', 'restored' ), true ) || $journal['installation_id'] !== $identity['id'] || $journal['owner'] !== $owner || $journal['id'] !== $id ) ) { throw new \RuntimeException( 'sunrise_transfer_journal' ); }
		if ( $restore ) {
			if ( ! $journal ) { throw new \RuntimeException( 'sunrise_transfer_journal' ); }
			if ( ! is_string( $plan_hash ) || $plan_hash !== $journal['plan_hash'] ) { throw new \RuntimeException( 'sunrise_transfer_conflict' ); }
			$plan_json = $journal['plan_json']; $plan_hash = $journal['plan_hash'];
		}
		if ( ! is_string( $plan_json ) || strlen( $plan_json ) > 262144 || ! is_string( $plan_hash ) || ! preg_match( '/^[0-9a-f]{64}$/D', $plan_hash ) || ! hash_equals( $plan_hash, hash( 'sha256', $plan_json ) ) ) { throw new \RuntimeException( 'sunrise_transfer_invalid' ); }
		$plan = json_decode( $plan_json, true, 32 );
		if ( ! is_array( $plan ) || count( $plan ) !== 9 || ! isset( $plan['schema'], $plan['scope'], $plan['source_site_id'], $plan['destination_site_id'], $plan['source_site_url'], $plan['source_home_url'], $plan['destination_site_url'], $plan['destination_home_url'], $plan['changes'] ) || 1 !== $plan['schema'] || 'options' !== $plan['scope'] || ! is_string( $plan['source_site_id'] ) || ! wp_is_uuid( $plan['source_site_id'], 4 ) || ! is_string( $plan['destination_site_id'] ) || ! wp_is_uuid( $plan['destination_site_id'], 4 ) || $plan['source_site_id'] === $plan['destination_site_id'] || ( ! $restore && $plan['destination_site_id'] !== $state['site_id'] ) || $plan['destination_site_url'] !== site_url( '/' ) || $plan['destination_home_url'] !== home_url( '/' ) || ! is_array( $plan['changes'] ) || ! $plan['changes'] || count( $plan['changes'] ) > 7 ) { throw new \RuntimeException( 'sunrise_transfer_invalid' ); }
		foreach ( $plan['changes'] as $change ) {
			if ( ! is_array( $change ) || count( $change ) !== 6 || ! isset( $change['name'], $change['before'], $change['after'], $change['source_fingerprint'], $change['expected_destination_fingerprint'], $change['action'] ) || ! is_string( $change['name'] ) || in_array( $change['name'], $names, true ) || ! is_string( $change['source_fingerprint'] ) || ! preg_match( '/^[0-9a-f]{64}$/D', $change['source_fingerprint'] ) ) { throw new \RuntimeException( 'sunrise_transfer_invalid' ); }
			transfer_native_value( $change['name'], $change['before'] ); transfer_native_value( $change['name'], $change['after'] );
			if ( $change['expected_destination_fingerprint'] !== hash( 'sha256', $change['before'] ) || $change['action'] !== ( $change['before'] === $change['after'] ? 'unchanged' : 'replace' ) ) { throw new \RuntimeException( 'sunrise_transfer_invalid' ); } $names[] = $change['name'];
		}
		if ( $journal ) {
			if ( $journal['plan_hash'] !== $plan_hash ) { throw new \RuntimeException( 'sunrise_transfer_conflict' ); }
			if ( ! $restore || 'restored' === $journal['state'] ) {
				$conn->rollback();
				// A prior process may have committed and died before invalidating a persistent object cache.
				foreach ( $names as $name ) { wp_cache_delete( $name, 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
				return array( 'state' => $journal['state'], 'replayed' => true );
			}
			if ( 'committed' !== $journal['state'] ) { throw new \RuntimeException( 'sunrise_transfer_journal' ); }
		} elseif ( (int) $run( "SELECT COUNT(*) AS total FROM {$table} WHERE option_name LIKE ?", array( 'sunrise\\_transfer\\_backup\\_' . $owner . '\\_%' ) )->get_result()->fetch_assoc()['total'] >= 50 ) { throw new \RuntimeException( 'sunrise_transfer_backup_limit' ); }
		// Lock all selected rows before the first write. Binary comparison preserves exact bytes regardless of collation.
		$changes = $plan['changes']; usort( $changes, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		foreach ( $changes as $change ) {
			$current = $run( "SELECT option_value FROM {$table} WHERE option_name=? FOR UPDATE", array( $change['name'] ) )->get_result()->fetch_assoc();
			if ( ! $current || $current['option_value'] !== ( $restore ? $change['after'] : $change['before'] ) ) { throw new \RuntimeException( 'sunrise_transfer_destination_changed' ); }
		}
		if ( is_wp_error( installation_guard() ) || ( ! $restore && $fence['start_until'] <= time() ) ) { throw new \RuntimeException( 'sunrise_transfer_authorization' ); }
		foreach ( $changes as $change ) {
			if ( $change['before'] === $change['after'] ) { continue; }
			$updated = $run( "UPDATE {$table} SET option_value=? WHERE option_name=? AND BINARY option_value=BINARY ?", array( $restore ? $change['before'] : $change['after'], $change['name'], $restore ? $change['after'] : $change['before'] ) );
			if ( 1 !== $updated->affected_rows ) { throw new \RuntimeException( 'sunrise_transfer_destination_changed' ); }
		}
		$journal = array( 'schema' => 1, 'id' => $id, 'owner' => $owner, 'installation_id' => $identity['id'], 'plan_json' => $plan_json, 'plan_hash' => $plan_hash, 'state' => $restore ? 'restored' : 'committed', 'at' => time() );
		$encoded = wp_json_encode( $journal ); if ( false === $encoded ) { throw new \RuntimeException( 'sunrise_transfer_journal' ); }
		if ( $restore ) { $run( "UPDATE {$table} SET option_value=? WHERE option_name=?", array( $encoded, $key ) ); }
		else { $run( "INSERT INTO {$table}(option_name,option_value,autoload) VALUES(?,?,'no')", array( $key, $encoded ) ); }
		$committing = true;
		if ( ! $conn->commit() ) { throw new \RuntimeException( 'sunrise_transfer_commit_unknown' ); }
		foreach ( $names as $name ) { wp_cache_delete( $name, 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		return array( 'state' => $journal['state'], 'replayed' => false );
	} catch ( \Throwable $error ) {
		if ( $conn ) { try { $conn->rollback(); } catch ( \Throwable $ignored ) {} }
		$code = preg_match( '/^sunrise_transfer_[a-z_]+$/D', $error->getMessage() ) ? $error->getMessage() : 'sunrise_transfer_database';
		$code = $committing ? 'sunrise_transfer_commit_unknown' : $code;
		$messages = array(
			'sunrise_transfer_destination_changed' => 'A selected setting changed after review. No settings were changed by this request. Review the current values before restoring.',
			'sunrise_transfer_conflict' => 'The recovery record does not match the reviewed transfer. Reload the recovery page.',
			'sunrise_transfer_database_unsupported' => 'Settings transfers require the standard WordPress MySQL driver and an InnoDB options table.',
			'sunrise_transfer_backup_limit' => 'This administrator has reached the 50-record recovery limit. Existing recovery records were retained.',
			'sunrise_transfer_busy' => 'Another update or transfer needs to finish before this request can run.',
		);
		return new \WP_Error( $code, isset( $messages[ $code ] ) ? $messages[ $code ] : 'Transfer settings were not confirmed. Inspect the retained journal before retrying.', array( 'status' => 409 ) );
	} finally {
		if ( $conn ) { try { $conn->close(); } catch ( \Throwable $ignored ) {} }
		if ( $automatic ) { \WP_Upgrader::release_lock( 'auto_updater' ); }
		flock( $file, LOCK_UN ); fclose( $file );
		\WP_Upgrader::release_lock( 'sunrise_worker' );
	}
}
