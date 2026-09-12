<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/migration-pairs.php';
require_once __DIR__ . '/transfer-database.php';

function database_job_key( $id ) { return 'sunrise_database_job_' . $id; }
function database_job( $id ) { if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) { return array(); } $job = get_option( database_job_key( $id ), array() ); return is_array( $job ) ? $job : array(); }
function database_job_save( $id, $job ) {
	if ( get_option( database_job_key( $id ), null ) !== $job && ! update_option( database_job_key( $id ), $job, false ) ) { throw new \RuntimeException( 'sunrise_database_job_storage' ); }
}

function database_snapshot_remove( $job ) {
	foreach ( $job['manifest']['tables'] ?? array() as $table ) { $path = ( $job['root'] ?? '' ) . '/database-' . $table['name'] . '.jsonl'; if ( is_file( $path ) && ! is_link( $path ) ) { unlink( $path ); } }
}

/** Process-held serialization for transfer, staging, commit and cancellation requests. */
function database_process_lock() {
	$handle = @fopen( WP_CONTENT_DIR . '/.sunrise-database.php', 'c+' );
	if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { if ( $handle ) { fclose( $handle ); } return new \WP_Error( 'sunrise_database_busy', 'Database migration work is already running.', array( 'status' => 409 ) ); }
	if ( 0 === fstat( $handle )['size'] ) { fwrite( $handle, '<?php exit;' ); fflush( $handle ); @chmod( WP_CONTENT_DIR . '/.sunrise-database.php', 0600 ); }
	return $handle;
}

function database_process_unlock( $handle ) { flock( $handle, LOCK_UN ); fclose( $handle ); }

/** Private destination workspace is installation-owned so recovery survives a users-table replacement. */
function database_workspace( $id ) {
	if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) { throw new \InvalidArgumentException( 'sunrise_database_id' ); }
	$base = realpath( get_temp_dir() ); if ( ! $base || ! is_writable( $base ) ) { throw new \RuntimeException( 'sunrise_database_storage' ); }
	foreach ( array( ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $public ) { $public = $public ? realpath( $public ) : false; if ( $public && ( $base === $public || 0 === strpos( $base . '/', rtrim( $public, '/' ) . '/' ) ) ) { throw new \RuntimeException( 'sunrise_database_storage' ); } }
	$identity = installation_identity(); $root = $base . '/sunrise-database-' . hash( 'sha256', ABSPATH . '|' . $identity['id'] ); $path = $root . '/' . $id;
	foreach ( array( $root, $path ) as $directory ) { if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! mkdir( $directory, 0700 ) ) || ! chmod( $directory, 0700 ) ) { throw new \RuntimeException( 'sunrise_database_storage' ); } }
	return $path;
}

function database_local_details() {
	global $wpdb, $wp_db_version;
	return array( 'site_id' => migration_site_id(), 'site_url' => site_url( '/' ), 'home_url' => home_url( '/' ), 'root_path' => untrailingslashit( wp_normalize_path( ABSPATH ) ), 'prefix' => $wpdb->prefix, 'db_version' => (int) $wp_db_version );
}

function database_source_export( $id, $peer_site_id, $authorize = false ) {
	if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || ! is_string( $peer_site_id ) || ! migration_pair_find( $peer_site_id ) ) { return new \WP_Error( 'sunrise_database_request', 'Invalid paired database request.', array( 'status' => 400 ) ); }
	$existing = database_job( $id ); if ( 'source' === ( $existing['role'] ?? null ) && ( $existing['peer_site_id'] ?? null ) === $peer_site_id ) { return 'running' === ( $existing['status'] ?? null ) ? array( 'manifest' => $existing['manifest'] ) : new \WP_Error( 'sunrise_database_request', 'That database export is no longer active.', array( 'status' => 409 ) ); }
	$active = get_option( 'sunrise_database_active' ); if ( $active && $active !== $id ) { return new \WP_Error( 'sunrise_database_busy', 'Another database migration is active.', array( 'status' => 409 ) ); }
	if ( ! $active && ! add_option( 'sunrise_database_active', $id, '', false ) ) { return new \WP_Error( 'sunrise_database_busy', 'Another database migration started.', array( 'status' => 409 ) ); }
	$pair = migration_pair_find( $peer_site_id ); $owner = get_current_user_id() ?: (int) ( $pair['approved_by'] ?? 0 );
	if ( ! $owner || ! user_can( $owner, 'manage_options' ) ) { $owners = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); $owner = $owners ? (int) $owners[0] : 0; }
	$manifest = database_export( $id, $authorize, $owner );
	if ( is_wp_error( $manifest ) ) { delete_option( 'sunrise_database_active' ); return $manifest; }
	try {
		$job = array( 'schema' => 1, 'id' => $id, 'role' => 'source', 'peer_site_id' => $peer_site_id, 'status' => 'running', 'created_at' => time(), 'manifest' => $manifest, 'root' => transfer_file_workspace( $id ) );
		database_job_save( $id, $job ); return array( 'manifest' => $manifest );
	} catch ( \Throwable $error ) { delete_option( 'sunrise_database_active' ); return new \WP_Error( 'sunrise_database_storage', 'Could not retain the database export.', array( 'status' => 503 ) ); }
}

