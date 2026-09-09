<?php
/** Run with wp --user=<owner> eval-file on an enrolled disposable local site. No external requests escape. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local test administrator required.' ); }
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$states = Sunrise\agent_states(); $state = Sunrise\agent_state();
if ( empty( $state['site_id'] ) ) { WP_CLI::error( 'Enroll this fixture first.' ); }
$last = get_option( 'sunrise_last_refresh', null ); $transients = array();
foreach ( array( 'core', 'plugins', 'themes' ) as $type ) { $transients[ $type ] = get_site_transient( 'update_' . $type ); }
$calls = 0; $native = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
function sunrise_refresh_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$block = function () use ( &$calls ) { ++$calls; sunrise_refresh_assert( false === has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ), 'Native automatic-update callback is suppressed during provider checks' ); return new WP_Error( 'fixture_offline', 'Provider request blocked by test' ); };
if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { WP_CLI::error( 'Agent is busy.' ); }
add_filter( 'pre_http_request', $block );
try {
	delete_option( 'sunrise_last_refresh' );
	$request = array( 'id' => wp_generate_uuid4(), 'expires_at' => time() + 60 );
	sunrise_refresh_assert( Sunrise\agent_refresh_inventory( $request, $state ), 'An authorized refresh records its attempt' );
	sunrise_refresh_assert( ! empty( $state['refresh_ack_pending'] ), 'The persisted attempt requires acknowledgment' );
	sunrise_refresh_assert( $calls > 0 && 'refresh_attempted' === $state['refresh_result']['code'], 'A provider failure remains attempted, never proof that updates are current' );
	$before = $calls;
	sunrise_refresh_assert( ! Sunrise\agent_refresh_inventory( $request, $state ) && $calls === $before, 'The same request is never executed twice' );
	$request['id'] = wp_generate_uuid4();
	sunrise_refresh_assert( Sunrise\agent_refresh_inventory( $request, $state ) && 'refresh_throttled' === $state['refresh_result']['code'] && $calls === $before, 'Different connections share the local provider cooldown' );
	$request['id'] = wp_generate_uuid4(); $request['expires_at'] = time() - 1;
	sunrise_refresh_assert( ! Sunrise\agent_refresh_inventory( $request, $state ), 'Expired execution hints cannot start work' );
	$request['expires_at'] = time() + 60;
	\WP_Upgrader::create_lock( 'sunrise_worker', 120 );
	try { sunrise_refresh_assert( ! Sunrise\agent_refresh_inventory( $request, $state ), 'A running local worker blocks refresh work' ); }
	finally { \WP_Upgrader::release_lock( 'sunrise_worker' ); }
	sunrise_refresh_assert( $native === has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ), 'Native callback is restored after refresh' );
} finally {
	remove_filter( 'pre_http_request', $block );
	Sunrise\agent_store_states( $states );
	if ( null === $last ) { delete_option( 'sunrise_last_refresh' ); } else { update_option( 'sunrise_last_refresh', $last, false ); }
	foreach ( $transients as $type => $value ) { if ( false === $value ) { delete_site_transient( 'update_' . $type ); } else { set_site_transient( 'update_' . $type, $value ); } }
	\WP_Upgrader::release_lock( 'sunrise_agent' );
}
