<?php
/** Run with wp --user=<owner> eval-file on a disposable local site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local test administrator required.' ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
function sunrise_discovery_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$plugins = get_plugins(); $id = plugin_basename( dirname( __DIR__ ) . '/sunrise.php' );
if ( ! isset( $plugins[ $id ] ) ) { WP_CLI::error( 'Sunrise must be installed on the disposable site.' ); }
$version = $plugins[ $id ]['Version']; $option = 'external_updates-sunrise-test'; $saved = get_site_option( $option, null );
try {
	update_site_option( $option, (object) array( 'checkedVersion' => $version, 'update' => (object) array( 'filename' => $id, 'slug' => dirname( $id ), 'version' => '999.0.0', 'download_url' => 'https://example.invalid/plugin.zip' ) ) );
	$merged = Sunrise\agent_puc_update_offers( (object) array( 'response' => array(), 'no_update' => array( $id => (object) array() ) ) );
	sunrise_discovery_assert( isset( $merged->response[ $id ] ) && '999.0.0' === $merged->response[ $id ]->new_version && 'https://example.invalid/plugin.zip' === $merged->response[ $id ]->package && ! isset( $merged->no_update[ $id ] ), 'Five-minute inventory mirrors a standard Plugin Update Checker cache entry' );
	$live = (object) array( 'plugin' => $id, 'new_version' => '999.0.1' ); $merged = Sunrise\agent_puc_update_offers( (object) array( 'response' => array( $id => $live ) ) );
	sunrise_discovery_assert( '999.0.1' === $merged->response[ $id ]->new_version, 'A live native offer outranks the cached fallback' );
} finally {
	if ( null === $saved ) { delete_site_option( $option ); } else { update_site_option( $option, $saved ); }
}
