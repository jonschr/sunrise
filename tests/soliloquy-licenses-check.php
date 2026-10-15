<?php
/** php tests/soliloquy-licenses-check.php /absolute/disposable/wordpress/root [constant]. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) ) { throw new RuntimeException( 'Disposable WordPress root required.' ); }
define( 'DOING_CRON', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true );
if ( isset( $argv[2] ) && 'constant' === $argv[2] ) { define( 'SOLILOQUY_LICENSE_KEY', 'server-fixed-key' ); }
require rtrim( $argv[1], '/' ) . '/wp-load.php';
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || is_admin() ) { throw new RuntimeException( 'Isolated local cron fixture required.' ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Fixture administrator required.' ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$check = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$names = array( 'soliloquy', 'soliloquy_license_updates' ); $saved = array(); foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$addon = get_transient( '_sol_addons' ); $updates = get_site_transient( 'update_plugins' );
$requests = array(); $mode = 'success';
$mock = function ( $pre, $args, $url ) use ( &$requests, &$mode ) {
 $body = $args['body'] ?? array(); if ( is_string( $body ) ) { parse_str( $body, $body ); }
 if ( ! isset( $body['tgm-updater-action'] ) ) { return new WP_Error( 'sunrise_fixture_blocked', 'Unexpected HTTP blocked.' ); }
 $requests[] = array( 'url' => $url, 'body' => $body, 'redirects' => $args['redirection'] );
 if ( 'temporary' === $mode ) { return new WP_Error( 'offline', 'Unavailable' ); }
 $data = array( 'success' => 'Activated', 'type' => 'developer' );
 if ( 'get-plugin-update' === $body['tgm-updater-action'] ) { $data = array( 'new_version' => '9.9.9', 'slug' => 'soliloquy', 'package' => 'https://soliloquywp.com/fixture.zip' ); }
 elseif ( 'invalid' === $mode ) { $data = array( 'error' => 'Invalid key' ); }
 elseif ( in_array( $mode, array( 'expired', 'disabled', 'key', 'author' ), true ) ) { $data = array( $mode => 'Unavailable' ); }
 elseif ( 'empty' === $mode ) { $data = new stdClass(); }
 return array( 'headers' => array(), 'body' => 'malformed' === $mode ? '<html>Unavailable</html>' : wp_json_encode( $data ), 'response' => array( 'code' => 'http' === $mode ? 503 : 200, 'message' => 'Fixture' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
 require_once WP_PLUGIN_DIR . '/soliloquy/soliloquy.php';
 update_option( 'soliloquy', array( 'key' => 'old-key', 'type' => 'old-type', 'is_expired' => true, 'is_disabled' => true, 'is_invalid' => true, 'fixture_keep' => 'unchanged' ) );
 if ( defined( 'SOLILOQUY_LICENSE_KEY' ) ) {
  $result = Sunrise\premium_license_activate( 'soliloquy', 'different-key' );
  $check( is_wp_error( $result ) && 'sunrise_premium_license_invalid' === $result->get_error_code() && ! $requests, 'Configured constant must be respected before vendor contact.' );
  $check( 'old-key' === get_option( 'soliloquy' )['key'], 'Constant conflict replaced native storage.' );
 } else {
  $key = 'Soliloquy-Test_Key+123'; set_transient( '_sol_addons', array( 'stale' => true ), HOUR_IN_SECONDS );
  $check( true === Sunrise\premium_license_activate( 'soliloquy', ' ' . $key . ' ' ), 'Activation failed.' );
  $check( count( $requests ) === 1 && 'https://soliloquywp.com/' === $requests[0]['url'] && 0 === $requests[0]['redirects'], 'Activation must use HTTPS without redirects, including older versions.' );
  $check( $requests[0]['body'] === array( 'tgm-updater-action' => 'verify-key', 'tgm-updater-key' => $key, 'tgm-updater-wp-version' => get_bloginfo( 'version' ), 'tgm-updater-referer' => site_url() ), 'Native verification protocol or key formatting changed.' );
  $option = get_option( 'soliloquy' ); $base = Soliloquy::get_instance();
  $check( $base->get_license_key() === $key && $base->get_license_key_type() === 'developer' && ! $base->get_license_key_errors() && $option['fixture_keep'] === 'unchanged', 'Native license state or unrelated settings were not preserved.' );
  $check( get_option( 'soliloquy_license_updates' ) > time() && false === get_transient( '_sol_addons' ), 'Native license schedule or stale addon cache was not refreshed.' );
  $before = count( $requests ); $check( Sunrise\premium_license_valid( 'soliloquy' ) && count( $requests ) === $before, 'Routine validity check must be local.' );
  $updater = Sunrise\premium_license_soliloquy_provider(); Sunrise\agent_prepare_update_providers();
  $check( $updater === Sunrise\premium_license_soliloquy_provider(), 'Repeated provider loading created duplicate updater instances.' );
  $offer = $updater->update_plugins_filter( (object) array( 'response' => array(), 'checked' => array() ) );
  $check( '9.9.9' === $offer->response['soliloquy/soliloquy.php']->new_version && $requests[1]['body']['tgm-updater-key'] === $key && $requests[1]['url'] === 'https://soliloquywp.com/', 'Real native cron updater did not use the new key and HTTPS.' );
  $addonUpdater = new Soliloquy_Updater( array( 'plugin_name' => 'Fixture addon', 'plugin_slug' => 'soliloquy-fixture', 'plugin_path' => 'soliloquy-fixture/fixture.php', 'plugin_url' => plugins_url(), 'remote_url' => 'https://soliloquywp.com/', 'version' => '1.0', 'key' => 'addon-key' ) );
  $replacement = 'Replacement-Key_456'; $check( true === Sunrise\premium_license_activate( 'soliloquy', $replacement ), 'Replacing a key failed.' );
  $check( Sunrise\premium_license_soliloquy_provider()->key === $replacement, 'Native updater retained the previous key.' );
  $count = 0; foreach ( $GLOBALS['wp_filter']['pre_set_site_transient_update_plugins']->callbacks as $callbacks ) { foreach ( $callbacks as $callback ) { if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Soliloquy_Updater && $callback['function'][0]->plugin_path === 'soliloquy/soliloquy.php' ) { $count++; } } }
  $check( $count === 1, 'Duplicate native updater hooks survived replacement.' );
  $check( false !== has_filter( 'pre_set_site_transient_update_plugins', array( $addonUpdater, 'update_plugins_filter' ) ), 'An unrelated addon updater was removed.' );
  foreach ( array( 'invalid' => 'invalid', 'expired' => 'invalid', 'disabled' => 'invalid', 'key' => 'invalid', 'author' => 'invalid', 'temporary' => 'temporary', 'http' => 'temporary', 'malformed' => 'temporary', 'empty' => 'temporary' ) as $scenario => $expected ) {
   $mode = $scenario; $result = Sunrise\premium_license_activate( 'soliloquy', 'rejected-key' );
   $check( is_wp_error( $result ) && 'sunrise_premium_license_' . $expected === $result->get_error_code(), 'Incorrect failure classification: ' . $scenario );
   $check( get_option( 'soliloquy' )['key'] === $replacement, 'Failed activation replaced the working key.' );
  }
  $filter = function () { return 'configured-by-filter'; }; add_filter( 'soliloquy_license_key', $filter ); $before = count( $requests );
  $result = Sunrise\premium_license_activate( 'soliloquy', 'different-key' ); remove_filter( 'soliloquy_license_key', $filter );
  $check( is_wp_error( $result ) && count( $requests ) === $before, 'License filter override was not respected.' );
  foreach ( array( 'is_expired', 'is_disabled', 'is_invalid' ) as $flag ) { $option = get_option( 'soliloquy' ); $option[$flag] = true; update_option( 'soliloquy', $option ); $check( ! Sunrise\premium_license_valid( 'soliloquy' ), 'License error flag ignored: ' . $flag ); $option[$flag] = false; update_option( 'soliloquy', $option ); }
 }
 echo 'PASS: Soliloquy ' . get_plugins()['soliloquy/soliloquy.php']['Version'] . ' secure verification, native state, cron updater, retries, failures, and configuration guards.' . "\n";
} finally {
 remove_filter( 'pre_http_request', $mock, 10 );
 foreach ( $saved as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value ); }
 false === $addon ? delete_transient( '_sol_addons' ) : set_transient( '_sol_addons', $addon );
 false === $updates ? delete_site_transient( 'update_plugins' ) : set_site_transient( 'update_plugins', $updates );
}
