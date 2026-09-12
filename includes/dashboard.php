<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

function dashboard_request( $request ) {
	require_once __DIR__ . '/transfer-inventory.php';
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( strlen( $request->get_body() ) > 131072 ) { return new \WP_Error( 'sunrise_dashboard_size', 'Request too large.', array( 'status' => 413 ) ); }
	// Preserve empty JSON objects in policies/headers; WP's associative decoder turns them into arrays.
	$data = json_decode( $request->get_body(), false, 64 );
	if ( ! is_object( $data ) || array_diff( array_keys( get_object_vars( $data ) ), array( 'path', 'method', 'data', 'headers' ) ) ) { return new \WP_Error( 'sunrise_dashboard_request', 'Invalid dashboard request.', array( 'status' => 400 ) ); }
	return agent_http( 'agent/dashboard', $data, agent_state() );
}

function dashboard_page() {
	$url = agent_dashboard_url(); if ( ! $url ) { return; }
	$channel = wp_generate_uuid4();
	wp_enqueue_script( 'sunrise-dashboard', plugins_url( '../assets/dashboard.js', __FILE__ ), array(), VERSION, true );
	wp_localize_script( 'sunrise-dashboard', 'sunriseDashboard', array( 'api' => rest_url( 'sunrise/v1/dashboard' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'origin' => agent_url(), 'channel' => $channel, 'frame' => agent_url() . '/wordpress', 'version' => VERSION ) );
	echo '<p id="sunrise-dashboard-status" role="status"></p><a id="sunrise-dashboard-permission" hidden href="' . esc_url( add_query_arg( array( 'dashboard_site' => agent_state()['site_id'], 'view' => 'updates' ), $url ) ) . '">Sign in to enable network controls</a><iframe id="sunrise-dashboard" class="sunrise-dashboard-frame" title="Sunrise network updates" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin"></iframe>';
}
