<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/migrations.php';
$owner = get_current_user_id(); $saved = get_user_meta( $owner, 'sunrise_migration_draft', true );
$assert = function ( $ok ) { if ( ! $ok ) { throw new RuntimeException( 'Migration draft guard failed.' ); } };
try {
 delete_user_meta( $owner, 'sunrise_migration_draft' ); $draft = Sunrise\migration_draft();
 $draft['direction'] = 'push'; $draft['peer'] = array( 'id' => wp_generate_uuid4(), 'url' => 'https://peer.example/', 'name' => 'Peer' );
 $assert( $draft === Sunrise\save_migration_draft( $draft ) && $draft === Sunrise\migration_draft() );
 foreach ( array( 'connection' => wp_generate_uuid4(), 'direction' => 'sideways', 'scopes' => array( 'shell' ), 'names' => array( 'siteurl' ), 'date' => '2026-02-31', 'peer' => array( 'id' => wp_generate_uuid4(), 'url' => 'https://secret@peer.example/', 'name' => 'Peer' ) ) as $key => $bad ) { $invalid = $draft; $invalid[ $key ] = $bad; $assert( is_wp_error( Sunrise\save_migration_draft( $invalid ) ) ); }
 $assert( $draft === Sunrise\migration_draft() );
 $requests = array(); $http = function ( $pre, $args, $url ) use ( &$requests ) {
  if ( false !== strpos( $url, '/v1/agent/dashboard' ) ) { $payload = json_decode( $args['body'], true ); $requests[] = $payload; $body = array( 'status' => 'GET' === $payload['method'] ? 200 : 202, 'body' => array( 'id' => wp_generate_uuid4() ) ); }
  elseif ( false !== strpos( $url, '/v1/agent/transfers/status' ) ) { $body = array( 'items' => array(), 'execution_available' => true, 'file_transfers' => true ); }
  else { return $pre; }
  return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
 };
 add_filter( 'pre_http_request', $http, 10, 3 );
 $mixed = $draft; $mixed['scopes'] = array( 'options', 'plugins' ); $keys = array( wp_generate_uuid4(), wp_generate_uuid4() );
 $assert( ! is_wp_error( Sunrise\migration_prepare( array( 'keys' => $keys, 'draft' => $mixed ) ) ) && 2 === count( $requests ) );
 $assert( isset( $requests[0]['data']['names'], $requests[1]['data']['file_selection']['plugins'] ) && $requests[0]['headers']['Idempotency-Key'] === $keys[0] && $requests[1]['headers']['Idempotency-Key'] === $keys[1] );
 $id = wp_generate_uuid4(); $assert( ! is_wp_error( Sunrise\migration_action( 'approve', array( 'id' => $id, 'side' => 'source', 'revision' => 0, 'plan_hash' => str_repeat( 'a', 64 ) ) ) ) && '/approve' === substr( $requests[2]['path'], -8 ) );
 $assert( is_wp_error( Sunrise\migration_action( 'approve', array( 'id' => $id, 'side' => 'both', 'revision' => 0, 'plan_hash' => str_repeat( 'a', 64 ) ) ) ) );
 remove_filter( 'pre_http_request', $http, 10 );
 wp_set_current_user( 0 ); $assert( is_wp_error( Sunrise\save_migration_draft( $draft ) ) && is_wp_error( Sunrise\migration_status() ) && is_wp_error( Sunrise\migration_peers() ) && is_wp_error( Sunrise\migration_sync() ) ); wp_set_current_user( $owner );
 WP_CLI::success( 'Per-owner drafts and direct WordPress preparation/approval passed without an app UI.' );
} finally { wp_set_current_user( $owner ); if ( $saved ) { update_user_meta( $owner, 'sunrise_migration_draft', $saved ); } else { delete_user_meta( $owner, 'sunrise_migration_draft' ); } }