function database_source_chunk( $id, $peer_site_id, $table_name, $offset ) {
	$job = database_job( $id );
	if ( ( $job['role'] ?? null ) !== 'source' || ( $job['peer_site_id'] ?? null ) !== $peer_site_id || 'running' !== ( $job['status'] ?? null ) || ! is_int( $offset ) || $offset < 0 || 0 !== $offset % 196608 ) { return new \WP_Error( 'sunrise_database_chunk', 'Invalid database chunk request.', array( 'status' => 400 ) ); }
	$table = null; foreach ( $job['manifest']['tables'] as $candidate ) { if ( $candidate['name'] === $table_name ) { $table = $candidate; break; } }
	if ( ! $table || $offset > $table['bytes'] ) { return new \WP_Error( 'sunrise_database_chunk', 'Unknown database table or offset.', array( 'status' => 400 ) ); }
	$path = $job['root'] . '/database-' . $table_name . '.jsonl';
	if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) !== $table['bytes'] ) { return new \WP_Error( 'sunrise_database_chunk', 'The database snapshot is unavailable.', array( 'status' => 409 ) ); }
	$handle = fopen( $path, 'rb' ); if ( ! $handle ) { return new \WP_Error( 'sunrise_database_chunk', 'The database snapshot is unreadable.', array( 'status' => 409 ) ); }
	try { if ( 0 !== fseek( $handle, $offset ) || false === ( $bytes = fread( $handle, 196608 ) ) ) { throw new \RuntimeException(); } }
	catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_chunk', 'The database snapshot could not be read.', array( 'status' => 409 ) ); }
	finally { fclose( $handle ); }
	return array( 'data' => base64_encode( $bytes ), 'offset' => $offset, 'next' => $offset + strlen( $bytes ), 'bytes' => $table['bytes'] );
}

function database_source_finish( $id, $peer_site_id ) {
	$job = database_job( $id );
	if ( ( $job['role'] ?? null ) !== 'source' || ( $job['peer_site_id'] ?? null ) !== $peer_site_id ) { return new \WP_Error( 'sunrise_database_request', 'Invalid database completion request.', array( 'status' => 400 ) ); }
	if ( 'succeeded' === ( $job['status'] ?? null ) ) { return database_job_public( $job ); }
	if ( 'running' !== ( $job['status'] ?? null ) ) { return new \WP_Error( 'sunrise_database_request', 'That source snapshot is no longer active.', array( 'status' => 409 ) ); }
	try { $job['status'] = 'succeeded'; $job['completed_at'] = time(); database_job_save( $id, $job ); database_snapshot_remove( $job ); if ( get_option( 'sunrise_database_active' ) === $id ) { delete_option( 'sunrise_database_active' ); } update_option( 'sunrise_database_last', $id, false ); return database_job_public( $job ); }
	catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_storage', 'Could not finish the source snapshot.', array( 'status' => 503 ) ); }
}

function database_destination_prepare( $id, $peer_site_id, $manifest ) {
	if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || ! is_string( $peer_site_id ) || ! migration_pair_find( $peer_site_id ) ) { return new \WP_Error( 'sunrise_database_request', 'Invalid paired database request.', array( 'status' => 400 ) ); }
	$existing = database_job( $id ); if ( $existing ) { return ( $existing['peer_site_id'] ?? null ) === $peer_site_id && 'running' === ( $existing['status'] ?? null ) ? database_job_public( $existing ) : new \WP_Error( 'sunrise_database_request', 'That database destination is no longer available.', array( 'status' => 409 ) ); }
	$active = get_option( 'sunrise_database_active' ); if ( $active && $active !== $id ) { return new \WP_Error( 'sunrise_database_busy', 'Another database migration is active.', array( 'status' => 409 ) ); }
	$conn = null; $root = null;
	try {
		global $wp_db_version; $conn = transfer_mysql(); $manifest = database_manifest( $conn, $manifest );
		if ( $manifest['site_id'] !== $peer_site_id || $manifest['site_id'] === migration_site_id() || $manifest['db_version'] !== (int) $wp_db_version ) { throw new \RuntimeException( 'sunrise_database_core_mismatch' ); }
		$root = database_workspace( $id ); foreach ( $manifest['tables'] as $table ) { $path = $root . '/database-' . $table['name'] . '.jsonl'; $file = fopen( $path, 'x' ); if ( ! $file ) { throw new \RuntimeException( 'sunrise_database_storage' ); } fclose( $file ); chmod( $path, 0600 ); }
		if ( ! $active && ! add_option( 'sunrise_database_active', $id, '', false ) ) { throw new \RuntimeException( 'sunrise_database_busy' ); }
		$job = array( 'schema' => 1, 'id' => $id, 'role' => 'destination', 'peer_site_id' => $peer_site_id, 'status' => 'running', 'created_at' => time(), 'manifest' => $manifest, 'destination' => database_local_details(), 'root' => $root, 'staged' => array(), 'backups' => array() );
		database_job_save( $id, $job ); return database_job_public( $job );
	} catch ( \Throwable $error ) { if ( $root && is_array( $manifest ) ) { foreach ( $manifest['tables'] ?? array() as $table ) { $path = $root . '/database-' . ( $table['name'] ?? '' ) . '.jsonl'; if ( is_file( $path ) && ! is_link( $path ) ) { unlink( $path ); } } } if ( get_option( 'sunrise_database_active' ) === $id && ! database_job( $id ) ) { delete_option( 'sunrise_database_active' ); } return new \WP_Error( 'sunrise_database_prepare', 'Could not prepare the destination database: ' . ( preg_match( '/^sunrise_database_[a-z_]+$/D', $error->getMessage() ) ? $error->getMessage() : 'database_error' ), array( 'status' => 409 ) ); }
	finally { if ( $conn ) { $conn->close(); } }
}

