<?php
/** Run only on a disposable install: wp eval-file /path/to/sunrise/tests/check.php */
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Refusing to run: requires SUNRISE_TEST_SITE=true and WP_ENVIRONMENT_TYPE=local.' );
}
if ( Sunrise\agent_states() ) {
	throw new RuntimeException( 'Use an unenrolled disposable site for legacy checks; enrolled sites use tests/agent-check.php.' );
}

require_once WP_PLUGIN_DIR . '/sunrise/includes/api.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/jobs.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/controller.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

function sunrise_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo 'PASS: ' . $message . "\n";
}

function sunrise_task( $action = 'refresh', $extra = array() ) {
	return array_merge( array( 'request_id' => wp_generate_uuid4(), 'action' => $action ), $extra );
}

function sunrise_offer( $id, $version, $extra = array() ) {
	$plugins = get_plugins();
	$checked = array();
	foreach ( $plugins as $file => $plugin ) {
		$checked[ $file ] = $plugin['Version'];
	}
	$offer = (object) array_merge( array( 'plugin' => $id, 'slug' => dirname( $id ), 'new_version' => $version, 'package' => 'https://sunrise.invalid/test.zip', 'requires' => '6.6', 'requires_php' => '7.4' ), $extra );
	set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'checked' => $checked, 'response' => array( $id => $offer ), 'no_update' => array() ) );
}

