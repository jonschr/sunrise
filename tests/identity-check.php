<?php
/** wp --user=<owner> eval-file .../tests/identity-check.php; disposable enrolled sites only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type()
	|| ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || is_wp_error( Sunrise\agent_access() ) ) { throw new RuntimeException( 'Disposable local owner with traffic cron disabled required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/jobs.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/controller.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
global $wpdb;
$prefix = $wpdb->esc_like( 'sunrise_' ) . '%';
$saved = array();
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) as $name ) {
	if ( '.lock' === substr( $name, -5 ) ) { throw new RuntimeException( 'Wait for active Sunrise work to finish.' ); }
	$saved[ $name ] = get_option( $name );
}
$cron = get_option( 'cron' );
$anchor_path = WP_CONTENT_DIR . '/sunrise-installation.php';
$anchor_bytes = file_exists( $anchor_path ) ? file_get_contents( $anchor_path ) : null;
$original_user = get_current_user_id();
$post_id = 0;
$calls = 0;
$block = function () use ( &$calls ) { ++$calls; return new WP_Error( 'test_network_blocked' ); };
$salt = function ( $value ) { return $value . '-test-rotation'; };
$url = function () { return 'https://cloned.example'; };
$home = function () { return 'https://different-home.example'; };
$restore = function () use ( $wpdb, $prefix, $saved, $cron, $original_user, $salt, $url, $home, $anchor_path, $anchor_bytes ) {
	remove_filter( 'salt', $salt ); remove_filter( 'site_url', $url ); remove_filter( 'home_url', $home );
	wp_set_current_user( $original_user );
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) as $name ) { delete_option( $name ); }
	foreach ( $saved as $name => $value ) { add_option( $name, $value, '', false ); }
	if ( null === $anchor_bytes ) { if ( file_exists( $anchor_path ) ) { unlink( $anchor_path ); } }
	else { file_put_contents( $anchor_path, $anchor_bytes ); chmod( $anchor_path, 0640 ); }
	update_option( 'cron', $cron );
};
function sunrise_identity_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
add_filter( 'pre_http_request', $block );
try {
	$identity = Sunrise\installation_identity(); $id = $identity['id'];
	sunrise_identity_assert( true === Sunrise\installation_guard() && wp_is_uuid( $id, 4 ) && $identity === Sunrise\installation_identity(), 'Identity initializes once without changing a healthy enrollment' );
	$destination_connection = Sunrise\agent_state();
	$post_id = wp_insert_post( array( 'post_title' => 'Sunrise destination identity fixture', 'post_content' => 'Content received from another installation.', 'post_status' => 'draft' ), true );
	if ( is_wp_error( $post_id ) ) { throw new RuntimeException( 'Could not create content fixture' ); }
	sunrise_identity_assert( true === Sunrise\installation_guard() && $identity === Sunrise\installation_identity() && $destination_connection === Sunrise\agent_state(), 'Content changes preserve the destination installation identity and its connections' );
	add_filter( 'site_url', $url );
	$agent = Sunrise\agent_state(); $agent['url'] = $url(); update_option( 'sunrise_agents', array( get_current_user_id() => $agent ), false );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ), 'URL hash detects a clone even when a migrator rewrites the saved enrollment URL' );
	sunrise_identity_assert( is_wp_error( Sunrise\agent_sync() ) && is_wp_error( Sunrise\agent_enroll() ) && 0 === $calls, 'Changed installation makes no agent request' );
	sunrise_identity_assert( 'off' === Sunrise\item_policy( 'plugins', 'any/plugin.php' ) && ! Sunrise\filter_core( true, 'minor' ), 'Changed installation blocks managed automatic updates' );
	sunrise_identity_assert( is_wp_error( Sunrise\enqueue_job( array( 'request_id' => wp_generate_uuid4(), 'action' => 'refresh' ) ) )
		&& is_wp_error( Sunrise\controller_request( array(), 'inventory' ) ) && 0 === $calls, 'Queued work and peer requests share the identity guard' );
	remove_filter( 'site_url', $url );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ), 'Restoring the URL does not silently clear a detected mismatch' );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_resolve( 'clone', $id ) ) && is_wp_error( Sunrise\installation_resolve( 'clone', wp_generate_uuid4(), true ) ), 'Recovery requires confirmation and rejects stale identity forms' );
	WP_Upgrader::create_lock( 'sunrise_agent', 120 );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_resolve( 'clone', $id, true ) ), 'Recovery cannot race a running check-in' );
	WP_Upgrader::release_lock( 'sunrise_agent' );
	wp_set_current_user( 0 );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_resolve( 'clone', $id, true ) ), 'Recovery requires the authorized local owner' );
	wp_set_current_user( $original_user );
	$result = Sunrise\installation_resolve( 'clone', $id, true );
	sunrise_identity_assert( ! is_wp_error( $result ) && $result['installation_id'] !== $id && ! Sunrise\agent_state()
		&& ! wp_next_scheduled( 'sunrise_check_in' ) && ! get_option( 'sunrise_policy' ) && 0 === $calls && true === Sunrise\installation_guard(), 'Clone recovery generates a fresh identity and clears copied state without contacting the original enrollment' );
	$restore(); $identity = Sunrise\installation_identity(); $id = $identity['id'];
	add_filter( 'salt', $salt );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ) && $id === Sunrise\installation_identity()['id'], 'Salt rotation pauses the connection without changing the installation ID' );
	$result = Sunrise\installation_resolve( 'same', $id, true );
	sunrise_identity_assert( ! is_wp_error( $result ) && $id === $result['installation_id'] && true === Sunrise\installation_guard()
		&& ! Sunrise\agent_state() && 0 === $calls, 'Existing-site recovery retains identity but requires fresh enrollment after salt rotation' );
	$restore(); $destination = Sunrise\installation_identity();
	$source_id = wp_generate_uuid4();
	$source = array_merge( array( 'id' => $source_id, 'review' => false, 'anchor_required' => true ), Sunrise\installation_markers( $source_id ) );
	update_option( 'sunrise_installation', $source, false );
	$source_agent = Sunrise\agent_state(); $source_agent['user_id'] = PHP_INT_MAX; update_option( 'sunrise_agents', array( get_current_user_id() => $source_agent ), false );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ) && Sunrise\installation_anchor() === $destination['id'], 'Database replacement is detected even with matching URL and salts; the destination file retains its identity' );
	$result = Sunrise\installation_resolve( 'same', $destination['id'], true );
	sunrise_identity_assert( ! is_wp_error( $result ) && $result['installation_id'] === $destination['id'] && ! Sunrise\agent_state() && 0 === $calls, 'A current administrator can clear a copied owner connection and retain the destination identity' );
	$restore(); $missing = Sunrise\installation_identity(); unlink( $anchor_path );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ), 'A missing installation file cannot silently adopt a database identity' );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_resolve( 'same', $missing['id'], true ) ) && ! is_wp_error( Sunrise\installation_resolve( 'clone', $missing['id'], true ) ), 'Missing-file recovery requires a fresh identity unless the original file is restored' );
	$restore(); Sunrise\installation_identity(); add_filter( 'home_url', $home );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ), 'A changed home URL is detected independently of the WordPress URL' );
	$restore(); delete_option( 'sunrise_installation' ); if ( file_exists( $anchor_path ) ) { unlink( $anchor_path ); }
	$agent = Sunrise\agent_state(); $agent['secret'] = 'unreadable'; update_option( 'sunrise_agents', array( get_current_user_id() => $agent ), false );
	sunrise_identity_assert( is_wp_error( Sunrise\installation_guard() ), 'Upgrading an unreadable legacy enrollment requires review' );
	$restore(); $before_reconnect = Sunrise\installation_identity(); $old_connection = Sunrise\agent_state();
	$result = Sunrise\installation_resolve( 'same', $before_reconnect['id'], true );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Could not prepare reconnection fixture' ); }
	remove_filter( 'pre_http_request', $block );
	$enrollment = function ( $pre, $args, $url ) use ( $old_connection ) {
		$data = json_decode( $args['body'], true );
		sunrise_identity_assert( Sunrise\agent_url() . '/v1/enrollments' === $url && $data['credential_digest'] !== $old_connection['digest']
			&& false === strpos( $args['body'], 'salt_check' ) && false === strpos( $args['body'], 'url_hash' ), 'Reconnection uses fresh credentials and keeps local identity markers out of requests' );
		return array( 'response' => array( 'code' => 201 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => wp_generate_uuid4(), 'approval_url' => Sunrise\agent_url() . '/?enrollment=test', 'phrase' => 'test-approval' ) ) );
	};
	add_filter( 'pre_http_request', $enrollment, 10, 3 );
	try {
		$result = Sunrise\agent_enroll();
		sunrise_identity_assert( ! is_wp_error( $result ) && empty( Sunrise\agent_state()['site_id'] ) && Sunrise\installation_anchor() === $before_reconnect['id'], 'Reconnect starts fresh approval without assuming the old network membership' );
	} finally { remove_filter( 'pre_http_request', $enrollment ); }
} finally { if ( is_int( $post_id ) && $post_id > 0 ) { wp_delete_post( $post_id, true ); } $restore(); remove_filter( 'pre_http_request', $block ); }
WP_CLI::line( 'Identity checks passed; original site state restored. PHP ' . PHP_VERSION );
