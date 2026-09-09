<?php
/** Disposable enrolled local owner. All HTTP intercepted; settings and connection restored. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-transfers.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
function agent_transfer_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$states = Sunrise\agent_states(); $name = get_option( 'blogname' ); $calls = array(); $fail = true; $cancel = false; $claimed = false;
$task = array( 'id' => wp_generate_uuid4(), 'phase' => 'source', 'deadline' => gmdate( 'c', time() + HOUR_IN_SECONDS ), 'names' => array( 'blogname' ) );
$http = function ( $pre, $args, $url ) use ( &$calls, &$fail, &$cancel, &$claimed, $task ) {
	$calls[] = array( $url, $args['body'] );
	if ( false !== strpos( $url, '/agent/transfers/claim' ) ) { $response = array( 'transfer' => $claimed ? null : $task ); $claimed = true; }
	elseif ( $cancel ) { return array( 'response' => array( 'code' => 409 ), 'headers' => array(), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'transfer_expired' ) ) ) ); }
	elseif ( $fail ) { return new WP_Error( 'test_lost_receipt' ); }
	else { $response = array( 'transfer' => array( 'accepted' => true ) ); }
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $response ) );
};
add_filter( 'pre_http_request', $http, 10, 3 );
try {
	$state = Sunrise\agent_state(); $state['transfer_previews'] = true; unset( $state['transfer_report'] ); Sunrise\agent_store( $state );
	WP_Upgrader::create_lock( 'sunrise_agent', 120 );
	agent_transfer_check( is_wp_error( Sunrise\agent_prepare_transfer() ) && ! $calls, 'Transfer preparation respects the shared connection lock' ); WP_Upgrader::release_lock( 'sunrise_agent' );
	$result = Sunrise\agent_prepare_transfer(); $pending = Sunrise\agent_state()['transfer_report'];
	agent_transfer_check( is_wp_error( $result ) && count( $calls ) === 2 && $pending['payload']['items'][0]['value'] === $name, 'Source snapshot is persisted before a lost HTTP response' );
	$first_body = $calls[1][1]; update_option( 'blogname', 'Changed after capture' ); $fail = false;
	agent_transfer_check( true === Sunrise\agent_prepare_transfer() && count( $calls ) === 3 && $calls[2][1] === $first_body && empty( Sunrise\agent_state()['transfer_report'] ), 'Retry sends the original snapshot even when source content changed' );
	agent_transfer_check( false === Sunrise\agent_prepare_transfer() && count( $calls ) === 4, 'Completed preparation is not repeated when no task remains' );
	$state = Sunrise\agent_state(); $state['transfer_report'] = $pending; Sunrise\agent_store( $state ); $cancel = true;
	agent_transfer_check( is_wp_error( Sunrise\agent_prepare_transfer() ) && empty( Sunrise\agent_state()['transfer_report'] ), 'Cancelled or expired central work clears the retained report' );
	$state = Sunrise\agent_state(); $state['transfer_previews'] = false; Sunrise\agent_store( $state ); $count = count( $calls );
	agent_transfer_check( false === Sunrise\agent_prepare_transfer() && $count === count( $calls ), 'Older services do not receive transfer requests' );
} finally {
	remove_filter( 'pre_http_request', $http, 10 ); Sunrise\agent_store_states( $states ); update_option( 'blogname', $name ); WP_Upgrader::release_lock( 'sunrise_agent' );
}
WP_CLI::success( 'Read-only transfer retries passed; original settings and connection restored.' );
