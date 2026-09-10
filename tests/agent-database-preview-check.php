<?php
/** Disposable enrolled local owner. All HTTP is intercepted; no database write is performed. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-transfers.php';
function agent_database_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$states = Sunrise\agent_states(); $reports = array(); $tasks = array(); $reject = false; $id = wp_generate_uuid4();
$tasks[] = array( 'id' => $id, 'phase' => 'source', 'deadline' => gmdate( 'c', time() + HOUR_IN_SECONDS ), 'names' => array( 'database' ) );
$http = function ( $pre, $args, $url ) use ( &$tasks, &$reports, &$reject ) {
	if ( false !== strpos( $url, '/agent/transfers/claim' ) ) { $response = array( 'transfer' => array_shift( $tasks ) ); }
	elseif ( $reject ) { return array( 'response' => array( 'code' => 409 ), 'headers' => array(), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'transfer_expired' ) ) ) ); }
	else { $reports[] = json_decode( $args['body'], true ); $response = array( 'transfer' => array( 'accepted' => true ) ); }
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $response ) );
};
add_filter( 'pre_http_request', $http, 10, 3 );
try {
	$state = Sunrise\agent_state(); $state['transfer_previews'] = true; unset( $state['transfer_report'] ); Sunrise\agent_store( $state );
	agent_database_check( true === Sunrise\agent_prepare_transfer() && 'database' === $reports[0]['scope'], 'Agent reports a bounded database manifest through the existing transfer channel' );
	$root = Sunrise\transfer_file_workspace( $id ); agent_database_check( ! glob( $root . '/database-*.jsonl' ), 'Read-only source row files are removed after the manifest is accepted' );
	$source = $reports[0]; $source['site_id'] = wp_generate_uuid4(); $tasks[] = array( 'id' => wp_generate_uuid4(), 'phase' => 'destination', 'deadline' => gmdate( 'c', time() + HOUR_IN_SECONDS ), 'names' => array( 'database' ), 'snapshot' => $source );
	agent_database_check( true === Sunrise\agent_prepare_transfer() && 'database' === $reports[1]['plan']['scope'] && $reports[1]['read_only'] && $reports[1]['authorization_required'], 'Destination returns an identity-bound read-only inventory plan' );
	$rejected = wp_generate_uuid4(); $tasks[] = array( 'id' => $rejected, 'phase' => 'source', 'deadline' => gmdate( 'c', time() + HOUR_IN_SECONDS ), 'names' => array( 'database' ) ); $reject = true;
	agent_database_check( is_wp_error( Sunrise\agent_prepare_transfer() ) && ! glob( Sunrise\transfer_file_workspace( $rejected ) . '/database-*.jsonl' ) && empty( Sunrise\agent_state()['transfer_report'] ), 'A terminal Control rejection removes retained database rows and pending state' );
} finally { remove_filter( 'pre_http_request', $http, 10 ); Sunrise\agent_store_states( $states ); }
WP_CLI::success( 'Database transfer preflight passed without destination writes.' );
