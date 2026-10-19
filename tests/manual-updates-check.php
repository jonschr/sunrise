<?php
/** Run through WP-CLI as an administrator on a disposable local site. Every HTTP request is intercepted. */
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
set_current_screen( 'update' );
$GLOBALS['pagenow'] = 'update.php'; $_REQUEST['action'] = 'upgrade-plugin';
$buffer_level = ob_get_level();
$quiet = function () use ( $buffer_level ) { while ( ob_get_level() > $buffer_level ) { ob_end_clean(); } };
$check = function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); };
$directory = WP_PLUGIN_DIR . '/sunrise-manual-fixture'; $id = 'sunrise-manual-fixture/plugin.php';
if ( file_exists( $directory ) ) { throw new RuntimeException( 'Fixture already exists.' ); }
$updates = get_site_transient( 'update_plugins' ); $theme_updates = get_site_transient( 'update_themes' );
$theme_dir = get_theme_root() . '/sunrise-manual-fixture'; if ( file_exists( $theme_dir ) ) { throw new RuntimeException( 'Theme fixture exists.' ); }
$created = array(); $zip = null; $mode = 'success';
$rows = function () { return Sunrise\manual_update_activity( 0 )[0]; };
$initial = $rows(); if ( $initial ) { throw new RuntimeException( 'Manual fixture journal must be empty.' ); }
$http = function ( $pre, $args, $url ) use ( $id ) {
 if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'plugins' => array( $id => array( 'plugin' => $id, 'slug' => 'sunrise-manual-fixture', 'new_version' => '2.0', 'package' => 'https://fixture.invalid/manual.zip' ) ), 'no_update' => array(), 'translations' => array() ) ) ); }
 return new WP_Error( 'fixture_http_blocked', 'No external requests permitted' );
};
add_filter( 'pre_http_request', $http, 10, 3 );
$download = function ( $reply, $package ) use ( &$zip, &$mode ) {
 if ( 'https://fixture.invalid/manual.zip' !== $package ) { return new WP_Error( 'fixture_download_blocked', 'No unexpected packages' ); }
 if ( 'download' === $mode ) { return new WP_Error( 'http_request_failed', 'HTTP 403 Forbidden: private-token' ); }
 if ( 'interrupt' === $mode ) { throw new RuntimeException( 'Simulated interrupted installer' ); }
 $copy = wp_tempnam( 'manual.zip' ); copy( $zip, $copy ); return $copy;
};
add_filter( 'upgrader_pre_download', $download, 20, 2 );
$finish = function () { foreach ( array_keys( $GLOBALS['sunrise_manual_updates'] ?? array() ) as $key ) { Sunrise\manual_update_finish( $key ); } };
$reset = function ( $type = 'plugin', $version = '2.0' ) use ( &$zip, $directory, $theme_dir, $id ) {
 if ( ! is_dir( $directory ) ) { mkdir( $directory ); } if ( ! is_dir( $theme_dir ) ) { mkdir( $theme_dir ); }
 file_put_contents( $directory . '/plugin.php', "<?php\n/* Plugin Name: Manual fixture\nVersion: 1.0\n*/\n" );
 file_put_contents( $theme_dir . '/style.css', "/* Theme Name: Manual fixture\nVersion: 1.0\n*/\n" );
 wp_clean_plugins_cache( false ); wp_clean_themes_cache( false );
 $offer = array( 'new_version' => $version, 'package' => 'https://fixture.invalid/manual.zip', 'url' => 'https://fixture.invalid/', 'slug' => 'sunrise-manual-fixture', 'plugin' => $id );
 set_site_transient( 'update_plugins', (object) array( 'response' => array( $id => (object) $offer ), 'no_update' => array(), 'last_checked' => time(), 'checked' => array( $id => '1.0' ) ) );
 set_site_transient( 'update_themes', (object) array( 'response' => array( 'sunrise-manual-fixture' => $offer ), 'no_update' => array(), 'last_checked' => time() ) );
 if ( $zip ) { unlink( $zip ); } $zip = wp_tempnam( 'manual-package.zip' ); $archive = new ZipArchive(); $archive->open( $zip, ZipArchive::OVERWRITE );
 $archive->addFromString( 'sunrise-manual-fixture/' . ( 'plugin' === $type ? 'plugin.php' : 'style.css' ), 'plugin' === $type ? "<?php\n/* Plugin Name: Manual fixture\nVersion: $version\n*/\n" : "/* Theme Name: Manual fixture\nVersion: $version\n*/\n" ); $archive->close();
};
try {
 require_once dirname( __DIR__ ) . '/includes/execution-lock.php';
 $reset(); $held = Sunrise\agent_execution_lock(); $check( ! is_wp_error( $held ), 'A running Sunrise worker owns the shared file lock' );
 try {
  foreach ( array( 'plugin', 'theme', 'bulk', 'core' ) as $kind ) {
   $GLOBALS['pagenow'] = 'update.php'; $_REQUEST['action'] = 'plugin' === $kind ? 'upgrade-plugin' : ( 'theme' === $kind ? 'upgrade-theme' : 'update-selected' );
   ob_start();
   if ( 'core' === $kind ) {
    $GLOBALS['pagenow'] = 'update-core.php'; $_REQUEST['action'] = 'do-core-upgrade';
    ( new Core_Upgrader() )->upgrade( (object) array( 'response' => 'upgrade', 'current' => '99.0', 'packages' => (object) array( 'rollback' => false, 'partial' => false, 'new_bundled' => false, 'no_content' => false, 'full' => 'https://fixture.invalid/manual.zip' ) ), array( 'pre_check_md5' => false ) );
   } elseif ( 'theme' === $kind ) { ( new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() ) )->upgrade( 'sunrise-manual-fixture' ); }
   elseif ( 'bulk' === $kind ) { ( new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ) )->bulk_upgrade( array( $id ) ); }
   else { ( new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ) )->upgrade( $id ); }
   $quiet(); $finish();
   $check( '1.0' === get_plugin_data( $directory . '/plugin.php', false, false )['Version'] && '1.0' === wp_get_theme( 'sunrise-manual-fixture' )->get( 'Version' ) && ! $rows(), 'Native ' . $kind . ' update defers without file changes or failed-attempt guards while Sunrise is installing' );
  }
 } finally { flock( $held, LOCK_UN ); fclose( $held ); }
 $GLOBALS['pagenow'] = 'update.php'; $_REQUEST['action'] = 'upgrade-plugin';
 $reset(); $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ); ob_start(); $upgrader->upgrade( $id ); $quiet(); $finish();
 $contending = Sunrise\agent_execution_lock(); $check( is_wp_error( $contending ), 'A manual installation retains the process lock through bulk completion and rollback' );
 $events = $rows(); $check( 1 === count( $events ) && 'succeeded' === $events[0]['status'] && '2.0' === get_plugin_data( $directory . '/plugin.php', false, false )['Version'], 'Actual dashboard plugin installation is reported once as manual success' );
 list( $first, $cursor ) = Sunrise\manual_update_activity(); $check( array() === Sunrise\manual_update_activity( $cursor )[0] && $first === $rows(), 'Independent cursors acknowledge without consuming another account journal' );
 $reset(); $mode = 'download'; $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ); ob_start(); $upgrader->upgrade( $id ); $quiet(); $finish();
 $events = $rows(); $last = end( $events ); $check( 'failed' === $last['status'] && 'provider_forbidden' === $last['code'] && false === strpos( wp_json_encode( $events ), 'private-token' ), 'An early download failure is captured without provider messages or secrets' );
 require_once dirname( __DIR__ ) . '/includes/hourly-updates.php';
 $check( ! Sunrise\hourly_update_allowed( true, (object) array( 'plugin' => $id, 'new_version' => '2.0' ), 'plugin' ) && Sunrise\hourly_update_allowed( true, (object) array( 'plugin' => $id, 'new_version' => '2.1' ), 'plugin' ), 'Manual failures exclude only that version from bonus passes; newer releases remain eligible' );
 $reset( 'theme' ); $mode = 'success'; $upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() ); ob_start(); $upgrader->upgrade( 'sunrise-manual-fixture' ); $quiet(); $finish();
 $events = $rows(); $last = end( $events ); $check( 'theme' === $last['type'] && 'succeeded' === $last['status'], 'Actual manual theme installation is reported' );
 $reset(); $mode = 'interrupt'; try { ob_start(); ( new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ) )->upgrade( $id ); } catch ( RuntimeException $error ) { } finally { $quiet(); } $finish();
 $events = $rows(); $last = end( $events ); $check( 'closed_unverified' === $last['status'], 'An interrupted operation is unverified, not invented failure or success' );
 $before = count( $events ); $reset(); $mode = 'success'; ob_start(); ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $id ); $quiet(); $finish();
 $check( count( $rows() ) === $before, 'Native automatic skins never become manual events' );
 $reset(); require_once dirname( __DIR__ ) . '/includes/jobs.php'; ob_start(); $native_result = Sunrise\install_update( array( 'type' => 'plugin', 'id' => $id, 'version' => '2.0' ) ); $quiet(); $finish();
 $check( is_array( $native_result ) && 'updated' === $native_result['code'] && count( $rows() ) === $before, 'Sunrise explicit installations never become manual events' );
 $reset(); $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ); ob_start(); $upgrader->bulk_upgrade( array( $id, $id ) ); $quiet(); $finish();
 $events = $rows(); $check( count( $events ) === $before + 2 && 'succeeded' === $events[$before]['status'] && 'unchanged' === end( $events )['status'], 'Native bulk installation has one event per attempted component' );
 $GLOBALS['pagenow'] = 'update-core.php'; $_REQUEST['action'] = 'do-core-upgrade';
 $core_offer = (object) array( 'response' => 'upgrade', 'current' => '99.0', 'packages' => (object) array( 'rollback' => false, 'partial' => false, 'new_bundled' => false, 'no_content' => false, 'full' => 'https://fixture.invalid/manual.zip' ) );
 $mode = 'download'; ob_start(); ( new Core_Upgrader() )->upgrade( $core_offer, array( 'pre_check_md5' => false ) ); $quiet(); $finish();
 $events = $rows(); $last = end( $events ); $check( 'core' === $last['type'] && 'failed' === $last['status'] && 'provider_forbidden' === $last['code'], 'Core download failures are recorded without touching core files' );
 $GLOBALS['pagenow'] = 'plugins.php'; $_REQUEST['action'] = 'none'; $reset(); $mode = 'success'; $prior_count = count( $rows() );
 ob_start(); ( new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ) )->upgrade( $id ); $quiet(); $finish();
 $check( count( $rows() ) === $prior_count, 'Unrelated admin requests or third-party background tools do not establish a manual source' );
 $pending = array( 'id' => wp_generate_uuid4(), 'type' => 'plugin', 'installed_id' => $id, 'version' => '3.0', 'status' => 'pending', 'code' => 'execution_interrupted', 'updated_at' => time() - 7200 ); add_option( 'sunrise_manual_update_' . $pending['id'], $pending, '', false );
 $events = $rows(); $last = end( $events ); $check( 'closed_unverified' === $last['status'], 'An intent left by a killed process is retained as unverified' );
 $last['id'] = wp_generate_uuid4(); $last['updated_at'] = time() - 91 * DAY_IN_SECONDS; add_option( 'sunrise_manual_update_' . $last['id'], $last, '', false ); $rows(); $check( false === get_option( 'sunrise_manual_update_' . $last['id'] ), 'Journal cleanup is bounded to 90 days' );
 WP_CLI::success( 'Manual native update telemetry checks passed.' );
} finally {
 Sunrise\native_update_execution_unlock();
 $GLOBALS['sunrise_manual_updates'] = array();
 remove_filter( 'pre_http_request', $http ); remove_filter( 'upgrader_pre_download', $download, 20 );
 global $wpdb; foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sunrise\\_manual\\_%'" ) as $name ) { delete_option( $name ); }
 if ( false === $updates ) { delete_site_transient( 'update_plugins' ); } else { set_site_transient( 'update_plugins', $updates ); }
 if ( false === $theme_updates ) { delete_site_transient( 'update_themes' ); } else { set_site_transient( 'update_themes', $theme_updates ); }
 WP_Filesystem(); global $wp_filesystem; foreach ( array( $directory, $theme_dir ) as $dir ) { if ( is_dir( $dir ) ) { $wp_filesystem->delete( $dir, true ); } }
 if ( $zip && file_exists( $zip ) ) { unlink( $zip ); }
}
