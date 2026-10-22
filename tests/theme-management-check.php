<?php
/** Native theme deletion on generated themes only; every outbound request is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once dirname( __DIR__ ) . '/includes/agent-jobs.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
$states = Sunrise\agent_states(); $state = Sunrise\agent_state(); $fence_before = get_option( 'sunrise_remote_job_fence', null ); $cron = get_option( 'cron' );
if ( empty( $state['site_id'] ) || ! empty( $state['remote_job'] ) || $fence_before ) { throw new RuntimeException( 'Empty enrolled fixture required.' ); }
$options = array(); foreach ( array( 'stylesheet', 'template', 'current_theme', 'theme_switched', 'stylesheet_root', 'template_root', 'theme_switch_menu_locations', 'sidebars_widgets', 'theme_mods_' . get_option( 'stylesheet' ), 'auto_update_themes', 'auto_update_plugins' ) as $name ) { $options[ $name ] = get_option( $name, null ); }
$root = WP_CONTENT_DIR . '/themes'; $slug = 'sunrise-theme-fixture-' . strtolower( wp_generate_password( 10, false, false ) ); $parent = $slug . '-parent'; $child = $slug . '-child'; $unused = $slug . '-unused'; $dirs = array(); $job = null; $reports = array(); $checkins = array(); $offline = true; $support = true; $deletions = 0;
function sunrise_theme_assert( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
$write = function ( $id, $template = '' ) use ( $root, &$dirs ) { $path = $root . '/' . $id; if ( file_exists( $path ) ) { throw new RuntimeException( 'Fixture path exists.' ); } $dirs[] = $path; mkdir( $path ); file_put_contents( $path . '/style.css', "/*\nTheme Name: Sunrise theme fixture $id\nVersion: 1.0\n" . ( $template ? "Template: $template\n" : '' ) . "*/\n" ); file_put_contents( $path . '/index.php', "<?php // Synthetic test theme.\n" ); wp_clean_themes_cache( false ); };
$task = function ( $id ) { return array( 'action' => 'delete_theme', 'type' => 'theme', 'installed_id' => $id, 'version' => '1.0', 'from_version' => '1.0', 'identity' => Sunrise\agent_item_identity( 'themes', $id ) ); };
$run = function ( $task ) use ( $state ) { $fence = array( 'site_id' => $state['site_id'], 'job_id' => wp_generate_uuid4() ); update_option( 'sunrise_remote_job_fence', $fence, false ); return Sunrise\manage_theme( $task, $fence ); };
$transport = function ( $pre, $request, $url ) use ( &$job, &$reports, &$checkins, &$offline, &$support, $state ) {
 if ( false === strpos( $url, '/v1/agent/' ) ) { return new WP_Error( 'fixture_external_blocked', 'External requests blocked.' ); }
 if ( '/check-in' === substr( $url, -9 ) ) { $data = json_decode( $request['body'], true ); $checkins[] = $data; $document = $state['applied']['document']; $document['generation']++; $wire = wp_json_encode( $document ); $body = array( 'receipt_sequence' => $data['sequence'], 'policy' => array( 'generation' => $state['applied']['generation'] + 1, 'document_json' => $wire, 'hash' => hash( 'sha256', $wire ) ), 'update_jobs' => true, 'plugin_management' => true, 'theme_management' => $support ); }
 else {
  $body = array( 'job' => $job );
  if ( '/start' === substr( $url, -6 ) ) { $body['job']['status'] = 'running'; $body['job']['revision']++; $body['job']['start_until'] = gmdate( 'c', time() + 60 ); }
  elseif ( '/events' === substr( $url, -7 ) ) { $reports[] = $request['body']; if ( $offline ) { return new WP_Error( 'fixture_lost_response', 'Outcome response lost.' ); } $body['job']['status'] = 'succeeded'; }
 }
 return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200 ) );
};
$direct = function () { return 'direct'; }; $on_delete = function ( $id ) use ( $slug, &$deletions ) { if ( 0 === strpos( $id, $slug ) ) { $deletions++; } };
add_filter( 'filesystem_method', $direct ); add_filter( 'pre_http_request', $transport, 10, 3 ); add_action( 'deleted_theme', $on_delete );
try {
 $working = $states; foreach ( $working as &$connection ) { $connection['paused'] = false; } unset( $connection ); Sunrise\agent_store_states( $working ); $state = Sunrise\agent_state();
 $write( $parent ); $write( $child, $parent ); $write( $unused ); switch_theme( $child );
 $job = array( 'id' => wp_generate_uuid4(), 'status' => 'reserved', 'revision' => 2, 'payload' => $task( $unused ) );
 sunrise_theme_assert( ! is_wp_error( Sunrise\agent_job_response( array( 'job' => $job ) ) ), 'Protocol accepts exact theme-deletion payloads' );
 foreach ( array( array( 'type' => 'plugin' ), array( 'action' => 'deactivate_plugin' ), array( 'action' => 'arbitrary_command' ) ) as $bad ) { $invalid = $job; $invalid['payload'] = array_merge( $invalid['payload'], $bad ); sunrise_theme_assert( is_wp_error( Sunrise\agent_job_response( array( 'job' => $invalid ) ) ), 'Wrong theme action/type is rejected' ); }
 foreach ( array( '../plugins', '/tmp', '..', $slug . '/nested', $slug . '\\nested' ) as $id ) { sunrise_theme_assert( is_wp_error( Sunrise\theme_management_target( $id ) ), 'Unsafe theme path rejected: ' . $id ); }
 foreach ( array( $parent, $child ) as $id ) { $result = $run( $task( $id ) ); sunrise_theme_assert( is_wp_error( $result ) && 'sunrise_theme_in_use' === $result->get_error_code() && file_exists( $root . '/' . $id . '/style.css' ), 'Active child and parent remain installed: ' . $id ); }
 $inventory = Sunrise\agent_inventory(); $found_parent = false; foreach ( $inventory as $item ) { if ( $parent === $item['installed_id'] ) { $found_parent = in_array( 'active_parent_theme', $item['restrictions'], true ); } } sunrise_theme_assert( $found_parent, 'Inventory marks the active parent as protected' );
 $payload = $task( $unused ); $changed = $payload; $changed['from_version'] = '0.9'; sunrise_theme_assert( 'sunrise_installed_version_changed' === $run( $changed )->get_error_code(), 'Changed version blocks deletion' );
 $changed = $payload; $changed['identity']['name'] = 'Other'; sunrise_theme_assert( 'sunrise_component_identity_changed' === $run( $changed )->get_error_code(), 'Changed identity blocks deletion' );
 $deny = function ( $caps, $cap ) { return 'delete_themes' === $cap ? array( 'do_not_allow' ) : $caps; }; add_filter( 'map_meta_cap', $deny, 20, 2 ); sunrise_theme_assert( 'sunrise_theme_management_forbidden' === $run( $payload )->get_error_code(), 'Deletion rechecks owner theme permissions' ); remove_filter( 'map_meta_cap', $deny, 20 );
 $ftp = function () { return 'ftpext'; }; add_filter( 'filesystem_method', $ftp, PHP_INT_MAX ); sunrise_theme_assert( 'sunrise_filesystem_credentials_required' === $run( $payload )->get_error_code(), 'Interactive filesystem credentials cannot be requested asynchronously' ); remove_filter( 'filesystem_method', $ftp, PHP_INT_MAX );
 $link = $slug . '-link'; symlink( $root . '/' . $unused, $root . '/' . $link ); try { sunrise_theme_assert( 'sunrise_theme_management_unsupported' === Sunrise\theme_management_target( $link )->get_error_code(), 'Symlinked themes cannot be deleted' ); } finally { unlink( $root . '/' . $link ); }
 // A theme switch during an earlier native delete hook must still be caught before files are removed.
 $switch = function ( $id ) use ( $unused ) { if ( $id === $unused ) { switch_theme( $unused ); } }; add_action( 'delete_theme', $switch, 5 );
 try { $result = $run( $payload ); sunrise_theme_assert( is_wp_error( $result ) && 'sunrise_theme_in_use' === $result->get_error_code() && file_exists( $root . '/' . $unused . '/style.css' ), 'Theme activation during deletion hooks blocks removal' ); }
 finally { remove_action( 'delete_theme', $switch, 5 ); switch_theme( $child ); }
 $late_child = $slug . '-late-child'; $write( $late_child, $unused ); switch_theme( $late_child );
 sunrise_theme_assert( 'sunrise_theme_in_use' === $run( $payload )->get_error_code() && file_exists( $root . '/' . $unused . '/style.css' ), 'A previously inactive theme becoming the parent remains protected' ); switch_theme( $child );
 $no_files = function () { return false; }; add_filter( 'file_mod_allowed', $no_files ); sunrise_theme_assert( 'sunrise_file_modifications_disabled' === $run( $payload )->get_error_code(), 'File modification restrictions block theme deletion' ); remove_filter( 'file_mod_allowed', $no_files );
 $filesystem = $GLOBALS['wp_filesystem'] ?? null;
 $fail_delete = function () { $GLOBALS['wp_filesystem'] = new class extends WP_Filesystem_Direct { public function __construct() {} public function delete( $file, $recursive = false, $type = false ) { return false; } }; }; add_action( 'delete_theme', $fail_delete, 5 );
 try { $result = $run( $payload ); sunrise_theme_assert( is_wp_error( $result ) && 'sunrise_theme_delete_failed' === $result->get_error_code() && file_exists( $root . '/' . $unused . '/style.css' ), 'Filesystem deletion failure reports failure without claiming success' ); }
 finally { remove_action( 'delete_theme', $fail_delete, 5 ); $GLOBALS['wp_filesystem'] = $filesystem; }
 // One fenced worker can lose its receipt without repeating deletion hooks.
 delete_option( 'sunrise_remote_job_fence' ); $state['remote_job'] = null; Sunrise\agent_store( $state ); $job['payload'] = $payload;
 sunrise_theme_assert( is_wp_error( Sunrise\agent_run_update_job() ) && ! file_exists( $root . '/' . $unused ), 'Worker deletes an inactive theme through native WordPress deletion' );
 $before = $deletions; $offline = false; sunrise_theme_assert( true === Sunrise\agent_run_update_job() && $deletions === $before && count( $reports ) === 2 && $reports[0] === $reports[1], 'Lost receipts retry the same outcome without deleting again' );
 sunrise_theme_assert( 'already_deleted' === $run( $payload )['code'], 'Already-deleted theme requests are idempotent' );
 delete_option( 'sunrise_remote_job_fence' ); $write( $unused ); $payload = $task( $unused ); $job['id'] = wp_generate_uuid4(); $job['payload'] = $payload;
 $pending = Sunrise\agent_state(); $pending['remote_job'] = array( 'phase' => 'executing', 'token' => str_repeat( 'a', 64 ), 'job' => $job ); Sunrise\agent_store( $pending ); update_option( 'sunrise_remote_job_fence', array( 'site_id' => $state['site_id'], 'job_id' => $job['id'], 'management_stage' => 'deleting' ), false );
 sunrise_theme_assert( true === Sunrise\agent_run_update_job() && file_exists( $root . '/' . $unused ) && 'theme_management_interrupted' === json_decode( end( $reports ), true )['code'], 'Interrupted deletion reports uncertainty without removing files' );
 $latest = Sunrise\agent_state(); unset( $latest['theme_management'] ); Sunrise\agent_store( $latest );
 $first_check = Sunrise\agent_check_in(); sunrise_theme_assert( ! is_wp_error( $first_check ) && ! isset( end( $checkins )['theme_management'] ), 'Theme support is negotiated before it is sent' . ( is_wp_error( $first_check ) ? ': ' . $first_check->get_error_code() . ' ' . $first_check->get_error_message() : '' ) );
 sunrise_theme_assert( ! is_wp_error( Sunrise\agent_check_in() ) && true === end( $checkins )['theme_management'], 'Negotiated agent explicitly advertises theme support' );
 $support = false; Sunrise\agent_check_in(); Sunrise\agent_check_in(); sunrise_theme_assert( ! isset( end( $checkins )['theme_management'] ), 'Unsupported service stops theme capability reporting' );
 require_once dirname( __DIR__ ) . '/includes/admin.php';
 do_action( 'admin_menu' );
 $tools = array_filter( $GLOBALS['submenu']['tools.php'] ?? array(), function ( $item ) { return 'sunrise' === $item[2]; } );
 $settings = array_filter( $GLOBALS['submenu']['options-general.php'] ?? array(), function ( $item ) { return 'sunrise' === $item[2]; } );
 sunrise_theme_assert( 1 === count( $tools ) && ! $settings, 'Sunrise appears under Tools instead of Settings' );
 set_current_screen( 'tools_page_sunrise' );
 do_action( 'admin_enqueue_scripts', 'tools_page_sunrise' );
 sunrise_theme_assert( wp_script_is( 'sunrise-shell', 'enqueued' ) && wp_style_is( 'sunrise-settings', 'enqueued' ), 'Tools screen retains its scripts and styles' );
} finally {
 remove_filter( 'pre_http_request', $transport, 10 ); remove_filter( 'filesystem_method', $direct ); remove_action( 'deleted_theme', $on_delete );
 foreach ( $options as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
 foreach ( $dirs as $dir ) { foreach ( array( 'style.css', 'index.php' ) as $file ) { if ( file_exists( $dir . '/' . $file ) ) { unlink( $dir . '/' . $file ); } } if ( is_dir( $dir ) ) { rmdir( $dir ); } }
 foreach ( array( $parent, $child, $unused, $slug . '-late-child' ) as $id ) { delete_option( 'theme_mods_' . $id ); }
 wp_clean_themes_cache( false ); Sunrise\agent_store_states( $states ); update_option( 'cron', $cron ); if ( null === $fence_before ) { delete_option( 'sunrise_remote_job_fence' ); } else { update_option( 'sunrise_remote_job_fence', $fence_before, false ); }
}