// Block outbound downloads/update checks; only the explicit local package fixture is installed.
$http_calls = 0;
add_filter( 'pre_http_request', function () use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'sunrise_test_network_blocked' ); } );
remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
$native_auto_calls = 0;
add_action( 'wp_maybe_auto_update', function () use ( &$native_auto_calls ) { ++$native_auto_calls; } );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$old_policy = get_option( 'sunrise_policy' );
$old_refresh = get_option( 'sunrise_last_refresh' );
$old_cache = get_site_transient( 'update_plugins' );
$old_theme_cache = get_site_transient( 'update_themes' );
$fixture_dir = WP_PLUGIN_DIR . '/sunrise-test-fixture';
$theme_dir = get_theme_root() . '/sunrise-test-theme';
if ( file_exists( $fixture_dir ) || file_exists( $theme_dir ) ) {
	throw new RuntimeException( 'Remove the leftover sunrise-test-fixture before testing.' );
}
mkdir( $fixture_dir );
mkdir( $theme_dir );
file_put_contents( $theme_dir . '/style.css', "/* Theme Name: Sunrise Test Theme\nVersion: 1.0.0\n*/" );
file_put_contents( $theme_dir . '/index.php', '<?php // Test theme.' );
wp_clean_themes_cache();
$fixture = 'sunrise-test-fixture/fixture.php';
file_put_contents( $fixture_dir . '/fixture.php', "<?php\n/* Plugin Name: Sunrise Test Fixture\nVersion: 1.0.0\n*/\n" );
wp_clean_plugins_cache();
$subscriber = wp_insert_user( array( 'user_login' => 'sunrise-test-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
$job_ids = array();
$zip_path = wp_tempnam( 'sunrise-fixture.zip' );
$zip = new ZipArchive();
$zip->open( $zip_path, ZipArchive::OVERWRITE );
$zip->addFromString( 'sunrise-test-fixture/fixture.php', "<?php\n/* Plugin Name: Sunrise Test Fixture\nVersion: 2.0.0\n*/\n" );
$zip->close();
$theme_zip = wp_tempnam( 'sunrise-theme.zip' );
$zip->open( $theme_zip, ZipArchive::OVERWRITE );
$zip->addFromString( 'sunrise-test-theme/style.css', "/* Theme Name: Sunrise Test Theme\nVersion: 2.0.0\n*/" );
$zip->addFromString( 'sunrise-test-theme/index.php', '<?php // Test theme.' );
$zip->close();
$download = function ( $reply, $package ) use ( $zip_path, $theme_zip ) {
	if ( in_array( $package, array( 'https://sunrise.invalid/test.zip', 'https://sunrise.invalid/theme.zip' ), true ) ) {
		$copy = wp_tempnam( 'sunrise-package.zip' );
		copy( 'https://sunrise.invalid/theme.zip' === $package ? $theme_zip : $zip_path, $copy );
		return $copy;
	}
	return $reply;
};
add_filter( 'upgrader_pre_download', $download, 10, 2 );

try {
	wp_set_current_user( 0 );
	$server = rest_get_server();
	$response = $server->dispatch( new WP_REST_Request( 'GET', '/sunrise/v1/inventory' ) );
	sunrise_check( 401 === $response->get_status(), 'Anonymous inventory rejected' );
	wp_set_current_user( $subscriber );
	sunrise_check( is_wp_error( Sunrise\permission() ), 'Subscriber inventory rejected' );
	sunrise_check( is_wp_error( Sunrise\save_policy( array( 'site' => 'on' ) ) ), 'Subscriber policy mutation rejected' );
	sunrise_check( is_wp_error( Sunrise\enqueue_job( sunrise_task() ) ), 'Subscriber job rejected' );
	wp_set_current_user( $admin->ID );
	delete_option( 'sunrise_policy' );
	sunrise_check( true === Sunrise\permission(), 'Local administrator accepted' );
	WP_Upgrader::create_lock( 'sunrise_policy' );
	sunrise_check( is_wp_error( Sunrise\save_policy( array( 'site' => 'on' ) ) ), 'Concurrent policy writes are serialized' );
	WP_Upgrader::release_lock( 'sunrise_policy' );
	Sunrise\save_policy( array( 'site' => 'on', 'plugins' => array( $fixture => 'off' ) ) );
	sunrise_check( false === apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $fixture ) ), 'Per-plugin exclusion overrides site-on' );
	Sunrise\save_policy( array( 'plugins' => array( $fixture => 'inherit' ), 'core' => 'minor' ) );
	sunrise_check( true === apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => $fixture ) ), 'Removing exclusion restores site policy' );
	sunrise_check( false === apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $fixture, 'disable_autoupdate' => true ) ), 'Provider-disabled offer respected' );
	sunrise_check( Sunrise\filter_core( false, 'minor' ) && ! Sunrise\filter_core( true, 'major' ) && ! Sunrise\filter_core( true, 'dev' ), 'Minor core policy excludes major and development releases' );
	$before = Sunrise\policy();
	sunrise_check( is_wp_error( Sunrise\save_policy( array( 'site' => 'off', 'plugins' => array( '../../bad.php' => 'on' ) ) ) ) && $before === Sunrise\policy(), 'Invalid policy patch cannot partially save' );
	$host_block = '__return_true';
	add_filter( 'automatic_updater_disabled', $host_block );
	sunrise_check( false === apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $fixture ) ), 'Host automatic-update block respected' );
	remove_filter( 'automatic_updater_disabled', $host_block );
	delete_option( 'sunrise_policy' );
	sunrise_check( null === apply_filters( 'auto_update_plugin', null, (object) array( 'plugin' => $fixture ) ), 'Inherited policy preserves native null semantics' );
	sunrise_offer( $fixture, '2.0.0' );
	$before_inventory_http = $http_calls;
	$data = Sunrise\inventory();
	sunrise_check( $http_calls === $before_inventory_http, 'Inventory performs no outbound HTTP requests' );
	sunrise_check( false === strpos( wp_json_encode( $data ), 'sunrise.invalid/test.zip' ), 'Inventory omits package credentials and URLs' );
	$unknown = array_values( array_filter( $data['plugins'], function ( $item ) { return 'sunrise/sunrise.php' === $item['id']; } ) )[0];
	sunrise_check( null === $unknown['update_available'], 'Absent provider information is unknown' );
	$cache = get_site_transient( 'update_plugins' );
	$cache->response['sunrise/sunrise.php'] = (object) array( 'new_version' => '99.0', 'package' => 'https://downloads.wordpress.org/plugin/sunrise.zip' );
	set_site_transient( 'update_plugins', $cache );
	sunrise_check( ! isset( get_site_transient( 'update_plugins' )->response['sunrise/sunrise.php'] ), 'Unrelated Sunrise repository offer blocked' );
	sunrise_check( is_wp_error( Sunrise\enqueue_job( sunrise_task( 'update', array( 'type' => 'plugin', 'id' => '../bad.php', 'version' => '2.0' ) ) ) ), 'Arbitrary update paths rejected' );
	$task = sunrise_task( 'update', array( 'type' => 'plugin', 'id' => $fixture, 'version' => '2.0.0' ) );
	$job = Sunrise\enqueue_job( $task );
	sunrise_check( $job instanceof WP_REST_Response && 202 === $job->get_status(), 'Update queued asynchronously' );
	$job_ids[] = $task['request_id'];
	sunrise_check( $job->get_data()['id'] === Sunrise\enqueue_job( $task )->get_data()['id'], 'Duplicate request returns original job' );
	sunrise_check( is_wp_error( Sunrise\enqueue_job( sunrise_task() ) ), 'Second active job rejected' );
	$changed = $task;
	$changed['version'] = '3.0';
	sunrise_check( is_wp_error( Sunrise\enqueue_job( $changed ) ), 'Reusing request ID for different update rejected' );
	Sunrise\run_job( $task['request_id'] );
	$finished = Sunrise\job_response( $task['request_id'] )->get_data();
	sunrise_check( 'completed' === $finished['status'] && 'updated' === $finished['result']['code'], 'Native upgrader installs local fixture package' );
	wp_clean_plugins_cache();
	sunrise_check( '2.0.0' === get_plugins()[ $fixture ]['Version'], 'Updated plugin version verified on disk' );
	Sunrise\run_job( $task['request_id'] );
	sunrise_check( $finished === Sunrise\job_response( $task['request_id'] )->get_data(), 'Completed job never executes twice' );
	sunrise_offer( $fixture, '3.0.0', array( 'requires_php' => '99.0' ) );
	$bad = Sunrise\install_update( array( 'type' => 'plugin', 'id' => $fixture, 'version' => '3.0.0' ) );
	sunrise_check( is_wp_error( $bad ) && 'sunrise_incompatible_php' === $bad->get_error_code(), 'Incompatible update rejected before installation' );
	$bad = Sunrise\install_update( array( 'type' => 'plugin', 'id' => $fixture, 'version' => '4.0.0' ) );
	sunrise_check( is_wp_error( $bad ) && 'sunrise_offer_changed' === $bad->get_error_code(), 'Changed offer rejected instead of silently upgrading another version' );
	$app = WP_Application_Passwords::create_new_application_password( $admin->ID, array( 'name' => 'Sunrise temporary test' ) );
	$GLOBALS['wp_rest_application_password_uuid'] = $app[1]['uuid'];
	$revoked = sunrise_task();
	Sunrise\enqueue_job( $revoked );
	$job_ids[] = $revoked['request_id'];
	WP_Application_Passwords::delete_application_password( $admin->ID, $app[1]['uuid'] );
	$GLOBALS['wp_rest_application_password_uuid'] = null;
	Sunrise\run_job( $revoked['request_id'] );
	sunrise_check( 'sunrise_credential_revoked' === Sunrise\job_response( $revoked['request_id'] )->get_data()['result']['code'], 'Revoked application password prevents queued execution' );
	set_site_transient( 'update_themes', (object) array( 'last_checked' => time(), 'checked' => array( 'sunrise-test-theme' => '1.0.0' ), 'response' => array( 'sunrise-test-theme' => array( 'theme' => 'sunrise-test-theme', 'new_version' => '2.0.0', 'package' => 'https://sunrise.invalid/theme.zip' ) ) ) );
	$theme_task = sunrise_task( 'update', array( 'type' => 'theme', 'id' => 'sunrise-test-theme', 'version' => '2.0.0' ) );
	$job_ids[] = $theme_task['request_id'];
	sunrise_check( Sunrise\enqueue_job( $theme_task ) instanceof WP_REST_Response, 'Theme update queued' );
	WP_Upgrader::create_lock( 'auto_updater' );
	sunrise_check( is_wp_error( Sunrise\run_job( $theme_task['request_id'] ) ) && 'queued' === Sunrise\job_response( $theme_task['request_id'] )->get_data()['status'], 'Native automatic-updater lock prevents overlapping manual updates' );
	WP_Upgrader::release_lock( 'auto_updater' );
	Sunrise\run_job( $theme_task['request_id'] );
	sunrise_check( 'completed' === Sunrise\job_response( $theme_task['request_id'] )->get_data()['status'] && '2.0.0' === wp_get_theme( 'sunrise-test-theme' )->get( 'Version' ), 'Native theme upgrader installs and verifies the fixture version' );
	$context = 'test-connection';
	$encrypted = Sunrise\credential( 'test-secret', $context );
	sunrise_check( 'test-secret' !== $encrypted && 'test-secret' === Sunrise\credential( $encrypted, $context, true ), 'Controller credential encrypted at rest' );
	sunrise_check( is_wp_error( Sunrise\credential( $encrypted, 'different-site', true ) ), 'Credential ciphertext cannot be swapped between connections' );
	sunrise_check( Sunrise\local_url( 'http://sunrise-target.localhost:8080' ) && ! Sunrise\local_url( 'http://localhost.evil.test' ), 'Local transport exemption is hostname bounded' );
	update_option( 'sunrise_last_refresh', time(), false );
	sunrise_check( 'refresh_throttled' === Sunrise\execute_task( array( 'action' => 'refresh' ) )['code'], 'Repeated provider refresh throttled' );
	echo "All Sunrise checks passed on WordPress " . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ".\n";
} finally {
	wp_set_current_user( $admin->ID );
	foreach ( $job_ids as $id ) {
		delete_option( 'sunrise_job_' . $id );
		wp_clear_scheduled_hook( 'sunrise_run_job', array( $id ) );
	}
	$ids = array_values( array_diff( get_option( 'sunrise_job_ids', array() ), $job_ids ) );
	update_option( 'sunrise_job_ids', $ids, false );
	false === $old_policy ? delete_option( 'sunrise_policy' ) : update_option( 'sunrise_policy', $old_policy, false );
	false === $old_refresh ? delete_option( 'sunrise_last_refresh' ) : update_option( 'sunrise_last_refresh', $old_refresh, false );
	set_site_transient( 'update_plugins', $old_cache );
	set_site_transient( 'update_themes', $old_theme_cache );
	wp_delete_user( $subscriber );
	wp_delete_file( $fixture_dir . '/fixture.php' );
	rmdir( $fixture_dir );
	wp_delete_file( $zip_path );
	wp_delete_file( $theme_zip );
	wp_delete_file( $theme_dir . '/style.css' );
	wp_delete_file( $theme_dir . '/index.php' );
	rmdir( $theme_dir );
	wp_clean_themes_cache( false );
	wp_clean_plugins_cache( false );
}