/** Offset-bound append is idempotent; retries must contain the exact same bytes. */
function database_destination_receive( $id, $peer_site_id, $table_name, $offset, $encoded ) {
	$job = database_job( $id ); $table = null;
	if ( ( $job['role'] ?? null ) !== 'destination' || ( $job['peer_site_id'] ?? null ) !== $peer_site_id || 'running' !== ( $job['status'] ?? null ) || ! is_int( $offset ) || $offset < 0 || 0 !== $offset % 196608 || ! is_string( $encoded ) || strlen( $encoded ) > 262144 ) { return new \WP_Error( 'sunrise_database_chunk', 'Invalid database chunk.', array( 'status' => 400 ) ); }
	foreach ( $job['manifest']['tables'] as $candidate ) { if ( $candidate['name'] === $table_name ) { $table = $candidate; break; } }
	$bytes = base64_decode( $encoded, true ); if ( ! $table || false === $bytes || base64_encode( $bytes ) !== $encoded || strlen( $bytes ) > 196608 || $offset + strlen( $bytes ) > $table['bytes'] ) { return new \WP_Error( 'sunrise_database_chunk', 'Invalid database chunk bytes.', array( 'status' => 400 ) ); }
	$path = $job['root'] . '/database-' . $table_name . '.jsonl'; $handle = is_link( $path ) ? false : fopen( $path, 'c+b' );
	if ( ! $handle || ! flock( $handle, LOCK_EX ) ) { if ( $handle ) { fclose( $handle ); } return new \WP_Error( 'sunrise_database_storage', 'Could not lock the incoming database table.', array( 'status' => 503 ) ); }
	try {
		$size = fstat( $handle )['size'];
		if ( $offset < $size ) { if ( 0 !== fseek( $handle, $offset ) || fread( $handle, strlen( $bytes ) ) !== $bytes ) { throw new \RuntimeException( 'sunrise_database_chunk_conflict' ); } }
		elseif ( $offset === $size ) { if ( 0 !== fseek( $handle, 0, SEEK_END ) || strlen( $bytes ) !== fwrite( $handle, $bytes ) || ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) { throw new \RuntimeException( 'sunrise_database_storage' ); } $size += strlen( $bytes ); }
		else { throw new \RuntimeException( 'sunrise_database_chunk_order' ); }
		if ( $size === $table['bytes'] && hash_file( 'sha256', $path ) !== $table['sha256'] ) { throw new \RuntimeException( 'sunrise_database_hash' ); }
		return array( 'next' => $offset + strlen( $bytes ), 'bytes' => $table['bytes'] );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_chunk', $error->getMessage(), array( 'status' => 409 ) ); }
	finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
}

function database_destination_stage( $id, $peer_site_id, $index ) {
	$job = database_job( $id );
	if ( ( $job['role'] ?? null ) !== 'destination' || ( $job['peer_site_id'] ?? null ) !== $peer_site_id || 'running' !== ( $job['status'] ?? null ) || ! is_int( $index ) || ! isset( $job['manifest']['tables'][ $index ] ) ) { return new \WP_Error( 'sunrise_database_stage', 'Invalid database staging request.', array( 'status' => 400 ) ); }
	if ( isset( $job['staged'][ $index ] ) ) { return array( 'staged' => $index ); }
	$conn = null;
	try {
		$table = $job['manifest']['tables'][ $index ]; $name = 'sunrise_import_' . substr( str_replace( '-', '', $id ), 0, 20 ) . '_' . $index; $conn = transfer_mysql();
		$exists = database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $name ) )->get_result()->fetch_row();
		if ( $exists ) { $rows = $conn->query( 'SELECT COUNT(*) FROM ' . database_identifier( $name ) )->fetch_row(); if ( ! $rows || (int) $rows[0] !== $table['rows'] ) { throw new \RuntimeException( 'sunrise_database_stage_conflict' ); } }
		else { database_stage_table( $conn, $name, $table, $job['root'] . '/database-' . $table['name'] . '.jsonl', $job['manifest'], $job['destination'] ); }
		$job['staged'][ $index ] = $name; database_job_save( $id, $job ); return array( 'staged' => $index );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_stage', 'Could not stage the database table: ' . $error->getMessage(), array( 'status' => 409 ) ); }
	finally { if ( $conn ) { $conn->close(); } }
}

