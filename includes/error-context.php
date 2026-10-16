<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

function error_hook_name( $name ) {
 return is_string( $name ) && preg_match( '#^[A-Za-z0-9_./:-]{1,120}$#D', $name ) ? $name : null;
}
function error_context_path( $uri ) {
 if ( ! is_string( $uri ) || '/' !== substr( $uri, 0, 1 ) || strlen( $uri ) > 8192 ) { return null; }
 $path = rawurldecode( preg_split( '/[?#]/', $uri, 2 )[0] );
 $parts = explode( '/', $path ); $hide_next = false;
 foreach ( $parts as &$part ) {
  $sensitive = (bool) preg_match( '/password|passwd|secret|token|api[_-]?key|licen[sc]e/i', $part );
  if ( $hide_next || ! preg_match( '/^[A-Za-z0-9._~-]{0,80}$/D', $part ) || preg_match( '/@|password|passwd|secret|token|api[_-]?key|licen[sc]e/i', $part ) || preg_match( '/[A-Za-z0-9_-]{24,}/', $part ) || false !== strpos( $part, '..' ) ) { $part = '[redacted]'; }
  $hide_next = $sensitive;
 } unset( $part );
 $path = implode( '/', $parts );
 return strlen( $path ) <= 512 ? $path : null;
}
function error_callback( $callback ) {
 try {
  if ( is_array( $callback ) && count( $callback ) === 2 ) { $reflection = new \ReflectionMethod( $callback[0], $callback[1] ); $name = $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName(); }
  elseif ( is_object( $callback ) && ! $callback instanceof \Closure ) { $reflection = new \ReflectionMethod( $callback, '__invoke' ); $name = get_class( $callback ) . '::__invoke'; }
  else { $reflection = new \ReflectionFunction( $callback ); $name = $reflection->getName(); }
  if ( ! preg_match( '/^[A-Za-z0-9_\\\\:{}>\-]{1,200}$/D', $name ) ) { $name = '{unknown}'; }
  return array( 'function' => $name, 'file' => error_location( $reflection->getFileName() ), 'line' => min( 10000000, max( 0, (int) $reflection->getStartLine() ) ) );
 } catch ( \Throwable $error ) { return null; }
}
function error_context() {
 global $wp_current_filter, $wp_filter, $post, $wp_query, $template, $_wp_current_template_id;
 $hooks = array_values( array_filter( is_array( $wp_current_filter ) ? $wp_current_filter : array(), function ( $hook ) { return ! in_array( $hook, array( 'wp_php_error_message', 'shutdown' ), true ) && null !== error_hook_name( $hook ); } ) );
 $hooks_truncated = count( $hooks ) > 8;
 if ( $hooks_truncated ) { $hooks = array_merge( array_slice( $hooks, 0, 4 ), array_slice( $hooks, -4 ) ); }
 $kind = defined( 'WP_CLI' ) && WP_CLI ? 'cli' : ( defined( 'DOING_CRON' ) && DOING_CRON ? 'cron' : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : ( defined( 'DOING_AJAX' ) && DOING_AJAX ? 'ajax' : ( function_exists( 'is_admin' ) ? ( is_admin() ? 'admin' : 'frontend' ) : 'unknown' ) ) ) );
 $method = isset( $_SERVER['REQUEST_METHOD'] ) && in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'POST', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ? $_SERVER['REQUEST_METHOD'] : null;
 $template_id = null;
 if ( is_string( $_wp_current_template_id ) && preg_match( '#^[A-Za-z0-9_][A-Za-z0-9_./-]{0,199}$#D', $_wp_current_template_id ) && false === strpos( $_wp_current_template_id, '..' ) ) { $template_id = $_wp_current_template_id; }
 elseif ( is_string( $template ) ) { $template_id = error_location( $template ); }
 $block = null;
 if ( class_exists( '\\WP_Block_Supports', false ) && is_array( \WP_Block_Supports::$block_to_render ) ) {
  $name = \WP_Block_Supports::$block_to_render['blockName'] ?? null;
  if ( is_string( $name ) && preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#D', $name ) && strlen( $name ) <= 120 ) {
   $type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
   $block = array( 'name' => $name, 'callback' => $type && $type->render_callback ? error_callback( $type->render_callback ) : null );
  }
 }
 $callbacks = array(); $callbacks_truncated = false;
 $selected = array_unique( array_merge( $block ? array( 'render_block_context', 'render_block_data' ) : array(), array_reverse( $hooks ) ) );
 foreach ( $selected as $hook ) {
  foreach ( isset( $wp_filter[ $hook ]->callbacks ) ? $wp_filter[ $hook ]->callbacks : array() as $priority => $entries ) {
   foreach ( $entries as $entry ) {
    if ( count( $callbacks ) >= 8 ) { $callbacks_truncated = true; break 3; }
    $callback = error_callback( $entry['function'] );
    if ( $callback && is_int( $priority ) && abs( $priority ) <= 2147483647 ) { $callbacks[] = array_merge( array( 'hook' => $hook, 'priority' => $priority ), $callback ); }
   }
  }
 }
 $id = function ( $value ) { return is_numeric( $value ) && $value > 0 && $value <= 9007199254740991 ? (int) $value : null; };
 $context = array( 'kind' => $kind, 'method' => $method, 'path' => 'cli' === $kind ? null : error_context_path( $_SERVER['REQUEST_URI'] ?? null ),
  'post_id' => $id( is_object( $post ) ? ( $post->ID ?? null ) : null ), 'queried_object_id' => $id( is_object( $wp_query ) ? ( $wp_query->queried_object_id ?? null ) : null ),
  'template' => $template_id, 'hooks' => $hooks, 'hooks_truncated' => $hooks_truncated, 'block' => $block, 'callbacks' => $callbacks, 'callbacks_truncated' => $callbacks_truncated );
 while ( strlen( wp_json_encode( $context ) ) > 8192 && $context['callbacks'] ) { array_pop( $context['callbacks'] ); $context['callbacks_truncated'] = true; }
 return $context;
}

