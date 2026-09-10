<?php
/** Run with wp --user=<admin> eval-file on an enrolled disposable Cove site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { WP_CLI::error( 'Disposable local test administrator required.' ); }
$state = Sunrise\agent_state();
$saved_states = Sunrise\agent_states();
$saved_cron = get_option( 'cron' );
$pause = get_option( 'sunrise_agent_pause', false );
$revoked = get_option( 'sunrise_agent_revoked', false );

$original_user = get_current_user_id();
$other_admin = 0;
if ( empty( $state['applied'] ) ) { WP_CLI::error( 'Enroll and synchronize this disposable site first.' ); }
function sunrise_agent_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$block = function () { return new WP_Error( 'test_offline', 'Simulated transport outage' ); };
$reports = array();
$mock_document = $state['applied']['document'];
$mock_document['generation']++;
$mock_wire = array( 'generation' => $mock_document['generation'], 'document_json' => wp_json_encode( $mock_document ) );
$mock_wire['hash'] = hash( 'sha256', $mock_wire['document_json'] );
$reply = function ( $pre, $args ) use ( &$reports, $mock_wire ) {
	$report = json_decode( $args['body'], true ); $reports[] = $report;
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'receipt_sequence' => $report['sequence'], 'policy' => $mock_wire, 'site_profiles' => true, 'wake_requests' => true, 'update_failures' => true, 'failure_checks' => array() ) ) );
};
try {
	$loaded_wp_version = $GLOBALS['wp_version'];
	$fresh_wp_version = ( static function () { require ABSPATH . WPINC . '/version.php'; return $wp_version; } )();
	$GLOBALS['wp_version'] = '0.0-test-stale';
	try { $reported_wp_version = Sunrise\agent_inventory()[0]['version']; } finally { $GLOBALS['wp_version'] = $loaded_wp_version; }
	sunrise_agent_assert( $fresh_wp_version === $reported_wp_version, 'Post-update inventory reads the installed core files instead of stale request globals' );
	$other_admin = wp_insert_user( array( 'user_login' => 'sunrise-owner-test-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'administrator' ) );
	if ( is_wp_error( $other_admin ) ) { throw new RuntimeException( 'Could not create the ownership fixture' ); }
	wp_set_current_user( $other_admin );
	sunrise_agent_assert( is_wp_error( Sunrise\agent_sync() ) && true === Sunrise\agent_access( true ) && is_wp_error( Sunrise\agent_disconnect() ) && $state === Sunrise\agent_states()[ $original_user ], 'Another administrator cannot use, replace, or disconnect the owner connection' );
	foreach ( array( array( 'POST', '/sunrise/v1/agent/check-in' ), array( 'GET', '/sunrise/v1/policy' ), array( 'GET', '/sunrise/v1/inventory' ) ) as $route ) {
		$response = rest_do_request( new WP_REST_Request( $route[0], $route[1] ) );
		sunrise_agent_assert( 403 === $response->get_status(), 'Other administrator is denied ' . $route[1] );
	}
	require_once WP_PLUGIN_DIR . '/sunrise/includes/admin.php';
	ob_start(); Sunrise\admin_page(); $html = ob_get_clean();
	sunrise_agent_assert( false !== strpos( $html, 'Connect this site' ) && false === strpos( $html, 'agent_sync' ) && false === strpos( $html, 'Open Sunrise Control' ) && false === strpos( $html, $state['site_id'] ) && false === strpos( $html, $state['phrase'] ), 'Other administrator sees only the connection page, without owner details or controls' );
	sunrise_agent_assert( false === Sunrise\agent_dashboard_url(), 'Another administrator cannot obtain the owner network navigation hint' );
	wp_set_current_user( $original_user );
	sunrise_agent_assert( true === Sunrise\agent_access(), 'The enrolling administrator retains connection access' );
	$dashboard_url = Sunrise\agent_dashboard_url();
	sunrise_agent_assert( $dashboard_url && false !== strpos( $dashboard_url, 'network=' ) && false === strpos( $dashboard_url, $state['secret'] ) && false === strpos( $dashboard_url, $state['digest'] ), 'Dashboard link contains only a navigation hint, not site credentials' );
	ob_start(); Sunrise\admin_page(); $managed_html = ob_get_clean();
	sunrise_agent_assert( false !== strpos( $managed_html, 'id="sunrise-dashboard"' ) && false === strpos( $managed_html, 'Application password' ) && false === strpos( $managed_html, 'sunrise-refresh-network' ), 'Managed Network page does not fall through to the legacy peer interface' );
	ob_start(); Sunrise\migrations_page(); $migration_html = ob_get_clean();
	sunrise_agent_assert( false !== strpos( $migration_html, 'Sunrise Migrations' ) && false === strpos( $migration_html, 'Application password' ), 'Migrations has its own administrator page with the same connection boundary' );

	$profile_state = Sunrise\agent_state(); unset( $profile_state['wake_requests'], $profile_state['wake_registered'], $profile_state['site_profiles'], $profile_state['site_profile_hash'], $profile_state['update_failures'], $profile_state['update_failure_hash'] ); Sunrise\agent_store( $profile_state );
	wp_unschedule_hook( 'sunrise_check_in' );
	add_filter( 'pre_http_request', $reply, 10, 2 );
	wp_set_current_user( 0 );
	$now = time(); do_action( 'sunrise_check_in', $original_user );
	sunrise_agent_assert( 0 === get_current_user_id() && 2 === count( $reports ), 'Background cron sync works without a logged-in user and restores that context' );
	wp_set_current_user( $original_user );
	$next = wp_next_scheduled( 'sunrise_check_in', array( $original_user ) );
	sunrise_agent_assert( $next >= $now + 5 * MINUTE_IN_SECONDS && $next <= time() + 5 * MINUTE_IN_SECONDS + 30, 'Cron schedules the next outbound report in five minutes with jitter' );
	sunrise_agent_assert( 2 === count( $reports ) && $mock_wire['generation'] === $reports[1]['policy_ack']['generation'], 'A new policy is acknowledged immediately in the same sync' );
	sunrise_agent_assert( ! isset( $reports[0]['site_profile'] ) && Sunrise\agent_site_profile() === $reports[1]['site_profile'], 'Profiles are negotiated first and delivered in the bounded follow-up' );
	sunrise_agent_assert( ! isset( $reports[0]['wake_key'] ) && Sunrise\agent_wake_key( $state ) === $reports[1]['wake_key'], 'Wake-only authentication is negotiated and registered' );
	sunrise_agent_assert( ! isset( $reports[0]['automatic_update_failures'] ) && isset( $reports[1]['automatic_update_failures'] ), 'Automatic failures are negotiated before the snapshot is sent' );
	$reports = array(); $result = Sunrise\agent_sync();
	sunrise_agent_assert( Sunrise\agent_state()['wake_registered'] && ! isset( $reports[0]['wake_key'] ), 'Acknowledged wake key is omitted from routine reports' );
	sunrise_agent_assert( ! is_wp_error( $result ) && 1 === count( $reports ) && $next === wp_next_scheduled( 'sunrise_check_in', array( $original_user ) ), 'Manual sync works before cron is due; unchanged policy needs one request' );
	sunrise_agent_assert( ! isset( $reports[0]['site_profile'], $reports[0]['automatic_update_failures'] ), 'Acknowledged unchanged profile is omitted from later reports' );
	remove_filter( 'pre_http_request', $reply );
	$refresh_state = Sunrise\agent_state();
	$refresh_state['refresh_result'] = array( 'id' => wp_generate_uuid4(), 'code' => 'refresh_attempted' );
	$refresh_state['refresh_ack_pending'] = true;
	Sunrise\agent_store( $refresh_state );
	add_filter( 'pre_http_request', $block );
	$result = Sunrise\agent_check_in();
	$pending = Sunrise\agent_state()['pending_report'];
	sunrise_agent_assert( is_wp_error( $result ) && Sunrise\agent_state()['refresh_ack_pending'] && $pending['refresh_ack'] === $refresh_state['refresh_result'], 'Lost refresh acknowledgment remains pending with its exact report' );
	remove_filter( 'pre_http_request', $block );
	add_filter( 'pre_http_request', $reply, 10, 2 );
	$reports = array(); $result = Sunrise\agent_check_in();
	sunrise_agent_assert( ! is_wp_error( $result ) && $reports[0] === $pending && ! Sunrise\agent_state()['refresh_ack_pending'], 'Accepted retry clears refresh acknowledgment only after the exact report succeeds' );
	$result = Sunrise\agent_check_in();
	sunrise_agent_assert( ! is_wp_error( $result ) && ! isset( $reports[1]['refresh_ack'] ) && Sunrise\agent_state()['refresh_result'] === $refresh_state['refresh_result'], 'Later reports omit the acknowledged result while retaining its execution deduplication ID' );
	remove_filter( 'pre_http_request', $reply );
	update_option( 'sunrise_agents', array( $original_user => $state ), false );
	wp_unschedule_hook( 'sunrise_check_in' );
	add_filter( 'pre_http_request', $block );
	$now = time(); do_action( 'sunrise_check_in', $original_user );
	sunrise_agent_assert( wp_next_scheduled( 'sunrise_check_in', array( $original_user ) ) >= $now + 5 * MINUTE_IN_SECONDS, 'An unreachable site retains the five-minute retry interval' );
	remove_filter( 'pre_http_request', $block );
	$throttled = function () { return array( 'response' => array( 'code' => 429 ), 'headers' => array( 'retry-after' => 3600 ), 'body' => '{"error":{"code":"rate_limited"}}' ); };
	wp_unschedule_hook( 'sunrise_check_in' );add_filter( 'pre_http_request', $throttled );$now = time();
	try { do_action( 'sunrise_check_in', $original_user ); } finally { remove_filter( 'pre_http_request', $throttled ); }
	sunrise_agent_assert( wp_next_scheduled( 'sunrise_check_in', array( $original_user ) ) >= $now + 3600, 'A longer Retry-After remains authoritative' );

	update_option( 'sunrise_agents', array( $original_user => $state ), false );
	$before = Sunrise\policy();
	add_filter( 'pre_http_request', $block );
	$result = Sunrise\agent_check_in();
	sunrise_agent_assert( is_wp_error( $result ) && $before === Sunrise\policy(), 'Transport outage preserves the last applied policy' );
	remove_filter( 'pre_http_request', $block );
	update_option( 'sunrise_agents', array( $original_user => $state ), false );
	sunrise_agent_assert( is_wp_error( Sunrise\save_policy( array( 'site' => 'on' ) ) ), 'Legacy policy API cannot overwrite central policy' );
	require_once WP_PLUGIN_DIR . '/sunrise/includes/controller.php';
	sunrise_agent_assert( array() === Sunrise\connections(), 'Managed site exposes no legacy peer connections' );
	Sunrise\agent_pause( true );
	sunrise_agent_assert( 'off' === Sunrise\item_policy( 'plugins', 'any/plugin.php' ) && ! Sunrise\filter_core( true, 'minor' ), 'Local emergency pause blocks plugins and core' );
	Sunrise\agent_pause( false );
	$invalid = $state; $invalid['user_id'] = PHP_INT_MAX;
	update_option( 'sunrise_agents', array( $original_user => $invalid ), false );
	sunrise_agent_assert( is_wp_error( Sunrise\agent_check_in() ) && 'off' === Sunrise\item_policy( 'themes', 'any-theme' ), 'Missing enrolling user blocks synchronization and managed updates' );
	$invalid = $state; $invalid['url'] = 'https://changed.invalid';
	update_option( 'sunrise_agents', array( $original_user => $invalid ), false );
	sunrise_agent_assert( 'off' === Sunrise\item_policy( 'plugins', 'any/plugin.php' ), 'Changed installation URL disables managed updates' );
	update_option( 'sunrise_agents', array( $original_user => $state ), false );
	foreach ( array( 'http://control.example', 'https://user:pass@control.example', 'https://control.example/path', 'https://control.example?token=secret', 'https://control.example#fragment', 'https://127.0.0.1', 'https://control.example:8443' ) as $origin ) {
		sunrise_agent_assert( false === Sunrise\agent_control_origin( $origin, false ), 'Unsafe or ambiguous Control address rejected' );
	}
	sunrise_agent_assert( 'https://control.example' === Sunrise\agent_control_origin( 'https://control.example/', false ) && false === Sunrise\agent_control_origin( 'http://127.0.0.1:8787', false ), 'Hosted Control requires HTTPS; loopback HTTP remains local-only' );
	$wrong_service = $state; $wrong_service['control_url'] = 'https://different.example';
	sunrise_agent_assert( ! Sunrise\agent_service_matches( $wrong_service ) && is_wp_error( Sunrise\agent_http( 'agent/check-in', array(), $wrong_service ) ), 'Credentials cannot be sent to a different Control origin' );
	$fixture_id = 'sunrise-identity-test.php';
	$fixture_path = WP_PLUGIN_DIR . '/' . $fixture_id;
	if ( file_exists( $fixture_path ) ) { throw new RuntimeException( 'Identity fixture already exists' ); }
	try {
		$fixture = "<?php\n/*\nPlugin Name: Identity test\nVersion: 1.0\nUpdate URI: https://original.example\n*/\n";
		file_put_contents( $fixture_path, $fixture );
		wp_clean_plugins_cache( false );
		$guarded = $state;
		$headers = Sunrise\agent_item_identity( 'plugins', $fixture_id );
		$guarded['applied']['document']['policy']['plugins'][ $fixture_id ] = 'on';
		$guarded['applied']['document']['decisions']['plugins'][ $fixture_id ] = array( 'specificity' => 2, 'revision' => '999' );
		$guarded['applied']['document']['identities']['plugins'][ $fixture_id ] = array( 'status' => 'matched', 'component_id' => 'test', 'headers' => $headers );
		update_option( 'sunrise_agents', array( $original_user => $guarded ), false );
		sunrise_agent_assert( 'on' === Sunrise\item_policy( 'plugins', $fixture_id ), 'Unchanged approved headers retain the item rule' );
		file_put_contents( $fixture_path, str_replace( 'Version: 1.0', 'Version: 2.0', $fixture ) );
		sunrise_agent_assert( 'on' === Sunrise\item_policy( 'plugins', $fixture_id ), 'Version-only file changes retain the approved identity' );
		file_put_contents( $fixture_path, str_replace( 'original.example', 'different.example', $fixture ) );
		sunrise_agent_assert( 'off' === Sunrise\item_policy( 'plugins', $fixture_id ), 'Fresh local headers block identity drift before synchronization, even with a cached plugin list' );
	} finally {
		if ( file_exists( $fixture_path ) ) { unlink( $fixture_path ); }
		wp_clean_plugins_cache( false );
		update_option( 'sunrise_agents', array( $original_user => $state ), false );
	}
	$document = $state['applied']['document'];
	$wire = array( 'generation' => $state['applied']['generation'], 'document_json' => wp_json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	$wire['hash'] = hash( 'sha256', $wire['document_json'] );
	$fresh = $state; unset( $fresh['applied'] );
	sunrise_agent_assert( ! is_wp_error( Sunrise\agent_accept_policy( $wire, $fresh ) ), 'PHP validates the service policy representation' );
	$wire['hash'] = str_repeat( '0', 64 );
	sunrise_agent_assert( is_wp_error( Sunrise\agent_accept_policy( $wire, $state ) ), 'Modified policy bytes fail hash verification' );
	$document['generation'] = 1;
	$wire['generation'] = 1; $wire['document_json'] = wp_json_encode( $document ); $wire['hash'] = hash( 'sha256', $wire['document_json'] );
	$newer = $state; $newer['applied']['generation'] = 2;
	sunrise_agent_assert( is_wp_error( Sunrise\agent_accept_policy( $wire, $newer ) ), 'Older policy generations cannot replace newer state' );

	// Disconnect is per administrator; an unconnected connector is idle and native settings remain untouched.
	$native_updates = get_site_option( 'auto_update_plugins' ); $http_count = 0;
	$reject = function () use ( &$http_count ) { $http_count++; return array( 'response' => array( 'code' => 401 ), 'headers' => array(), 'body' => '{"error":{"code":"enrollment_revoked"}}' ); };
	add_filter( 'pre_http_request', $reject );
	try {
		Sunrise\agent_store_states( array() ); do_action( 'sunrise_check_in', $original_user );
		sunrise_agent_assert( 0 === $http_count && null === Sunrise\agent_effective_policy() && ! Sunrise\error_capture_enabled(), 'Unconnected plugin sends no reports and supplies no remote policy' );
		Sunrise\agent_store_states( array( $original_user => $state ) ); Sunrise\agent_schedule( 300 );
		$result = Sunrise\agent_check_in();
		sunrise_agent_assert( is_wp_error( $result ) && Sunrise\agent_state()['revoked'] && ! wp_next_scheduled( 'sunrise_check_in', array( $original_user ) ), 'Revocation stops this administrator check-in schedule' );
		sunrise_agent_assert( null === Sunrise\agent_effective_policy() && false === Sunrise\agent_dashboard_url() && ! Sunrise\error_capture_enabled(), 'Revoked connection stops remote policy, dashboard and error reporting' );
		ob_start(); Sunrise\admin_page(); $disconnected_html = ob_get_clean();
		sunrise_agent_assert( false !== strpos( $disconnected_html, 'This connection was disconnected' ) && false !== strpos( $disconnected_html, 'agent_enroll' ) && false === strpos( $disconnected_html, 'id="sunrise-dashboard"' ), 'Disconnected administrator gets the reconnection page' );
		$requests = $http_count; Sunrise\agent_sync(); do_action( 'sunrise_check_in', $original_user );
		sunrise_agent_assert( $requests === $http_count, 'Known revoked connection remains idle on later sync attempts' );
		$other_state = $state; $other_state['user_id'] = $other_admin; Sunrise\agent_store_states( array( $original_user => Sunrise\agent_state(), $other_admin => $other_state ) );
		sunrise_agent_assert( null !== Sunrise\agent_effective_policy(), 'Other administrator policy remains active' );
		sunrise_agent_assert( ! is_wp_error( Sunrise\agent_disconnect() ) && isset( Sunrise\agent_states()[ $other_admin ] ), 'Clearing revoked credentials leaves the other administrator connection intact' );
		sunrise_agent_assert( $native_updates === get_site_option( 'auto_update_plugins' ), 'Disconnect does not rewrite native auto-update selections' );
	} finally { remove_filter( 'pre_http_request', $reject ); }
} finally {
	wp_set_current_user( $original_user );
	if ( is_int( $other_admin ) && $other_admin > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $other_admin ); }
	remove_filter( 'pre_http_request', $reply );
	remove_filter( 'pre_http_request', $block );
	wp_unschedule_hook( 'sunrise_check_in' );
	update_option( 'cron', $saved_cron );
	update_option( 'sunrise_agents', $saved_states, false );
	update_option( 'sunrise_agent_pause', $pause, false );
	update_option( 'sunrise_agent_revoked', $revoked, false );
}
WP_CLI::line( 'Agent checks passed on WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION );
