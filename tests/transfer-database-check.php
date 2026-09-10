<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/transfer-database.php';
$assert = function ( $v, $m ) { if ( ! $v ) { throw new RuntimeException( $m ); } };
$conn = Sunrise\transfer_mysql(); $prefix = 'sunrise_db_test_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ); $table = $prefix . '_source'; $copy = $prefix . '_copy'; $id = wp_generate_uuid4(); $root = null; $staged = 'sunrise_import_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 ) . '_0';
try {
 $conn->query( "SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'" );
 $conn->query( 'CREATE TABLE ' . Sunrise\database_identifier( $table ) . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, label VARCHAR(191) NOT NULL DEFAULT 'a\\'b', value LONGBLOB NULL, published DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY(id), KEY label_index(label(40))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=900" );
 $schema = Sunrise\database_schema( $conn, $table ); $sql = Sunrise\database_create_sql( $conn, $copy, $schema ); $assert( $conn->query( $sql ), 'Structured table creation' );
 $assert( Sunrise\database_schema( $conn, $copy ) === $schema, 'Schema, literal defaults, collation and next identifier preserved' );
 $value = "\x00binary\xff"; Sunrise\database_query( $conn, 'INSERT INTO ' . Sunrise\database_identifier( $table ) . ' (id,label,value) VALUES(?,?,?)', array( '0', 'https://source.example/quoted', $value ) );
 $wire = Sunrise\database_row_wire( array( '0', "a\0b\xff", null ) ); $decoded = json_decode( $wire, true ); $assert( array_map( function( $v ) { return null === $v ? null : base64_decode( $v, true ); }, $decoded ) === array( '0', "a\0b\xff", null ), 'Binary rows and null survive transport' );
 $assert( Sunrise\database_skip_row( 'options', array( 'option_name' => 'sunrise_agents' ) ) && Sunrise\database_skip_row( 'usermeta', array( 'meta_key' => 'session_tokens' ) ) && ! Sunrise\database_skip_row( 'options', array( 'option_name' => 'blogname' ) ), 'Exclude authority and sessions, retain content' );
 foreach ( array( 'bad`name', '../options', '', str_repeat( 'x', 65 ) ) as $bad ) { try { Sunrise\database_identifier( $bad ); throw new LogicException( 'Unsafe identifier accepted' ); } catch ( RuntimeException $expected ) {} }
 $invalid = $schema; $invalid['columns'][0]['type'] = 'int); DROP TABLE wp_users; --'; try { Sunrise\database_create_sql( $conn, $copy, $invalid ); throw new LogicException( 'Source SQL accepted' ); } catch ( RuntimeException $expected ) {}
 $before = Sunrise\installation_identity(); $agents = Sunrise\agent_states();
 $snapshot = Sunrise\database_export( $id ); $assert( ! is_wp_error( $snapshot ), is_wp_error( $snapshot ) ? $snapshot->get_error_message() : 'Snapshot' ); $root = Sunrise\transfer_file_workspace( $id );
 foreach ( $snapshot['tables'] as $item ) { $file = $root . '/database-' . $item['name'] . '.jsonl'; $assert( hash_file( 'sha256', $file ) === $item['sha256'] && filesize( $file ) === $item['bytes'], 'Exact snapshot bytes' ); }
 $assert( in_array( 'users', array_column( $snapshot['tables'], 'name' ), true ) && $snapshot['owner']['id'] === get_current_user_id(), 'Source users and exact owner represented' );
 $assert( Sunrise\installation_identity() === $before && Sunrise\agent_states() === $agents, 'Export leaves installation authority unchanged' );
 $assert( Sunrise\database_manifest( $conn, $snapshot ) === $snapshot, 'Manifest validates without source SQL' );
 $source = $snapshot; $source['site_id'] = wp_generate_uuid4(); $preview = Sunrise\database_preview( $source ); $assert( ! is_wp_error( $preview ), is_wp_error( $preview ) ? $preview->get_error_message() : 'Destination snapshot preview' );
 $assert( $preview['authorization_required'] && $preview['read_only'] && $preview['plan']['destination']['installation_id'] === $before['id'], 'Preview binds destination identity and requires authorization' );
 $bad = $source; $bad['db_version']++; $assert( is_wp_error( Sunrise\database_preview( $bad ) ), 'Different core database schema rejected' );
 $file = $root . '/database-test.jsonl'; $stream = fopen( $file, 'wb' ); $stats = Sunrise\database_scan( $conn, '', $table, $schema, $stream ); fclose( $stream ); chmod( $file, 0600 );
 $entry = array_merge( array( 'name' => 'test', 'definition' => $schema ), $stats );
 $from = array( 'site_url' => 'https://source.example', 'home_url' => 'https://source.example', 'prefix' => 'wp_' ); $to = array( 'site_url' => 'https://destination.example', 'home_url' => 'https://destination.example', 'prefix' => 'dst_' );
 $assert( Sunrise\database_stage_table( $conn, $staged, $entry, $file, $from, $to ) === 1, 'Private staging inserts verified rows' );
 $row = $conn->query( 'SELECT * FROM ' . Sunrise\database_identifier( $staged ) )->fetch_assoc(); $assert( (string) $row['id'] === '0' && $row['label'] === 'https://destination.example/quoted' && $row['value'] === $value, 'URLs rewritten, zero identifiers and binary values retained' ); $conn->query( 'DROP TABLE ' . Sunrise\database_identifier( $staged ) );
 $entry['rows']++; try { Sunrise\database_stage_table( $conn, $staged, $entry, $file, $from, $to ); throw new LogicException( 'Wrong count accepted' ); } catch ( RuntimeException $expected ) {} $assert( ! Sunrise\database_query( $conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', array( DB_NAME, $staged ) )->get_result()->fetch_row(), 'Failed staging removes only its private table' );

 foreach ( glob( $root . '/database-*.jsonl' ) as $file ) { touch( $file, time() - 8 * DAY_IN_SECONDS ); } touch( $root, time() - 8 * DAY_IN_SECONDS ); delete_user_meta( get_current_user_id(), 'sunrise_file_pruned_at' ); Sunrise\transfer_file_prune( dirname( $root ), wp_generate_uuid4() ); $assert( ! is_dir( $root ), 'Private database snapshots expire after seven days' ); $root = null;
 WP_CLI::success( 'Database snapshot, read-only preview, verified private staging, URL rewriting, binary preservation and failure cleanup passed.' );
} finally { $conn->query( 'DROP TABLE IF EXISTS ' . Sunrise\database_identifier( $copy ) . ',' . Sunrise\database_identifier( $table ) . ',' . Sunrise\database_identifier( $staged ) ); $conn->close(); if ( $root ) { foreach ( glob( $root . '/database-*.jsonl' ) as $file ) { unlink( $file ); } if ( count( scandir( $root ) ) === 2 ) { rmdir( $root ); } } }
