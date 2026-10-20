<?php
/** Exercise native email decisions without installing updates or delivering mail. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { WP_CLI::error( 'Disposable local administrator with cron disabled required.' ); }
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$saved = get_option( 'auto_plugin_theme_update_emails', null );
$saved_filters = array();
foreach ( array( 'auto_plugin_update_send_email', 'auto_theme_update_send_email' ) as $hook ) { $saved_filters[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null; }
$mail = array(); $types = array(); $checks = 0;
$check = function ( $value, $message ) use ( &$checks ) { ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } };
$capture_mail = function ( $sent, $email ) use ( &$mail ) { $mail[] = $email; return true; };
$capture_type = function ( $email, $type ) use ( &$types ) { $types[] = $type; return $email; };
add_filter( 'pre_wp_mail', $capture_mail, PHP_INT_MAX, 2 );
add_filter( 'auto_plugin_theme_update_email', $capture_type, 10, 2 );
$updater = new class extends WP_Automatic_Updater {
 public function check_email( $results ) { $this->after_plugin_theme_update( $results ); }
};
$result = function ( $id, $outcome, $type = 'plugin' ) {
 return (object) array( 'name' => 'Fixture ' . $id, 'result' => $outcome, 'item' => (object) array( $type => $id, 'current_version' => '1.0', 'new_version' => '2.0' ) );
};
$success = $result( 'success/plugin.php', true );
$failure = $result( 'failure/plugin.php', new WP_Error( 'download_failed', 'Fixture download failed.' ) );
$callback = 'Sunrise\\filter_plugin_update_send_email';
try {
 $check( false !== has_filter( 'auto_plugin_update_send_email', $callback ), 'The plugin email filter must be registered.' );
 // Isolate existing site email opt-outs for this process; restore them even after a failed assertion.
 foreach ( $saved_filters as $hook => $filter ) { remove_all_filters( $hook ); }
 add_filter( 'auto_plugin_update_send_email', $callback, 10, 2 );
 foreach ( array( array(), null, array( null ), array( (object) array() ), array( (object) array( 'result' => null ) ), array( (object) array( 'result' => false ) ), array( (object) array( 'result' => 1 ) ), array( $failure ), array( $success, $failure ) ) as $results ) {
  $check( true === apply_filters( 'auto_plugin_update_send_email', true, $results ), 'Missing, unknown or failed outcomes must retain enabled notifications.' );
  $check( false === apply_filters( 'auto_plugin_update_send_email', false, $results ), 'Existing notification opt-outs must remain disabled.' );
 }
 $run = function ( $plugins, $themes = array() ) use ( $updater, &$mail, &$types ) {
  delete_option( 'auto_plugin_theme_update_emails' ); $mail = array(); $types = array();
  $updater->check_email( array( 'plugin' => $plugins, 'theme' => $themes ) );
 };
 // A control run proves the native method would otherwise send the success email.
 remove_filter( 'auto_plugin_update_send_email', $callback, 10 );
 try { $run( array( $success ) ); $check( 1 === count( $mail ) && array( 'success' ) === $types, 'Native success email control failed.' ); }
 finally { add_filter( 'auto_plugin_update_send_email', $callback, 10, 2 ); }
 foreach ( array( array( $success ), array( $success, $result( 'another/plugin.php', true ) ) ) as $results ) {
  $run( $results ); $check( ! $mail && ! $types, 'All-successful plugin batches must not send an email.' );
 }
 $run( array( $failure ) );
 $check( 1 === count( $mail ) && array( 'fail' ) === $types && false !== strpos( $mail[0]['message'], 'Fixture failure/plugin.php' ), 'Failure emails must retain their component details.' );
 $updater->check_email( array( 'plugin' => array( $failure ) ) );
 $check( 1 === count( $mail ), 'Native duplicate-failure notification suppression must remain intact.' );
 $run( array( $success, $failure ) );
 $check( 1 === count( $mail ) && array( 'mixed' ) === $types && false !== strpos( $mail[0]['message'], 'Fixture success/plugin.php' ) && false !== strpos( $mail[0]['message'], 'Fixture failure/plugin.php' ), 'Mixed plugin emails must retain both successes and failures.' );
 $run( array( $success ), array( $result( 'fixture-theme', new WP_Error( 'download_failed', 'Fixture theme failed.' ), 'theme' ) ) );
 $check( 1 === count( $mail ) && false !== strpos( $mail[0]['message'], 'Fixture fixture-theme' ), 'A successful plugin batch must never hide a theme failure email.' );
 $run( array(), array( $result( 'fixture-theme', true, 'theme' ) ) );
 $check( 1 === count( $mail ) && array( 'success' ) === $types, 'Theme-only success notifications must remain unchanged.' );
 WP_CLI::success( $checks . ' automatic-update email checks passed; no mail was delivered.' );
} finally {
 foreach ( $saved_filters as $hook => $filter ) { if ( null === $filter ) { unset( $GLOBALS['wp_filter'][ $hook ] ); } else { $GLOBALS['wp_filter'][ $hook ] = $filter; } }
 remove_filter( 'pre_wp_mail', $capture_mail, PHP_INT_MAX );
 remove_filter( 'auto_plugin_theme_update_email', $capture_type, 10 );
 if ( null === $saved ) { delete_option( 'auto_plugin_theme_update_emails' ); } else { update_option( 'auto_plugin_theme_update_emails', $saved, false ); }
}
