<?php
/** wp --user=1 eval-file tests/transfer-inventory-check.php; enrolled disposable Cove only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/transfer-inventory.php';
function transfer_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$owner = get_current_user_id(); $posts = array(); $identity = Sunrise\installation_identity(); $states = Sunrise\agent_states();
$description = get_option( 'blogdescription' );
try {
	foreach ( array( 'posts', 'media', 'options', 'plugins', 'themes', 'tables' ) as $scope ) {
		$result = Sunrise\transfer_inventory( $scope );
		transfer_check( ! is_wp_error( $result ) && true === $result['read_only'] && false === $result['execution_available'] && count( $result['items'] ) <= 50, 'Bounded read-only ' . $scope . ' inventory' );
	}
	$before = 0;
	for ( $i = 0; $i < 51; ++$i ) { $id = wp_insert_post( array( 'post_title' => 'Sunrise transfer fixture ' . $i, 'post_content' => 'private-transfer-content-' . $i, 'post_status' => 'draft' ), true ); if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture creation failed' ); } $posts[] = $id; if ( ! $i ) { $before = $id - 1; } }
	$page = Sunrise\transfer_inventory( 'posts', $before );
	transfer_check( count( $page['items'] ) === 50 && $page['next_cursor'] === $posts[49] && false === strpos( wp_json_encode( $page ), 'private-transfer-content' ), 'Post inventory pages without exporting content' );
	$next = Sunrise\transfer_inventory( 'posts', $page['next_cursor'] );
	transfer_check( count( $next['items'] ) === 1 && $next['items'][0]['id'] === $posts[50], 'Post cursor has no duplicated boundary row' );
	$old_hash = $page['items'][0]['fingerprint'];
	wp_update_post( array( 'ID' => $posts[0], 'post_content' => 'Changed content with unchanged timestamp' ) );
	$changed = Sunrise\transfer_inventory( 'posts', $before );
	transfer_check( strlen( $old_hash ) === 64 && $old_hash !== $changed['items'][0]['fingerprint'], 'Raw post fingerprint detects content changes independently of version labels' );
	$snapshot = Sunrise\transfer_option_snapshot( array( 'blogname', 'blogdescription' ) );
	transfer_check( ! is_wp_error( $snapshot ) && count( $snapshot['items'] ) === 2, 'Explicit native setting snapshot' );
	foreach ( array( 'sunrise_agents', 'home', 'siteurl', 'active_plugins', 'cron', 'wp_user_roles', 'unknown_plugin_secret' ) as $name ) { transfer_check( is_wp_error( Sunrise\transfer_option_snapshot( array( $name ) ) ), 'Protected or unsupported setting excluded: ' . $name ); }
	$source = $snapshot; $source['site_id'] = wp_generate_uuid4(); $source['site_url'] = 'https://source.example/'; $source['home_url'] = $source['site_url'];
	foreach ( $source['items'] as &$item ) { $item['value'] = 'Imported https://source.example/path'; $item['fingerprint'] = hash( 'sha256', $item['value'] ); } unset( $item );
	$preview = Sunrise\transfer_option_preview( $source );
	transfer_check( ! is_wp_error( $preview ) && $preview['plan']['changes'][0]['after'] === 'Imported ' . rtrim( home_url( '/' ), '/' ) . '/path' && ! $preview['execution_available'] && $preview['authorization_required'], 'Preview rewrites explicit source URLs without authorizing execution' );
	transfer_check( Sunrise\transfer_option_snapshot( array( 'blogname', 'blogdescription' ) ) === $snapshot && Sunrise\agent_states() === $states && Sunrise\installation_identity() === $identity, 'Preview leaves destination data, connections and identity unchanged' );
	update_option( 'blogdescription', 'Concurrent destination change' );
	$new_preview = Sunrise\transfer_option_preview( $source );
	transfer_check( $preview['fingerprint'] !== $new_preview['fingerprint'], 'Destination change invalidates the exact preview fingerprint' );
	$source['items'][0]['fingerprint'] = str_repeat( '0', 64 ); transfer_check( is_wp_error( Sunrise\transfer_option_preview( $source ) ), 'Altered source value rejected' );
	wp_set_current_user( 0 ); transfer_check( is_wp_error( Sunrise\transfer_inventory( 'options' ) ) && is_wp_error( Sunrise\transfer_option_preview( $snapshot ) ), 'Unauthenticated callers cannot inspect or preview settings' );
} finally {
	wp_set_current_user( $owner ); update_option( 'blogdescription', $description );
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
}
WP_CLI::success( 'Transfer inventories and destination previews passed; fixture content removed.' );
