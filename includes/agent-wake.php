<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

/** Derive a separate wake-only key; never send the agent credential back to a site. */
function agent_wake_key( $state ) {
 require_once __DIR__ . '/controller.php';
 $secret = credential( $state['secret'], 'agent|' . $state['url'] . '|' . $state['user_id'], true );
 if ( is_wp_error( $secret ) ) { return $secret; }
 return hash_hmac( 'sha256', 'sunrise-wake-v1|' . $state['site_id'] . '|' . $state['generation'] . '|' . $state['url'], $secret );
}

function agent_wake_state( $request ) {
 $denied = new \WP_Error( 'sunrise_wake_denied', 'Wake request not authorized.', array( 'status' => 403 ) );
 if ( ( ! is_ssl() && 'local' !== wp_get_environment_type() ) || strlen( $request->get_body() ) > 1024 ) { return $denied; }
 $data = $request->get_json_params(); $signature = $request->get_header( 'x-sunrise-signature' );
 if ( ! is_array( $data ) || count( $data ) !== 4 || ! isset( $data['site_id'], $data['generation'], $data['request_id'], $data['issued_at'] )
  || ! is_string( $data['site_id'] ) || ! wp_is_uuid( $data['site_id'], 4 ) || ! is_string( $data['request_id'] ) || ! wp_is_uuid( $data['request_id'], 4 )
  || ! is_int( $data['generation'] ) || ! is_int( $data['issued_at'] ) || abs( time() - $data['issued_at'] ) > 120
  || ! is_string( $signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) { return $denied; }
 foreach ( agent_states() as $state ) {
  if ( ( $state['site_id'] ?? null ) !== $data['site_id'] || ( $state['generation'] ?? null ) !== $data['generation'] || ! empty( $state['revoked'] ) || ! empty( $state['paused'] ) ) { continue; }
  if ( ! agent_owner_valid( $state ) || ! agent_service_matches( $state ) || $state['url'] !== untrailingslashit( site_url() ) ) { return $denied; }
  $key = agent_wake_key( $state );
  if ( is_wp_error( $key ) || ! hash_equals( hash_hmac( 'sha256', $request->get_body(), $key ), $signature ) || is_wp_error( installation_guard() ) ) { return $denied; }
  return $state;
 }
 return $denied;
}

function agent_wake_permission( $request ) {
 $state = agent_wake_state( $request ); return is_wp_error( $state ) ? $state : true;
}

function agent_wake( $request ) {
 require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
 if ( ! \WP_Upgrader::create_lock( 'sunrise_agent', 120 ) ) { return new \WP_Error( 'sunrise_agent_busy', 'Check-in is already running.', array( 'status' => 409 ) ); }
 try {
  $state = agent_wake_state( $request ); if ( is_wp_error( $state ) ) { return $state; }
  $data = $request->get_json_params(); $name = 'sunrise_wake_' . $state['user_id']; $last = get_option( $name, array() );
  $response = array( 'status' => 'scheduled', 'site_id' => $state['site_id'], 'request_id' => $data['request_id'], 'cron_spawned' => false );
  // Same signed request may be retried after a lost response, without rescheduling work.
  if ( ( $last['request_id'] ?? null ) === $data['request_id'] ) { $response['cron_spawned'] = ! empty( $last['cron_spawned'] ); return new \WP_REST_Response( $response, 202 ); }
  if ( isset( $last['issued_at'] ) && ( $data['issued_at'] < $last['issued_at'] || time() - $last['received_at'] < 30 ) ) { return new \WP_Error( 'sunrise_wake_rate_limited', 'A check-in was recently requested.', array( 'status' => 429 ) ); }
  $args = array( (int) $state['user_id'] ); $next = wp_next_scheduled( 'sunrise_check_in', $args );
  if ( ! $next || $next > time() ) {
   if ( $next && ! wp_unschedule_event( $next, 'sunrise_check_in', $args ) ) { return new \WP_Error( 'sunrise_wake_schedule', 'Could not reschedule check-in.', array( 'status' => 500 ) ); }
   $scheduled = wp_schedule_single_event( time() - 1, 'sunrise_check_in', $args, true );
   if ( is_wp_error( $scheduled ) || ! $scheduled ) {
    if ( $next ) { wp_schedule_single_event( $next, 'sunrise_check_in', $args ); }
    return new \WP_Error( 'sunrise_wake_schedule', 'Could not schedule check-in.', array( 'status' => 500 ) );
   }
  }
  if ( ! update_option( $name, array( 'request_id' => $data['request_id'], 'issued_at' => $data['issued_at'], 'received_at' => time(), 'cron_spawned' => false ), false ) ) { return new \WP_Error( 'sunrise_wake_storage', 'Could not retain wake request.', array( 'status' => 500 ) ); }
 } finally { \WP_Upgrader::release_lock( 'sunrise_agent' ); }
 // Explicitly start the due event, even when page-triggered WP-Cron is disabled by the host.
 $response['cron_spawned'] = (bool) spawn_cron();
 $last = get_option( $name, array() );
 if ( ( $last['request_id'] ?? null ) === $data['request_id'] ) { $last['cron_spawned'] = $response['cron_spawned']; update_option( $name, $last, false ); }
 return new \WP_REST_Response( $response, 202 );
}
