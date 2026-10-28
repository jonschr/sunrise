<?php
/** Offline native admin-ajax bootstrap, with generic permission-gated update providers. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) ) { throw new RuntimeException( 'Disposable WordPress root required.' ); }
define( 'DOING_CRON', true );
$GLOBALS['wp_filter']['pre_http_request'][-PHP_INT_MAX][] = array( 'function' => function () { return new WP_Error( 'fixture_network_blocked', 'No real HTTP permitted.' ); }, 'accepted_args' => 3 );
require rtrim( $argv[1], '/' ) . '/wp-load.php';
if ( ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Disposable local fixture required.' ); }
require_once dirname( __DIR__ ) . '/sunrise.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$owner = 1; wp_set_current_user( $owner ); $state = Sunrise\agent_state();
if ( ! $state || ! Sunrise\agent_owner_valid( $state ) ) { throw new RuntimeException( 'Seed the disposable CI connection first.' ); }
$directory = WP_PLUGIN_DIR . '/sunrise-context-fixture';
if ( file_exists( $directory ) ) { throw new RuntimeException( 'Fixture directory already exists.' ); }
$names = array( 'active_plugins', 'sunrise_background_1', 'sunrise_fixture_context', 'sunrise_fixture_early', 'sunrise_fixture_late', 'sunrise_fixture_report', 'sunrise_agents', 'sunrise_agent', 'sunrise_agent_log', 'sunrise_hourly_update_at', 'sunrise_routine_check_in_1', 'cron' ); $saved = array();
foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$native = get_site_transient( 'update_plugins' ); $sessions = WP_Session_Tokens::get_instance( $owner )->get_all();
$check = function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo 'PASS: ' . $message . "\n"; };
$mode = 'success'; $requests = 0; $last = null;
$transport = function ( $pre, $args, $url ) use ( &$mode, &$requests, &$last, $owner, $check ) {
 if ( false === strpos( $url, '/wp-admin/admin-ajax.php' ) ) { return new WP_Error( 'fixture_network_blocked', 'No real HTTP permitted.' ); }
 $requests++; $last = $args;
 $check( false === $args['blocking'] && 0 === $args['redirection'], 'Worker transport is asynchronous and cannot forward cookies through redirects' );
 if ( 'transport_failure' === $mode ) { return new WP_Error( 'http_request_failed', 'Fixture loopback blocked' ); }
 if ( 'hold' === $mode ) { return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '' ); }
 $pending = get_option( 'sunrise_background_1' );
 if ( 'expired' === $mode ) { $pending['expires_at'] = time() - 1; update_option( 'sunrise_background_1', $pending, false ); }
 if ( 'changed_connection' === $mode ) { $pending['connection'] = str_repeat( '0', 64 ); update_option( 'sunrise_background_1', $pending, false ); }
 $cookies = array(); foreach ( $args['cookies'] as $cookie ) { $cookies[ $cookie->name ] = $cookie->value; }
 $other_session = null;
 if ( 'wrong_session' === $mode ) { $other_session = WP_Session_Tokens::get_instance( $owner )->create( time() + 120 ); $cookies[ AUTH_COOKIE ] = wp_generate_auth_cookie( $owner, time() + 120, 'auth', $other_session ); $cookies[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $owner, time() + 120, 'logged_in', $other_session ); }
 $payload = array( 'root' => ABSPATH, 'cookies' => 'anonymous' === $mode ? array() : $cookies, 'body' => $args['body'] );
 if ( 'real_work' === $mode ) { $payload['body']['fixture_real_work'] = true; }
 if ( 'wrong_ticket' === $mode ) { $payload['body']['ticket'] = str_repeat( 'f', 64 ); }
 $runner = <<<'PHP'
$data=json_decode(stream_get_contents(STDIN),true);
$_COOKIE=$data['cookies'];$_POST=$data['body'];$_REQUEST=$_POST;
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/wp-admin/admin-ajax.php';$_SERVER['SCRIPT_NAME']='/wp-admin/admin-ajax.php';$_SERVER['HTTP_HOST']='sunrise-fixture.localhost';$_SERVER['SERVER_PROTOCOL']='HTTP/1.1';
$GLOBALS['wp_filter']['pre_http_request'][-PHP_INT_MAX][]=array('function'=>function($pre,$args,$url){if(strpos($url,'https://enviragallery.com')===0)return array('headers'=>array(),'response'=>array('code'=>200),'body'=>json_encode(array('new_version'=>'1.17.3','slug'=>'envira-gallery','package'=>'https://provider.example.invalid/fixture.zip')));if(substr($url,-18)==='/v1/agent/check-in'){$report=json_decode($args['body'],true);update_option('sunrise_fixture_report',$report,false);$state=Sunrise\agent_state();$wire=$state['applied']['json']??wp_json_encode($state['applied']['document']);return array('headers'=>array(),'response'=>array('code'=>200),'body'=>wp_json_encode(array('receipt_sequence'=>$report['sequence'],'policy'=>array('generation'=>$state['applied']['generation'],'document_json'=>$wire,'hash'=>hash('sha256',$wire)),'work_available'=>false)));}return new WP_Error('fixture_network_blocked','No real HTTP permitted.');},'accepted_args'=>3);
require $data['root'].'wp-admin/admin-ajax.php';
PHP;
 $process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $runner ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
 fwrite( $pipes[0], wp_json_encode( $payload ) ); fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $exit = proc_close( $process );
 if ( $exit ) { throw new RuntimeException( 'Child bootstrap failed: ' . $errors ); }
 if ( $other_session ) { WP_Session_Tokens::get_instance( $owner )->destroy( $other_session ); }
 if ( 'success' === $mode ) { $reply = json_decode( $output, true ); $check( ! empty( $reply['success'] ), 'The native authenticated AJAX worker completes its scoped action' ); }
 return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '' );
};
try {
 wp_mkdir_p( $directory );
 $provider = <<<'PHP'
<?php
/* Plugin Name: Generic premium provider fixture
Version: 1.0 */
add_action('plugins_loaded',function(){if(is_admin() && current_user_can('update_plugins'))update_option('sunrise_fixture_early',get_current_user_id(),false);},0);
add_action('admin_init',function(){
 if(!current_user_can('update_plugins'))return;
 update_option('sunrise_fixture_late',get_current_user_id(),false);
 add_filter('pre_set_site_transient_update_plugins',function($updates){$updates->response['sunrise-context-fixture/provider.php']=(object)array('plugin'=>'Generic premium provider fixture','new_version'=>'2.0','package'=>'https://provider.example.invalid/fixture.zip');return $updates;});
 // Observe the worker without contacting Control or executing a real update.
 if(($_POST['action']??null)!=='sunrise_background')return;
 if(empty($_POST['fixture_real_work'])){remove_all_actions('sunrise_check_in');remove_all_actions('sunrise_wake_check_in');}
 foreach(array('sunrise_check_in','sunrise_wake_check_in') as $hook)add_action($hook,function($owner){
  set_site_transient('update_plugins',(object)array('checked'=>array('sunrise-context-fixture/provider.php'=>'1.0'),'response'=>array(),'no_update'=>array()));
  $cache=get_site_transient('update_plugins');$offer=$cache->response['sunrise-context-fixture/provider.php'];
  update_option('sunrise_fixture_context',array('owner'=>$owner,'user'=>get_current_user_id(),'admin'=>is_admin(),'cron'=>wp_doing_cron(),'early'=>(int)get_option('sunrise_fixture_early'),'late'=>(int)get_option('sunrise_fixture_late'),'plugin'=>$offer->plugin,'version'=>$offer->new_version,'envira_plugin'=>$cache->response['envira-gallery/envira-gallery.php']->plugin??null,'envira_version'=>$cache->response['envira-gallery/envira-gallery.php']->new_version??null),false);
 });
});
PHP;
 file_put_contents( $directory . '/provider.php', $provider );
 $plugins = array( 'sunrise-context-fixture/provider.php', 'sunrise/sunrise.php' );
 if ( file_exists( WP_PLUGIN_DIR . '/envira-gallery/envira-gallery.php' ) ) { $plugins[] = 'envira-gallery/envira-gallery.php'; }
 update_option( 'active_plugins', $plugins ); wp_clean_plugins_cache( false );
 delete_option( 'sunrise_background_1' ); delete_option( 'sunrise_fixture_context' );
 add_filter( 'pre_http_request', $transport, PHP_INT_MAX, 3 );
 wp_set_current_user( 0 );
 $check( Sunrise\agent_background_dispatch( 'sunrise_check_in', $owner ), 'Scheduled work dispatches before the anonymous cron callback executes' );
 wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'sunrise_fixture_context', 'options' ); wp_cache_delete( 'sunrise_background_1', 'options' );
 $context = get_option( 'sunrise_fixture_context' );
 $check( $context && $owner === $context['user'] && $owner === $context['owner'] && $owner === $context['early'] && $owner === $context['late'] && $context['admin'] && $context['cron'], 'Both early and admin-init providers see the owner during a native admin bootstrap' );
 $check( 'sunrise-context-fixture/provider.php' === $context['plugin'] && '2.0' === $context['version'], 'A generic provider display name becomes a native executable file identity' );
 if ( in_array( 'envira-gallery/envira-gallery.php', $plugins, true ) ) { $check( 'envira-gallery/envira-gallery.php' === $context['envira_plugin'] && '1.17.3' === $context['envira_version'], 'The real Envira updater works in the generic admin context without a provider-specific loader' ); }
 $check( 0 === get_current_user_id() && ! get_option( 'sunrise_background_1' ), 'The parent keeps its anonymous identity and the ticket is consumed' );
 clean_user_cache( $owner );
 $check( count( WP_Session_Tokens::get_instance( $owner )->get_all() ) === count( $sessions ), 'The temporary worker session is destroyed after completion' );
 $completed = $last; delete_option( 'sunrise_fixture_context' ); $mode = 'replay'; $transport( false, $completed, admin_url( 'admin-ajax.php' ) );
 wp_cache_delete( 'sunrise_fixture_context', 'options' ); $check( ! get_option( 'sunrise_fixture_context' ), 'A completed worker request cannot be replayed' );
 foreach ( array( 'wrong_ticket', 'wrong_session', 'expired', 'changed_connection', 'anonymous' ) as $scenario ) {
  delete_option( 'sunrise_fixture_context' ); delete_option( 'sunrise_background_1' ); $mode = $scenario;
  Sunrise\agent_background_dispatch( 'sunrise_wake_check_in', $owner );
  wp_cache_delete( 'sunrise_fixture_context', 'options' );
  $check( ! get_option( 'sunrise_fixture_context' ), 'Worker action denied: ' . $scenario );
  foreach ( $last['cookies'] as $cookie ) { if ( LOGGED_IN_COOKIE === $cookie->name ) { $parts = wp_parse_auth_cookie( $cookie->value, 'logged_in' ); WP_Session_Tokens::get_instance( $owner )->destroy( $parts['token'] ); } }
 }
 delete_option( 'sunrise_background_1' ); $mode = 'hold'; $before = $requests;
 Sunrise\agent_background_dispatch( 'sunrise_wake_check_in', $owner ); Sunrise\agent_background_dispatch( 'sunrise_wake_check_in', $owner );
 $check( $requests === $before + 1, 'Concurrent scheduled triggers coalesce behind one pending worker ticket' );
 foreach ( $last['cookies'] as $cookie ) { if ( LOGGED_IN_COOKIE === $cookie->name ) { $parts = wp_parse_auth_cookie( $cookie->value, 'logged_in' ); WP_Session_Tokens::get_instance( $owner )->destroy( $parts['token'] ); } }
 delete_option( 'sunrise_background_1' ); $mode = 'transport_failure';
 Sunrise\agent_background_dispatch( 'sunrise_wake_check_in', $owner );
 $check( ! get_option( 'sunrise_background_1' ) && wp_next_scheduled( 'sunrise_wake_check_in', array( $owner ) ), 'Loopback failure releases the ticket and retains a scheduled retry' );
 $check( false === Sunrise\agent_background_dispatch( 'untrusted_hook', $owner ), 'No arbitrary hook can be dispatched' );
 $before = $requests; $foreign_origin = function () { return 'https://foreign.example.invalid/wp-admin/admin-ajax.php'; }; add_filter( 'admin_url', $foreign_origin );
 try { Sunrise\agent_background_dispatch( 'sunrise_wake_check_in', $owner ); } finally { remove_filter( 'admin_url', $foreign_origin ); }
 $check( $before === $requests && ! get_option( 'sunrise_background_1' ), 'A changed admin origin receives neither cookies nor a worker ticket' );
 $mode = 'real_work'; update_option( 'sunrise_hourly_update_at', time(), false ); delete_option( 'sunrise_fixture_report' );
 Sunrise\agent_background_dispatch( 'sunrise_check_in', $owner );
 wp_cache_delete( 'sunrise_fixture_report', 'options' ); $report = get_option( 'sunrise_fixture_report' );
 $offers = array_values( array_filter( $report['inventory'] ?? array(), function ( $item ) { return 'sunrise-context-fixture/provider.php' === $item['installed_id']; } ) );
 $check( 1 === count( $offers ) && true === $offers[0]['update_available'], 'The unmodified scheduled check-in reports the initialized provider through the existing agent protocol' );
 echo "PASS: generic provider bootstrap, native cookies, scoped tickets, expiration, connection changes, anonymous denial, coalescing and retry preservation.\n";
} finally {
 remove_filter( 'pre_http_request', $transport, PHP_INT_MAX ); wp_set_current_user( $owner );
 foreach ( $saved as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
 if ( file_exists( $directory . '/provider.php' ) ) { unlink( $directory . '/provider.php' ); rmdir( $directory ); }
 wp_clean_plugins_cache( false ); false === $native ? delete_site_transient( 'update_plugins' ) : set_site_transient( 'update_plugins', $native );
}