function database_preserve_options( $conn, $job ) {
	global $wpdb; $index = array_search( 'options', array_column( $job['manifest']['tables'], 'name' ), true ); if ( false === $index ) { return; }
	$stage = $job['staged'][ $index ]; $fields = array( 'option_name', 'option_value', 'autoload' );
	$result = $conn->query( 'SELECT ' . implode( ',', array_map( __NAMESPACE__ . '\\database_identifier', $fields ) ) . ' FROM ' . database_identifier( $wpdb->options ) . " WHERE option_name='cron' OR option_name='external_updates-sunrise' OR option_name LIKE 'sunrise\\_%'" );
	if ( ! $result ) { throw new \RuntimeException( 'sunrise_database_preserve' ); }
	$sql = 'REPLACE INTO ' . database_identifier( $stage ) . ' (' . implode( ',', array_map( __NAMESPACE__ . '\\database_identifier', $fields ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $fields ), '?' ) ) . ')';
	while ( $row = $result->fetch_assoc() ) { database_query( $conn, $sql, array_values( $row ) ); }
}

function database_owner_snapshots( $conn ) {
	global $wpdb; $owners = array();
	foreach ( agent_states() as $state ) { $row = database_query( $conn, 'SELECT ID,user_login,user_pass FROM ' . database_identifier( $wpdb->users ) . ' WHERE ID=?', array( (string) $state['user_id'] ) )->get_result()->fetch_assoc(); if ( $row ) { $owners[ $state['user_id'] ] = hash( 'sha256', $row['ID'] . "\n" . $row['user_login'] . "\n" . $row['user_pass'] ); } }
	return $owners;
}

function database_disconnect_replaced_owners( $before ) {
	global $wpdb; $states = agent_states(); $changed = false; $hint = array();
	foreach ( $states as $user_id => $state ) { $row = $wpdb->get_row( $wpdb->prepare( "SELECT ID,user_login,user_pass FROM {$wpdb->users} WHERE ID=%d", $user_id ), ARRAY_A ); $after = $row ? hash( 'sha256', $row['ID'] . "\n" . $row['user_login'] . "\n" . $row['user_pass'] ) : null; if ( ! isset( $before[ $user_id ] ) || ! $after || ! hash_equals( $before[ $user_id ], $after ) ) { if ( ! $hint ) { $hint = array_intersect_key( $state, array_flip( array( 'account_id', 'network_id', 'site_id' ) ) ); } unset( $states[ $user_id ] ); $changed = true; } }
	if ( $changed ) { update_option( 'sunrise_agents', $states, false ); update_option( 'sunrise_control_reauth_required', array_merge( $hint, array( 'site_id' => migration_site_id(), 'control_url' => agent_url(), 'created_at' => time() ) ), false ); }
}

