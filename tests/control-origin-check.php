<?php
/** Standalone: php tests/control-origin-check.php. No WordPress database or network needed. */
define( 'ABSPATH', __DIR__ . '/' );
$environment = 'production';
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function add_action() {}
require dirname( __DIR__ ) . '/includes/agent.php';
if ( 'https://sunrise-staging.elod.in' !== Sunrise\agent_url() ) { throw new RuntimeException( 'A fresh install must use staging without configuration' ); }
if ( Sunrise\agent_service_matches( array( 'control_url' => 'http://127.0.0.1:8787' ) ) || Sunrise\agent_service_matches( array() ) ) { throw new RuntimeException( 'Existing loopback credentials must not be sent to staging' ); }
define( 'SUNRISE_CONTROL_URL', 'http://127.0.0.1:8787' );
if ( false !== Sunrise\agent_url() ) { throw new RuntimeException( 'HTTP override must remain forbidden outside local environments' ); }
$environment = 'local';
if ( 'http://127.0.0.1:8787' !== Sunrise\agent_url() ) { throw new RuntimeException( 'Local development override must still work' ); }
echo "PASS: staging default, existing credential origin binding, and local-only HTTP override.\n";
