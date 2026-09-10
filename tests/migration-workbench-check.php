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
 wp_set_current_user( 0 ); $assert( is_wp_error( Sunrise\save_migration_draft( $draft ) ) && is_wp_error( Sunrise\migration_status() ) && is_wp_error( Sunrise\migration_peers() ) && is_wp_error( Sunrise\migration_sync() ) ); wp_set_current_user( $owner );
 WP_CLI::success( 'Per-owner draft persistence, connection binding, selected-setting allowlist, URL/date validation and unauthenticated denial passed.' );
} finally { wp_set_current_user( $owner ); if ( $saved ) { update_user_meta( $owner, 'sunrise_migration_draft', $saved ); } else { delete_user_meta( $owner, 'sunrise_migration_draft' ); } }
