<?php
defined( 'ABSPATH' ) || exit;

$GLOBALS['sunrise_test_acf_key'] = ''; $sunrise_test_acf_stubbed = ! function_exists( 'acf_pro_activate_license' );
if ( $sunrise_test_acf_stubbed ) {
	function acf_pro_activate_license( $key, $silent = false ) { $GLOBALS['sunrise_test_acf_key'] = $key; update_option( 'acf_pro_license', $key ); return array( 'success' => true ); }
	function acf_pro_get_license_key() { return $GLOBALS['sunrise_test_acf_key']; }
	function acf_pro_is_license_active() { return (bool) $GLOBALS['sunrise_test_acf_key']; }
}
$check = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$names = array( 'acf_pro_license', 'acf_pro_license_status', 'gen_premium_license_key', 'gen_premium_license_key_status', 'generateblocks_pro_licensing', 'acp_activation_key', '_acp_access_permissions', 'acp_subscription_details', 'acp_subscription_details_key', 'acp_subscription_key', 'acp_update_plugins_data', 'sunrise_test_unrelated_edd_cache' );
$cache_names = array(); foreach ( array( 'gp-premiumgp-key_123', 'plugingb-key_123' ) as $value ) { foreach ( array( false, true ) as $beta ) { $hash = md5( serialize( $value . $beta ) ); $cache_names[] = 'edd_sl_' . $hash; $cache_names[] = 'edd_api_request_' . $hash; } }
$cache_names[] = 'edd_sl_failed_http_' . md5( trailingslashit( 'https://generatepress.com' ) ); $cache_names[] = 'edd_sl_failed_http_' . md5( trailingslashit( 'https://generateblocks.com' ) ); $names = array_merge( $names, $cache_names );
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
	foreach ( $cache_names as $name ) { update_option( $name, array( 'stale' => true ), false ); } update_option( 'sunrise_test_unrelated_edd_cache', array( 'keep' => true ), false );
	if ( $sunrise_test_acf_stubbed ) { $check( true === Sunrise\premium_license_activate( 'advanced-custom-fields-pro', ' ACF-Key_123 ' ), 'Advanced Custom Fields PRO activation failed' ); $check( 'ACF-Key_123' === $GLOBALS['sunrise_test_acf_key'] && Sunrise\premium_license_valid( 'advanced-custom-fields-pro' ), 'Advanced Custom Fields PRO native license functions were not used' ); }
	$check( true === Sunrise\premium_license_activate( 'gp-premium', ' GP-Key_123 ' ), 'GP Premium activation failed' );
	$check( 'gp-key_123' === get_option( 'gen_premium_license_key' ) && 'valid' === get_option( 'gen_premium_license_key_status' ), 'GP Premium native options were not used' );
	$check( true === Sunrise\premium_license_activate( 'generateblocks-pro', ' GB-Key_123 ' ), 'GenerateBlocks Pro activation failed' );
	$check( array( 'key' => 'gb-key_123', 'status' => 'valid' ) === get_option( 'generateblocks_pro_licensing' ), 'GenerateBlocks Pro native option was not used' );
	foreach ( $cache_names as $name ) { $check( false === get_option( $name ), 'Stale EDD updater cache was not cleared' ); }
	$check( array( 'keep' => true ) === get_option( 'sunrise_test_unrelated_edd_cache' ), 'Unrelated updater cache was changed' );
	$check( true === Sunrise\premium_license_activate( 'admin-columns-pro', 'subscription-key-12345' ), 'Admin Columns Pro activation failed' );
	$check( 'activation-key-12345' === get_option( 'acp_activation_key' ) && 'activation-key-12345' === get_option( 'acp_subscription_details_key' ), 'Admin Columns Pro activation options were not used' );
	$check( 'active' === get_option( 'acp_subscription_details' )['status'] && array( 'usage', 'update' ) === get_option( '_acp_access_permissions' ), 'Admin Columns Pro license state was not stored' );
	$check( $requests[0]['body']['item_name'] === rawurlencode( 'GP Premium' ) && $requests[1]['body']['item_name'] === rawurlencode( 'GenerateBlocks Pro' ), 'EDD product names changed' );
	$check( 'subscription-key-12345' === $requests[2]['body']['subscription_key'] && 'activate' === $requests[2]['headers']['X-AC-Command'], 'Admin Columns Pro did not receive its native License Key value' );
	$check( false === Sunrise\premium_license_metadata( array( 'revision' => 1, 'configured' => array( 'unknown' ) ) ), 'Unsupported plugin accepted' );
	$check( false !== Sunrise\premium_license_metadata( array( 'revision' => 1, 'configured' => array_keys( Sunrise\premium_license_plugins() ) ) ), 'Supported plugin count was not updated' );
	$last = array( 'revision' => 2, 'failure' => 'old-failure', 'status' => 'invalid', 'attempted_at' => time() );
	$check( ! Sunrise\premium_license_attempt_required( $last, 2, 'old-failure', false ), 'An unchanged invalid key retried without a new failure' );
	$check( Sunrise\premium_license_attempt_required( $last, 2, 'new-failure', false ), 'A new plugin failure did not retry the configured key' );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 ); foreach ( $saved as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
}
echo "Premium license checks passed.\n";
