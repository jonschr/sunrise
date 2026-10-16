<?php
/** Standalone context collection checks; no database, HTTP or real request values. */
namespace Sunrise { function agent_states() { return array(); } }
namespace {
define('ABSPATH','/fixture/');define('WP_PLUGIN_DIR','/fixture/wp-content/plugins');define('WPMU_PLUGIN_DIR','/fixture/wp-content/mu-plugins');define('WP_CONTENT_DIR','/fixture/wp-content');
function wp_normalize_path($value){return str_replace('\\','/',$value);}function trailingslashit($value){return rtrim($value,'/').'/';}function get_theme_root(){return '/fixture/wp-content/themes';}function wp_check_invalid_utf8($value){return $value;}function wp_json_encode($value){return json_encode($value);}function add_filter(){}function is_admin(){return false;}
class WP_Block_Supports {public static $block_to_render=array('blockName'=>'core/tab-list');}
class WP_Block_Type_Registry {static function get_instance(){return new self();}function get_registered($name){return (object)array('render_callback'=>'context_fixture_callback');}}
function context_fixture_callback($private_argument=null){}
function context_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
require dirname(__DIR__).'/includes/errors.php';
$_SERVER=array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/daily-schedule/?token=private-query');$_POST=array('password'=>'private-body');$_COOKIE=array('session'=>'private-cookie');
$GLOBALS['wp_current_filter']=array('example_cron_hook','the_content','wp_php_error_message');$GLOBALS['post']=(object)array('ID'=>42);$GLOBALS['wp_query']=(object)array('queried_object_id'=>43);$GLOBALS['_wp_current_template_id']='ollie//page';
$GLOBALS['wp_filter']=array('render_block_context'=>(object)array('callbacks'=>array(10=>array(array('function'=>'context_fixture_callback')))));
$c=Sunrise\error_context();context_check(Sunrise\error_context_valid($c),'Captured context must validate');context_check('/daily-schedule/'===$c['path'] && 42===$c['post_id'] && 43===$c['queried_object_id'],'Request and content context missing');context_check(array('example_cron_hook','the_content')===$c['hooks'],'Recovery hook must not replace the active hook stack');context_check('core/tab-list'===$c['block']['name'] && 'context_fixture_callback'===$c['block']['callback']['function'],'Current block renderer missing');context_check('render_block_context'===$c['callbacks'][0]['hook'],'Related filter callback missing');context_check(false===strpos(json_encode($c),'private-'),'Request secrets leaked');
foreach(array('/reset-password/abcdefghijklmnopqrstuvwx','/email/person%40example.test','/private/..hidden','/path/%0d%0aCookie:secret') as $path){$sanitized=Sunrise\error_context_path($path);context_check(false!==strpos($sanitized,'[redacted]'),'Sensitive path segment must be redacted');}
context_check(null===Sunrise\error_context_path('https://example.test/private'),'Absolute URL accepted');
$bad=$c;$bad['headers']=array('cookie'=>'private');context_check(!Sunrise\error_context_valid($bad),'Unknown context data accepted');
$bad=$c;$bad['path']='/page/?token=private';context_check(!Sunrise\error_context_valid($bad),'Query accepted');
$bad=$c;$bad['callbacks'][0]['args']=array('private');context_check(!Sunrise\error_context_valid($bad),'Arguments accepted');
$GLOBALS['wp_current_filter']=array_fill(0,20,'nested_hook');$GLOBALS['wp_filter']['render_block_context']->callbacks[10]=array_fill(0,20,array('function'=>'context_fixture_callback'));
$c=Sunrise\error_context();context_check(8===count($c['hooks']) && $c['hooks_truncated'] && 8===count($c['callbacks']) && $c['callbacks_truncated'] && Sunrise\error_context_valid($c),'Context caps or truncation flags failed');
if(isset($argv[1])){if('cron'===$argv[1])define('DOING_CRON',true);if('ajax'===$argv[1])define('DOING_AJAX',true);if('rest'===$argv[1])define('REST_REQUEST',true);if('cli'===$argv[1])define('WP_CLI',true);$c=Sunrise\error_context();context_check($argv[1]===$c['kind'],'Request kind mismatch');if('cli'===$argv[1])context_check(null===$c['path'],'CLI must not retain a synthetic request path');}
echo 'PASS: bounded trigger, content, hook and block metadata; secret-free paths and callbacks'."\n";
}
