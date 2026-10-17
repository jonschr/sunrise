<?php
/** Disposable local report/replay test. All outbound HTTP is intercepted; original connection state is restored. */
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once dirname( __DIR__ ) . '/includes/api.php';
require_once dirname( __DIR__ ) . '/includes/controller.php';
$owner = get_current_user_id(); $other = 0;
$names = array( 'sunrise_agents', 'sunrise_agent', 'sunrise_installation', 'sunrise_agent_log', 'sunrise_agent_log_version', 'sunrise_reconnect_errors', 'cron', 'external_updates-sunrise' );
$saved = array(); foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$anchor_path = WP_CONTENT_DIR . '/sunrise-installation.php'; $anchor = file_exists( $anchor_path ) ? file_get_contents( $anchor_path ) : null;
$check = function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); };
$reports = array(); $offline = false; $legacy = false; $wire = null; $names_created = array();
$http = function ( $pre, $args, $url ) use ( &$reports, &$offline, &$legacy, &$wire ) {
 if ( false !== strpos( $url, '/releases/latest/download/update.json' ) ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'name' => 'Sunrise', 'version' => Sunrise\VERSION, 'download_url' => 'https://github.com/jonschr/sunrise/releases/download/v' . Sunrise\VERSION . '/sunrise.zip' ) ) ); }
 if ( false === strpos( $url, '/v1/agent/check-in' ) ) { return new WP_Error( 'fixture_blocked', 'No external requests' ); }
 $report = json_decode( $args['body'], true ); $reports[] = $report;
 if ( $offline ) { return new WP_Error( 'fixture_offline', 'Simulated response loss' ); }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'receipt_sequence' => $report['sequence'], 'policy' => $wire, 'manual_update_activity' => ! $legacy ) ) );
};
add_filter( 'pre_http_request', $http, 10, 3 );
try {
 $identity = wp_generate_uuid4(); Sunrise\installation_write_anchor( $identity );
 update_option( 'sunrise_installation', array_merge( array( 'id' => $identity, 'review' => false, 'anchor_required' => true ), Sunrise\installation_markers( $identity ) ), false );
 $decision = array( 'revision' => '2', 'specificity' => 1 );
 $document = array( 'schema_version' => 2, 'generation' => 2, 'policy' => array( 'site' => 'inherit', 'core' => 'off', 'plugins' => array(), 'themes' => array() ), 'category_defaults' => array( 'plugins' => 'off', 'themes' => 'off' ), 'decisions' => array( 'core' => $decision, 'plugins' => array(), 'themes' => array(), 'category_defaults' => array( 'plugins' => $decision, 'themes' => $decision ) ) );
 $json = wp_json_encode( $document ); $wire = array( 'generation' => 2, 'document_json' => $json, 'hash' => hash( 'sha256', $json ) );
 $state = array( 'user_id' => $owner, 'url' => Sunrise\installation_site_url(), 'control_url' => Sunrise\agent_url(), 'enrollment_id' => wp_generate_uuid4(), 'site_id' => wp_generate_uuid4(), 'account_id' => wp_generate_uuid4(), 'network_id' => wp_generate_uuid4(), 'sequence' => 0, 'generation' => 1, 'secret' => Sunrise\credential( str_repeat( 'a', 64 ), 'agent|' . Sunrise\installation_site_url() . '|' . $owner ), 'paused' => false );
 Sunrise\agent_store_states( array( $owner => $state ) );
 $event = array( 'id' => wp_generate_uuid4(), 'type' => 'plugin', 'installed_id' => 'fixture/plugin.php', 'version' => '2.0', 'status' => 'succeeded', 'code' => 'updated', 'updated_at' => time() );
 $name = 'sunrise_manual_update_' . $event['id']; add_option( $name, $event, '', false ); $names_created[] = $name;
 Sunrise\agent_check_in(); $check( ! isset( $reports[0]['manual_update_activity'] ) && Sunrise\agent_state()['manual_update_activity'], 'New field is sent only after feature negotiation' );
 $offline = true; $result = Sunrise\agent_check_in(); $pending = Sunrise\agent_state()['pending_report'];
 $check( is_wp_error( $result ) && array( $event ) === $pending['manual_update_activity'] && empty( Sunrise\agent_state()['manual_activity_cursor'] ), 'Lost response preserves exact pending event and does not advance the cursor' );
 $offline = false; Sunrise\agent_check_in(); $check( end( $reports ) === $pending && Sunrise\agent_state()['manual_activity_cursor'] > 0, 'Accepted exact replay advances only this connection cursor' );
 Sunrise\agent_check_in(); $check( array() === end( $reports )['manual_update_activity'], 'Acknowledged events are not resent in subsequent batches' );
 $other = wp_insert_user( array( 'user_login' => 'manual-fixture-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ); if ( is_wp_error( $other ) ) { throw new RuntimeException( 'Could not create fixture owner' ); }
 $second = $state; $second['user_id'] = $other; $second['secret'] = Sunrise\credential( str_repeat( 'b', 64 ), 'agent|' . Sunrise\installation_site_url() . '|' . $other ); $second['manual_update_activity'] = true;
 $states = Sunrise\agent_states(); $states[ $other ] = $second; Sunrise\agent_store_states( $states ); wp_set_current_user( $other );
 Sunrise\agent_check_in(); $check( array( $event ) === end( $reports )['manual_update_activity'], 'A separately enrolled administrator reports the same event to their own account' );
 wp_set_current_user( $owner ); $legacy = true; Sunrise\agent_check_in(); Sunrise\agent_check_in(); $check( ! isset( end( $reports )['manual_update_activity'] ), 'Legacy Control remains compatible when feature support is absent' );
 $check( false !== get_option( $name ), 'Account acknowledgments do not delete the shared journal' );
 for ( $i = 0; $i < 105; $i++ ) { $next = $event; $next['id'] = wp_generate_uuid4(); $key = 'sunrise_manual_update_' . $next['id']; add_option( $key, $next, '', false ); $names_created[] = $key; }
 list( $batch, $cursor ) = Sunrise\manual_update_activity(); list( $rest, $end ) = Sunrise\manual_update_activity( $cursor );
 $check( 100 === count( $batch ) && 6 === count( $rest ) && array() === Sunrise\manual_update_activity( $end )[0], 'Backlogs are delivered in bounded batches without silently dropping older events' );
 WP_CLI::success( 'Manual report negotiation, replay, acknowledgment and independent account delivery passed.' );
} finally {
 remove_filter( 'pre_http_request', $http, 10 ); wp_set_current_user( $owner );
 if ( $other && ! is_wp_error( $other ) ) { wp_delete_user( $other ); }
 foreach ( $names_created as $name ) { delete_option( $name ); }
 foreach ( $saved as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
 if ( null === $anchor ) { if ( file_exists( $anchor_path ) ) { unlink( $anchor_path ); } } else { file_put_contents( $anchor_path, $anchor ); }
}
