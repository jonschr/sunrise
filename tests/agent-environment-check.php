<?php
/** cove wp sunrise-environment-check --user=1 eval-file /path/to/tests/agent-environment-check.php. All HTTP is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || defined( 'SUNRISE_CONTROL_URL' ) || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator without a Control override required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/admin.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/controller.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
if ( is_wp_error( Sunrise\installation_guard() ) ) { throw new RuntimeException( 'Fixture identity must already be healthy.' ); }
function sunrise_environment_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
global $wpdb;
$prefix = $wpdb->esc_like( 'sunrise_' ) . '%'; $saved = array();
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) as $name ) {
 if ( '.lock' === substr( $name, -5 ) ) { throw new RuntimeException( 'Active Sunrise work must finish first.' ); }
 $saved[ $name ] = get_option( $name );
}
$cron = get_option( 'cron' ); $owner = get_current_user_id(); $query = $_GET; $calls = array();
$mock = function ( $pre, $args, $url ) use ( &$calls ) {
 $calls[] = array( 'url' => $url, 'method' => $args['method'], 'authorization' => $args['headers']['Authorization'], 'body' => json_decode( $args['body'], true ) );
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => wp_generate_uuid4(), 'approval_url' => Sunrise\agent_url() . '/approve', 'phrase' => 'fixture' ) ) );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
 delete_option( 'sunrise_control_environment' ); delete_option( 'sunrise_agents' ); delete_option( 'sunrise_agent' ); delete_option( 'sunrise_remote_job_fence' );
 sunrise_environment_assert( 'https://desk.sunrisecontrol.app' === Sunrise\agent_url(), 'Fresh install defaults to production' );
 sunrise_environment_assert( is_wp_error( Sunrise\agent_set_environment( 'https://untrusted.example' ) ), 'Arbitrary endpoints cannot be selected' );
 wp_set_current_user( 0 ); sunrise_environment_assert( is_wp_error( Sunrise\agent_set_environment( 'staging' ) ), 'Unauthenticated users cannot change environments' ); wp_set_current_user( $owner );
 $_GET = array(); ob_start(); Sunrise\agent_environment_form(); sunrise_environment_assert( '' === ob_get_clean(), 'Environment setting is hidden from the normal screen' );
 $_GET['sunrise_environment'] = '1'; ob_start(); Sunrise\agent_environment_form(); $html = ob_get_clean();
 sunrise_environment_assert( false !== strpos( $html, 'name="_wpnonce"' ) && false !== strpos( $html, 'Save environment' ), 'Hidden setting renders a nonce-protected form' );
 $state = array( 'control_url' => Sunrise\agent_url(), 'url' => untrailingslashit( site_url() ), 'user_id' => $owner, 'sequence' => 7, 'site_id' => wp_generate_uuid4(), 'enrollment_id' => wp_generate_uuid4(), 'generation' => 1 );
 $old_secret = bin2hex( random_bytes( 32 ) ); $state['secret'] = Sunrise\credential( $old_secret, 'agent|' . $state['url'] . '|' . $owner ); $state['digest'] = hash( 'sha256', $old_secret );
 Sunrise\agent_store( $state );
 WP_Upgrader::create_lock( 'sunrise_agent', 120 ); sunrise_environment_assert( is_wp_error( Sunrise\agent_set_environment( 'staging' ) ), 'Active connection work blocks an environment change' ); WP_Upgrader::release_lock( 'sunrise_agent' );
 $pending = $state; $pending['remote_job'] = array( 'id' => wp_generate_uuid4() ); Sunrise\agent_store( $pending );
 sunrise_environment_assert( is_wp_error( Sunrise\agent_set_environment( 'staging' ) ) && 'https://desk.sunrisecontrol.app' === Sunrise\agent_url(), 'Pending update work blocks the switch' ); Sunrise\agent_store( $state );
 update_option( 'sunrise_remote_job_fence', array( 'id' => wp_generate_uuid4() ), false ); sunrise_environment_assert( is_wp_error( Sunrise\agent_set_environment( 'staging' ) ), 'Interrupted update fence blocks the switch' ); delete_option( 'sunrise_remote_job_fence' );
 sunrise_environment_assert( true === Sunrise\agent_set_environment( 'staging' ) && 'https://staging.sunrisecontrol.app' === Sunrise\agent_url(), 'Staging can be selected without a code change' );
 sunrise_environment_assert( $state === Sunrise\agent_state(), 'Selecting an environment preserves the old connection until explicit enrollment' );
 sunrise_environment_assert( is_wp_error( Sunrise\agent_http( 'agent/check-in', array(), $state ) ) && is_wp_error( Sunrise\agent_reconnect( $state ) ) && ! $calls, 'Old credentials and existing-record recovery cannot cross environments' );
 sunrise_environment_assert( 'off' === Sunrise\agent_effective_policy()['policy']['site'] && Sunrise\agent_connection_needed(), 'Old policy pauses and the site offers a fresh connection' );
 $result = Sunrise\agent_enroll(); sunrise_environment_assert( ! is_wp_error( $result ) && 1 === count( $calls ) && 'https://staging.sunrisecontrol.app/v1/enrollments' === $calls[0]['url'] && 'POST' === $calls[0]['method'], 'Explicit connect enrolls in staging without deleting the production record' );
 sunrise_environment_assert( 'Bearer ' . $old_secret !== $calls[0]['authorization'] && ! isset( $calls[0]['body']['reconnect_site_id'] ) && 'https://staging.sunrisecontrol.app' === Sunrise\agent_state()['control_url'], 'New environment gets a fresh credential and site enrollment' );
 sunrise_environment_assert( true === Sunrise\agent_set_environment( 'production' ) && 'https://desk.sunrisecontrol.app' === Sunrise\agent_url(), 'Production can be selected again' );
} finally {
 remove_filter( 'pre_http_request', $mock, 10 ); wp_set_current_user( $owner ); $_GET = $query; WP_Upgrader::release_lock( 'sunrise_agent' );
 foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) as $name ) { delete_option( $name ); }
 foreach ( $saved as $name => $value ) { add_option( $name, $value, '', false ); }
 update_option( 'cron', $cron );
}
WP_CLI::success( 'Environment switch checks passed; no outbound requests were sent.' );
