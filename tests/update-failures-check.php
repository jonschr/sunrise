<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
$previous = get_option( 'sunrise_automatic_update_failures', null );
$previous_activity = get_option( 'sunrise_automatic_update_activity', null );
$check = function ( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
try {
 delete_option( 'sunrise_automatic_update_failures' );
 delete_option( 'sunrise_automatic_update_activity' );
 $item = (object) array( 'plugin' => 'fixture/fixture.php', 'new_version' => '2.0' );
 $busy = new WP_Error( 'sunrise_execution_busy', 'Another installer is active.' ); $busy->add( 'download_failed', 'Native wrapper' );
 Sunrise\capture_automatic_update_failures( array( 'plugin' => array( (object) array( 'item' => $item, 'result' => $busy ) ) ) );
 $check( ! get_option( 'sunrise_automatic_update_failures' ) && ! Sunrise\automatic_update_activity(), 'A native lock deferral must not become a failed-version guard or installation failure' );
 $result = (object) array( 'item' => $item, 'result' => new WP_Error( 'download_failed', 'License expired: secret-key https://provider.test/?key=secret' ) );
 require_once dirname( __DIR__ ) . '/includes/agent-jobs.php';
 require_once dirname( __DIR__ ) . '/includes/jobs.php';
 $check( 'license_required' === Sunrise\agent_job_failure_code( $result->result ), 'Central jobs must report a safe specific reason' );
 $check( 'sunrise_database_upgrade_pending' === Sunrise\agent_job_failure_code( new WP_Error( 'sunrise_database_upgrade_pending', 'Database upgrade required.' ) ), 'Sunrise job errors must retain their specific code' );
 foreach ( array( 'sunrise_incompatible_php' => 'sunrise_incompatible_php', 'incompatible_php_required_version' => 'incompatible_php', 'incompatible_wp_required_version' => 'incompatible_wordpress', 'incompatible_archive_no_plugins' => 'invalid_package', 'copy_failed' => 'copy_failed', 'mkdir_failed' => 'mkdir_failed', 'remove_old_failed' => 'remove_old_failed', 'insufficient_disk_space' => 'insufficient_disk_space', 'rollback_failed' => 'rollback_failed' ) as $code => $reason ) {
  $check( $reason === Sunrise\agent_job_failure_code( new WP_Error( $code, 'Native updater error' ) ), 'Specific central error classification: ' . $code );
 }
 foreach ( array( 'cURL error 28: Operation timed out' => 'download_timeout', 'SSL certificate problem' => 'tls_verification_failed', 'HTTP 429 Too Many Requests' => 'provider_rate_limited', 'HTTP 503 Service Unavailable' => 'provider_unavailable', 'HTTP 403 Forbidden' => 'provider_forbidden', 'HTTP 404 Not Found' => 'package_unavailable' ) as $message => $reason ) {
  $check( $reason === Sunrise\automatic_failure_reason( new WP_Error( 'http_request_failed', $message ) ), 'Provider/transport classification: ' . $reason );
 }
 $check( 'incompatible_php' === Sunrise\automatic_failure_reason( new WP_Error( 'sunrise_incompatible_php', 'PHP is too old' ) ), 'Automatic PHP failures stay specific' );
 foreach ( array( 'TypeError' => 'php_type_error', 'ArgumentCountError' => 'php_argument_error', 'ParseError' => 'php_parse_error', 'Error' => 'php_runtime_error', 'RuntimeException' => 'execution_exception' ) as $class => $code ) { $check( $code === Sunrise\agent_job_exception_code( new $class( 'secret provider detail' ) ), 'Exception classification must not expose raw error messages' ); }
 $stored_db_version = get_option( 'db_version' ); $expected_db_version = (int) $stored_db_version + 1;
 $upgrade = function () use ( $expected_db_version ) { update_option( 'db_version', $expected_db_version ); return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '0' ); };
 add_filter( 'pre_http_request', $upgrade );
 try { $check( true === Sunrise\core_database_upgrade( $expected_db_version ), 'Core database upgrades use a fresh loopback request' ); } finally { remove_filter( 'pre_http_request', $upgrade ); update_option( 'db_version', $stored_db_version ); }
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
 $activity = Sunrise\automatic_update_activity();$check( 4 === count( $activity ) && 'failed' === $activity[0]['status'] && 'succeeded' === $activity[3]['status'], 'Automatic attempts must remain visible as update activity' );
 $probe = array( 'id' => wp_generate_uuid4(), 'type' => 'plugin', 'installed_id' => 'fixture/fixture.php', 'version' => '2.0' );
 $check( Sunrise\validate_failure_checks( array( $probe ) ), 'Valid probe rejected' );
 $check( array( $probe['id'] ) === Sunrise\failure_resolutions( array( $probe ), $inventory ), 'Higher installed version must resolve central failure' );
 $inventory[0]['version'] = '2.0-RC1';$check( array() === Sunrise\failure_resolutions( array( $probe ), $inventory ), 'Prerelease must not resolve stable target' );
 $probe['version'] = '../unsafe';$check( ! Sunrise\validate_failure_checks( array( $probe ) ), 'Invalid probe accepted' );
 $php_error = clone $result; $php_error->result = new WP_Error( 'incompatible_php_required_version', 'Requires a newer PHP version' );
 do_action( 'automatic_updates_complete', array( 'plugin' => array( $php_error ) ) );
 $check( 'incompatible_php' === Sunrise\automatic_update_failures( array(), true )[0]['code'] && 'incompatible' === Sunrise\automatic_update_failures( array(), false )[0]['code'], 'New failure codes are negotiated and downgraded only for legacy Control' );
 $check( 'incompatible_php' === get_option( 'sunrise_automatic_update_failures' )['plugin:fixture/fixture.php']['code'], 'Legacy report conversion must not overwrite the specific stored failure' );
 WP_CLI::success( 'Native failure capture, deduplication, secret-free reasons, successful auto/manual resolution and WordPress version comparison passed.' );
} finally { if ( null === $previous ) { delete_option( 'sunrise_automatic_update_failures' ); } else { update_option( 'sunrise_automatic_update_failures', $previous, false ); } if ( null === $previous_activity ) { delete_option( 'sunrise_automatic_update_activity' ); } else { update_option( 'sunrise_automatic_update_activity', $previous_activity, false ); } }
