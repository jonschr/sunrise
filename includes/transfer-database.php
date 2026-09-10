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
	if ( 'options' === $suffix ) { $name = $row['option_name']; return 'cron' === $name || 'external_updates-sunrise' === $name || preg_match( '/^(?:sunrise_|_transient_|_site_transient_)/', $name ); }
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
		global $wpdb, $wp_db_version; $root = transfer_file_workspace( $id ); $conn = transfer_mysql(); $tables = database_tables( $conn, $wpdb->prefix ); $metadata = array();
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