function error_context_valid( $context ) {
 $keys = array( 'kind', 'method', 'path', 'post_id', 'queried_object_id', 'template', 'hooks', 'hooks_truncated', 'block', 'callbacks', 'callbacks_truncated' );
 if ( ! is_array( $context ) || count( $context ) !== count( $keys ) || array_diff( $keys, array_keys( $context ) ) || strlen( wp_json_encode( $context ) ) > 8192 ) { return false; }
 if ( ! in_array( $context['kind'], array( 'frontend', 'admin', 'rest', 'ajax', 'cron', 'cli', 'unknown' ), true ) || ( null !== $context['method'] && ! in_array( $context['method'], array( 'GET', 'POST', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ) ) { return false; }
 if ( null !== $context['path'] && ( ! is_string( $context['path'] ) || strlen( $context['path'] ) > 512 || ! preg_match( '#^/(?:[A-Za-z0-9._~/-]|\[redacted\])*$#D', $context['path'] ) || false !== strpos( $context['path'], '..' ) ) ) { return false; }
 foreach ( array( 'post_id', 'queried_object_id' ) as $key ) { if ( null !== $context[ $key ] && ( ! is_int( $context[ $key ] ) || $context[ $key ] < 1 || $context[ $key ] > 9007199254740991 ) ) { return false; } }
 if ( null !== $context['template'] && ( ! is_string( $context['template'] ) || ! preg_match( '#^[A-Za-z0-9_][A-Za-z0-9_./-]{0,239}$#D', $context['template'] ) || false !== strpos( $context['template'], '..' ) ) ) { return false; }
 if ( ! is_bool( $context['hooks_truncated'] ) || ! is_bool( $context['callbacks_truncated'] ) || ! is_array( $context['hooks'] ) || count( $context['hooks'] ) > 8 || ! is_array( $context['callbacks'] ) || count( $context['callbacks'] ) > 8 ) { return false; }
 if ( array_values( $context['hooks'] ) !== $context['hooks'] || array_values( $context['callbacks'] ) !== $context['callbacks'] ) { return false; }
 foreach ( $context['hooks'] as $hook ) { if ( null === error_hook_name( $hook ) ) { return false; } }
 $callback_valid = function ( $callback, $hook = false ) {
  $keys = $hook ? array( 'function', 'file', 'line', 'hook', 'priority' ) : array( 'function', 'file', 'line' );
  if ( ! is_array( $callback ) || count( $callback ) !== count( $keys ) || array_diff( $keys, array_keys( $callback ) ) ) { return false; }
  if ( ! is_string( $callback['function'] ) || ! preg_match( '/^[A-Za-z0-9_\\\\:{}>\-]{1,200}$/D', $callback['function'] ) || ! is_string( $callback['file'] ) || strlen( $callback['file'] ) > 240 || ! preg_match( '#^(wordpress|plugins|themes|mu-plugins|content|outside)/[A-Za-z0-9_./-]+$#D', $callback['file'] ) || false !== strpos( $callback['file'], '..' ) || false !== strpos( $callback['file'], '//' ) || ! is_int( $callback['line'] ) || $callback['line'] < 0 || $callback['line'] > 10000000 ) { return false; }
  return ! $hook || ( null !== error_hook_name( $callback['hook'] ) && is_int( $callback['priority'] ) && abs( $callback['priority'] ) <= 2147483647 );
 };
 if ( null !== $context['block'] && ( ! is_array( $context['block'] ) || count( $context['block'] ) !== 2 || ! isset( $context['block']['name'] ) || ! array_key_exists( 'callback', $context['block'] ) || ! is_string( $context['block']['name'] ) || strlen( $context['block']['name'] ) > 120 || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#D', $context['block']['name'] ) || ( null !== $context['block']['callback'] && ! $callback_valid( $context['block']['callback'] ) ) ) ) { return false; }
 foreach ( $context['callbacks'] as $callback ) { if ( ! $callback_valid( $callback, true ) ) { return false; } }
 return true;
}
