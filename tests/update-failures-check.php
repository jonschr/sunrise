<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
$previous = get_option( 'sunrise_automatic_update_failures', null );
$check = function ( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
try {
 delete_option( 'sunrise_automatic_update_failures' );
 $item = (object) array( 'plugin' => 'fixture/fixture.php', 'new_version' => '2.0' );
 $result = (object) array( 'item' => $item, 'result' => new WP_Error( 'download_failed', 'License expired: secret-key https://provider.test/?key=secret' ) );
 do_action( 'automatic_updates_complete', array( 'plugin' => array( $result ) ) );
 do_action( 'automatic_updates_complete', array( 'plugin' => array( $result ) ) );
 $inventory = array( array( 'type' => 'plugins', 'installed_id' => 'fixture/fixture.php', 'version' => '1.0' ) );
 $failures = Sunrise\automatic_update_failures( $inventory );
 $check( 1 === count( $failures ) && 'license_required' === $failures[0]['code'] && false === strpos( wp_json_encode( $failures ), 'secret' ), 'Distinct capture or redaction failed' );
 $inventory[0]['version'] = '2.1';
 $check( array() === Sunrise\automatic_update_failures( $inventory ), 'A later manual update must close the failure' );
 do_action( 'automatic_updates_complete', array( 'plugin' => array( $result ) ) );
 $result->result = true;do_action( 'automatic_updates_complete', array( 'plugin' => array( $result ) ) );
 $check( array() === Sunrise\automatic_update_failures( array() ), 'Native success must clear native failure' );
 $probe = array( 'id' => wp_generate_uuid4(), 'type' => 'plugin', 'installed_id' => 'fixture/fixture.php', 'version' => '2.0' );
 $check( Sunrise\validate_failure_checks( array( $probe ) ), 'Valid probe rejected' );
 $check( array( $probe['id'] ) === Sunrise\failure_resolutions( array( $probe ), $inventory ), 'Higher installed version must resolve central failure' );
 $inventory[0]['version'] = '2.0-RC1';$check( array() === Sunrise\failure_resolutions( array( $probe ), $inventory ), 'Prerelease must not resolve stable target' );
 $probe['version'] = '../unsafe';$check( ! Sunrise\validate_failure_checks( array( $probe ) ), 'Invalid probe accepted' );
 WP_CLI::success( 'Native failure capture, deduplication, secret-free reasons, successful auto/manual resolution and WordPress version comparison passed.' );
} finally { if ( null === $previous ) { delete_option( 'sunrise_automatic_update_failures' ); } else { update_option( 'sunrise_automatic_update_failures', $previous, false ); } }
