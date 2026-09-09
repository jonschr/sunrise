<?php
/** Fixture phases: prepare, run, cleanup. Only the synthetic plugin is ever replaced. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! current_user_can( 'manage_options' ) || 'http://127.0.0.1:8787' !== Sunrise\agent_url() ) { WP_CLI::error( 'Disposable local fixture with traffic cron disabled required.' ); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
$phase = isset( $args[0] ) ? $args[0] : '';
$dir = WP_PLUGIN_DIR . '/sunrise-test-remote-update'; $id = 'sunrise-test-remote-update/fixture.php';
$saved = get_option( 'sunrise_installation_job_fixture', null );
$block = function ( $reply, $options, $url ) { return 0 === strpos( $url, Sunrise\agent_url() . '/v1/' ) ? $reply : new WP_Error( 'fixture_offline', 'External providers blocked during test' ); };
add_filter( 'pre_http_request', $block, 10, 3 );
try {
 if ( 'prepare' === $phase ) {
  if ( $saved || file_exists( $dir ) || ! empty( Sunrise\agent_state()['remote_job'] ) || get_option( 'sunrise_remote_job_fence' ) ) { throw new RuntimeException( 'Fixture or execution already exists' ); }
  $zip_path = tempnam( sys_get_temp_dir(), 'sunrise-update-' );
  $saved = array( 'plugins' => get_site_transient( 'update_plugins' ), 'zip' => $zip_path );
  if ( ! add_option( 'sunrise_installation_job_fixture', $saved, '', false ) ) { throw new RuntimeException( 'Could not preserve fixture state' ); }
  mkdir( $dir ); file_put_contents( $dir . '/fixture.php', "<?php\n/*\nPlugin Name: Sunrise Remote Update Fixture\nVersion: 1.0.0\n*/\n" );
  $zip = new ZipArchive(); $zip->open( $zip_path, ZipArchive::OVERWRITE );
  $zip->addFromString( 'sunrise-test-remote-update/fixture.php', "<?php\n/*\nPlugin Name: Sunrise Remote Update Fixture\nVersion: 2.0.0\n*/\n" ); $zip->close();
  wp_clean_plugins_cache( false ); $checked = array(); foreach ( get_plugins() as $path => $plugin ) { $checked[ $path ] = $plugin['Version']; }
  set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'checked' => $checked, 'response' => array( $id => (object) array( 'plugin' => $id, 'slug' => dirname( $id ), 'new_version' => '2.0.0', 'package' => 'https://sunrise.invalid/remote-fixture.zip', 'requires_php' => '7.4', 'requires' => '6.6' ) ), 'no_update' => array() ) );
  // First sync negotiates offered-version support; the next reports the fixture offer.
  for ( $i = 0; $i < 2; ++$i ) { $result = Sunrise\agent_sync(); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); } }
  echo wp_json_encode( array( 'prepared' => true, 'site_id' => Sunrise\agent_state()['site_id'], 'installed_id' => $id ) );
 } elseif ( 'run' === $phase ) {
  if ( ! $saved ) { throw new RuntimeException( 'Prepare first' ); }
  $downloads = 0; $during_sequence = 0;
  $download = function ( $reply, $package ) use ( $saved, &$downloads, &$during_sequence ) {
   if ( 'https://sunrise.invalid/remote-fixture.zip' !== $package ) { return new WP_Error( 'unexpected_package', 'Only the synthetic package may install' ); }
   // A report may run while filesystem work is slow; finishing the job must retain its newer transport sequence.
   $report = Sunrise\agent_check_in(); if ( is_wp_error( $report ) ) { throw new RuntimeException( 'Concurrent inventory report failed' ); }
   $during_sequence = $report['receipt_sequence'];
   ++$downloads; $copy = tempnam( sys_get_temp_dir(), 'sunrise-install-' ); copy( $saved['zip'], $copy ); return $copy;
  };
  add_filter( 'upgrader_pre_download', $download, 10, 2 );
  try {
   $result = Sunrise\agent_sync(); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); }
   wp_clean_plugins_cache( false ); $installed = get_plugins();
   if ( '2.0.0' !== $installed[ $id ]['Version'] || 1 !== $downloads || Sunrise\agent_state()['sequence'] <= $during_sequence || get_option( 'sunrise_remote_job_fence' ) || ! empty( Sunrise\agent_state()['remote_job'] ) ) { throw new RuntimeException( 'Installation did not finish cleanly or rewound a concurrent report' ); }
   $result = Sunrise\agent_sync(); if ( is_wp_error( $result ) || 1 !== $downloads ) { throw new RuntimeException( 'A repeat sync repeated the installation' ); }
   echo wp_json_encode( array( 'installed' => '2.0.0', 'downloads' => $downloads, 'duplicate_suppressed' => true ) );
  } finally { remove_filter( 'upgrader_pre_download', $download ); }
 } elseif ( 'cleanup' === $phase ) {
  if ( get_option( 'sunrise_remote_job_fence' ) || ! empty( Sunrise\agent_state()['remote_job'] ) ) { throw new RuntimeException( 'Resolve the outstanding job before fixture cleanup' ); }
  if ( $saved ) {
   if ( file_exists( $dir . '/fixture.php' ) ) { unlink( $dir . '/fixture.php' ); } if ( is_dir( $dir ) ) { rmdir( $dir ); }
   if ( is_file( $saved['zip'] ) ) { unlink( $saved['zip'] ); }
   if ( false === $saved['plugins'] ) { delete_site_transient( 'update_plugins' ); } else { set_site_transient( 'update_plugins', $saved['plugins'] ); }
   delete_option( 'sunrise_installation_job_fixture' ); wp_clean_plugins_cache( false );
   $result = Sunrise\agent_sync(); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); }
  }
  echo wp_json_encode( array( 'cleaned' => true ) );
 } else { throw new RuntimeException( 'Unknown fixture phase' ); }
} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
finally { remove_filter( 'pre_http_request', $block ); }
