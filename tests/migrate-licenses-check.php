<?php
/** php tests/migrate-licenses-check.php /absolute/disposable/wordpress/root [constant]. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) ) { throw new RuntimeException( 'Disposable WordPress root required.' ); }
define( 'DOING_CRON', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
if ( isset( $argv[2] ) && 'constant' === $argv[2] ) { define( 'WPMDB_LICENCE', 'server-fixed-key' ); }
require rtrim( $argv[1], '/' ) . '/wp-load.php';
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || defined( 'WP_CLI' ) || is_admin() ) { throw new RuntimeException( 'Isolated local cron fixture required.' ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Fixture administrator required.' ); }
require_once WP_PLUGIN_DIR . '/wp-migrate-db-pro/wp-migrate-db-pro.php';
$check = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$settings = get_site_option( 'wpmdb_settings', null ); $user_key = get_user_meta( 1, 'wpmdb_licence_key', true );
$transients = array( 'wpmdb_upgrade_data', 'wpmdb_licence_response', 'wpmdb_licence_response_1', 'wpmdb_available_addons_per_user_1', 'wpmdb_dbrains_api_down' );
$saved = array(); foreach ( $transients as $name ) { $saved[ $name ] = get_site_transient( $name ); delete_site_transient( $name ); }
$requests = array(); $mode = 'success';
$mock = function ( $pre, $args, $url ) use ( &$requests, &$mode ) {
 parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?: '', $query );
 if ( empty( $query['request'] ) ) { return new WP_Error( 'sunrise_fixture_blocked', 'Unexpected HTTP blocked.' ); }
 $requests[] = $query;
 $data = array( 'success' => true, 'addons_available_list' => array( 'media-files' ) );
 if ( 'reactivate' === $mode && 'activate_licence' === $query['request'] ) { $data = array( 'errors' => array( 'activation_deactivated' => 'Inactive' ) ); }
 elseif ( 'invalid' === $mode ) { $data = array( 'errors' => array( 'licence_not_found' => 'Invalid' ) ); }
 elseif ( 'temporary' === $mode ) { $data = array( 'errors' => array( 'connection_failed' => 'Offline' ) ); }
 elseif ( 'down' === $mode ) { $data = array( 'dbrains_api_down' => 'Unavailable' ); }
 $body = 'malformed' === $mode ? '<html>Unavailable</html>' : wp_json_encode( $data );
 return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
 update_site_option( 'wpmdb_settings', array( 'licence' => 'old-key', 'sunrise_fixture_keep' => 'unchanged' ) );
 update_user_meta( 1, 'wpmdb_licence_key', 'old-user-key' );
 if ( defined( 'WPMDB_LICENCE' ) ) {
  $result = Sunrise\premium_license_activate( 'wp-migrate-db-pro', 'different-key' );
  $check( is_wp_error( $result ) && 'sunrise_premium_license_invalid' === $result->get_error_code() && ! $requests, 'A configured constant must be respected before contacting the vendor.' );
  $check( 'old-user-key' === get_user_meta( 1, 'wpmdb_licence_key', true ), 'Constant conflict changed the stored credential.' );
 } else {
  $key = 'WP-Migrate-Test_Key+123';
  set_site_transient( 'wpmdb_upgrade_data', array( 'stale' => true ), HOUR_IN_SECONDS );
  $check( true === Sunrise\premium_license_activate( 'wp-migrate-db-pro', ' ' . $key . ' ' ), 'Native WP Migrate activation failed.' );
  $check( array( 'activate_licence', 'check_support_access' ) === array_column( $requests, 'request' ), 'Native activation and license refresh were not used.' );
  $check( $key === $requests[0]['licence_key'], 'License case or punctuation changed.' );
  $check( untrailingslashit( network_home_url( '', 'http' ) ) === $requests[0]['site_url'], 'Native site identity changed.' );
  $check( $key === get_user_meta( 1, 'wpmdb_licence_key', true ) && $key === get_site_option( 'wpmdb_settings' )['licence'], 'User and cron-compatible global native storage were not updated.' );
  $check( 'unchanged' === get_site_option( 'wpmdb_settings' )['sunrise_fixture_keep'], 'Unrelated WP Migrate settings changed.' );
  $check( false === get_site_transient( 'wpmdb_upgrade_data' ), 'Stale update data survived activation.' );
  $check( $key === DeliciousBrains\WPMDB\Pro\License::get_license(), 'Native download credential remained stale.' );
  $before = count( $requests ); $check( Sunrise\premium_license_valid( 'wp-migrate-db-pro' ) && $before === count( $requests ), 'Routine validity checks must use cached state without vendor requests.' );
  $mode = 'reactivate'; $requests = array();
  $check( true === Sunrise\premium_license_activate( 'wp-migrate-db-pro', $key ), 'Native reactivation failed.' );
  $check( array( 'activate_licence', 'reactivate_licence', 'check_support_access' ) === array_column( $requests, 'request' ), 'Deactivated sites did not use native reactivation.' );
  foreach ( array( 'invalid' => 'invalid', 'temporary' => 'temporary', 'down' => 'temporary', 'malformed' => 'temporary' ) as $scenario => $expected ) {
   $mode = $scenario; $result = Sunrise\premium_license_activate( 'wp-migrate-db-pro', 'rejected-key' );
   $check( is_wp_error( $result ) && 'sunrise_premium_license_' . $expected === $result->get_error_code(), 'Incorrect failure classification: ' . $scenario );
   $check( $key === get_site_option( 'wpmdb_settings' )['licence'], 'A rejected activation replaced the working credential.' );
  }
  set_site_transient( 'wpmdb_licence_response_1', wp_json_encode( array( 'errors' => array( 'subscription_expired' => 'Expired' ) ) ) );
  $check( ! Sunrise\premium_license_valid( 'wp-migrate-db-pro' ), 'Expired licenses must not count as update-entitled.' );
 }
 echo 'PASS: WP Migrate ' . get_plugins()['wp-migrate-db-pro/wp-migrate-db-pro.php']['Version'] . ' native license activation, reactivation, cron state, failures, and configuration guard.' . "\n";
} finally {
 remove_filter( 'pre_http_request', $mock, 10 );
 null === $settings ? delete_site_option( 'wpmdb_settings' ) : update_site_option( 'wpmdb_settings', $settings );
 '' === $user_key ? delete_user_meta( 1, 'wpmdb_licence_key' ) : update_user_meta( 1, 'wpmdb_licence_key', $user_key );
 foreach ( $saved as $name => $value ) { false === $value ? delete_site_transient( $name ) : set_site_transient( $name, $value ); }
}