/** One atomic rename publishes every staged table; destination-only tables remain untouched. */
function database_destination_commit( $id, $peer_site_id ) {
	$job = database_job( $id );
	if ( 'succeeded' === ( $job['status'] ?? null ) ) { return database_job_public( $job ); }
	if ( ( $job['role'] ?? null ) !== 'destination' || ( $job['peer_site_id'] ?? null ) !== $peer_site_id || ! in_array( $job['status'] ?? null, array( 'running', 'uncertain' ), true ) || count( $job['staged'] ?? array() ) !== count( $job['manifest']['tables'] ) ) { return new \WP_Error( 'sunrise_database_commit', 'The complete database has not been staged.', array( 'status' => 409 ) ); }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; require_once __DIR__ . '/agent-jobs.php';
	$worker = \WP_Upgrader::create_lock( 'sunrise_worker' ); $automatic = false; $execution = null; $conn = null;
	if ( ! $worker ) { return new \WP_Error( 'sunrise_database_busy', 'A Sunrise worker is running.', array( 'status' => 409 ) ); }
	try {
		if ( ! ( $automatic = \WP_Upgrader::create_lock( 'auto_updater' ) ) || is_wp_error( $execution = agent_execution_lock() ) ) { throw new \RuntimeException( 'sunrise_database_busy' ); }
		global $wpdb; $conn = transfer_mysql(); $journal_path = $job['root'] . '/journal.json';
		if ( is_file( $journal_path ) && ! is_link( $journal_path ) && filesize( $journal_path ) < 2 * MB_IN_BYTES ) {
			$journal = json_decode( file_get_contents( $journal_path ), true );
			if ( is_array( $journal ) && in_array( $journal['state'] ?? null, array( 'committing', 'committed' ), true ) ) {
				$published = ! empty( $journal['published'] ); foreach ( $journal['published'] ?? array() as $table ) { $published = $published && (bool) database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $table ) )->get_result()->fetch_row(); }
				$backed_up = true; foreach ( $journal['backups'] ?? array() as $table ) { $backed_up = $backed_up && (bool) database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $table ) )->get_result()->fetch_row(); }
				$stages_gone = true; foreach ( $job['staged'] as $table ) { $stages_gone = $stages_gone && ! database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $table ) )->get_result()->fetch_row(); }
				if ( $published && $backed_up && $stages_gone ) { $journal['state'] = 'committed'; $journal['committed_at'] = time(); transfer_file_write_json( $journal_path, $journal ); wp_cache_flush(); $job['status'] = 'succeeded'; $job['backups'] = $journal['backups']; $job['published'] = $journal['published']; $job['completed_at'] = time(); database_job_save( $id, $job ); database_snapshot_remove( $job ); database_disconnect_replaced_owners( $journal['owners'] ?? array() ); delete_option( 'sunrise_database_active' ); update_option( 'sunrise_database_last', $id, false ); return database_job_public( $job ); }
			}
		}
		$owners = database_owner_snapshots( $conn ); database_preserve_options( $conn, $job ); $renames = array(); $backups = array(); $published = array();
		foreach ( $job['manifest']['tables'] as $index => $table ) {
			$live = $wpdb->prefix . $table['name']; $backup = 'sunrise_backup_' . substr( str_replace( '-', '', $id ), 0, 16 ) . '_' . $index;
			$exists = database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $live ) )->get_result()->fetch_row();
			if ( $exists ) { $renames[] = database_identifier( $live ) . ' TO ' . database_identifier( $backup ); $backups[ $live ] = $backup; }
			$renames[] = database_identifier( $job['staged'][ $index ] ) . ' TO ' . database_identifier( $live );
			$published[] = $live;
		}
		$journal = array( 'schema' => 1, 'id' => $id, 'state' => 'prepared', 'created_at' => time(), 'backups' => $backups, 'published' => $published, 'owners' => $owners ); require_once __DIR__ . '/transfer-files.php'; transfer_file_write_json( $job['root'] . '/journal.json', $journal );
		$journal['state'] = 'committing'; transfer_file_write_json( $job['root'] . '/journal.json', $journal );
		if ( ! $conn->query( 'RENAME TABLE ' . implode( ',', $renames ) ) ) { throw new \RuntimeException( 'sunrise_database_rename' ); }
		$journal['state'] = 'committed'; $journal['committed_at'] = time(); transfer_file_write_json( $job['root'] . '/journal.json', $journal );
		wp_cache_flush(); $job['status'] = 'succeeded'; $job['backups'] = $backups; $job['published'] = $published; $job['completed_at'] = time(); database_job_save( $id, $job ); database_snapshot_remove( $job ); database_disconnect_replaced_owners( $owners ); delete_option( 'sunrise_database_active' ); update_option( 'sunrise_database_last', $id, false ); flush_rewrite_rules( false );
		return database_job_public( $job );
	} catch ( \Throwable $error ) { $journal = isset( $journal_path ) && is_file( $journal_path ) && ! is_link( $journal_path ) ? json_decode( file_get_contents( $journal_path ), true ) : null; $job['status'] = is_array( $journal ) && in_array( $journal['state'] ?? null, array( 'committing', 'committed' ), true ) ? 'uncertain' : 'failed'; $job['error'] = preg_match( '/^sunrise_database_[a-z_]+$/D', $error->getMessage() ) ? $error->getMessage() : 'sunrise_database_commit'; try { database_job_save( $id, $job ); } catch ( \Throwable $ignored ) {} return new \WP_Error( 'sunrise_database_commit', 'Database replacement stopped: ' . $job['error'], array( 'status' => 409 ) ); }
	finally { if ( $conn ) { $conn->close(); } if ( is_resource( $execution ) ) { flock( $execution, LOCK_UN ); fclose( $execution ); } if ( $automatic ) { \WP_Upgrader::release_lock( 'auto_updater' ); } \WP_Upgrader::release_lock( 'sunrise_worker' ); }
}

