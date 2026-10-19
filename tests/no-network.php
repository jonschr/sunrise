<?php
/** WP-CLI --require hook blocks bootstrap traffic before the fixture installs its own HTTP mocks. */
$GLOBALS['wp_filter']['pre_http_request'][-PHP_INT_MAX][] = array( 'function' => function () { return new WP_Error( 'fixture_network_blocked', 'No real HTTP is permitted during plugin verification.' ); }, 'accepted_args' => 3 );

WP_CLI::add_hook( 'after_wp_load', function () { require_once dirname( __DIR__ ) . '/sunrise.php'; } );
