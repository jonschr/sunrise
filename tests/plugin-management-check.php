<?php
/** Native deactivation/uninstall on synthetic plugins only; outbound requests are intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once dirname( __DIR__ ) . '/includes/agent-jobs.php';
require_once dirname( __DIR__ ) . '/includes/jobs.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
$states = Sunrise\agent_states(); $state = Sunrise\agent_state(); $fence_before = get_option( 'sunrise_remote_job_fence', null ); $cron = get_option( 'cron' ); $active = get_option( 'active_plugins' );
if ( empty( $state['site_id'] ) || ! empty( $state['remote_job'] ) || $fence_before ) { throw new RuntimeException( 'Empty enrolled fixture required.' ); }
$slug = 'sunrise-management-fixture-' . strtolower( wp_generate_password( 10, false, false ) ); $dir = WP_PLUGIN_DIR . '/' . $slug; $id = $slug . '/fixture.php';
if ( file_exists( $dir ) ) { throw new RuntimeException( 'Fixture path already exists.' ); }
$dirs = array(); $option = 'sunrise_management_fixture'; $job = null; $reports = array(); $offline = true; $support = true; $checkins = array();
function sunrise_management_assert( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$write = function () use ( &$dir, $option, &$id, &$dirs ) {
 $dirs[] = $dir;
 remove_all_actions( 'deactivate_' . $id );
 if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
 file_put_contents( $dir . '/fixture.php', "<?php\n/* Plugin Name: Sunrise management fixture\nVersion: 1.0\nText Domain: sunrise-management-fixture\n*/\nregister_deactivation_hook(__FILE__,function(){update_option('$option',get_option('$option','').'deactivated;');});\n" );
 file_put_contents( $dir . '/uninstall.php', "<?php\nif(!defined('WP_UNINSTALL_PLUGIN')){exit;}\nupdate_option('$option',get_option('$option','').'uninstalled;');\n" ); wp_clean_plugins_cache( false );
};
$task = function ( $action ) use ( &$id ) { return array( 'action' => $action, 'type' => 'plugin', 'installed_id' => $id, 'version' => '1.0', 'from_version' => '1.0', 'identity' => Sunrise\agent_item_identity( 'plugins', $id ) ); };
$run = function ( $task ) use ( $state ) { $fence = array( 'site_id' => $state['site_id'], 'job_id' => wp_generate_uuid4() ); update_option( 'sunrise_remote_job_fence', $fence, false ); return Sunrise\manage_plugin( $task, $fence ); };
$transport = function ( $pre, $options, $url ) use ( &$job, &$reports, &$offline, &$support, &$checkins, $state ) {
 if ( false === strpos( $url, '/v1/agent/' ) ) { return new WP_Error( 'fixture_external_blocked', 'External requests blocked.' ); }
 if ( '/check-in' === substr( $url, -9 ) ) { $request = json_decode( $options['body'], true ); $checkins[] = $request; $wire = $state['applied']['json'] ?? null; if ( ! $wire ) { $wire = wp_json_encode( $state['applied']['document'] ); } $body = array( 'receipt_sequence' => $request['sequence'], 'policy' => array( 'generation' => $state['applied']['generation'], 'document_json' => $wire, 'hash' => hash( 'sha256', $wire ) ), 'update_jobs' => true, 'plugin_management' => $support ); }
 else {
  $body = array( 'job' => $job );
  if ( '/start' === substr( $url, -6 ) ) { $body['job']['status'] = 'running'; $body['job']['revision']++; $body['job']['start_until'] = gmdate( 'c', time() + 60 ); }
  elseif ( '/events' === substr( $url, -7 ) ) { $reports[] = $options['body']; if ( $offline ) { return new WP_Error( 'fixture_offline', 'Result acknowledgment lost.' ); } $body['job']['status'] = json_decode( $options['body'], true )['status']; }
  elseif ( '/claim' !== substr( $url, -6 ) ) { throw new RuntimeException( 'Unexpected job request.' ); }
 }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
};
$direct = function () { return 'direct'; }; add_filter( 'filesystem_method', $direct ); add_filter( 'pre_http_request', $transport, 10, 3 );
try {
 $write(); $payload = $task( 'deactivate_plugin' );
 sunrise_management_assert( ! is_wp_error( Sunrise\agent_job_response( array( 'job' => array( 'id' => wp_generate_uuid4(), 'status' => 'queued', 'revision' => 1, 'payload' => $payload ) ) ) ), 'New protocol accepts frozen management payloads' );
 $bad = $payload; $bad['type'] = 'theme'; sunrise_management_assert( is_wp_error( Sunrise\agent_job_response( array( 'job' => array( 'id' => wp_generate_uuid4(), 'status' => 'queued', 'revision' => 1, 'payload' => $bad ) ) ) ), 'Management tasks cannot target themes or arbitrary types' );
 sunrise_management_assert( is_wp_error( Sunrise\plugin_management_target( 'sunrise/sunrise.php' ) ) && is_wp_error( Sunrise\plugin_management_target( '../wp-config.php', true ) ), 'Sunrise and traversal paths are protected' );
 sunrise_management_assert( ! is_wp_error( activate_plugin( $id ) ), 'Synthetic plugin activates using WordPress' );
 sunrise_management_assert( 'sunrise_plugin_active' === $run( $task( 'delete_plugin' ) )->get_error_code() && file_exists( $dir . '/fixture.php' ), 'Delete-only rejects active plugins without removing files' );
 $dependent_dir = WP_PLUGIN_DIR . '/' . $slug . '-dependent'; $dirs[] = $dependent_dir; mkdir( $dependent_dir ); file_put_contents( $dependent_dir . '/fixture.php', "<?php\n/* Plugin Name: Management dependent\nVersion: 1.0\nRequires Plugins: $slug\n*/" ); wp_clean_plugins_cache( false );
 $dependent_id = $slug . '-dependent/fixture.php'; update_option( 'active_plugins', array_merge( get_option( 'active_plugins' ), array( $dependent_id ) ) );
 sunrise_management_assert( 'sunrise_plugin_required' === $run( $task( 'deactivate_delete_plugin' ) )->get_error_code() && is_plugin_active( $id ), 'Active declared dependents block deactivation and deletion' ); deactivate_plugins( $dependent_id, true );
 $changed = $task( 'deactivate_plugin' ); $changed['from_version'] = '0.9'; sunrise_management_assert( 'sunrise_installed_version_changed' === $run( $changed )->get_error_code() && is_plugin_active( $id ), 'Changed installed versions are rejected before deactivation' );
 $changed = $task( 'deactivate_plugin' ); $changed['identity']['name'] = 'Other'; sunrise_management_assert( 'sunrise_component_identity_changed' === $run( $changed )->get_error_code(), 'Changed identities are rejected before deactivation' );
 $result = $run( $task( 'deactivate_plugin' ) ); sunrise_management_assert( ! is_plugin_active( $id ) && 'deactivated' === $result['code'] && file_exists( $dir . '/fixture.php' ), 'Deactivate invokes native hooks and retains files' );
 sunrise_management_assert( 'already_inactive' === $run( $task( 'deactivate_plugin' ) )['code'], 'Already-inactive deactivation is idempotent' );
 file_put_contents( $dir . '/other.php', "<?php\n/* Plugin Name: Shared fixture\nVersion: 1.0\n*/" ); wp_clean_plugins_cache( false ); sunrise_management_assert( is_wp_error( $run( $task( 'delete_plugin' ) ) ) && file_exists( $dir . '/other.php' ), 'Shared plugin directories cannot be deleted accidentally' ); unlink( $dir . '/other.php' ); wp_clean_plugins_cache( false );
 $ftp = function () { return 'ftpext'; }; add_filter( 'filesystem_method', $ftp, PHP_INT_MAX ); $ftp_result = $run( $task( 'delete_plugin' ) ); sunrise_management_assert( is_wp_error( $ftp_result ) && 'sunrise_filesystem_credentials_required' === $ftp_result->get_error_code(), 'Asynchronous deletion rejects interactive filesystem credentials' ); remove_filter( 'filesystem_method', $ftp, PHP_INT_MAX );
 $deny = function ( $caps, $cap ) { return 'activate_plugins' === $cap ? array( 'do_not_allow' ) : $caps; }; add_filter( 'map_meta_cap', $deny, 20, 2 ); sunrise_management_assert( 'sunrise_plugin_management_forbidden' === $run( $task( 'deactivate_plugin' ) )->get_error_code(), 'WordPress capability checks run at execution' ); remove_filter( 'map_meta_cap', $deny, 20 );
 sunrise_management_assert( 'deleted' === $run( $task( 'delete_plugin' ) )['code'] && ! file_exists( $dir ), 'Inactive plugin deletion runs native uninstall and removes the directory' );
 sunrise_management_assert( 'already_deleted' === $run( $payload = array_merge( $payload, array( 'action' => 'delete_plugin' ) ) )['code'], 'Already-deleted requests do not rerun uninstall' );
 $dir = WP_PLUGIN_DIR . '/' . $slug . '-combined'; $id = $slug . '-combined/fixture.php'; $write(); activate_plugin( $id ); delete_option( $option ); delete_option( 'sunrise_remote_job_fence' ); $state['remote_job'] = null; Sunrise\agent_store( $state );
 $job = array( 'id' => wp_generate_uuid4(), 'revision' => 2, 'status' => 'reserved', 'payload' => $task( 'deactivate_delete_plugin' ) );
 sunrise_management_assert( is_wp_error( Sunrise\agent_run_update_job() ) && ! file_exists( $dir ) && ! is_plugin_active( $id ), 'One asynchronous request deactivates before uninstall even when acknowledgment is lost' );
 sunrise_management_assert( 'deactivated;uninstalled;' === get_option( $option ), 'Deactivation and uninstall hooks run in order' );
 $offline = false; sunrise_management_assert( true === Sunrise\agent_run_update_job() && count( $reports ) === 2 && $reports[0] === $reports[1] && 'deactivated;uninstalled;' === get_option( $option ) && ! get_option( 'sunrise_remote_job_fence' ), 'Lost result retries report the same outcome without repeating hooks' );
 // A killed combined operation retains a fence, reports uncertainty and never resumes deletion blindly.
 $dir = WP_PLUGIN_DIR . '/' . $slug . '-interrupted'; $id = $slug . '-interrupted/fixture.php'; $write(); activate_plugin( $id ); $job['payload'] = $task( 'deactivate_delete_plugin' ); $job['status'] = 'closed_unverified';
 $state = Sunrise\agent_state(); $state['remote_job'] = array( 'phase' => 'executing', 'token' => bin2hex( random_bytes( 32 ) ), 'job' => $job ); Sunrise\agent_store( $state ); update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'management_stage' => 'deleting' ), false );
 sunrise_management_assert( true === Sunrise\agent_run_update_job() && file_exists( $dir . '/fixture.php' ) && 'plugin_management_interrupted' === json_decode( end( $reports ), true )['code'], 'Interrupted operations report uncertainty without repeating destructive execution' );
 $dir = WP_PLUGIN_DIR . '/' . $slug . '-partial'; $id = $slug . '-partial/fixture.php'; $write(); activate_plugin( $id );
 $filesystem_before = $GLOBALS['wp_filesystem'] ?? null;
 $fail_delete = function () { $GLOBALS['wp_filesystem'] = new class extends WP_Filesystem_Direct { public function __construct() {} public function delete( $file, $recursive = false, $type = false ) { return false; } }; }; add_action( 'delete_plugin', $fail_delete );
 try { $partial = $run( $task( 'deactivate_delete_plugin' ) ); sunrise_management_assert( is_wp_error( $partial ) && 'sunrise_deactivated_delete_failed' === $partial->get_error_code() && ! is_plugin_active( $id ) && file_exists( $dir . '/fixture.php' ), 'A failed deletion reports partial completion and leaves the plugin inactive' ); }
 finally { remove_action( 'delete_plugin', $fail_delete ); $GLOBALS['wp_filesystem'] = $filesystem_before; delete_option( 'sunrise_remote_job_fence' ); }
 $theme_root = get_theme_root(); $parent_slug = $slug . '-parent'; $child_slug = $slug . '-child';
 foreach ( array( $parent_slug, $child_slug ) as $theme_slug ) { $theme_dir = $theme_root . '/' . $theme_slug; if ( file_exists( $theme_dir ) ) { throw new RuntimeException( 'Fixture theme already exists.' ); } mkdir( $theme_dir ); $dirs[] = $theme_dir; file_put_contents( $theme_dir . '/index.php', '<?php' ); file_put_contents( $theme_dir . '/style.css', "/* Theme Name: Management fixture $theme_slug\nVersion: 1.0\n" . ( $theme_slug === $child_slug ? "Template: $parent_slug\n" : '' ) . '*/' ); }
 $child_option = function () use ( $child_slug ) { return $child_slug; }; $parent_option = function () use ( $parent_slug ) { return $parent_slug; };
 add_filter( 'pre_option_stylesheet', $child_option ); add_filter( 'pre_option_template', $parent_option ); wp_clean_themes_cache( false );
 try { $theme_inventory = array(); foreach ( Sunrise\agent_inventory() as $entry ) { if ( 'themes' === $entry['type'] ) { $theme_inventory[ $entry['installed_id'] ] = $entry; } }
  sunrise_management_assert( true === $theme_inventory[ $child_slug ]['active'] && false === $theme_inventory[ $parent_slug ]['active'] && in_array( 'active_parent_theme', $theme_inventory[ $parent_slug ]['restrictions'], true ), 'Inventory distinguishes the active child theme and its parent in use' );
 } finally { remove_filter( 'pre_option_stylesheet', $child_option ); remove_filter( 'pre_option_template', $parent_option ); wp_clean_themes_cache( false ); }
 $state = Sunrise\agent_state(); unset( $state['plugin_management'] ); Sunrise\agent_store( $state );
 sunrise_management_assert( ! is_wp_error( Sunrise\agent_check_in() ) && ! isset( end( $checkins )['plugin_management'] ), 'Support is negotiated before a new field is sent' );
 sunrise_management_assert( ! is_wp_error( Sunrise\agent_check_in() ) && true === end( $checkins )['plugin_management'], 'A negotiated agent explicitly reports management support' );
} finally {
 remove_filter( 'pre_http_request', $transport ); remove_filter( 'filesystem_method', $direct );
 foreach ( $dirs as $dir ) { foreach ( array( 'fixture.php', 'uninstall.php', 'other.php', 'style.css', 'index.php' ) as $file ) { if ( file_exists( $dir . '/' . $file ) ) { unlink( $dir . '/' . $file ); } } if ( is_dir( $dir ) ) { rmdir( $dir ); } }
 update_option( 'active_plugins', $active ); delete_option( $option ); Sunrise\agent_store_states( $states ); update_option( 'cron', $cron ); if ( null === $fence_before ) { delete_option( 'sunrise_remote_job_fence' ); } else { update_option( 'sunrise_remote_job_fence', $fence_before, false ); } wp_clean_plugins_cache( false );
}