function database_destination_restore( $id ) {
	$access = migration_access(); if ( is_wp_error( $access ) ) { return $access; } $job = database_job( $id );
	if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || 'succeeded' !== ( $job['status'] ?? null ) || empty( $job['published'] ) || empty( $job['backups'] ) ) { return new \WP_Error( 'sunrise_database_restore', 'No restorable database backup exists for this migration.', array( 'status' => 409 ) ); }
	if ( get_option( 'sunrise_database_active' ) === $id ) { return new \WP_Error( 'sunrise_database_busy', 'Finish migration cleanup before restoring the destination.', array( 'status' => 409 ) ); }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; require_once __DIR__ . '/agent-jobs.php'; require_once __DIR__ . '/transfer-files.php';
	$worker = \WP_Upgrader::create_lock( 'sunrise_worker' ); $automatic = false; $execution = null; $conn = null; if ( ! $worker ) { return new \WP_Error( 'sunrise_database_busy', 'A Sunrise worker is running.', array( 'status' => 409 ) ); }
	try {
		if ( ! ( $automatic = \WP_Upgrader::create_lock( 'auto_updater' ) ) || is_wp_error( $execution = agent_execution_lock() ) ) { throw new \RuntimeException( 'sunrise_database_busy' ); }
		$conn = transfer_mysql(); $renames = array();
		foreach ( $job['published'] as $index => $live ) { $failed = 'sunrise_failed_' . substr( str_replace( '-', '', $id ), 0, 16 ) . '_' . $index; $renames[] = database_identifier( $live ) . ' TO ' . database_identifier( $failed ); if ( isset( $job['backups'][ $live ] ) ) { $renames[] = database_identifier( $job['backups'][ $live ] ) . ' TO ' . database_identifier( $live ); } }
		$journal_path = $job['root'] . '/journal.json'; if ( is_link( $journal_path ) || ! is_file( $journal_path ) || filesize( $journal_path ) > 2 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_database_journal' ); }
		$journal = json_decode( file_get_contents( $journal_path ), true ); if ( ! is_array( $journal ) || ! in_array( $journal['state'] ?? null, array( 'committed', 'restoring', 'restored' ), true ) ) { throw new \RuntimeException( 'sunrise_database_journal' ); }
		if ( in_array( $journal['state'], array( 'restoring', 'restored' ), true ) ) {
			$restored = true; $original = true;
			foreach ( $job['published'] as $index => $live ) {
				$failed = 'sunrise_failed_' . substr( str_replace( '-', '', $id ), 0, 16 ) . '_' . $index; $live_exists = (bool) database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $live ) )->get_result()->fetch_row(); $failed_exists = (bool) database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $failed ) )->get_result()->fetch_row(); $backup_exists = isset( $job['backups'][ $live ] ) && (bool) database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $job['backups'][ $live ] ) )->get_result()->fetch_row();
				$restored = $restored && $failed_exists && ( isset( $job['backups'][ $live ] ) ? $live_exists && ! $backup_exists : ! $live_exists ); $original = $original && $live_exists && ! $failed_exists && ( ! isset( $job['backups'][ $live ] ) || $backup_exists );
			}
			if ( $restored ) { $journal['state'] = 'restored'; $journal['restored_at'] = time(); transfer_file_write_json( $journal_path, $journal ); wp_cache_flush(); $job['status'] = 'restored'; $job['restored_at'] = time(); database_job_save( $id, $job ); return database_job_public( $job ); }
			if ( ! $original ) { throw new \RuntimeException( 'sunrise_database_restore_uncertain' ); }
		}
		$journal['state'] = 'restoring'; transfer_file_write_json( $job['root'] . '/journal.json', $journal ); if ( ! $conn->query( 'RENAME TABLE ' . implode( ',', $renames ) ) ) { throw new \RuntimeException( 'sunrise_database_restore' ); }
		$journal['state'] = 'restored'; $journal['restored_at'] = time(); transfer_file_write_json( $job['root'] . '/journal.json', $journal ); wp_cache_flush(); $job['status'] = 'restored'; $job['restored_at'] = time(); database_job_save( $id, $job ); flush_rewrite_rules( false ); return database_job_public( $job );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_restore', 'Could not restore the destination database: ' . $error->getMessage(), array( 'status' => 409 ) ); }
	finally { if ( $conn ) { $conn->close(); } if ( is_resource( $execution ) ) { flock( $execution, LOCK_UN ); fclose( $execution ); } if ( $automatic ) { \WP_Upgrader::release_lock( 'auto_updater' ); } \WP_Upgrader::release_lock( 'sunrise_worker' ); }
}

