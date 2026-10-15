<?php
/** php tests/migrate-updates-check.php /absolute/disposable/wordpress/root. Requires WP Migrate's files, not activation. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) ) { throw new RuntimeException( 'Disposable WordPress root required.' ); }
define( 'DOING_CRON', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WPMDB_LICENCE', 'sunrise_fixture_license' );
require rtrim( $argv[1], '/' ) . '/wp-load.php';
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || defined( 'WP_CLI' ) || is_admin() ) { throw new RuntimeException( 'Isolated local cron fixture required.' ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Fixture administrator required.' ); }
require_once WP_PLUGIN_DIR . '/wp-migrate-db-pro/wp-migrate-db-pro.php';
if ( false !== wp_migrate_db_pro_loaded() || ! empty( $GLOBALS['wpmdbpro'] ) ) { throw new RuntimeException( 'WP Migrate should skip its provider in cron before the fix.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/api.php';
$native = get_site_transient( 'update_plugins' );
$upgrade = get_site_transient( 'wpmdb_upgrade_data' );
$settings = get_site_option( 'wpmdb_settings', null );
$states = get_option( 'sunrise_agents', null );
$id = 'wp-migrate-db-pro/wp-migrate-db-pro.php';
$installed = get_plugins()[ $id ]['Version'];
$offered = '999.0.0';
try {
 set_site_transient( 'wpmdb_upgrade_data', array( 'wp-migrate-db-pro' => array( 'version' => $offered ) ), HOUR_IN_SECONDS );
 set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'checked' => array( $id => $installed ), 'response' => array(), 'no_update' => array() ) );
 update_option( 'sunrise_agents', array( 1 => array( 'user_id' => 1, 'update_jobs' => true, 'revoked' => true ) ), false );
 if ( isset( get_site_transient( 'update_plugins' )->response[ $id ] ) ) { throw new RuntimeException( 'Expected the original cron discrepancy.' ); }
 $inventory = Sunrise\inventory();
 $item = array_values( array_filter( $inventory['plugins'], function ( $item ) use ( $id ) { return $item['id'] === $id; } ) )[0];
 if ( $item['version'] !== $installed || true !== $item['update_available'] || $offered !== $item['update']['version'] ) { throw new RuntimeException( 'Normal provider must expose installed and offered versions during cron.' ); }
 $items = array_values( array_filter( Sunrise\agent_inventory(), function ( $item ) use ( $id ) { return $item['installed_id'] === $id; } ) );
 if ( 1 !== count( $items ) || $offered !== $items[0]['offered_version'] || true !== $items[0]['update_available'] || false !== strpos( json_encode( $items ), 'sunrise_fixture_license' ) ) { throw new RuntimeException( 'Sanitized agent inventory lost the version or exposed a credential.' ); }
 $provider = $GLOBALS['wpmdbpro'];Sunrise\agent_prepare_update_providers();
 if ( $provider !== $GLOBALS['wpmdbpro'] ) { throw new RuntimeException( 'Repeated checks must reuse the provider.' ); }
 if ( get_plugins()[ $id ]['Version'] !== $installed ) { throw new RuntimeException( 'The test must not install an update.' ); }
 echo "PASS: original cron omission reproduced; native provider and sanitized agent report agree on installed/target versions; repeated checks reuse the provider; no update installed.\n";
} finally {
 false === $native ? delete_site_transient( 'update_plugins' ) : set_site_transient( 'update_plugins', $native );
 false === $upgrade ? delete_site_transient( 'wpmdb_upgrade_data' ) : set_site_transient( 'wpmdb_upgrade_data', $upgrade );
 null === $settings ? delete_site_option( 'wpmdb_settings' ) : update_site_option( 'wpmdb_settings', $settings );
 null === $states ? delete_option( 'sunrise_agents' ) : update_option( 'sunrise_agents', $states, false );
}
