<?php
/** Offline static metadata fixtures; never downloads or installs a package. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
$checker = Sunrise\plugin_update_checker();
$saved = get_site_option( 'external_updates-sunrise', null );
$calls = array(); $mode = 'release'; $offered = '999.0.0';
$asset = 'https://github.com/jonschr/sunrise/releases/download/v' . $offered . '/sunrise.zip';
$transport = function ( $pre, $options, $url ) use ( &$calls, &$mode, $asset, $offered ) {
 $calls[] = $url;
 if ( 'raw.githubusercontent.com' !== wp_parse_url( $url, PHP_URL_HOST ) || '/jonschr/sunrise/main/update.json' !== wp_parse_url( $url, PHP_URL_PATH ) ) { throw new RuntimeException( 'Unexpected metadata source' ); }
 if ( isset( $options['headers']['Authorization'] ) ) { throw new RuntimeException( 'A public update must not send an authorization token' ); }
 if ( 'offline' === $mode ) { return new WP_Error( 'fixture_offline', 'Metadata unavailable' ); }
 $body = array( 'name' => 'invalid_name' === $mode ? 'Other' : 'Sunrise', 'version' => $offered, 'download_url' => 'invalid_package' === $mode ? 'https://example.invalid/plugin.zip' : $asset, 'icons' => array( 'svg' => 'https://raw.githubusercontent.com/jonschr/sunrise/main/assets/icon.svg' ) );
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
};
function sunrise_update_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
add_filter( 'pre_http_request', $transport, 10, 3 );
try {
 $checker->resetUpdateState();
 $collision = (object) array( 'response' => array( 'sunrise/sunrise.php' => (object) array( 'new_version' => '99.0', 'package' => 'https://downloads.wordpress.org/plugin/sunrise.99.zip' ) ), 'no_update' => array() );
 $native = apply_filters( 'site_transient_update_plugins', $collision );
 sunrise_update_assert( empty( $native->response['sunrise/sunrise.php'] ) && ! $calls, 'Cached WordPress.org collision is removed without a network request' );
 $update = $checker->checkForUpdates();
 sunrise_update_assert( $update && $offered === $update->version && $asset === $update->download_url, 'Static metadata offers its exact matching release asset' );
 $before = count( $calls ); Sunrise\plugin_update_check(); sunrise_update_assert( count( $calls ) === $before, 'Automated reports reuse Sunrise metadata for one hour' );
 Sunrise\plugin_update_check( true ); sunrise_update_assert( count( $calls ) === $before + 1, 'Requested refresh forces a Sunrise metadata check' );
 $native = apply_filters( 'site_transient_update_plugins', $collision );
 sunrise_update_assert( $asset === $native->response['sunrise/sunrise.php']->package && $offered === $native->response['sunrise/sunrise.php']->new_version, 'Own update survives collision protection in the native WordPress update list' );
 require_once WP_PLUGIN_DIR . '/sunrise/includes/api.php';
 $reported = array_values( array_filter( Sunrise\inventory()['plugins'], function ( $item ) { return 'sunrise/sunrise.php' === $item['id']; } ) );
 sunrise_update_assert( 1 === count( $reported ) && true === $reported[0]['update_available'] && $offered === $reported[0]['update']['version'], 'Automated inventory reports the same Sunrise offer shown by WordPress' );
 sunrise_update_assert( ! empty( $native->response['sunrise/sunrise.php']->icons['svg'] ) && false !== strpos( $native->response['sunrise/sunrise.php']->icons['svg'], '/assets/icon.svg' ), 'Sunrise mark is exposed as the plugin update icon' );
 $force_off = function () { return false; };
 add_filter( 'auto_update_plugin', $force_off, 19 );
 $development = is_link( WP_PLUGIN_DIR . '/sunrise' ) || file_exists( WP_PLUGIN_DIR . '/sunrise/.git' );
 sunrise_update_assert( ! $development === apply_filters( 'auto_update_plugin', false, $native->response['sunrise/sunrise.php'] ), 'Packaged Sunrise self-updates despite disabled policies; development copies stay protected' );
 sunrise_update_assert( ! apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => 'other/other.php' ) ), 'Self-update selection does not enable other plugins' );
 $blocked = clone $native->response['sunrise/sunrise.php']; $blocked->disable_autoupdate = true;
 sunrise_update_assert( ! apply_filters( 'auto_update_plugin', true, $blocked ), 'Provider-disabled Sunrise offers remain disabled' );
 add_filter( 'plugins_auto_update_enabled', $force_off );
 sunrise_update_assert( ! apply_filters( 'auto_update_plugin', true, $native->response['sunrise/sunrise.php'] ), 'Host-disabled native plugin updates remain disabled' );
 remove_filter( 'plugins_auto_update_enabled', $force_off ); remove_filter( 'auto_update_plugin', $force_off, 19 );
 $before = count( $calls ); apply_filters( 'site_transient_update_plugins', $collision );
 sunrise_update_assert( count( $calls ) === $before, 'Reading cached inventory performs no additional metadata requests' );
 foreach ( array( 'invalid_package', 'invalid_name', 'offline' ) as $mode ) {
  $calls = array(); $checker->resetUpdateState();
  sunrise_update_assert( null === $checker->checkForUpdates() && 1 === count( $calls ), $mode . ' produces no update' );
 }
} finally {
 remove_filter( 'pre_http_request', $transport ); $checker->resetUpdateState();
 if ( null !== $saved ) { update_site_option( 'external_updates-sunrise', $saved ); }
}
