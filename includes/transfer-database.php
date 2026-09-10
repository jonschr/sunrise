<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-options.php';
require_once __DIR__ . '/transfer-files.php';
require_once __DIR__ . '/transfer.php';

function database_identifier( $name ) {
	if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]{1,64}$/D', $name ) ) { throw new \RuntimeException( 'sunrise_database_identifier' ); }
	return '`' . $name . '`';
}
function database_query( $conn, $sql, $values = array() ) {
	$stmt = $conn->prepare( $sql );
	if ( ! $stmt || ( $values && ! $stmt->bind_param( str_repeat( 's', count( $values ) ), ...$values ) ) || ! $stmt->execute() ) { throw new \RuntimeException( 'sunrise_database_query' ); }
	return $stmt;
}
/** Skip connection authority and disposable runtime state, never ordinary content or source users. */
function database_skip_row( $suffix, $row ) {
	if ( 'options' === $suffix ) { $name = $row['option_name']; return 'cron' === $name || 'external_updates-sunrise' === $name || in_array( $name, array( 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt' ), true ) || preg_match( '/^(?:sunrise_|_transient_|_site_transient_)/', $name ); }
	return 'usermeta' === $suffix && ( 'session_tokens' === $row['meta_key'] || 0 === strpos( $row['meta_key'], 'sunrise_' ) );
}
function database_tables( $conn, $prefix ) {
	database_identifier( $prefix );
	$rows = database_query( $conn, 'SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME', array( DB_NAME ) )->get_result(); $tables = array();
	while ( $row = $rows->fetch_assoc() ) {
		if ( 0 !== strpos( $row['TABLE_NAME'], $prefix ) ) { continue; }
		$suffix = substr( $row['TABLE_NAME'], strlen( $prefix ) ); database_identifier( $suffix );
		if ( 0 === strpos( $suffix, 'sunrise_' ) ) { continue; }
		if ( 'BASE TABLE' !== $row['TABLE_TYPE'] || 'innodb' !== strtolower( $row['ENGINE'] ) ) { throw new \RuntimeException( 'sunrise_database_engine' ); }
		$tables[] = $suffix;
	}
	if ( ! $tables || count( $tables ) > 200 || array_diff( array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships' ), $tables ) ) { throw new \RuntimeException( 'sunrise_database_tables' ); }
	return $tables;
}
/** Structured DDL only: source SQL is never executed on the destination. */
function database_schema( $conn, $table ) {
	$name = database_identifier( $table );
	foreach ( array( 'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE=? LIMIT 1', "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE IN ('FOREIGN KEY','CHECK') LIMIT 1" ) as $sql ) { if ( database_query( $conn, $sql, array( DB_NAME, $table ) )->get_result()->fetch_row() ) { throw new \RuntimeException( 'sunrise_database_constraints' ); } }
	$columns = array(); $result = $conn->query( 'SHOW FULL COLUMNS FROM ' . $name );
	while ( $row = $result->fetch_assoc() ) {
		if ( ! in_array( strtolower( $row['Extra'] ), array( '', 'auto_increment', 'default_generated', 'on update current_timestamp()', 'default_generated on update current_timestamp' ), true ) ) { throw new \RuntimeException( 'sunrise_database_column' ); }
		$columns[] = array( 'name' => $row['Field'], 'type' => strtolower( $row['Type'] ), 'collation' => $row['Collation'], 'nullable' => 'YES' === $row['Null'], 'default' => $row['Default'], 'extra' => strtolower( $row['Extra'] ) );
	}
	$indexes = array(); $result = $conn->query( 'SHOW INDEX FROM ' . $name );
	while ( $row = $result->fetch_assoc() ) {
		$key = $row['Key_name']; if ( ! isset( $indexes[ $key ] ) ) { $indexes[ $key ] = array( 'name' => $key, 'unique' => ! (bool) $row['Non_unique'], 'type' => $row['Index_type'], 'columns' => array() ); }
		$indexes[ $key ]['columns'][] = array( 'name' => $row['Column_name'], 'length' => null === $row['Sub_part'] ? null : (int) $row['Sub_part'], 'descending' => 'D' === $row['Collation'] );
	}
	$table_meta = database_query( $conn, 'SELECT TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $table ) )->get_result()->fetch_assoc();
	$schema = array( 'columns' => $columns, 'indexes' => array_values( $indexes ), 'collation' => $table_meta['TABLE_COLLATION'], 'auto_increment' => null === $table_meta['AUTO_INCREMENT'] ? null : (string) $table_meta['AUTO_INCREMENT'] ); database_create_sql( $conn, $table, $schema ); return $schema;
}
function database_create_sql( $conn, $name, $schema ) {
	if ( ! is_array( $schema ) || count( $schema ) !== 4 || ! is_array( $schema['columns'] ?? null ) || ! is_array( $schema['indexes'] ?? null ) || ! $schema['columns'] || count( $schema['columns'] ) > 256 || count( $schema['indexes'] ) > 64 ) { throw new \RuntimeException( 'sunrise_database_schema' ); }
	database_identifier( $schema['collation'] ?? null );
	if ( ! array_key_exists( 'auto_increment', $schema ) || ( null !== $schema['auto_increment'] && ( ! is_string( $schema['auto_increment'] ) || ! preg_match( '/^[1-9][0-9]{0,19}$/D', $schema['auto_increment'] ) ) ) ) { throw new \RuntimeException( 'sunrise_database_schema' ); }
	$parts = array(); $names = array(); $primary = false;
	foreach ( $schema['columns'] as $c ) {
		if ( ! is_array( $c ) || count( $c ) !== 6 || ! is_string( $c['type'] ?? null ) || ! preg_match( '/^(?:(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]{1,3}\))?|(?:decimal|numeric|float|double)(?:\([0-9]{1,3}(?:,[0-9]{1,3})?\))?|(?:var)?(?:char|binary)\([0-9]{1,5}\)|(?:tiny|medium|long)?(?:text|blob)|date|datetime(?:\([0-6]\))?|timestamp(?:\([0-6]\))?|time(?:\([0-6]\))?|year(?:\(4\))?|bit\([0-9]{1,2}\)|json)(?: unsigned)?(?: zerofill)?$/D', $c['type'] ) || ! is_bool( $c['nullable'] ?? null ) || ! array_key_exists( 'default', $c ) || ! array_key_exists( 'collation', $c ) || ! in_array( $c['extra'] ?? null, array( '', 'auto_increment', 'default_generated', 'on update current_timestamp()', 'default_generated on update current_timestamp' ), true ) ) { throw new \RuntimeException( 'sunrise_database_column' ); }
		$part = database_identifier( $c['name'] ); if ( isset( $names[ strtolower( $c['name'] ) ] ) ) { throw new \RuntimeException( 'sunrise_database_column' ); } $names[ strtolower( $c['name'] ) ] = true;
		$part .= ' ' . $c['type'];
		if ( null !== $c['collation'] ) { database_identifier( $c['collation'] ); $part .= ' COLLATE ' . $c['collation']; }
		$part .= $c['nullable'] ? ' NULL' : ' NOT NULL';
		if ( null !== $c['default'] ) {
			if ( ! is_string( $c['default'] ) || strlen( $c['default'] ) > 8192 ) { throw new \RuntimeException( 'sunrise_database_default' ); }
			$default = $c['default'];
			if ( preg_match( '/^current_timestamp(?:\([0-6]?\))?$/iD', $default ) && preg_match( '/^(?:timestamp|datetime)/', $c['type'] ) ) { $part .= ' DEFAULT ' . strtoupper( $default ); }
			else { $part .= " DEFAULT '" . $conn->real_escape_string( $default ) . "'"; }
		} elseif ( $c['nullable'] ) { $part .= ' DEFAULT NULL'; }
		if ( 'auto_increment' === $c['extra'] ) { $part .= ' AUTO_INCREMENT'; }
		if ( false !== strpos( $c['extra'], 'on update' ) ) { $part .= ' ON UPDATE CURRENT_TIMESTAMP'; }
		$parts[] = $part;
	}
	$index_names = array();
	foreach ( $schema['indexes'] as $index ) {
		if ( ! is_array( $index ) || count( $index ) !== 4 || ! is_bool( $index['unique'] ?? null ) || ! in_array( $index['type'] ?? null, array( 'BTREE', 'FULLTEXT' ), true ) || ! is_array( $index['columns'] ?? null ) || ! $index['columns'] || count( $index['columns'] ) > 16 ) { throw new \RuntimeException( 'sunrise_database_index' ); }
		database_identifier( $index['name'] ); if ( isset( $index_names[ $index['name'] ] ) ) { throw new \RuntimeException( 'sunrise_database_index' ); } $index_names[ $index['name'] ] = true;
		$columns = array(); foreach ( $index['columns'] as $c ) { if ( ! is_array( $c ) || count( $c ) !== 3 || ! isset( $names[ strtolower( $c['name'] ?? '' ) ] ) || ! is_bool( $c['descending'] ?? null ) || ! array_key_exists( 'length', $c ) || ( null !== $c['length'] && ( ! is_int( $c['length'] ) || $c['length'] < 1 || $c['length'] > 3072 ) ) ) { throw new \RuntimeException( 'sunrise_database_index' ); } $columns[] = database_identifier( $c['name'] ) . ( null === $c['length'] ? '' : '(' . $c['length'] . ')' ) . ( $c['descending'] ? ' DESC' : '' ); }
		if ( 'PRIMARY' === $index['name'] ) { $primary = true; $kind = 'PRIMARY KEY'; } else { $kind = ( 'FULLTEXT' === $index['type'] ? 'FULLTEXT ' : ( $index['unique'] ? 'UNIQUE ' : '' ) ) . 'KEY ' . database_identifier( $index['name'] ); }
		$parts[] = $kind . ' (' . implode( ',', $columns ) . ')';
	}
	if ( ! $primary ) { throw new \RuntimeException( 'sunrise_database_primary_key' ); }
	return 'CREATE TABLE ' . database_identifier( $name ) . ' (' . implode( ',', $parts ) . ') ENGINE=InnoDB DEFAULT CHARACTER SET ' . explode( '_', $schema['collation'] )[0] . ' COLLATE ' . $schema['collation'] . ( null === $schema['auto_increment'] ? '' : ' AUTO_INCREMENT=' . $schema['auto_increment'] );
}
function database_row_wire( $row ) { return wp_json_encode( array_map( function ( $v ) { return null === $v ? null : base64_encode( $v ); }, array_values( $row ) ), JSON_UNESCAPED_SLASHES ) . "\n"; }
function database_scan( $conn, $prefix, $suffix, $schema, $output = null, $deadline = null ) {
	$primary = null; foreach ( $schema['indexes'] as $index ) { if ( 'PRIMARY' === $index['name'] ) { $primary = array_column( $index['columns'], 'name' ); } }
	if ( ! $primary ) { throw new \RuntimeException( 'sunrise_database_primary_key' ); }
	$fields = implode( ',', array_map( __NAMESPACE__ . '\\database_identifier', array_column( $schema['columns'], 'name' ) ) );
	$result = $conn->query( 'SELECT ' . $fields . ' FROM ' . database_identifier( $prefix . $suffix ) . ' ORDER BY ' . implode( ',', array_map( __NAMESPACE__ . '\\database_identifier', $primary ) ), MYSQLI_USE_RESULT );
	if ( ! $result ) { throw new \RuntimeException( 'sunrise_database_read' ); } $hash = hash_init( 'sha256' ); $count = 0; $bytes = 0;
	try { while ( $row = $result->fetch_assoc() ) {
		if ( $deadline && microtime( true ) > $deadline ) { throw new \RuntimeException( 'sunrise_database_time_limit' ); }
		if ( database_skip_row( $suffix, $row ) ) { continue; } $wire = database_row_wire( $row );
		if ( strlen( $wire ) > 4 * MB_IN_BYTES || ( $bytes += strlen( $wire ) ) > 512 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_database_size_limit' ); }
		hash_update( $hash, $wire ); if ( $output && fwrite( $output, $wire ) !== strlen( $wire ) ) { throw new \RuntimeException( 'sunrise_database_storage' ); } ++$count;
	} } finally { $result->free(); }
	return array( 'rows' => $count, 'bytes' => $bytes, 'sha256' => hash_final( $hash ) );
}
/** A consistent InnoDB read snapshot, streamed to private row files instead of PHP arrays or executable SQL. */
function database_export( $id ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; } $conn = null; $created = array();
	try {
		global $wpdb, $wp_db_version; $root = transfer_file_workspace( $id ); $conn = transfer_mysql(); $conn->query( "SET SESSION time_zone='+00:00'" ); $tables = database_tables( $conn, $wpdb->prefix ); $metadata = array();
		foreach ( $tables as $suffix ) { $metadata[ $suffix ] = database_schema( $conn, $wpdb->prefix . $suffix ); }
		if ( ! $conn->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || ! $conn->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) { throw new \RuntimeException( 'sunrise_database_snapshot' ); }
		$owner = database_query( $conn, 'SELECT ID,user_login,user_pass FROM ' . database_identifier( $wpdb->users ) . ' WHERE ID=?', array( get_current_user_id() ) )->get_result()->fetch_assoc();
		if ( ! $owner ) { throw new \RuntimeException( 'sunrise_database_owner' ); }
		$manifest = array( 'schema' => 1, 'scope' => 'database', 'site_id' => agent_state()['site_id'], 'site_url' => site_url( '/' ), 'home_url' => home_url( '/' ), 'prefix' => $wpdb->prefix, 'db_version' => (int) $wp_db_version, 'owner' => array( 'id' => (int) $owner['ID'], 'login' => $owner['user_login'], 'fingerprint' => hash( 'sha256', $owner['ID'] . "\n" . $owner['user_login'] . "\n" . $owner['user_pass'] ) ), 'tables' => array() );
		// ponytail: consistent snapshot creation is bounded to 20 seconds / 512 MiB; long-running exports need a durable snapshot worker.
		$deadline = microtime( true ) + 20; $total = 0;
		foreach ( $metadata as $suffix => $schema ) {
			$path = $root . '/database-' . $suffix . '.jsonl'; if ( is_link( $path ) ) { throw new \RuntimeException( 'sunrise_database_storage' ); } $created[] = $path; $out = fopen( $path, 'wb' ); if ( ! $out ) { throw new \RuntimeException( 'sunrise_database_storage' ); } chmod( $path, 0600 );
			try { $stats = database_scan( $conn, $wpdb->prefix, $suffix, $schema, $out, $deadline ); } finally { fclose( $out ); }
			$after_schema = database_schema( $conn, $wpdb->prefix . $suffix ); $after_schema['auto_increment'] = $schema['auto_increment'];
			if ( $after_schema !== $schema ) { throw new \RuntimeException( 'sunrise_database_schema_changed' ); }
			$total += $stats['bytes']; if ( $total > 512 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_database_size_limit' ); }
			$manifest['tables'][] = array_merge( array( 'name' => $suffix, 'definition' => $schema ), $stats );
		}
		if ( database_tables( $conn, $wpdb->prefix ) !== $tables ) { throw new \RuntimeException( 'sunrise_database_schema_changed' ); }
		$conn->rollback();
		if ( strlen( wp_json_encode( $manifest ) ) > 900000 ) { throw new \RuntimeException( 'sunrise_database_size_limit' ); }
		return $manifest;
	} catch ( \Throwable $error ) { foreach ( $created as $file ) { if ( ! is_link( $file ) && is_file( $file ) ) { unlink( $file ); } } return new \WP_Error( 'sunrise_database_export', 'Database snapshot failed: ' . ( preg_match( '/^sunrise_database_[a-z_]+$/D', $error->getMessage() ) ? $error->getMessage() : 'database_read_error' ) ); }
	finally { if ( $conn ) { $conn->close(); } }
}

/** Import compatibility is checked before any destination table is replaced. */
function database_manifest( $conn, $manifest ) {
 if ( ! is_array( $manifest ) || count( $manifest ) !== 9 || ( $manifest['schema'] ?? null ) !== 1 || ( $manifest['scope'] ?? null ) !== 'database' || ! is_string( $manifest['site_id'] ?? null ) || ! wp_is_uuid( $manifest['site_id'], 4 ) || ! is_int( $manifest['db_version'] ?? null ) || $manifest['db_version'] < 1 || ! is_array( $manifest['tables'] ?? null ) || ! $manifest['tables'] || array_values( $manifest['tables'] ) !== $manifest['tables'] || count( $manifest['tables'] ) > 200 ) { throw new \InvalidArgumentException( 'sunrise_database_manifest' ); }
 database_identifier( $manifest['prefix'] ?? null );
 transfer_rewrite( '', 'text', array( $manifest['site_url'] ?? '' => $manifest['home_url'] ?? '' ) );
 $owner = $manifest['owner'] ?? null;
 if ( ! is_array( $owner ) || count( $owner ) !== 3 || ! is_int( $owner['id'] ?? null ) || $owner['id'] < 1 || ! is_string( $owner['login'] ?? null ) || ! $owner['login'] || strlen( $owner['login'] ) > 60 || ! is_string( $owner['fingerprint'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $owner['fingerprint'] ) ) { throw new \InvalidArgumentException( 'sunrise_database_owner' ); }
 $names = array(); $total = 0;
 foreach ( $manifest['tables'] as $table ) {
  if ( ! is_array( $table ) || count( $table ) !== 5 || ! is_int( $table['rows'] ?? null ) || $table['rows'] < 0 || ! is_int( $table['bytes'] ?? null ) || $table['bytes'] < 0 || ! is_string( $table['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $table['sha256'] ) ) { throw new \InvalidArgumentException( 'sunrise_database_manifest' ); }
  database_identifier( $table['name'] ?? null ); $name = strtolower( $table['name'] );
  if ( isset( $names[ $name ] ) || 0 === strpos( $name, 'sunrise_' ) || ( $total += $table['bytes'] ) > 512 * MB_IN_BYTES ) { throw new \InvalidArgumentException( 'sunrise_database_manifest' ); } $names[ $name ] = true;
  database_create_sql( $conn, $table['name'], $table['definition'] ?? null );
 }
 if ( array_diff( array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships' ), array_keys( $names ) ) ) { throw new \InvalidArgumentException( 'sunrise_database_tables' ); }
 return $manifest;
}

/** No writes: the approved plan binds both table snapshots and the exact copied administrator. */
function database_preview( $manifest ) {
 $access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; } $conn = null;
 try {
  global $wpdb, $wp_db_version; $conn = transfer_mysql(); $manifest = database_manifest( $conn, $manifest );
  if ( $manifest['site_id'] === agent_state()['site_id'] || $manifest['db_version'] !== (int) $wp_db_version ) { throw new \RuntimeException( 'sunrise_database_core_mismatch' ); }
  $conn->query( "SET SESSION time_zone='+00:00'" ); $tables = database_tables( $conn, $wpdb->prefix ); $metadata = array();
  foreach ( $tables as $name ) { $metadata[ $name ] = database_schema( $conn, $wpdb->prefix . $name ); }
  if ( ! $conn->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || ! $conn->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) { throw new \RuntimeException( 'sunrise_database_snapshot' ); } $destination = array(); $deadline = microtime( true ) + 20; $total = 0;
  foreach ( $metadata as $name => $schema ) {
   $stats = database_scan( $conn, $wpdb->prefix, $name, $schema, null, $deadline );
   $after_schema = database_schema( $conn, $wpdb->prefix . $name ); $after_schema['auto_increment'] = $schema['auto_increment'];
   if ( $after_schema !== $schema ) { throw new \RuntimeException( 'sunrise_database_schema_changed' ); }
   if ( ( $total += $stats['bytes'] ) > 512 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_database_size_limit' ); }
   $destination[] = array_merge( array( 'name' => $name, 'definition' => $schema ), $stats );
  }
  if ( database_tables( $conn, $wpdb->prefix ) !== $tables ) { throw new \RuntimeException( 'sunrise_database_schema_changed' ); }
  $conn->rollback();
  $plan = array( 'schema' => 1, 'scope' => 'database', 'source_site_id' => $manifest['site_id'], 'destination_site_id' => agent_state()['site_id'], 'source' => $manifest, 'destination' => array( 'installation_id' => installation_identity()['id'], 'owner' => get_current_user_id(), 'prefix' => $wpdb->prefix, 'site_url' => site_url( '/' ), 'home_url' => home_url( '/' ), 'tables' => $destination ) );
  if ( strlen( wp_json_encode( $plan ) ) > 1400000 ) { throw new \RuntimeException( 'sunrise_database_size_limit' ); }
  return array( 'plan' => $plan, 'fingerprint' => hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), 'read_only' => true, 'authorization_required' => true );
 } catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_database_preview', 'Could not compare the database: ' . ( preg_match( '/^sunrise_database_[a-z_]+$/D', $error->getMessage() ) ? $error->getMessage() : 'database_read_error' ), array( 'status' => 409 ) ); }
 finally { if ( $conn ) { $conn->close(); } }
}

function database_rewrite_row( $suffix, $row, $schema, $source, $destination ) {
 if ( database_skip_row( $suffix, $row ) ) { throw new \RuntimeException( 'sunrise_database_authority_in_source' ); }
 $urls = array( untrailingslashit( $source['site_url'] ) => untrailingslashit( $destination['site_url'] ) );
 $from_home = untrailingslashit( $source['home_url'] ); $to_home = untrailingslashit( $destination['home_url'] );
 if ( isset( $urls[ $from_home ] ) && $urls[ $from_home ] !== $to_home ) { throw new \RuntimeException( 'sunrise_database_url_mapping' ); } $urls[ $from_home ] = $to_home;
 foreach ( $schema['columns'] as $column ) {
  $key = $column['name']; $value = $row[ $key ]; if ( null === $value ) { continue; }
  // Binary payloads are preserved. Rewrite text and serialized/JSON strings, never executable PHP.
  $serialized = (bool) preg_match( '/^(?:[aObisSdCREr]:|N;)/', $value );
  if ( ! $serialized && ! preg_match( '/^(?:varchar|char|tinytext|text|mediumtext|longtext|json)\b/', $column['type'] ) ) { continue; }
  $contains = false; foreach ( $urls as $from => $to ) { if ( $from !== $to && ( false !== strpos( $value, $from ) || false !== strpos( $value, str_replace( '/', '\\/', $from ) ) ) ) { $contains = true; break; } }
  if ( ! $contains ) { continue; }
  $format = $serialized ? 'serialized' : 'text'; if ( ! $serialized && preg_match( '/^\s*[\[{]/', $value ) ) { json_decode( $value ); if ( JSON_ERROR_NONE === json_last_error() ) { $format = 'json'; } }
  $row[ $key ] = transfer_rewrite( $value, $format, $urls );
 }
 if ( 'options' === $suffix ) {
  if ( 'siteurl' === $row['option_name'] ) { $row['option_value'] = untrailingslashit( $destination['site_url'] ); }
  if ( 'home' === $row['option_name'] ) { $row['option_value'] = untrailingslashit( $destination['home_url'] ); }
 }
 $key = 'options' === $suffix ? 'option_name' : ( 'usermeta' === $suffix ? 'meta_key' : null );
 if ( $key && 0 === strpos( $row[ $key ], $source['prefix'] ) ) { $row[ $key ] = $destination['prefix'] . substr( $row[ $key ], strlen( $source['prefix'] ) ); }
 return $row;
}

/** Internal staging primitive. Its generated names cannot address live WordPress tables. No HTTP route calls it. */
function database_stage_table( $conn, $name, $table, $path, $source, $destination ) {
 if ( ! is_string( $name ) || ! preg_match( '/^sunrise_import_[a-f0-9]{20}_[0-9]{1,3}$/D', $name ) || is_link( $path ) || ! is_file( $path ) || filesize( $path ) !== $table['bytes'] || hash_file( 'sha256', $path ) !== $table['sha256'] ) { throw new \RuntimeException( 'sunrise_database_stage' ); }
 $created = false; $stream = null;
 try {
  if ( ! $conn->query( "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'" ) || ! $conn->query( "SET SESSION time_zone='+00:00'" ) ) { throw new \RuntimeException( 'sunrise_database_stage' ); }
  if ( ! $conn->query( database_create_sql( $conn, $name, $table['definition'] ) ) ) { throw new \RuntimeException( 'sunrise_database_stage' ); } $created = true;
  $fields = array_column( $table['definition']['columns'], 'name' ); $sql = 'INSERT INTO ' . database_identifier( $name ) . ' (' . implode( ',', array_map( __NAMESPACE__ . '\\database_identifier', $fields ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $fields ), '?' ) ) . ')';
  $stream = fopen( $path, 'rb' ); if ( ! $stream ) { throw new \RuntimeException( 'sunrise_database_storage' ); }
  // ponytail: staging is bounded to 20 seconds per table; larger tables need resumable import checkpoints before enabling that scale.
  $deadline = microtime( true ) + 20; $rows = 0; $hash = hash_init( 'sha256' ); if ( ! $conn->begin_transaction() ) { throw new \RuntimeException( 'sunrise_database_stage' ); }
  while ( false !== ( $line = fgets( $stream, 4 * MB_IN_BYTES + 2 ) ) ) {
   if ( microtime( true ) > $deadline || substr( $line, -1 ) !== "\n" || strlen( $line ) > 4 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_database_stage_limit' ); }
   hash_update( $hash, $line ); $values = json_decode( $line, true );
   if ( ! is_array( $values ) || array_values( $values ) !== $values || count( $values ) !== count( $fields ) ) { throw new \RuntimeException( 'sunrise_database_row' ); }
   foreach ( $values as &$value ) { if ( null === $value ) { continue; } if ( ! is_string( $value ) ) { throw new \RuntimeException( 'sunrise_database_row' ); } $decoded = base64_decode( $value, true ); if ( false === $decoded || base64_encode( $decoded ) !== $value ) { throw new \RuntimeException( 'sunrise_database_row' ); } $value = $decoded; } unset( $value );
   $row = database_rewrite_row( $table['name'], array_combine( $fields, $values ), $table['definition'], $source, $destination ); database_query( $conn, $sql, array_values( $row ) ); ++$rows;
  }
  if ( ! feof( $stream ) || $rows !== $table['rows'] || hash_final( $hash ) !== $table['sha256'] ) { throw new \RuntimeException( 'sunrise_database_row_count' ); }
  if ( ! $conn->commit() ) { throw new \RuntimeException( 'sunrise_database_stage' ); } return $rows;
 } catch ( \Throwable $error ) { $conn->rollback(); if ( $created ) { $conn->query( 'DROP TABLE ' . database_identifier( $name ) ); } throw $error; }
 finally { if ( is_resource( $stream ) ) { fclose( $stream ); } }
}
