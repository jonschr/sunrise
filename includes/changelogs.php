<?php
/** Read the installed updater's View details response; never activate a plugin to discover it. */
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

function changelog_http_args( $args ) {
	$args['timeout'] = max( 0.1, min( 3, isset( $args['timeout'] ) ? $args['timeout'] : 3, $GLOBALS['sunrise_changelog_deadline'] - microtime( true ) ) );
	$args['redirection'] = min( 3, isset( $args['redirection'] ) ? $args['redirection'] : 3 );
	$args['limit_response_size'] = 500000;
	$args['reject_unsafe_urls'] = true;
	return $args;
}

function changelog_http_guard( $response ) {
	if ( ++$GLOBALS['sunrise_changelog_requests'] > 6 || microtime( true ) >= $GLOBALS['sunrise_changelog_deadline'] ) { return new \WP_Error( 'sunrise_changelog_budget', 'Changelog lookup budget reached.' ); }
	return $response;
}

function changelog_http_response( $response ) {
	$retry = wp_remote_retrieve_header( $response, 'retry-after' );
	if ( $retry ) { $GLOBALS['sunrise_changelog_retry_at'] = max( $GLOBALS['sunrise_changelog_retry_at'], ctype_digit( (string) $retry ) ? time() + (int) $retry : (int) strtotime( $retry ) ); }
	return $response;
}

function plugin_changelog_info( $id, $offer ) {
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	$slug = isset( $offer->slug ) && is_string( $offer->slug ) ? $offer->slug : '';
	if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $slug ) ) { return null; }
	$GLOBALS['sunrise_changelog_requests'] = 0; $GLOBALS['sunrise_changelog_deadline'] = microtime( true ) + 6; $GLOBALS['sunrise_changelog_retry_at'] = 0;
	add_filter( 'http_request_args', __NAMESPACE__ . '\\changelog_http_args', PHP_INT_MAX );
	add_filter( 'pre_http_request', __NAMESPACE__ . '\\changelog_http_guard', PHP_INT_MAX );
	add_filter( 'http_response', __NAMESPACE__ . '\\changelog_http_response', PHP_INT_MAX );
	try {
		// PUC (JSON and repository/Markdown), EDD, Freemius and vendor updaters share this hook.
		$args = apply_filters( 'plugins_api_args', (object) array( 'slug' => $slug, 'per_page' => 24, 'locale' => get_user_locale(), 'fields' => array( 'sections' => true ) ), 'plugin_information' );
		$info = apply_filters( 'plugins_api', false, 'plugin_information', $args );
		if ( false !== $info ) { $info = apply_filters( 'plugins_api_result', $info, 'plugin_information', $args ); }
	} catch ( \Throwable $error ) { return null; }
	finally {
		remove_filter( 'http_request_args', __NAMESPACE__ . '\\changelog_http_args', PHP_INT_MAX );
		remove_filter( 'pre_http_request', __NAMESPACE__ . '\\changelog_http_guard', PHP_INT_MAX );
		remove_filter( 'http_response', __NAMESPACE__ . '\\changelog_http_response', PHP_INT_MAX );
	}
	if ( ! is_object( $info ) || is_wp_error( $info ) || ( isset( $info->slug ) && $slug !== $info->slug ) || ( isset( $info->plugin ) && $id !== $info->plugin ) ) { return null; }
	$sections = isset( $info->sections ) ? (array) $info->sections : array();
	$notes = isset( $sections['changelog'] ) ? $sections['changelog'] : null;
	if ( ! is_string( $notes ) || strlen( $notes ) > 500000 || ! isset( $info->version ) || ! is_string( $info->version ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $info->version ) ) { return null; }
	$without_links = preg_replace( '#<a\b[^>]*>.*?</a\s*>#is', '', $notes );
	$without_links = preg_replace( '#https?://[^\s<>]+|\[[^\]]*\]\([^)]*\)#i', '', $without_links );
	$without_links = preg_replace( '#<h[2-6]\b[^>]*>.*?</h[2-6]\s*>#is', '', $without_links );
	if ( ! trim( wp_strip_all_tags( $without_links ) ) ) { return null; }
	// Strip URLs/attributes as well as executable markup; no credentials or package URLs leave the site.
	$notes = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $notes );
	$notes = preg_replace( '/<([a-z][a-z0-9]*)\b[^>]*>/i', '<$1>', strip_tags( $notes, '<h2><h3><h4><h5><h6><p><br><ul><ol><li><a>' ) );
	$notes = preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $notes );
	$notes = preg_replace( '#https?://[^\s<>]+#i', '[link]', $notes );
	$truncated = strlen( $notes ) > 32000;
	$notes = wp_check_invalid_utf8( substr( $notes, 0, 32000 ), true );
	if ( ! trim( wp_strip_all_tags( $notes ) ) ) { return null; }
	return array( 'version' => $info->version, 'changelog' => $notes, 'truncated' => $truncated );
}

/** Two provider lookups at most; rotate across offers, including ignored updates. */
function agent_plugin_changelogs( $inventory, $connection = '' ) {
	$updates = get_site_transient( 'update_plugins' );
	$cache_option = 'sunrise_changelog_cache_' . get_current_user_id();
	$state = get_option( $cache_option, array() );
	if ( ! is_array( $state ) ) { $state = array(); }
	$entries = array(); $candidates = array(); $now = time();
	foreach ( $inventory as $item ) {
		if ( 'plugins' !== $item['type'] || empty( $item['offered_version'] ) || empty( $updates->response[ $item['installed_id'] ] ) ) { continue; }
		$id = $item['installed_id']; $offer = (object) $updates->response[ $id ];
		$key = hash( 'sha256', wp_json_encode( array( $connection, $id, $item['version'], $item['offered_version'], $offer->slug ?? '', $item['identity'] ?? null ) ) );
		$cached = $state[ $key ] ?? null;
		$entries[ $key ] = $cached;
		$candidates[] = array( 'id' => $id, 'offer' => $offer, 'key' => $key, 'target' => $item['offered_version'], 'checked' => $cached['checked'] ?? 0 );
	}
	usort( $candidates, static function ( $a, $b ) { return $a['checked'] <=> $b['checked']; } );
	$report = array(); $started = microtime( true );
	foreach ( $candidates as $candidate ) {
		if ( count( $report ) >= 2 || microtime( true ) - $started > 6 ) { break; }
		$key = $candidate['key']; $cached = $entries[ $key ];
		if ( $cached && max( $cached['retry_at'] ?? 0, $cached['checked'] + ( $cached['info'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS ) ) > $now ) { continue; }
		$info = plugin_changelog_info( $candidate['id'], $candidate['offer'] );
		$entries[ $key ] = array( 'checked' => $now, 'info' => (bool) $info, 'retry_at' => $GLOBALS['sunrise_changelog_retry_at'] ?? 0 );
		$report[] = array( 'installed_id' => $candidate['id'], 'offered_version' => $candidate['target'], 'version' => $info['version'] ?? null, 'changelog' => $info['changelog'] ?? null, 'truncated' => $info['truncated'] ?? false );
	}
	update_option( $cache_option, $entries, false );
	return $report;
}
