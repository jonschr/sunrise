<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page( 'Sunrise Site Settings', 'Sunrise', 'manage_options', 'sunrise', __NAMESPACE__ . '\\admin_page' );
} );

// Preserve old Settings bookmarks before WordPress checks the removed menu's permissions.
add_action( 'admin_init', function () {
	global $pagenow;
	if ( 'options-general.php' !== $pagenow || ! isset( $_GET['page'] ) || 'sunrise' !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) { return; }
	$params = array( 'page' => 'sunrise' );
	foreach ( array( 'site', 'sunrise_environment' ) as $key ) { if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) { $params[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); } }
	wp_safe_redirect( add_query_arg( $params, admin_url( 'tools.php' ) ) ); exit;
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( agent_connection_needed() || ( 'tools_page_sunrise' === $hook && agent_state() ) ) {
		wp_enqueue_script( 'sunrise-connect', plugins_url( '../assets/connect.js', __FILE__ ), array(), VERSION, true );
		wp_localize_script( 'sunrise-connect', 'sunriseConnect', array( 'enroll' => rest_url( 'sunrise/v1/agent/connect' ), 'sync' => rest_url( 'sunrise/v1/agent/check-in' ), 'reconnect' => rest_url( 'sunrise/v1/agent/reconnect' ), 'control' => agent_url(), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}
	if ( 'tools_page_sunrise' === $hook ) {
		wp_add_inline_style( 'common', '.sunrise-wrap{max-width:1180px}.sunrise-wrap>form,.sunrise-wrap details{margin:12px 0}.sunrise-wrap p{max-width:90ch}.sunrise-wrap summary{cursor:pointer}.sunrise-wrap select{margin-right:6px}.sunrise-wrap pre{white-space:pre-wrap;overflow-wrap:anywhere}.sunrise-wrap td{padding:12px}.sunrise-wrap th{width:33.33%}.sunrise-wrap small{display:block;margin-top:6px}.sunrise-settings{max-width:820px}.sunrise-settings form{display:inline-block;margin:0 8px 12px 0}.sunrise-settings table{margin:12px 0 18px}.sunrise-diagnostics textarea{width:100%;font:12px/1.5 monospace}.sunrise-totals{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin:20px 0}.sunrise-totals>div{background:white;border:1px solid #c3c4c7;padding:20px}.sunrise-totals strong{display:block;font-size:32px;line-height:1.3}.sunrise-totals span{color:#50575e}' );
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices' ) as $notice_hook ) { remove_all_actions( $notice_hook ); }
		wp_enqueue_script( 'sunrise-shell', plugins_url( '../assets/shell.js', __FILE__ ), array(), VERSION, true );
		admin_load();
		wp_enqueue_style( 'sunrise-settings', plugins_url( '../assets/settings.css', __FILE__ ), array(), VERSION );
		if ( agent_url() ) { return; }
		wp_enqueue_script( 'sunrise-network', plugins_url( '../assets/network.js', __FILE__ ), array(), VERSION, true );
		wp_localize_script( 'sunrise-network', 'sunriseNetwork', array( 'url' => rest_url( 'sunrise/v1/controller/refresh/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'sites' => array_keys( connections() ), 'refreshing' => __( 'Refreshing site', 'sunrise' ), 'finished' => __( 'Finished. Reloading inventory…', 'sunrise' ) ) );
	}
} );

function admin_load() {
	require_once __DIR__ . '/api.php';
	require_once __DIR__ . '/jobs.php';
	require_once __DIR__ . '/controller.php';
}

function agent_connection_needed() {
	$state = agent_state();
	return current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' ) && current_user_can( 'update_themes' ) && current_user_can( 'update_core' ) && agent_url() && ( ! $state || empty( $state['site_id'] ) || ! empty( $state['revoked'] ) || ! agent_service_matches( $state ) ) && ! is_wp_error( installation_guard() );
}

function connection_form( $operation = 'agent_enroll', $label = 'Connect this site', $pending = false, $approval_url = '', $phrase = '' ) {
	form_start( 'local', $operation );
	echo '<div class="sunrise-connection-primary" data-sunrise-connection data-pending="' . ( $pending ? '1' : '0' ) . '"><button type="submit" class="button button-primary">' . esc_html__( $label, 'sunrise' ) . '</button> <span class="sunrise-connect-status" role="status" aria-live="polite"></span><p><a data-sunrise-approval href="' . esc_url( $approval_url ) . '" target="_blank" rel="noopener noreferrer"' . ( $approval_url ? '' : ' hidden' ) . '>' . ( $approval_url ? esc_html__( 'Open Sunrise Control to approve', 'sunrise' ) : '' ) . '</a> <span data-sunrise-phrase>' . esc_html( $phrase ? sprintf( __( 'Verification phrase: %s', 'sunrise' ), $phrase ) : '' ) . '</span></p></div></form>';
}

function diagnostic_log_section() {
	$diagnostics = wp_json_encode( agent_diagnostic_log(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	echo '<details class="sunrise-diagnostics"><summary>' . esc_html__( 'Diagnostic log', 'sunrise' ) . '</summary><p>' . esc_html__( 'Includes connection-owner names, WordPress user IDs, site and Control identifiers, URLs and recent events for support. It excludes credentials, salts, cookies, authorization headers and request bodies.', 'sunrise' ) . '</p><p><button type="button" class="button" data-sunrise-copy-log>' . esc_html__( 'Copy diagnostic log', 'sunrise' ) . '</button> <span role="status" aria-live="polite"></span></p><textarea readonly rows="16" id="sunrise-diagnostic-log">' . esc_textarea( $diagnostics ) . '</textarea></details>';
}

/** Shared maintenance view; each connection's repair controls remain on its owner's page. */
function other_connections_section() {
 $shown = false;
 foreach ( agent_states() as $owner_id => $state ) {
  if ( (int) $owner_id === get_current_user_id() || empty( $state['site_id'] ) ) { continue; }
  $shown = true; $owner = get_user_by( 'id', $owner_id );
  $name = $owner ? $owner->display_name . ' (' . $owner->user_login . ')' : sprintf( __( 'Deleted administrator (ID %d)', 'sunrise' ), $owner_id );
  $ready = empty( $state['revoked'] ) && empty( $state['reconnect'] ) && empty( $state['identity_recovery'] ) && agent_owner_valid( $state ) && agent_service_matches( $state ) && ! is_wp_error( installation_guard() ) && $state['url'] === installation_site_url();
  echo '<section class="sunrise-settings-card"><h2>' . esc_html__( 'Site connection', 'sunrise' ) . '</h2><p><strong>' . esc_html__( 'Owned by', 'sunrise' ) . ': ' . esc_html( $name ) . '</strong></p>';
  echo '<p>' . esc_html( $ready ? ( ! empty( $state['paused'] ) ? __( 'Connected; managed automatic updates paused', 'sunrise' ) : __( 'Connected', 'sunrise' ) ) : __( 'Owner attention required', 'sunrise' ) ) . '</p>';
  if ( ! empty( $state['last_success'] ) ) { echo '<p>' . esc_html__( 'Last successful check-in', 'sunrise' ) . ': ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['last_success'] ) ) . '</p>'; }
  echo '<p>' . esc_html__( 'You can check in and copy diagnostics. Sign in as the connection owner to reconnect, replace credentials or disconnect.', 'sunrise' ) . '</p>';
  if ( $ready ) { form_start( 'local', 'agent_site_check_in' ); echo '<input type="hidden" name="connection_user_id" value="' . esc_attr( $owner_id ) . '">'; submit_button( __( 'Check in now', 'sunrise' ), 'primary', 'submit', false ); echo '</form>'; }
  echo '</section>';
 }
 return $shown;
}

add_action( 'admin_notices', function () {
	if ( ! agent_connection_needed() || ! current_user_can( 'manage_options' ) || 'tools_page_sunrise' === get_current_screen()->id ) { return; }
	foreach ( agent_states() as $owner_state ) { if ( ! empty( $owner_state['site_id'] ) && empty( $owner_state['revoked'] ) && empty( $owner_state['reconnect'] ) && empty( $owner_state['identity_recovery'] ) && agent_owner_valid( $owner_state ) && agent_service_matches( $owner_state ) && $owner_state['url'] === installation_site_url() && ! is_wp_error( installation_guard() ) ) { return; } }
	echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Connect this site to Sunrise', 'sunrise' ) . '</strong></p><p>' . esc_html__( 'Send this site’s update status to your Sunrise network and manage its updates there.', 'sunrise' ) . '</p>';
	connection_form(); echo '<p></p></div>';
} );

function admin_request( $site, $path, $method = 'GET', $body = null ) {
	if ( 'local' !== $site ) {
		$sites = connections();
		$result = isset( $sites[ $site ] ) ? controller_request( $sites[ $site ], $path, $method, $body ) : job_error( 'connection_missing', __( 'Connection not found.', 'sunrise' ), 404 );
		if ( 'inventory' === $path && ! is_wp_error( $result ) ) {
			update_option( snapshot_key( $site ), array( 'inventory' => $result, 'checked_at' => time(), 'error' => null ), false );
		}
		return $result;
	}
	if ( 'inventory' === $path ) {
		return inventory();
	}
	if ( 'policy' === $path ) {
		return save_policy( $body );
	}
	if ( 'jobs' === $path ) {
		$result = enqueue_job( $body );
	} else {
		$parts = explode( '/', $path );
		$id = $parts[1];
		if ( isset( $parts[2] ) ) {
			$result = 'run' === $parts[2] ? run_job( $id ) : acknowledge_job( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$result = job_response( $id );
	}
	return $result instanceof \WP_REST_Response ? $result->get_data() : $result;
}

add_action( 'admin_post_sunrise', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Administrator access is required.', 'sunrise' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sunrise' );
	$access = agent_access( isset( $_POST['operation'] ) && in_array( $_POST['operation'], array( 'agent_enroll', 'agent_environment', 'agent_site_check_in' ), true ) );
	$recovery = isset( $_POST['operation'] ) && 'identity_resolve' === $_POST['operation'] && is_wp_error( installation_guard() );
	if ( is_wp_error( $access ) && ! $recovery ) { wp_die( esc_html( $access->get_error_message() ), '', array( 'response' => 403 ) ); }
	admin_load();
	$input = wp_unslash( $_POST );
	$site = isset( $input['site'] ) && is_string( $input['site'] ) ? sanitize_key( $input['site'] ) : 'local';
	$action = isset( $input['operation'] ) && is_string( $input['operation'] ) ? $input['operation'] : '';
	$result = job_error( 'invalid_action', __( 'Unknown action.', 'sunrise' ), 400 );
	if ( 'identity_resolve' === $action ) {
		$result = installation_resolve( isset( $input['identity_kind'] ) ? $input['identity_kind'] : '', isset( $input['installation_id'] ) ? $input['installation_id'] : '', isset( $input['confirm_identity'] ) && '1' === $input['confirm_identity'] );
		if ( ! is_wp_error( $result ) && agent_url() ) { $result = agent_enroll(); }
	} elseif ( 'errors_enable' === $action || 'errors_disable' === $action ) {
		$result = error_capture_setting( 'errors_enable' === $action );
	} elseif ( 'agent_enroll' === $action ) {
		$result = agent_enroll();
	} elseif ( 'agent_environment' === $action ) {
		$result = agent_set_environment( $input['environment'] ?? null );
	} elseif ( 'agent_reconnect' === $action || 'agent_reauthenticate' === $action ) {
		$result = agent_reauthenticate( 'agent_reauthenticate' === $action );
		if ( ! is_wp_error( $result ) ) { $result = agent_sync(); }
	} elseif ( 'agent_restart_enrollment' === $action ) {
		$result = agent_restart_enrollment( isset( $input['confirm_restart'] ) && '1' === $input['confirm_restart'] );
	} elseif ( 'agent_disconnect' === $action ) {
		$result = agent_disconnect();
	} elseif ( 'agent_pause' === $action || 'agent_resume' === $action ) {
		$result = agent_pause( 'agent_pause' === $action );
	} elseif ( 'agent_site_check_in' === $action ) {
		$result = agent_site_check_in( $input['connection_user_id'] ?? null );
	} elseif ( 'agent_sync' === $action ) {
		$result = agent_sync();
	} elseif ( 'connect' === $action ) {
		if ( isset( $input['url'], $input['username'], $input['password'] ) && is_string( $input['url'] ) && is_string( $input['username'] ) && is_string( $input['password'] ) ) {
			$result = connect_site( $input['url'], $input['username'], $input['password'], ! empty( $input['trust_local_certificate'] ) );
			if ( ! is_wp_error( $result ) ) {
				$site = $result;
			}
		}
	} elseif ( 'disconnect' === $action ) {
		$result = remove_peer( $site );
		$site = 'overview';
	} elseif ( 'refresh_peer' === $action ) {
		$result = refresh_peer( $site );
		$site = 'overview';
	} elseif ( 'policy' === $action ) {
		$result = admin_request( $site, 'policy', 'POST', isset( $input['policy'] ) ? $input['policy'] : null );
	} elseif ( 'job' === $action ) {
		$task = isset( $input['task'] ) && is_array( $input['task'] ) ? $input['task'] : array();
		// A UUID is rendered into the form; re-submitting the same form reuses it.
		if ( isset( $task['request_id'] ) && is_string( $task['request_id'] ) && wp_is_uuid( $task['request_id'], 4 ) ) {
			// Retain the ID before sending: a timeout can occur after the target accepts the job.
			update_option( 'sunrise_last_job_' . get_current_user_id() . '_' . $site, strtolower( $task['request_id'] ), false );
		}
		$result = admin_request( $site, 'jobs', 'POST', $task );
	} elseif ( in_array( $action, array( 'run', 'acknowledge' ), true ) && isset( $input['job_id'] ) && is_string( $input['job_id'] ) && wp_is_uuid( $input['job_id'], 4 ) ) {
		$result = admin_request( $site, 'jobs/' . strtolower( $input['job_id'] ) . '/' . $action, 'POST' );
	}
	$expected_pending = agent_connection_pending( $result ) || ( ! is_wp_error( $result ) && in_array( $action, array( 'agent_enroll', 'agent_restart_enrollment', 'identity_resolve' ), true ) && ! empty( agent_state()['enrollment_id'] ) && empty( agent_state()['site_id'] ) );
	$notice = $expected_pending ? __( 'Waiting for approval in Sunrise Control. Keep this page open to finish automatically.', 'sunrise' ) : ( is_wp_error( $result ) ? $result->get_error_message() : __( 'Request completed.', 'sunrise' ) );
	if ( 'agent_environment' === $action && ! is_wp_error( $result ) ) { $notice = __( 'Control environment saved. Connect this administrator to the selected environment if prompted.', 'sunrise' ); }
	if ( 'job' === $action && ! is_wp_error( $result ) && isset( $result['id'] ) ) {
		update_option( 'sunrise_last_job_' . get_current_user_id() . '_' . $site, $result['id'], false );
		$notice = __( 'Job accepted. WordPress cron will run it, or use Run queued job below.', 'sunrise' );
	}
	set_transient( 'sunrise_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ) && ! $expected_pending, 'pending' => $expected_pending, 'message' => $notice ), 60 );
	$redirect = array( 'page' => 'sunrise', 'site' => $site );
	if ( 'agent_environment' === $action ) { $redirect['sunrise_environment'] = '1'; }
	wp_safe_redirect( add_query_arg( $redirect, admin_url( 'tools.php' ) ) );
	exit;
} );

function form_start( $site, $operation ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'sunrise' );
	echo '<input type="hidden" name="action" value="sunrise"><input type="hidden" name="site" value="' . esc_attr( $site ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
}

/** Hidden support setting: tools.php?page=sunrise&sunrise_environment=1. */
function agent_environment_form() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['sunrise_environment'] ) || '1' !== $_GET['sunrise_environment'] ) { return; }
	echo '<section><h2>' . esc_html__( 'Control environment', 'sunrise' ) . '</h2><p>' . esc_html__( 'Applies to every Sunrise connection on this WordPress site. Changing environments pauses its managed updates and reports until each administrator reconnects. Site records and history in the previous environment are retained.', 'sunrise' ) . '</p>';
	if ( defined( 'SUNRISE_CONTROL_URL' ) ) {
		echo '<p>' . esc_html__( 'The Control address is overridden in wp-config.php; this setting is unavailable.', 'sunrise' ) . '</p></section>';
		return;
	}
	form_start( 'local', 'agent_environment' );
	echo '<label for="sunrise-control-environment">' . esc_html__( 'Environment', 'sunrise' ) . '</label> <select id="sunrise-control-environment" name="environment">';
	foreach ( array( 'production' => 'Production', 'staging' => 'Staging' ) as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '" ' . selected( get_option( 'sunrise_control_environment', 'production' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select> '; submit_button( __( 'Save environment', 'sunrise' ), 'secondary', 'submit', false ); echo '</form></section>';
}

function policy_select( $name, $value, $core = false ) {
	$options = $core ? array( 'inherit' => __( 'Use site policy', 'sunrise' ), 'off' => __( 'Off', 'sunrise' ), 'minor' => __( 'Minor releases', 'sunrise' ), 'all' => __( 'All stable releases', 'sunrise' ) ) : array( 'inherit' => __( 'Inherit', 'sunrise' ), 'on' => __( 'On', 'sunrise' ), 'off' => __( 'Off', 'sunrise' ) );
	echo '<select aria-label="' . esc_attr__( 'Automatic update policy', 'sunrise' ) . '" name="' . esc_attr( $name ) . '">';
	foreach ( $options as $key => $label ) {
		echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
}

function job_button( $site, $label, $task ) {
	form_start( $site, 'job' );
	$task['request_id'] = wp_generate_uuid4();
	foreach ( $task as $key => $value ) {
		echo '<input type="hidden" name="task[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
	}
	submit_button( $label, 'secondary', 'submit', false );
	echo '</form>';
}

function admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	admin_load();
	$site = isset( $_GET['site'] ) && is_string( $_GET['site'] ) ? sanitize_key( wp_unslash( $_GET['site'] ) ) : 'overview';
	echo '<div class="wrap sunrise-wrap"><h1>' . esc_html__( 'Sunrise Site Settings', 'sunrise' ) . '</h1>';
	$notice = get_transient( 'sunrise_notice_' . get_current_user_id() );
	if ( $notice ) {
		echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : ( ! empty( $notice['pending'] ) ? 'notice-info' : 'notice-success' ) ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		delete_transient( 'sunrise_notice_' . get_current_user_id() );
	}
	agent_environment_form();
	$other_connections = other_connections_section();
	if ( agent_url() && ( ! agent_state() || ! empty( agent_state()['revoked'] ) || ! agent_service_matches( agent_state() ) ) && ! is_wp_error( installation_guard() ) ) {
		if ( $other_connections ) { echo '<details class="sunrise-advanced"><summary>' . esc_html__( 'Connect your administrator separately', 'sunrise' ) . '</summary>'; }
		echo '<section class="sunrise-connect-card"><h2>' . esc_html__( 'Connect this site', 'sunrise' ) . '</h2><p>' . esc_html__( 'Connect this administrator to Sunrise to report available updates and manage the network. Connections belong to individual WordPress administrators. Other administrators can remain connected and continue reporting.', 'sunrise' ) . '</p>';
		if ( ! empty( agent_state()['revoked'] ) ) { echo '<p>' . esc_html__( 'This connection was disconnected. Reconnect to choose a network again.', 'sunrise' ) . '</p>'; }
		if ( agent_state() && ! agent_service_matches( agent_state() ) ) { echo '<p>' . esc_html__( 'The Control address changed. Authenticate again to connect to the selected environment. Your previous site record and history remain in the previous environment.', 'sunrise' ) . '</p>'; }
		connection_form(); echo '</section>';
		if ( $other_connections ) { echo '</details>'; }
		diagnostic_log_section();
		echo '</div>';
		return;
	}
	$identity_error = installation_guard();
	if ( is_wp_error( $identity_error ) ) {
		$identity = installation_identity(); $anchor_id = installation_anchor();
		$identity_id = $anchor_id ? $anchor_id : ( is_array( $identity ) && isset( $identity['id'] ) && is_string( $identity['id'] ) ? $identity['id'] : '' );
		$database_replaced = $anchor_id && is_array( $identity ) && isset( $identity['id'] ) && $anchor_id !== $identity['id'];
		$normal_reauthentication = agent_state() && agent_reconnectable( agent_state() );
		if ( ! $normal_reauthentication ) {
   echo '<section class="sunrise-recovery"><h2>' . esc_html__( 'Connect as a new site', 'sunrise' ) . '</h2><p>' . esc_html__( 'This WordPress installation no longer matches the connected site. Connect it as a new site; the original site in Sunrise Control will not be changed.', 'sunrise' ) . '</p>';
   form_start( 'local', 'identity_resolve' );
   echo '<input type="hidden" name="installation_id" value="' . esc_attr( $identity_id ) . '"><input type="hidden" name="identity_kind" value="' . esc_attr( $database_replaced ? 'same' : 'clone' ) . '"><input type="hidden" name="confirm_identity" value="1">';
   submit_button( __( 'Connect as a new site', 'sunrise' ), 'primary', 'submit', false ); echo '</form></section>';
   diagnostic_log_section(); echo '</div>'; return;
  }
	}
	if ( agent_url() ) {
		require_once __DIR__ . '/network.php';
		managed_network_page();
		diagnostic_log_section();
		echo '</div>'; return;
	}
	echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '"><input type="hidden" name="page" value="sunrise"><label for="sunrise-site">' . esc_html__( 'View', 'sunrise' ) . ' </label><select id="sunrise-site" name="site"><option value="overview" ' . selected( $site, 'overview', false ) . '>' . esc_html__( 'Network overview', 'sunrise' ) . '</option><option value="local" ' . selected( $site, 'local', false ) . '>' . esc_html__( 'This site', 'sunrise' ) . '</option>';
	foreach ( connections() as $id => $connection ) {
		echo '<option value="' . esc_attr( $id ) . '" ' . selected( $site, $id, false ) . '>' . esc_html( $connection['url'] ) . '</option>';
	}
	echo '</select> <button class="button">' . esc_html__( 'View site / check job status', 'sunrise' ) . '</button></form>';
	if ( ! in_array( $site, array( 'local', 'overview' ), true ) ) {
		form_start( $site, 'disconnect' );
		submit_button( __( 'Forget connection', 'sunrise' ), 'link-delete', 'submit', false );
		echo '</form><p>' . esc_html__( 'This removes the saved credential here. Revoke its application password on the target to invalidate it everywhere.', 'sunrise' ) . '</p>';
	}
	echo '<details><summary>' . esc_html__( 'Connect another site', 'sunrise' ) . '</summary><p>' . esc_html__( 'Install Sunrise on the target, then create an application password under Users → Profile there. This legacy connection stores an encrypted application password for your WordPress user. Central enrollment uses separate site credentials.', 'sunrise' ) . '</p>';
	form_start( 'local', 'connect' );
	foreach ( array( 'url' => array( 'url', __( 'WordPress URL', 'sunrise' ) ), 'username' => array( 'text', __( 'WordPress username', 'sunrise' ) ), 'password' => array( 'password', __( 'Application password', 'sunrise' ) ) ) as $name => $field ) {
		echo '<p><label for="sunrise-' . esc_attr( $name ) . '">' . esc_html( $field[1] ) . '</label><br><input class="regular-text" required autocomplete="' . ( 'password' === $name ? 'new-password' : 'off' ) . '" id="sunrise-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $field[0] ) . '"></p>';
	}
	if ( 'local' === wp_get_environment_type() ) {
		echo '<p><label><input type="checkbox" name="trust_local_certificate" value="1"> ' . esc_html__( 'Allow a self-signed certificate for a localhost target (local testing only).', 'sunrise' ) . '</label></p>';
	}
	submit_button( __( 'Verify and connect', 'sunrise' ) );
	echo '</form></details>';
	if ( 'overview' === $site ) {
		require_once __DIR__ . '/network.php';
		network_page();
		echo '</div>';
		return;
	}
	$data = admin_request( $site, 'inventory' );
	if ( is_wp_error( $data ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $data->get_error_message() ) . '</p></div></div>';
		return;
	}
	echo '<h2>' . esc_html( $data['site_url'] ) . '</h2><p>' . esc_html__( 'Inventory uses WordPress’s cached update offers. Unknown does not mean up to date. Policies affect WordPress automatic updates; managed-host rules can take precedence. Explicit update buttons work independently of automatic-update exclusions.', 'sunrise' ) . '</p>';
	if ( $data['capabilities']['automatic_updater_disabled'] || ! $data['capabilities']['file_modifications_allowed'] ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'This site restricts automatic updates or file modifications. Sunrise will respect those restrictions.', 'sunrise' ) . '</p></div>';
	}
	if ( ! empty( $data['core']['database_upgrade_required'] ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'WordPress needs a database upgrade. Open the target site’s wp-admin to complete it.', 'sunrise' ) . '</p></div>';
	}
	$job_id = get_option( 'sunrise_last_job_' . get_current_user_id() . '_' . $site );
	if ( 'local' === $site ) {
		$ids = get_option( 'sunrise_job_ids', array() );
		$job_id = $ids ? end( $ids ) : $job_id;
	}
	if ( $job_id ) {
		$job = admin_request( $site, 'jobs/' . $job_id );
		if ( ! is_wp_error( $job ) ) {
			echo '<h3>' . esc_html__( 'Latest job', 'sunrise' ) . '</h3><p><strong>' . esc_html( ucfirst( $job['status'] ) ) . '</strong></p>';
			if ( $job['result'] ) {
				if ( isset( $job['result']['message'] ) ) {
					echo '<p>' . esc_html( $job['result']['message'] ) . '</p>';
				}
			}
			echo '<details><summary>' . esc_html__( 'Job details', 'sunrise' ) . '</summary><p><code>' . esc_html( $job_id ) . '</code></p><pre>' . esc_html( wp_json_encode( $job['result'], JSON_PRETTY_PRINT ) ) . '</pre></details>';
			if ( 'queued' === $job['status'] || 'interrupted' === $job['status'] || ( 'running' === $job['status'] && time() - $job['started_at'] > HOUR_IN_SECONDS ) ) {
				$queued = 'queued' === $job['status'];
				form_start( $site, $queued ? 'run' : 'acknowledge' );
				echo '<input type="hidden" name="job_id" value="' . esc_attr( $job_id ) . '">';
				submit_button( $queued ? __( 'Run queued job', 'sunrise' ) : __( 'I inspected the site; acknowledge interrupted job', 'sunrise' ), 'secondary', 'submit', false );
				echo '</form>';
			}
		} else {
			echo '<p>' . esc_html( $job->get_error_message() ) . '</p>';
		}
	}
	echo '<h3>' . esc_html__( 'Actions', 'sunrise' ) . '</h3>';
	job_button( $site, __( 'Check for updates', 'sunrise' ), array( 'action' => 'refresh' ) );
	job_button( $site, __( 'Run eligible automatic updates', 'sunrise' ), array( 'action' => 'auto_updates' ) );
	echo '<h3>' . esc_html__( 'Site-wide automatic updates', 'sunrise' ) . '</h3>';
	form_start( $site, 'policy' );
	policy_select( 'policy[site]', $data['policy']['site'] );
	submit_button( __( 'Save site policy', 'sunrise' ), 'secondary', 'submit', false );
	echo '</form><p>' . esc_html__( 'Inherit preserves native settings. On includes all installed plugins, themes, and stable core releases unless an item has an override. Off is the default for items without an override.', 'sunrise' ) . '</p>';
	echo '<h3>WordPress ' . esc_html( $data['core']['version'] ) . '</h3>';
	form_start( $site, 'policy' );
	policy_select( 'policy[core]', $data['policy']['core'], true );
	submit_button( __( 'Save core policy', 'sunrise' ), 'secondary', 'submit', false );
	echo '</form>';
	foreach ( $data['core']['updates'] as $offer ) {
		job_button( $site, sprintf( __( 'Update WordPress to %s', 'sunrise' ), $offer['version'] ), array( 'action' => 'update', 'type' => 'core', 'id' => 'wordpress', 'version' => $offer['version'] ) );
	}
	foreach ( array( 'plugins' => __( 'Plugins', 'sunrise' ), 'themes' => __( 'Themes', 'sunrise' ) ) as $type => $heading ) {
		echo '<h2>' . esc_html( $heading ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'sunrise' ) . '</th><th>' . esc_html__( 'Version / update', 'sunrise' ) . '</th><th>' . esc_html__( 'Automatic updates', 'sunrise' ) . '</th></tr></thead><tbody>';
		foreach ( $data[ $type ] as $item ) {
			echo '<tr><td><strong>' . esc_html( $item['name'] ) . '</strong><br>' . esc_html( $item['id'] ) . '<br>' . esc_html( $item['active'] ? __( 'Active', 'sunrise' ) : ( $item['active_parent'] ? __( 'Active parent theme', 'sunrise' ) : __( 'Inactive', 'sunrise' ) ) ) . '</td><td>' . esc_html( $item['version'] ) . '<br>';
			if ( true === $item['update_available'] ) {
				job_button( $site, sprintf( __( 'Update to %s', 'sunrise' ), $item['update']['version'] ), array( 'action' => 'update', 'type' => 'plugins' === $type ? 'plugin' : 'theme', 'id' => $item['id'], 'version' => $item['update']['version'] ) );
			} else {
				echo esc_html( null === $item['update_available'] ? __( 'Unknown', 'sunrise' ) : __( 'No update reported', 'sunrise' ) );
			}
			echo '</td><td>';
			form_start( $site, 'policy' );
			policy_select( 'policy[' . $type . '][' . $item['id'] . ']', isset( $data['policy'][ $type ][ $item['id'] ] ) ? $data['policy'][ $type ][ $item['id'] ] : 'inherit' );
			submit_button( __( 'Save', 'sunrise' ), 'secondary', 'submit', false );
			echo '</form><small>' . esc_html( $item['auto_update_selected'] ? __( 'Selected by current filters', 'sunrise' ) : __( 'Not selected by current filters', 'sunrise' ) ) . '</small></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}
