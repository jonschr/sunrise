<?php
/** Standalone: php tests/control-origin-check.php. No WordPress database or network needed. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$environment = 'production';
$control_environment = 'production';
function get_option( $name, $default = false ) { return 'sunrise_control_environment' === $name ? $GLOBALS['control_environment'] : $default; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function add_action() {}
function add_filter() {}
require dirname( __DIR__ ) . '/includes/agent.php';
if ( 'https://desk.sunrisecontrol.app' !== Sunrise\agent_url() ) { throw new RuntimeException( 'A fresh install must use production without configuration' ); }
if ( Sunrise\agent_service_matches( array( 'control_url' => 'http://127.0.0.1:8787' ) ) || Sunrise\agent_service_matches( array( 'control_url' => 'https://sunrise-staging.elod.in' ) ) || Sunrise\agent_service_matches( array() ) ) { throw new RuntimeException( 'Existing credentials must remain bound to their original origin' ); }
$control_environment = 'staging';
if ( 'https://staging.sunrisecontrol.app' !== Sunrise\agent_url() ) { throw new RuntimeException( 'Site option must select staging' ); }
$control_environment = 'https://untrusted.example';
if ( 'https://desk.sunrisecontrol.app' !== Sunrise\agent_url() ) { throw new RuntimeException( 'An invalid option must not select an arbitrary origin' ); }
define( 'SUNRISE_CONTROL_URL', 'http://127.0.0.1:8787' );
if ( false !== Sunrise\agent_url() ) { throw new RuntimeException( 'HTTP override must remain forbidden outside local environments' ); }
$environment = 'local';
if ( 'http://127.0.0.1:8787' !== Sunrise\agent_url() ) { throw new RuntimeException( 'Local development override must still work' ); }
echo "PASS: production default, staging site option, credential origin binding, and local-only HTTP override.\n";
