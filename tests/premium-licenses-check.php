<?php
defined( 'ABSPATH' ) || exit;

$check = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$names = array( 'gen_premium_license_key', 'gen_premium_license_key_status', 'generateblocks_pro_licensing', 'acp_activation_key', '_acp_access_permissions', 'acp_subscription_details', 'acp_subscription_details_key', 'acp_subscription_key', 'acp_update_plugins_data' );
$saved = array(); foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$requests = array(); $mock = function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = array( 'url' => $url, 'body' => $args['body'], 'headers' => isset( $args['headers'] ) ? $args['headers'] : array() ); $body = $args['body'];
	if ( isset( $body['edd_action'] ) ) { $data = array( 'license' => 'valid' ); }
	elseif ( 'activate' === $body['command'] ) { $data = array( 'activation_key' => 'activation-key-12345', 'permissions' => array( 'usage', 'update' ) ); }
	elseif ( 'subscription_details' === $body['command'] ) { $data = array( 'activation_key' => 'activation-key-12345', 'status' => 'active', 'renewal_method' => 'auto', 'expiry_date' => '2027-01-02 03:04:05', 'permissions' => array( 'usage', 'update' ) ); }
	else { $data = array( 'plugins' => array() ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	foreach ( $names as $name ) { delete_option( $name ); }
	$check( true === Sunrise\premium_license_activate( 'gp-premium', ' GP-Key_123 ' ), 'GP Premium activation failed' );
	$check( 'gp-key_123' === get_option( 'gen_premium_license_key' ) && 'valid' === get_option( 'gen_premium_license_key_status' ), 'GP Premium native options were not used' );
	$check( true === Sunrise\premium_license_activate( 'generateblocks-pro', ' GB-Key_123 ' ), 'GenerateBlocks Pro activation failed' );
	$check( array( 'key' => 'gb-key_123', 'status' => 'valid' ) === get_option( 'generateblocks_pro_licensing' ), 'GenerateBlocks Pro native option was not used' );
	$check( true === Sunrise\premium_license_activate( 'admin-columns-pro', 'subscription-key-12345' ), 'Admin Columns Pro activation failed' );
	$check( 'activation-key-12345' === get_option( 'acp_activation_key' ) && 'activation-key-12345' === get_option( 'acp_subscription_details_key' ), 'Admin Columns Pro activation options were not used' );
	$check( 'active' === get_option( 'acp_subscription_details' )['status'] && array( 'usage', 'update' ) === get_option( '_acp_access_permissions' ), 'Admin Columns Pro license state was not stored' );
	$check( $requests[0]['body']['item_name'] === rawurlencode( 'GP Premium' ) && $requests[1]['body']['item_name'] === rawurlencode( 'GenerateBlocks Pro' ), 'EDD product names changed' );
	$check( 'subscription-key-12345' === $requests[2]['body']['subscription_key'] && 'activate' === $requests[2]['headers']['X-AC-Command'], 'Admin Columns Pro did not receive its native License Key value' );
	$check( false === Sunrise\premium_license_metadata( array( 'revision' => 1, 'configured' => array( 'unknown' ) ) ), 'Unsupported plugin accepted' );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 ); foreach ( $saved as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
}
echo "Premium license checks passed.\n";
