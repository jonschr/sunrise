<?php
/** Standalone redaction and real fatal-trace checks. Run with PHP 7.4+; add fatal for a subprocess. */
namespace Sunrise { function agent_states() { return array(); } }
namespace {
define( 'ABSPATH', '/fixture-wordpress/' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'WPMU_PLUGIN_DIR', '/fixture-wordpress/wp-content/mu-plugins' );
define( 'WP_CONTENT_DIR', '/fixture-wordpress/wp-content' );
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function get_theme_root() { return '/fixture-wordpress/wp-content/themes'; }
function wp_check_invalid_utf8( $value, $strip = false ) { return preg_match( '//u', $value ) ? $value : ''; }
function add_filter() {}
require dirname( __DIR__ ) . '/includes/errors.php';
function check( $condition, $label ) { if ( ! $condition ) { throw new \RuntimeException( $label ); } }
function sunrise_trace_fixture( $argument ) { sunrise_undefined_fixture_function( $argument ); }
if ( isset( $argv[1] ) && 'fatal' === $argv[1] ) {
 register_shutdown_function( function () {
  $error = error_get_last(); $details = \Sunrise\error_diagnostics( $error );
  check( false !== strpos( $details['message'], 'sunrise_undefined_fixture_function' ), 'Real fatal message lost.' );
  check( count( $details['stack_trace'] ) > 0, 'Real fatal trace missing.' );
  check( false === strpos( json_encode( $details ), 'trace-secret-value' ) && false === strpos( json_encode( $details ), WP_PLUGIN_DIR ), 'Real trace leaked arguments or paths.' );
  echo json_encode( array( 'passed' => true, 'php' => PHP_VERSION, 'frames' => count( $details['stack_trace'] ), 'native_trace' => isset( $error['trace'] ) ) ) . "\n";
 } );
 sunrise_trace_fixture( 'trace-secret-value' );
 exit;
}
$file = WP_PLUGIN_DIR . '/example/main.php';
$error = array( 'file' => $file, 'line' => 42, 'message' => "Uncaught Error: Call to undefined function example() in $file:42\nStack trace:\n#0 $file(10): Example->run('trace-secret-value')\n#1 {main}" );
$details = \Sunrise\error_diagnostics( $error );
check( 1 === count( $details['stack_trace'] ) && 'Example->run' === $details['stack_trace'][0]['function'], 'String trace frames not parsed.' );
check( 'plugins/example/main.php' === $details['stack_trace'][0]['file'], 'Trace location not made relative.' );
check( false === strpos( json_encode( $details ), 'trace-secret-value' ) && false === strpos( json_encode( $details ), WP_PLUGIN_DIR ), 'String trace leaked arguments or paths.' );
$error['trace'] = array_fill( 0, 20, array( 'file' => $file, 'line' => 99, 'class' => 'Example', 'type' => '->', 'function' => 'run', 'args' => array( 'trace-secret-value' ), 'object' => new \stdClass() ) );
$details = \Sunrise\error_diagnostics( $error );
check( 20 === count( $details['stack_trace'] ) && ! $details['trace_truncated'] && 3 === count( $details['stack_trace'][0] ), 'Structured traces must be bounded and exclude arguments/objects.' );
$error['trace'] = array_fill( 0, 40, $error['trace'][0] );
$details = \Sunrise\error_diagnostics( $error );
check( 32 === count( $details['stack_trace'] ) && $details['trace_truncated'], 'Truncation must be explicit.' );
$details = \Sunrise\error_diagnostics( array( 'file' => $file, 'message' => 'password=fixture-secret email=private@example.invalid URL=https://private.example/path SQL SELECT private FROM users' ) );
foreach ( array( 'fixture-secret', 'private@example.invalid', 'private.example', 'SELECT private', 'SQL' ) as $secret ) { check( false === strpos( $details['message'], $secret ), 'Sensitive diagnostic content survived redaction.' ); }
$details = \Sunrise\error_diagnostics( array( 'message' => str_repeat( 'a', 5000 ) ) );
check( 2000 === strlen( $details['message'] ) && array() === $details['stack_trace'], 'Message bound or missing-trace fallback failed.' );
$details = \Sunrise\error_diagnostics( array( 'message' => 'Authorization: Bearer private-bearer-value failed' ) );
check( false === strpos( $details['message'], 'private-bearer-value' ), 'Authorization value survived redaction.' );
$details = \Sunrise\error_diagnostics( array( 'message' => 'Failed opening "/private/folder with spaces/secret.php"' ) );
check( false === strpos( $details['message'], 'folder with spaces' ), 'Quoted private path survived redaction.' );
echo 'PASS: bounded messages, redaction, string traces, structured traces, and omitted arguments on PHP ' . PHP_VERSION . ".\n";
}