function database_local_cancel( $id, $peer_site_id = null ) {
	$lock = database_process_lock(); if ( is_wp_error( $lock ) ) { return $lock; } $conn = null;
	try {
		$job = database_job( $id );
		if ( ! $job || ( null !== $peer_site_id && ( $job['peer_site_id'] ?? null ) !== $peer_site_id ) || in_array( $job['status'] ?? '', array( 'succeeded', 'restored', 'uncertain' ), true ) ) { return new \WP_Error( 'sunrise_database_cancel', 'This database migration cannot be cancelled.', array( 'status' => 409 ) ); }
		$journal_path = ( $job['root'] ?? '' ) . '/journal.json';
		if ( is_file( $journal_path ) && ! is_link( $journal_path ) ) { $journal = json_decode( file_get_contents( $journal_path ), true ); if ( in_array( $journal['state'] ?? '', array( 'committing', 'committed', 'restoring', 'restored' ), true ) ) { return new \WP_Error( 'sunrise_database_cancel', 'The database publish needs recovery and cannot be discarded.', array( 'status' => 409 ) ); } }
		if ( 'destination' === ( $job['role'] ?? null ) && ! empty( $job['staged'] ) ) { $conn = transfer_mysql(); foreach ( $job['staged'] as $table ) { if ( ! $conn->query( 'DROP TABLE IF EXISTS ' . database_identifier( $table ) ) ) { throw new \RuntimeException( 'sunrise_database_cancel' ); } } }
		database_snapshot_remove( $job );
		if ( is_file( $journal_path ) && ! is_link( $journal_path ) ) { unlink( $journal_path ); }
		$job['status'] = 'cancelled'; $job['completed_at'] = time(); database_job_save( $id, $job ); if ( get_option( 'sunrise_database_active' ) === $id ) { delete_option( 'sunrise_database_active' ); } update_option( 'sunrise_database_last', $id, false ); return database_job_public( $job );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_cancel', 'Could not discard the staged database.', array( 'status' => 409 ) ); }
	finally { if ( $conn ) { $conn->close(); } database_process_unlock( $lock ); }
}

function migration_database_cancel( $id ) {
	$access = migration_access(); if ( is_wp_error( $access ) ) { return $access; } $job = database_job( $id );
	if ( ! $job ) { return new \WP_Error( 'sunrise_database_cancel', 'Unknown database migration.', array( 'status' => 404 ) ); }
	if ( ! empty( $job['initiator'] ) ) { migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/cancel', array( 'id' => $id ) ); }
	return database_local_cancel( $id );
}

function database_job_public( $job ) {
	$total = array_sum( array_column( $job['manifest']['tables'] ?? array(), 'bytes' ) ); $done = 0;
	if ( 'destination' === ( $job['role'] ?? null ) ) { foreach ( $job['manifest']['tables'] ?? array() as $table ) { $path = ( $job['root'] ?? '' ) . '/database-' . $table['name'] . '.jsonl'; if ( is_file( $path ) && ! is_link( $path ) ) { $done += min( $table['bytes'], filesize( $path ) ); } } }
	if ( ! empty( $job['initiator'] ) ) { $done = 0; for ( $i = 0; $i < ( $job['table_index'] ?? 0 ); ++$i ) { $done += $job['manifest']['tables'][ $i ]['bytes']; } $done += (int) ( $job['offset'] ?? 0 ); }
	if ( in_array( $job['status'] ?? null, array( 'succeeded', 'restored' ), true ) ) { $done = $total; }
	return array( 'id' => $job['id'], 'status' => $job['status'], 'role' => $job['role'], 'initiator' => ! empty( $job['initiator'] ), 'cleanup_pending' => ! empty( $job['cleanup_pending'] ), 'source_site_id' => $job['manifest']['site_id'], 'destination_site_id' => 'destination' === $job['role'] ? migration_site_id() : $job['peer_site_id'], 'created_at' => gmdate( 'c', $job['created_at'] ), 'progress' => array( 'bytes' => $done, 'total' => max( 1, $total ) ), 'recovery_available' => 'succeeded' === $job['status'] && empty( $job['cleanup_pending'] ) && ! empty( $job['backups'] ), 'cancellable' => in_array( $job['status'], array( 'running', 'failed' ), true ), 'error' => $job['error'] ?? null );
}

function migration_database_start( $draft ) {
	$access = migration_access(); if ( is_wp_error( $access ) ) { return $access; }
	$pair = migration_pair_find( $draft['peer']['id'] ?? null ); if ( ! $pair ) { return new \WP_Error( 'sunrise_pair_required', 'Approve this site pair before migrating the database.', array( 'status' => 403 ) ); }
	$id = wp_generate_uuid4(); $peer = migration_pair_request( $pair['peer_site_id'], '/sunrise/v1/migrations/direct/ping', array( 'id' => $id ) ); if ( is_wp_error( $peer ) ) { return $peer; }
	$local = database_local_details();
	if ( ( $peer['site_id'] ?? null ) !== $pair['peer_site_id'] || ( $peer['db_version'] ?? null ) !== $local['db_version'] ) { return new \WP_Error( 'sunrise_pair_identity', 'The paired site identity or WordPress database version changed.', array( 'status' => 409 ) ); }
	if ( 'push' === $draft['direction'] ) {
		$export = database_source_export( $id, $pair['peer_site_id'], false ); if ( is_wp_error( $export ) ) { return $export; }
		$prepared = migration_pair_request( $pair['peer_site_id'], '/sunrise/v1/migrations/direct/prepare', array( 'id' => $id, 'manifest' => $export['manifest'] ) ); if ( is_wp_error( $prepared ) ) { migration_pair_request( $pair['peer_site_id'], '/sunrise/v1/migrations/direct/cancel', array( 'id' => $id ) ); database_local_cancel( $id ); return $prepared; }
	} else {
		$export = migration_pair_request( $pair['peer_site_id'], '/sunrise/v1/migrations/direct/export', array( 'id' => $id ) ); if ( is_wp_error( $export ) ) { return $export; }
		$prepared = database_destination_prepare( $id, $pair['peer_site_id'], $export['manifest'] ); if ( is_wp_error( $prepared ) ) { migration_pair_request( $pair['peer_site_id'], '/sunrise/v1/migrations/direct/cancel', array( 'id' => $id ) ); return $prepared; }
	}
	try { $job = database_job( $id ); $job['initiator'] = true; $job['direction'] = $draft['direction']; $job['phase'] = 'transfer'; $job['table_index'] = 0; $job['offset'] = 0; database_job_save( $id, $job ); update_option( 'sunrise_database_active', $id, false ); update_option( 'sunrise_database_last', $id, false ); }
	catch ( \Throwable $error ) { migration_pair_request( $pair['peer_site_id'], '/sunrise/v1/migrations/direct/cancel', array( 'id' => $id ) ); database_local_cancel( $id ); return new \WP_Error( 'sunrise_database_storage', 'Could not retain the database migration state.', array( 'status' => 503 ) ); }
	return migration_database_status();
}

function migration_database_continue() {
	$id = get_option( 'sunrise_database_active' ); if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) { return false; } $job = database_job( $id );
	if ( empty( $job['initiator'] ) || ( ! in_array( $job['status'] ?? null, array( 'running', 'uncertain' ), true ) && empty( $job['cleanup_pending'] ) ) ) { return false; }
	$lock = database_process_lock(); if ( is_wp_error( $lock ) ) { return $lock; }
	try {
		if ( ! empty( $job['cleanup_pending'] ) ) {
			$finished = migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/finish', array( 'id' => $id ) );
			if ( is_wp_error( $finished ) ) { $job['error'] = $finished->get_error_code(); database_job_save( $id, $job ); return migration_database_status( $id ); }
			unset( $job['cleanup_pending'], $job['error'] ); $job['phase'] = 'done'; $job['completed_at'] = time(); delete_option( 'sunrise_database_active' ); database_job_save( $id, $job ); return migration_database_status( $id );
		}
		$index = $job['table_index']; $table = $job['manifest']['tables'][ $index ] ?? null;
		if ( 'transfer' === $job['phase'] ) {
			if ( $job['offset'] < $table['bytes'] ) {
				$chunk = 'source' === $job['role'] ? database_source_chunk( $id, $job['peer_site_id'], $table['name'], $job['offset'] ) : migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/chunk', array( 'id' => $id, 'table' => $table['name'], 'offset' => $job['offset'] ) );
				if ( is_wp_error( $chunk ) ) { throw new \RuntimeException( $chunk->get_error_code() ); }
				$received = 'destination' === $job['role'] ? database_destination_receive( $id, $job['peer_site_id'], $table['name'], $job['offset'], $chunk['data'] ) : migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/upload', array( 'id' => $id, 'table' => $table['name'], 'offset' => $job['offset'], 'data' => $chunk['data'] ) );
				if ( is_wp_error( $received ) ) { throw new \RuntimeException( $received->get_error_code() ); } $job['offset'] = $received['next'];
			} else { $job['phase'] = 'stage'; }
		} elseif ( 'stage' === $job['phase'] ) {
			$staged = 'destination' === $job['role'] ? database_destination_stage( $id, $job['peer_site_id'], $index ) : migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/stage', array( 'id' => $id, 'index' => $index ) );
			if ( is_wp_error( $staged ) ) { throw new \RuntimeException( $staged->get_error_code() ); } ++$job['table_index']; $job['offset'] = 0; $job['phase'] = isset( $job['manifest']['tables'][ $job['table_index'] ] ) ? 'transfer' : 'commit';
		} elseif ( 'commit' === $job['phase'] ) {
			$result = 'destination' === $job['role'] ? database_destination_commit( $id, $job['peer_site_id'] ) : migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/commit', array( 'id' => $id ) );
			if ( is_wp_error( $result ) ) { throw new \RuntimeException( $result->get_error_code() ); }
			if ( 'destination' === $job['role'] ) { $finished = migration_pair_request( $job['peer_site_id'], '/sunrise/v1/migrations/direct/finish', array( 'id' => $id ) ); $job = database_job( $id ); if ( is_wp_error( $finished ) ) { $job['cleanup_pending'] = true; $job['phase'] = 'finish'; $job['error'] = $finished->get_error_code(); update_option( 'sunrise_database_active', $id, false ); database_job_save( $id, $job ); return migration_database_status( $id ); } } else { $finished = database_source_finish( $id, $job['peer_site_id'] ); if ( is_wp_error( $finished ) ) { throw new \RuntimeException( $finished->get_error_code() ); } }
			$job['status'] = 'succeeded'; $job['phase'] = 'done'; $job['completed_at'] = time(); delete_option( 'sunrise_database_active' );
		}
		database_job_save( $id, $job ); return migration_database_status( $id );
	} catch ( \Throwable $error ) { $job['status'] = 'commit' === ( $job['phase'] ?? null ) ? 'uncertain' : 'failed'; $job['error'] = $error->getMessage(); database_job_save( $id, $job ); return new \WP_Error( 'sunrise_database_failed', 'Database migration stopped: ' . $job['error'], array( 'status' => 409 ) ); }
	finally { database_process_unlock( $lock ); }
}

function migration_database_status( $id = null ) {
	$id = $id ?: get_option( 'sunrise_database_active', get_option( 'sunrise_database_last' ) ); if ( ! $id ) { return null; } $job = database_job( $id );
	return $job ? database_job_public( $job ) : null;
}
