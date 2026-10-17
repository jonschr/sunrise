<?php
/** wp --user=1 eval-file tests/hourly-updates-check.php on a disposable local site. All HTTP is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { WP_CLI::error( 'Disposable local administrator with cron disabled required.' ); }
$names = array( 'cron', 'active_plugins', 'sunrise_agents', 'sunrise_agent', 'sunrise_installation', 'sunrise_hourly_update_at', 'sunrise_hourly_update_attempts', 'sunrise_automatic_update_failures', 'sunrise_automatic_update_activity', 'sunrise_last_refresh', 'sunrise_agent_log', 'sunrise_remote_job_fence', 'auto_plugin_theme_update_emails', 'external_updates-sunrise' );
$saved = array(); foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$transients = array(); foreach ( array( 'core', 'plugins', 'themes' ) as $type ) { $transients[ $type ] = get_site_transient( 'update_' . $type ); }
$anchor_path = WP_CONTENT_DIR . '/sunrise-installation.php'; $anchor = file_exists( $anchor_path ) ? file_get_contents( $anchor_path ) : null;
$directory = WP_PLUGIN_DIR . '/sunrise-hourly-fixture'; $id = 'sunrise-hourly-fixture/plugin.php'; $zip = null; $reports = array(); $attempts = 0; $mode = 'success'; $wire = null; $events = array(); $lose_report = false; $offline = false;
if ( file_exists( $directory ) ) { WP_CLI::error( 'Fixture directory already exists.' ); }
function hourly_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$response = static function ( $body ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) ); };
$http = function ( $pre, $args, $url ) use ( &$reports, &$attempts, &$mode, &$wire, &$events, &$lose_report, &$offline, $id, $response ) {
 if ( false !== strpos( $url, '/v1/agent/check-in' ) ) {
  $report = json_decode( $args['body'], true ); $reports[] = $report; $events[] = 'report';
  if ( $offline || ( $lose_report && $attempts ) ) { return new WP_Error( 'fixture_offline', 'Simulated lost response' ); }
  return $response( array( 'receipt_sequence' => $report['sequence'], 'policy' => $wire, 'update_failures' => true, 'update_activity' => true, 'update_failure_details' => true, 'failure_checks' => array(), 'check_in_cadence' => true ) );
 }
 if ( false !== strpos( $url, 'wp_scrape_key=' ) ) {
  parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query ); $key = $query['wp_scrape_key'];
  $value = 'rollback' === $mode ? array( 'type' => E_ERROR, 'message' => 'Disposable fatal rollback fixture' ) : true;
  return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => "###### wp_scraping_result_start:$key ######" . wp_json_encode( $value ) . "###### wp_scraping_result_end:$key ######" );
 }
 if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
  return $response( array( 'plugins' => array( $id => array( 'plugin' => $id, 'slug' => 'sunrise-hourly-fixture', 'new_version' => '2.0', 'url' => 'https://fixture.invalid/', 'package' => 'https://fixture.invalid/hourly.zip' ) ), 'no_update' => array(), 'translations' => array() ) );
 }
 if ( false !== strpos( $url, '/releases/latest/download/update.json' ) ) { return $response( array( 'name' => 'Sunrise', 'version' => Sunrise\VERSION, 'download_url' => 'https://fixture.invalid/unused.zip' ) ); }
 if ( false !== strpos( $url, 'api.wordpress.org/core/version-check' ) ) { return $response( array( 'offers' => array(), 'translations' => array() ) ); }
 if ( false !== strpos( $url, 'api.wordpress.org/themes/update-check' ) ) { return $response( array( 'themes' => array(), 'no_update' => array(), 'translations' => array() ) ); }
 return new WP_Error( 'fixture_blocked', 'All other HTTP blocked' );
};
add_filter( 'pre_http_request', $http, 10, 3 );
$download = function ( $reply, $package ) use ( &$zip ) { if ( 'https://fixture.invalid/hourly.zip' !== $package ) { return new WP_Error( 'fixture_blocked', 'Unexpected package' ); } $copy = wp_tempnam( 'hourly.zip' ); copy( $zip, $copy ); return $copy; };
$pre = function ( $type, $item ) use ( &$attempts, &$events, &$mode, $id ) { if ( 'plugin' === $type && $id === $item->plugin ) { $attempts++; $events[] = 'install'; if ( 'interrupted' === $mode ) { throw new RuntimeException( 'Disposable interrupted update' ); } } };
try {
 require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
 wp_register_plugin_realpath( WP_PLUGIN_DIR . '/sunrise/sunrise.php' );
 if ( ! function_exists( 'Sunrise\\agent_state' ) ) { require dirname( __DIR__ ) . '/sunrise.php'; }
 require_once dirname( __DIR__ ) . '/includes/controller.php'; require_once dirname( __DIR__ ) . '/includes/api.php'; require_once dirname( __DIR__ ) . '/includes/hourly-updates.php';
 $owner = get_current_user_id(); $identity = wp_generate_uuid4(); Sunrise\installation_write_anchor( $identity );
 update_option( 'sunrise_installation', array_merge( array( 'id' => $identity, 'review' => false, 'anchor_required' => true ), Sunrise\installation_markers( $identity ) ), false );
 $decision = array( 'revision' => '2', 'specificity' => 1 );
 $document = array( 'schema_version' => 2, 'generation' => 2, 'policy' => array( 'site' => 'inherit', 'core' => 'off', 'plugins' => array( $id => 'on' ), 'themes' => array() ), 'category_defaults' => array( 'plugins' => 'off', 'themes' => 'off' ), 'decisions' => array( 'core' => $decision, 'plugins' => array( $id => array( 'revision' => '2', 'specificity' => 3 ) ), 'themes' => array(), 'category_defaults' => array( 'plugins' => $decision, 'themes' => $decision ) ) );
 $make_wire = static function ( $doc ) { $json = wp_json_encode( $doc ); return array( 'generation' => $doc['generation'], 'hash' => hash( 'sha256', $json ), 'document_json' => $json ); };
 $wire = $make_wire( $document );
 $state = array( 'user_id' => $owner, 'url' => Sunrise\installation_site_url(), 'control_url' => Sunrise\agent_url(), 'enrollment_id' => wp_generate_uuid4(), 'site_id' => wp_generate_uuid4(), 'account_id' => wp_generate_uuid4(), 'network_id' => wp_generate_uuid4(), 'sequence' => 0, 'generation' => 1, 'secret' => Sunrise\credential( str_repeat( 'a', 64 ), 'agent|' . Sunrise\installation_site_url() . '|' . $owner ), 'paused' => false );
 delete_option( 'sunrise_agent' ); Sunrise\agent_store_states( array( $owner => $state ) );
 mkdir( $directory ); $plugin = "<?php\n/* Plugin Name: Sunrise hourly fixture\nVersion: 1.0\n*/\n"; file_put_contents( $directory . '/plugin.php', $plugin );
 $zip = wp_tempnam( 'hourly-original.zip' ); $archive = new ZipArchive(); $archive->open( $zip, ZipArchive::OVERWRITE ); $archive->addFromString( 'sunrise-hourly-fixture/plugin.php', str_replace( '1.0', '2.0', $plugin ) ); $archive->close();
 update_option( 'active_plugins', array_merge( get_option( 'active_plugins', array() ), array( $id ) ) );
 add_filter( 'automatic_updater_disabled', '__return_false' ); add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' ); add_filter( 'auto_plugin_theme_update_send_email', '__return_false' );
 add_filter( 'upgrader_pre_download', $download, 10, 2 ); add_action( 'pre_auto_update', $pre, 20, 2 );
 $reset = function () use ( &$reports, &$attempts, &$events, &$state, $directory, $plugin ) {
  file_put_contents( $directory . '/plugin.php', $plugin ); wp_clean_plugins_cache();
  foreach ( array( 'sunrise_hourly_update_at', 'sunrise_hourly_update_attempts', 'sunrise_automatic_update_failures', 'sunrise_automatic_update_activity', 'sunrise_last_refresh', 'sunrise_remote_job_fence' ) as $name ) { delete_option( $name ); }
  foreach ( array( 'sunrise_agent', 'sunrise_worker', 'auto_updater' ) as $lock ) { WP_Upgrader::release_lock( $lock ); }
  Sunrise\agent_store_states( array( get_current_user_id() => $state ) ); $reports = array(); $attempts = 0; $events = array();
 };
 $reset(); $result = Sunrise\agent_synchronize( true ); hourly_check( ! is_wp_error( $result ) && 0 === $attempts, 'Foreground/manual check-ins only sync; no bonus installer runs' );
 define( 'DOING_CRON', true );
 $reset(); $result = Sunrise\agent_synchronize( true );
 hourly_check( ! is_wp_error( $result ), 'Hourly background synchronization succeeds' );

 hourly_check( 1 === $attempts && '2.0' === get_plugin_data( $directory . '/plugin.php', false, false )['Version'], 'Native WordPress installs the freshly allowed update' );
 $last = end( $reports ); $reported = array_values( array_filter( $last['inventory'], static function ( $item ) use ( $id ) { return $id === $item['installed_id']; } ) );
 hourly_check( '2.0' === $reported[0]['version'] && 'succeeded' === $last['automatic_update_activity'][0]['status'], 'Completion immediately reports installed inventory and native success activity' );
 hourly_check( array_search( 'install', $events, true ) >= 2 && 'report' === end( $events ), 'Fresh policy and acknowledgment precede installation; the final operation reports results' );
 $count = count( $reports ); Sunrise\agent_synchronize( true ); hourly_check( 1 === $attempts && count( $reports ) === $count + 1, 'Repeated background checks share the installation-wide hourly cooldown' );
 $reset(); $mode = 'rollback'; Sunrise\agent_synchronize( true );
 hourly_check( 1 === $attempts && '1.0' === get_plugin_data( $directory . '/plugin.php', false, false )['Version'], 'Native fatal-error detection restores the previous plugin files' );
 delete_option( 'sunrise_hourly_update_at' ); delete_option( 'sunrise_last_refresh' ); Sunrise\agent_synchronize( true );
 hourly_check( 1 === $attempts, 'The next hourly bonus pass skips the failed/rolled-back version' );
 $mode = 'success'; ( new WP_Automatic_Updater() )->run();
 hourly_check( 2 === $attempts && '2.0' === get_plugin_data( $directory . '/plugin.php', false, false )['Version'], 'The normal native updater remains free to retry that same failed version' );
 $reset(); update_option( 'sunrise_automatic_update_failures', array( 'plugin:' . $id => array( 'type' => 'plugin', 'installed_id' => $id, 'version' => '2.0', 'code' => 'rollback', 'failed_at' => time() ) ), false ); Sunrise\agent_synchronize( true );
 hourly_check( 0 === $attempts, 'An existing native failure also excludes that version from the bonus pass' );
 $failure_state = Sunrise\agent_state(); $failure_state['failure_checks'] = array( array( 'id' => wp_generate_uuid4(), 'type' => 'plugin', 'installed_id' => $id, 'version' => '2.0' ) ); Sunrise\agent_store( $failure_state ); delete_option( 'sunrise_automatic_update_failures' );
 hourly_check( ! Sunrise\hourly_update_allowed( true, (object) array( 'plugin' => $id, 'new_version' => '2.0' ), 'plugin' ), 'Open Control failures also exclude that version from the bonus pass' );
 hourly_check( ! Sunrise\hourly_update_allowed( false, (object) array( 'plugin' => $id, 'new_version' => '2.1' ), 'plugin' ), 'The extra-pass guard never enables an update disallowed by native policy' );
 $reset(); $mode = 'interrupted'; Sunrise\agent_synchronize( true );
 hourly_check( 1 === $attempts && isset( get_option( 'sunrise_hourly_update_attempts' )[ 'plugin:' . $id ] ), 'An interrupted bonus attempt retains its target before any file operation' );
 update_option( 'sunrise_automatic_update_failures', array( 'plugin:' . $id => array( 'type' => 'plugin', 'installed_id' => $id, 'version' => '1.9', 'code' => 'rollback', 'failed_at' => time() ) ), false );
 $failures = Sunrise\automatic_update_failures( Sunrise\agent_inventory() ); hourly_check( '2.0' === $failures[0]['version'], 'An older native failure cannot hide a newer unconfirmed attempt' );
 $last = end( $reports ); hourly_check( 'unknown' === $last['automatic_update_failures'][0]['code'], 'An unconfirmed interrupted attempt is reported without claiming success' );
 delete_option( 'sunrise_hourly_update_at' ); $mode = 'success'; Sunrise\agent_synchronize( true ); hourly_check( 1 === $attempts, 'Unconfirmed versions are not repeated by the next bonus pass' );
 hourly_check( Sunrise\hourly_update_allowed( true, (object) array( 'plugin' => $id, 'new_version' => '2.1' ), 'plugin' ), 'A newer offered release is eligible for the extra hourly pass' );
 $reset(); $offline = true; Sunrise\agent_synchronize( true ); hourly_check( 0 === $attempts, 'A failed policy check-in cannot start automatic installation' ); $offline = false;
 $reset(); $paused = $state; $paused['paused'] = true; Sunrise\agent_store_states( array( $owner => $paused ) ); Sunrise\agent_synchronize( true ); hourly_check( 0 === $attempts, 'Local pause blocks the bonus updater' );
 $reset(); WP_Upgrader::create_lock( 'sunrise_worker' ); Sunrise\agent_synchronize( true ); hourly_check( 0 === $attempts, 'An active Sunrise worker blocks the bonus updater' ); WP_Upgrader::release_lock( 'sunrise_worker' );
 $reset(); $held = Sunrise\agent_execution_lock(); Sunrise\agent_synchronize( true ); hourly_check( 0 === $attempts, 'The process-held execution lock blocks overlapping installers' ); flock( $held, LOCK_UN ); fclose( $held );
 $reset(); update_option( 'sunrise_remote_job_fence', array( 'job_id' => wp_generate_uuid4() ), false ); Sunrise\agent_synchronize( true ); hourly_check( 0 === $attempts, 'Unresolved remote work blocks the bonus updater' );
 $reset(); $lose_report = true; Sunrise\agent_synchronize( true ); $pending = Sunrise\agent_state()['pending_report']; $lose_report = false; Sunrise\agent_synchronize( true );
 hourly_check( 1 === $attempts && in_array( $pending, $reports, true ), 'A lost completion report is retried without repeating the installation' );
 WP_CLI::success( 'Hourly native updates, rollback, interrupted-attempt guard, native retries, reporting and worker isolation passed.' );
} finally {
 remove_filter( 'pre_http_request', $http ); remove_filter( 'upgrader_pre_download', $download ); remove_action( 'pre_auto_update', $pre, 20 );
 remove_filter( 'automatic_updater_disabled', '__return_false' ); remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false' ); remove_filter( 'auto_plugin_theme_update_send_email', '__return_false' );
 foreach ( $saved as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
 foreach ( $transients as $type => $value ) { if ( false === $value ) { delete_site_transient( 'update_' . $type ); } else { set_site_transient( 'update_' . $type, $value ); } }
 if ( null === $anchor ) { if ( file_exists( $anchor_path ) ) { unlink( $anchor_path ); } } else { file_put_contents( $anchor_path, $anchor ); }
 require_once ABSPATH . 'wp-admin/includes/file.php'; WP_Filesystem(); global $wp_filesystem; if ( is_dir( $directory ) ) { $wp_filesystem->delete( $directory, true ); }
 if ( $zip && file_exists( $zip ) ) { unlink( $zip ); }
 foreach ( array( 'sunrise_agent', 'sunrise_worker', 'auto_updater' ) as $lock ) { WP_Upgrader::release_lock( $lock ); }
}
