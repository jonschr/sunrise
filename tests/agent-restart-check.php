<?php
/** wp --user=1 eval-file tests/agent-restart-check.php on a disposable local site. All HTTP is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator with traffic cron disabled required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/admin.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/controller.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
global $wpdb;
$prefix = $wpdb->esc_like( 'sunrise_' ) . '%'; $saved = array();
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) as $name ) {
	if ( '.lock' === substr( $name, -5 ) ) { throw new RuntimeException( 'Active Sunrise work must finish first.' ); }
	$saved[ $name ] = get_option( $name );
}
$cron = get_option( 'cron' ); $owner = get_current_user_id(); $other_admin = 0;
$anchor_path = WP_CONTENT_DIR . '/sunrise-installation.php'; $anchor = file_exists( $anchor_path ) ? file_get_contents( $anchor_path ) : null;
$native_updates = get_site_option( 'auto_update_plugins' );
$verification = 'valid'; $assignment_generation = 1;
$requests = array(); $approved = false; $offline = false; $expired = false; $always_expired = false; $missing = false;
$enrollment_id = wp_generate_uuid4(); $new_site = wp_generate_uuid4(); $account = wp_generate_uuid4(); $network = wp_generate_uuid4();
function restart_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$decision = array( 'revision' => '1', 'specificity' => 0 );
$document = array( 'schema_version' => 2, 'generation' => 1, 'policy' => array( 'site' => 'inherit', 'core' => 'off', 'plugins' => array(), 'themes' => array() ), 'category_defaults' => array( 'plugins' => 'off', 'themes' => 'off' ), 'decisions' => array( 'core' => $decision, 'category_defaults' => array( 'plugins' => $decision, 'themes' => $decision ), 'plugins' => array(), 'themes' => array() ) );
$wire = array( 'generation' => 1, 'document_json' => wp_json_encode( $document ), 'hash' => hash( 'sha256', wp_json_encode( $document ) ) );
$mock = function ( $pre, $args, $url ) use ( &$verification, &$assignment_generation, &$requests, &$approved, &$offline, &$expired, &$always_expired, &$missing, $enrollment_id, $new_site, $account, $network, $wire ) {
	$requests[] = array( 'url' => $url, 'method' => $args['method'], 'authorization' => $args['headers']['Authorization'] ?? null, 'body' => json_decode( $args['body'] ?? '', true ) );
	if ( $offline ) { return new WP_Error( 'fixture_offline' ); }
 if ( Sunrise\agent_url() . '/v1/agent/connection' === $url ) {
  restart_check( '{}' === $args['body'], 'Connection verification sends an empty JSON object, never identity overrides' );
  if ( 'limited' === $verification ) { return array( 'response' => array( 'code' => 429 ), 'headers' => array( 'retry-after' => 900 ), 'body' => '{"error":{"code":"rate_limited"}}' ); }
  if ( 'rejected' === $verification ) { return array( 'response' => array( 'code' => 401 ), 'headers' => array(), 'body' => '{"error":{"code":"enrollment_revoked"}}' ); }
  $current = Sunrise\agent_state(); $body = array_intersect_key( $current, array_flip( array( 'account_id', 'network_id', 'site_id', 'generation' ) ) ); $body['site_url'] = $current['url'];
  if ( 'mismatch' === $verification ) { $body['site_id'] = wp_generate_uuid4(); }
 }
 elseif ( Sunrise\agent_url() . '/v1/enrollments' === $url ) { $body = array( 'id' => $enrollment_id, 'phrase' => 'fresh-code', 'approval_url' => Sunrise\agent_url() . '/?enrollment=' . $enrollment_id ); }
	elseif ( false !== strpos( $url, '/exchange' ) ) {
		if ( $missing ) { $missing = false; return array( 'response' => array( 'code' => 401 ), 'headers' => array(), 'body' => '{"error":{"code":"invalid_credential"}}' ); }
		if ( $expired || $always_expired ) { $expired = false; return array( 'response' => array( 'code' => 410 ), 'headers' => array(), 'body' => '{"error":{"code":"enrollment_expired"}}' ); }
		$body = $approved ? array( 'status' => 'approved', 'site_id' => $new_site, 'account_id' => $account, 'network_id' => $network, 'generation' => $assignment_generation ) : array( 'status' => 'pending' );
	} elseif ( Sunrise\agent_url() . '/v1/agent/check-in' === $url ) { $body = array( 'receipt_sequence' => end( $requests )['body']['sequence'], 'policy' => $wire ); }
	elseif ( false !== strpos( $url, '/jonschr/sunrise/releases/latest/download/update.json' ) ) { $body = array( 'name' => 'Sunrise', 'version' => Sunrise\VERSION, 'download_url' => 'https://github.com/jonschr/sunrise/releases/download/v' . Sunrise\VERSION . '/sunrise.zip' ); }
	else { return new WP_Error( 'unexpected_fixture_http' ); }
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
};
$salt = function ( $value ) { return $value . '-restart-fixture'; };
$url_change = function () { return 'https://clone.example'; };
$runtime_url = function ( $url ) { return preg_replace( '#^https:#', 'http:', $url ); };
$translated_home = function ( $url ) { return untrailingslashit( $url ) . '/en'; };
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	Sunrise\agent_store_states( array() ); delete_option( 'sunrise_remote_job_fence' );
	$id = wp_generate_uuid4(); Sunrise\installation_write_anchor( $id );
	$identity = array_merge( array( 'id' => $id, 'review' => false, 'anchor_required' => true ), Sunrise\installation_markers( $id ) ); update_option( 'sunrise_installation', $identity, false );
	$old = array( 'control_url' => Sunrise\agent_url(), 'url' => Sunrise\installation_site_url(), 'user_id' => $owner, 'sequence' => 12, 'site_id' => wp_generate_uuid4(), 'enrollment_id' => wp_generate_uuid4(), 'account_id' => $account, 'network_id' => $network, 'generation' => 2 );
	$old['secret'] = Sunrise\credential( str_repeat( 'a', 64 ), 'agent|' . $old['url'] . '|' . $owner ); $old['digest'] = hash( 'sha256', str_repeat( 'a', 64 ) );
	$old['reconnect'] = array( 'site_id' => $old['site_id'], 'generation' => 2, 'enrollment_id' => wp_generate_uuid4(), 'approval_url' => Sunrise\agent_url() . '/?enrollment=stale', 'phrase' => 'stale-code' );
	Sunrise\agent_store( $old );
	$other_admin = wp_insert_user( array( 'user_login' => 'sunrise-restart-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'administrator' ) );
	if ( is_wp_error( $other_admin ) ) { throw new RuntimeException( 'Could not create administrator fixture.' ); }
	wp_set_current_user( $other_admin );
	restart_check( is_wp_error( Sunrise\agent_restart_enrollment( true ) ) && ! $requests && $old === Sunrise\agent_states()[ $owner ], 'Another administrator cannot reset the owner connection' );
	restart_check( 403 === rest_do_request( new WP_REST_Request( 'POST', '/sunrise/v1/agent/reconnect' ) )->get_status() && $old === Sunrise\agent_states()[ $owner ], 'Another administrator cannot repair the owner connection through REST' );
	wp_set_current_user( $owner );
	restart_check( is_wp_error( Sunrise\agent_restart_enrollment() ) && ! $requests && $old === Sunrise\agent_state(), 'A new connection requires explicit confirmation' );
	WP_Upgrader::create_lock( 'sunrise_agent', 120 ); restart_check( is_wp_error( Sunrise\agent_restart_enrollment( true ) ) && $old === Sunrise\agent_state(), 'Concurrent connection work blocks reset' ); WP_Upgrader::release_lock( 'sunrise_agent' );
	$pending = $old; $pending['remote_job'] = array( 'id' => wp_generate_uuid4() ); Sunrise\agent_store( $pending );
	restart_check( is_wp_error( Sunrise\agent_restart_enrollment( true ) ) && $pending === Sunrise\agent_state(), 'Pending update work is never discarded' ); Sunrise\agent_store( $old );
	update_option( 'sunrise_remote_job_fence', array( 'id' => wp_generate_uuid4() ), false );
	restart_check( is_wp_error( Sunrise\agent_restart_enrollment( true ) ) && ! $requests, 'An interrupted update fence blocks reset' ); delete_option( 'sunrise_remote_job_fence' );
	$other = $old; $other['user_id'] = $other_admin; Sunrise\agent_store( $other );
	ob_start(); Sunrise\admin_page(); $html = ob_get_clean();
	restart_check( false !== strpos( $html, 'Create new site connection' ) && false !== strpos( $html, 'name="confirm_restart"' ) && false !== strpos( $html, 'Waiting for approval in Sunrise Control' ), 'Settings show confirmed recovery and honest pending status' );
	$result = Sunrise\agent_restart_enrollment( true ); $fresh = Sunrise\agent_state();
	restart_check( ! is_wp_error( $result ) && 1 === count( $requests ) && 'POST' === $requests[0]['method'] && ! isset( $requests[0]['body']['reconnect_site_id'] ) && $old['digest'] !== $fresh['digest'] && empty( $fresh['site_id'] ) && empty( $fresh['reconnect'] ) && 0 === $fresh['sequence'], 'Deleted-record recovery creates a fresh credential and enrollment without using or revoking the old connection' );
	restart_check( $other === Sunrise\agent_states()[ $other_admin ] && $native_updates === get_site_option( 'auto_update_plugins' ) && $identity === Sunrise\installation_identity(), 'Other connections, native update settings, and healthy identity are preserved' );
	$result = Sunrise\agent_check_in(); restart_check( is_wp_error( $result ) && 'sunrise_approval_pending' === $result->get_error_code() && empty( Sunrise\agent_state()['site_id'] ), 'Fresh connection waits for new approval' );
	$approved = true; $result = Sunrise\agent_check_in();
	restart_check( ! is_wp_error( $result ) && $new_site === Sunrise\agent_state()['site_id'] && 1 === Sunrise\agent_state()['sequence'] && ! empty( Sunrise\agent_state()['applied'] ), 'New approval completes inventory reporting and policy receipt' );
 $normal = Sunrise\agent_state(); ob_start(); Sunrise\admin_page(); $connected_html = ob_get_clean();
 $primary = explode( 'Advanced local controls', $connected_html )[0];
 restart_check( false !== strpos( $primary, 'Check in now' ) && false === strpos( $primary, 'Replace connection credentials' ) && false === strpos( $primary, 'Create new site connection' ) && false === strpos( $primary, 'Finish connection' ) && false !== strpos( $primary, 'Approximately every 1 hour' ), 'A healthy connection shows one check-in action and the actual hourly cadence; exceptional actions stay advanced' );
 $review = Sunrise\installation_identity(); $review['review'] = true; update_option( 'sunrise_installation', $review, false ); $requests = array();
 $verified = Sunrise\agent_connection_recover();
 restart_check( is_array( $verified ) && 'connected' === $verified['status'] && $normal['digest'] === Sunrise\agent_state()['digest'] && $normal['generation'] === Sunrise\agent_state()['generation'] && true === Sunrise\installation_guard() && ! array_filter( $requests, function ( $r ) { return false !== strpos( $r['url'], '/enrollments' ); } ), 'A valid existing key verifies and resumes reporting without replacement, enrollment or Control approval' );
 foreach ( array( 'offline', 'limited', 'mismatch' ) as $failure ) {
  Sunrise\agent_store( $normal ); update_option( 'sunrise_installation', $review, false ); $offline = 'offline' === $failure; $verification = $failure; $requests = array();
  $result = Sunrise\agent_connection_recover();
  restart_check( is_wp_error( $result ) && $normal['digest'] === Sunrise\agent_state()['digest'] && empty( Sunrise\agent_state()['reconnect'] ) && is_wp_error( Sunrise\installation_guard() ) && ! array_filter( $requests, function ( $r ) { return false !== strpos( $r['url'], '/enrollments' ); } ), 'Verification ' . $failure . ' retains the original credential and cannot release identity review' );
 }
 $offline = false; $verification = 'rejected'; Sunrise\agent_store( $normal ); update_option( 'sunrise_installation', $review, false ); $approved = false;
 $denied = Sunrise\agent_connection_recover();
 restart_check( is_array( $denied ) && 'pending' === $denied['status'] && ! empty( Sunrise\agent_state()['reconnect'] ) && $normal['digest'] !== Sunrise\agent_state()['digest'] && is_wp_error( Sunrise\installation_guard() ), 'A rejected old key starts replacement recovery and still requires human approval' );
 $verification = 'valid'; Sunrise\agent_store( $normal ); update_option( 'sunrise_installation', $identity, false );
 $recovering = $old; $recovering['site_id'] = $new_site; $recovering['generation'] = 1; $recovering['reconnect']['site_id'] = $new_site; $recovering['reconnect']['generation'] = 1;
 Sunrise\agent_store( $recovering ); $approved = false; $assignment_generation = 2;
 ob_start(); Sunrise\admin_page(); $waiting_html = ob_get_clean(); $primary = explode( 'Advanced local controls', $waiting_html )[0];
 restart_check( 1 === substr_count( $primary, 'data-sunrise-connection' ) && false !== strpos( $primary, 'name="operation" value="agent_reconnect"' ) && false !== strpos( $primary, 'Continue reconnection' ) && false !== strpos( $primary, 'data-pending="1"' ) && false === strpos( $primary, 'Check in now' ) && false === strpos( $primary, 'Finish connection' ), 'Recovery shows one resumable approval action and enables automatic completion polling' );
 $waiting = Sunrise\agent_connection_recover();
 restart_check( is_array( $waiting ) && 'pending' === $waiting['status'] && ! empty( $waiting['approval_url'] ) && Sunrise\agent_connection_pending( Sunrise\agent_sync() ), 'The browser recovery route returns a usable approval link and keeps check-ins pending before approval' );
 $approved = true; $completed = Sunrise\agent_connection_recover(); $repaired = Sunrise\agent_state();
 restart_check( is_array( $completed ) && 'connected' === $completed['status'] && 2 === $repaired['generation'] && ! empty( $repaired['last_success'] ) && ! empty( $repaired['applied'] ) && empty( $repaired['reconnect'] ), 'Approval collection includes a successful inventory report and policy acknowledgment before the browser sees connected' );
 $requests = array(); Sunrise\agent_connection_recover();
 restart_check( $repaired['digest'] === Sunrise\agent_state()['digest'] && ! array_filter( $requests, function ( $r ) { return false !== strpos( $r['url'], '/exchange' ); } ), 'A stale browser tab cannot rotate an already-repaired connection again' );
 Sunrise\agent_store( $normal ); $assignment_generation = 1;
	add_filter( 'site_url', $runtime_url ); add_filter( 'home_url', $translated_home );
	$result = Sunrise\agent_check_in();
	restart_check( 0 === strpos( site_url(), 'http:' ) && '/en' === substr( home_url(), -3 ) && true === Sunrise\installation_guard() && ! is_wp_error( $result ) && $identity === Sunrise\installation_identity(), 'Background HTTP scheme and translated frontend URLs cannot change identity or interrupt authenticated check-ins' );
	remove_filter( 'site_url', $runtime_url ); remove_filter( 'home_url', $translated_home );
	$approved = false; Sunrise\agent_store( $old ); $expired = true; $requests = array();
	$result = Sunrise\agent_reauthenticate();
	restart_check( is_wp_error( $result ) && 'sunrise_reconnect_pending' === $result->get_error_code() && 3 === count( $requests ) && $enrollment_id === Sunrise\agent_state()['reconnect']['enrollment_id'] && 'fresh-code' === Sunrise\agent_state()['reconnect']['phrase'], 'Expired re-authentication gets a fresh approval link in one click' );
	Sunrise\agent_store( $old ); $missing = true; $requests = array(); $result = Sunrise\agent_reauthenticate();
	restart_check( is_wp_error( $result ) && 'sunrise_reconnect_pending' === $result->get_error_code() && 3 === count( $requests ) && $enrollment_id === Sunrise\agent_state()['reconnect']['enrollment_id'], 'A deleted expired enrollment is renewed after invalid_credential instead of retrying forever' );
	Sunrise\agent_store( $old ); $always_expired = true; $requests = array(); Sunrise\agent_reauthenticate(); $always_expired = false;
	restart_check( 3 === count( $requests ) && empty( Sunrise\agent_state()['reconnect']['approval_url'] ) && empty( Sunrise\agent_state()['reconnect']['phrase'] ), 'Repeated expiration is bounded and clears stale approval details' );
	Sunrise\agent_store( $old ); add_filter( 'salt', $salt );
	restart_check( is_wp_error( Sunrise\installation_guard() ), 'Security-key change pauses this installation' );
	$requests = array(); $result = Sunrise\agent_restart_enrollment( true );
	restart_check( ! is_wp_error( $result ) && ! empty( Sunrise\agent_state()['identity_recovery'] ) && is_wp_error( Sunrise\installation_guard() ), 'Same-site security-key recovery creates new approval while keeping the identity paused' );
	$result = Sunrise\agent_check_in(); restart_check( 'sunrise_approval_pending' === $result->get_error_code() && is_wp_error( Sunrise\installation_guard() ), 'Pending replacement cannot release the identity guard' );
	$approved = true; $result = Sunrise\agent_check_in();
	restart_check( ! is_wp_error( $result ) && true === Sunrise\installation_guard() && empty( Sunrise\agent_state()['identity_recovery'] ) && $id === Sunrise\installation_anchor() && $other === Sunrise\agent_states()[ $other_admin ], 'Fresh approval restores the same identity without deleting other administrators' );
	remove_filter( 'salt', $salt ); update_option( 'sunrise_installation', $identity, false ); Sunrise\agent_store( $old );
	add_filter( 'option_siteurl', $url_change ); $before = count( $requests );
	restart_check( is_wp_error( Sunrise\agent_restart_enrollment( true ) ) && $old === Sunrise\agent_state() && $before === count( $requests ), 'A changed-domain clone cannot use same-site recovery' ); remove_filter( 'option_siteurl', $url_change ); update_option( 'sunrise_installation', $identity, false );
	$offline = true; $result = Sunrise\agent_restart_enrollment( true ); $retry = Sunrise\agent_state(); $offline = false;
	restart_check( is_wp_error( $result ) && empty( $retry['site_id'] ) && $retry['digest'] !== $old['digest'] && ! is_wp_error( Sunrise\agent_enroll() ) && $retry['digest'] === Sunrise\agent_state()['digest'], 'A transport outage retains fresh credentials for safe enrollment retry' );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 ); remove_filter( 'salt', $salt ); remove_filter( 'option_siteurl', $url_change ); remove_filter( 'site_url', $runtime_url ); remove_filter( 'home_url', $translated_home ); wp_set_current_user( $owner ); WP_Upgrader::release_lock( 'sunrise_agent' );
	if ( is_int( $other_admin ) && $other_admin > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $other_admin ); }
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) as $name ) { delete_option( $name ); }
	foreach ( $saved as $name => $value ) { add_option( $name, $value, '', false ); }
	update_option( 'cron', $cron );
	if ( null === $anchor ) { if ( file_exists( $anchor_path ) ) { unlink( $anchor_path ); } } else { file_put_contents( $anchor_path, $anchor ); chmod( $anchor_path, 0640 ); }
}
WP_CLI::success( 'Connection recovery checks passed; no outbound requests were sent.' );
