<?php
/** Offline GitHub fixtures; never downloads or installs a package. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
$checker = Sunrise\plugin_update_checker();
$saved = get_site_option( 'external_updates-sunrise', null );
$calls = array(); $mode = 'release';
$asset = 'https://github.com/jonschr/sunrise/releases/download/v0.3.0/sunrise.zip';
$transport = function ( $pre, $options, $url ) use ( &$calls, &$mode, $asset ) {
 $calls[] = $url;
 if ( 'api.github.com' !== wp_parse_url( $url, PHP_URL_HOST ) || 0 !== strpos( wp_parse_url( $url, PHP_URL_PATH ), '/repos/jonschr/sunrise/' ) ) { throw new RuntimeException( 'Unexpected host or repository' ); }
 if ( isset( $options['headers']['Authorization'] ) ) { throw new RuntimeException( 'A public update must not send an authorization token' ); }
 $path = wp_parse_url( $url, PHP_URL_PATH );
 if ( '/repos/jonschr/sunrise/releases/latest' === $path ) {
  if ( 'offline' === $mode ) { return new WP_Error( 'fixture_offline', 'GitHub unavailable' ); }
  $body = array( 'tag_name' => 'v0.3.0', 'created_at' => '2026-09-09T00:00:00Z', 'draft' => 'draft' === $mode, 'prerelease' => 'prerelease' === $mode,
   'zipball_url' => 'https://api.github.com/repos/jonschr/sunrise/zipball/v0.3.0', 'body' => 'Release notes',
   'assets' => 'missing_asset' === $mode ? array() : array( array( 'name' => 'sunrise.zip', 'browser_download_url' => $asset, 'download_count' => 0 ) ) );
 } elseif ( '/repos/jonschr/sunrise/contents/sunrise.php' === $path ) {
  if ( false === strpos( $url, 'ref=v0.3.0' ) ) { throw new RuntimeException( 'Metadata must come from the release tag' ); }
  $body = array( 'encoding' => 'base64', 'content' => base64_encode( "<?php\n/**\n * Plugin Name: Sunrise\n * Version: 0.3.0\n * Requires at least: 6.6\n * Requires PHP: 7.4\n */" ) );
 } elseif ( '/repos/jonschr/sunrise/contents/readme.txt' === $path ) {
  $body = array( 'encoding' => 'base64', 'content' => base64_encode( "=== Sunrise ===\nRequires at least: 6.6\nRequires PHP: 7.4\nStable tag: 0.3.0\n\n== Description ==\nFixture" ) );
 } else { throw new RuntimeException( 'Unexpected branch/tag fallback or request: ' . $path ); }
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
 sunrise_update_assert( $update && '0.3.0' === $update->version && $asset === $update->download_url, 'Published release offers its exact installable asset and tagged metadata' );
 $native = apply_filters( 'site_transient_update_plugins', $collision );
 sunrise_update_assert( $asset === $native->response['sunrise/sunrise.php']->package && '0.3.0' === $native->response['sunrise/sunrise.php']->new_version, 'Own update survives collision protection in the native WordPress update list' );
 sunrise_update_assert( ! empty( $native->response['sunrise/sunrise.php']->icons['svg'] ) && false !== strpos( $native->response['sunrise/sunrise.php']->icons['svg'], '/assets/icon.svg' ), 'Sunrise mark is exposed as the plugin update icon' );
 $before = count( $calls ); apply_filters( 'site_transient_update_plugins', $collision );
 sunrise_update_assert( count( $calls ) === $before, 'Reading cached inventory performs no additional GitHub requests' );
 foreach ( array( 'missing_asset', 'draft', 'prerelease', 'offline' ) as $mode ) {
  $calls = array(); $checker->resetUpdateState();
  sunrise_update_assert( null === $checker->checkForUpdates() && 1 === count( $calls ), $mode . ' produces no update and never falls back to a branch or source ZIP' );
 }
} finally {
 remove_filter( 'pre_http_request', $transport ); $checker->resetUpdateState();
 if ( null !== $saved ) { update_site_option( 'external_updates-sunrise', $saved ); }
}
