<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-inventory.php';

function migration_draft() {
	$draft = get_user_meta( get_current_user_id(), 'sunrise_migration_draft', true );
	$draft = is_array( $draft ) && isset( $draft['connection'] ) && $draft['connection'] === agent_state()['site_id'] ? $draft : array( 'connection' => agent_state()['site_id'], 'direction' => 'pull', 'peer' => null, 'preset' => 'settings', 'scopes' => array( 'options' ), 'since' => 'all', 'date' => '', 'names' => transfer_option_names() );
	if ( ! isset( $draft['file_selection'] ) ) { $draft['file_selection'] = array( 'plugins' => array( 'mode' => 'all', 'items' => array() ), 'themes' => array( 'mode' => 'all', 'items' => array() ), 'media' => array( 'mode' => 'last', 'since' => null ) ); }
	return $draft;
}
function save_migration_draft( $data ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	$keys = array( 'connection', 'direction', 'peer', 'preset', 'scopes', 'since', 'date', 'names', 'file_selection' );
	$valid = is_array( $data ) && count( $data ) === count( $keys ) && ! array_diff( $keys, array_keys( $data ) );
	if ( $valid ) {
		$valid = $data['connection'] === agent_state()['site_id'] && in_array( $data['direction'], array( 'push', 'pull' ), true ) && in_array( $data['preset'], array( 'settings', 'content', 'files', 'custom' ), true ) && in_array( $data['since'], array( 'all', 'date', 'last' ), true ) && is_string( $data['date'] ) && ( '' === $data['date'] || ( preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $data['date'] ) && gmdate( 'Y-m-d', strtotime( $data['date'] ) ) === $data['date'] ) );
		foreach ( array( 'scopes' => array( 'posts', 'media', 'options', 'plugins', 'themes', 'tables' ), 'names' => transfer_option_names() ) as $key => $allowed ) {
			$valid = $valid && is_array( $data[ $key ] ) && array_values( $data[ $key ] ) === $data[ $key ] && count( $data[ $key ] ) <= count( $allowed ) && count( array_filter( $data[ $key ], 'is_string' ) ) === count( $data[ $key ] ) && count( array_unique( $data[ $key ] ) ) === count( $data[ $key ] ) && ! array_diff( $data[ $key ], $allowed );
		}
		if ( null !== $data['peer'] ) {
			$p = $data['peer'];
			$valid = $valid && is_array( $p ) && count( $p ) === 3 && isset( $p['id'], $p['url'], $p['name'] ) && is_string( $p['id'] ) && wp_is_uuid( $p['id'], 4 ) && $p['id'] !== $data['connection'] && is_string( $p['url'] ) && strlen( $p['url'] ) <= 2048 && is_string( $p['name'] ) && strlen( $p['name'] ) <= 255;
			if ( $valid ) { $url = wp_parse_url( $p['url'] ); $valid = is_array( $url ) && ! empty( $url['host'] ) && isset( $url['scheme'] ) && in_array( $url['scheme'], array( 'https', 'http' ), true ) && ! isset( $url['user'] ) && ! isset( $url['pass'] ) && ! isset( $url['query'] ) && ! isset( $url['fragment'] ); }
		}
		require_once __DIR__ . '/transfer-files.php'; try { if ( ! is_array( $data['file_selection'] ) || count( $data['file_selection'] ) !== 3 || strlen( wp_json_encode( $data['file_selection'] ) ) > 8192 ) { throw new \InvalidArgumentException(); } transfer_file_selection( $data['file_selection'] ); } catch ( \Throwable $error ) { $valid = false; }
	}
	if ( ! $valid ) { return new \WP_Error( 'sunrise_migration_draft', 'Invalid migration selections.', array( 'status' => 400 ) ); }
	if ( ! update_user_meta( get_current_user_id(), 'sunrise_migration_draft', $data ) && migration_draft() !== $data ) { return new \WP_Error( 'sunrise_migration_save', 'Could not save migration selections.', array( 'status' => 503 ) ); }
	return $data;
}

function migration_control_request( $method, $suffix = '', $data = null, $headers = array() ) {
	$state = agent_state(); $payload = array( 'path' => '/v1/accounts/' . $state['account_id'] . '/networks/' . $state['network_id'] . '/transfers' . $suffix, 'method' => $method );
	if ( null !== $data ) { $payload['data'] = $data; }
	if ( $headers ) { $payload['headers'] = $headers; }
	$response = agent_http( 'agent/dashboard', $payload, $state ); if ( is_wp_error( $response ) ) { return $response; }
	if ( ! isset( $response['status'], $response['body'] ) || ! is_int( $response['status'] ) || ! is_array( $response['body'] ) ) { return new \WP_Error( 'sunrise_migration_control', 'Sunrise Control returned an invalid migration response.', array( 'status' => 502 ) ); }
	if ( $response['status'] < 200 || $response['status'] >= 300 ) { $code = $response['body']['error']['code'] ?? 'migration_request_failed'; return new \WP_Error( 'sunrise_migration_control', 'Sunrise Control rejected the migration request: ' . sanitize_key( $code ) . '.', array( 'status' => $response['status'] ) ); }
	return $response['body'];
}

function migration_prepare( $data ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( ! is_array( $data ) || count( $data ) !== 2 || ! isset( $data['keys'], $data['draft'] ) || ! is_array( $data['keys'] ) ) { return new \WP_Error( 'sunrise_migration_selection', 'Invalid migration request.', array( 'status' => 400 ) ); }
	$draft = save_migration_draft( $data['draft'] ); if ( is_wp_error( $draft ) ) { return $draft; } $supported = array( 'options', 'plugins', 'themes', 'media', 'tables' );
	if ( ! $draft['peer'] || array_diff( $draft['scopes'], $supported ) || ( in_array( 'tables', $draft['scopes'], true ) && array( 'tables' ) !== $draft['scopes'] ) || ( in_array( 'options', $draft['scopes'], true ) && ( 'all' !== $draft['since'] || ! $draft['names'] ) ) ) { return new \WP_Error( 'sunrise_migration_selection', 'Choose a supported migration and connected site.', array( 'status' => 400 ) ); }
	$current = agent_state()['site_id']; $source = 'push' === $draft['direction'] ? $current : $draft['peer']['id']; $destination = 'push' === $draft['direction'] ? $draft['peer']['id'] : $current; $requests = array();
	if ( in_array( 'options', $draft['scopes'], true ) ) { $requests[] = array( 'source_site_id' => $source, 'destination_site_id' => $destination, 'names' => $draft['names'] ); }
	$file_scopes = array_intersect( $draft['scopes'], array( 'plugins', 'themes', 'media' ) ); if ( $file_scopes ) { $requests[] = array( 'source_site_id' => $source, 'destination_site_id' => $destination, 'file_selection' => array_intersect_key( $draft['file_selection'], array_flip( $file_scopes ) ) ); }
	if ( in_array( 'tables', $draft['scopes'], true ) ) { $requests[] = array( 'source_site_id' => $source, 'destination_site_id' => $destination, 'names' => array( 'database' ) ); }
	if ( ! $requests || count( $data['keys'] ) !== count( $requests ) ) { return new \WP_Error( 'sunrise_migration_selection', 'Migration request keys do not match the selected work.', array( 'status' => 400 ) ); }
	foreach ( $requests as $index => $request ) {
		$key = $data['keys'][ $index ] ?? null; if ( ! is_string( $key ) || ! wp_is_uuid( $key, 4 ) ) { return new \WP_Error( 'sunrise_migration_key', 'Invalid migration request key.', array( 'status' => 400 ) ); }
		$result = migration_control_request( 'POST', '', $request, array( 'Idempotency-Key' => $key ) ); if ( is_wp_error( $result ) ) { return $result; }
	}
	return migration_status();
}

function migration_action( $action, $data ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( ! in_array( $action, array( 'approve', 'cancel' ), true ) || ! is_array( $data ) || ! isset( $data['id'], $data['revision'] ) || ! is_string( $data['id'] ) || ! wp_is_uuid( $data['id'], 4 ) || ! is_int( $data['revision'] ) || $data['revision'] < 0 ) { return new \WP_Error( 'sunrise_migration_action', 'Invalid migration action.', array( 'status' => 400 ) ); }
	if ( 'approve' === $action ) {
		if ( count( $data ) !== 4 || ! isset( $data['side'], $data['plan_hash'] ) || ! in_array( $data['side'], array( 'source', 'destination' ), true ) || ! is_string( $data['plan_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['plan_hash'] ) ) { return new \WP_Error( 'sunrise_migration_action', 'Invalid migration approval.', array( 'status' => 400 ) ); }
		$body = array( 'side' => $data['side'], 'revision' => $data['revision'], 'plan_hash' => $data['plan_hash'] );
	} else { if ( count( $data ) !== 2 ) { return new \WP_Error( 'sunrise_migration_action', 'Invalid migration cancellation.', array( 'status' => 400 ) ); } $body = array( 'revision' => $data['revision'] ); }
	$result = migration_control_request( 'POST', '/' . $data['id'] . '/' . $action, $body ); if ( is_wp_error( $result ) ) { return $result; }
	return migration_status();
}

function migration_peers( $after = null ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( null !== $after && ( ! is_string( $after ) || ! wp_is_uuid( $after, 4 ) ) ) { return new \WP_Error( 'sunrise_peer_cursor', 'Invalid site list cursor.', array( 'status' => 400 ) ); }
	$state = agent_state(); $path = '/v1/accounts/' . $state['account_id'] . '/networks/' . $state['network_id'] . '/sites?limit=100' . ( $after ? '&after=' . rawurlencode( $after ) : '' );
	$response = agent_http( 'agent/dashboard', array( 'path' => $path, 'method' => 'GET' ), $state ); if ( is_wp_error( $response ) ) { return $response; }
	if ( ( $response['status'] ?? 0 ) !== 200 || ! isset( $response['body']['items'] ) ) { return new \WP_Error( 'sunrise_peers', 'Network access is unavailable. Enable network controls from the Updates page.', array( 'status' => 403 ) ); }
	$items = array(); foreach ( $response['body']['items'] as $site ) { if ( $site['id'] !== $state['site_id'] && $site['active'] ) { $items[] = array( 'id' => $site['id'], 'url' => $site['url'], 'name' => $site['site_profile']['name'] ?? $site['url'] ); } }
	return array( 'items' => $items, 'next_cursor' => $response['body']['next_cursor'] ?? null );
}
function migration_file_catalog( $site, $after = null ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( ! is_string( $site ) || ! wp_is_uuid( $site, 4 ) || ( null !== $after && ( ! is_string( $after ) || ! preg_match( '/^[0-9]{1,16}:[0-9]{1,4}$/D', $after ) ) ) ) { return new \WP_Error( 'sunrise_file_site', 'Choose a connected source site.', array( 'status' => 400 ) ); }
	require_once __DIR__ . '/transfer-files.php';
	if ( $site === agent_state()['site_id'] ) { return array( 'plugins' => transfer_file_catalog( 'plugins' ), 'themes' => transfer_file_catalog( 'themes' ), 'next_cursor' => null ); }
	$state = agent_state(); $path = '/v1/accounts/' . $state['account_id'] . '/networks/' . $state['network_id'] . '/sites/' . $site . '/inventory' . ( $after ? '?after=' . rawurlencode( $after ) : '' );
	$response = agent_http( 'agent/dashboard', array( 'path' => $path, 'method' => 'GET' ), $state ); if ( is_wp_error( $response ) ) { return $response; }
	if ( ( $response['status'] ?? 0 ) !== 200 || ! isset( $response['body']['items'] ) ) { return new \WP_Error( 'sunrise_file_catalog', 'Could not load the source inventory. Check network dashboard permission and the source’s latest check-in.', array( 'status' => 409 ) ); }
	$result = array( 'plugins' => array(), 'themes' => array(), 'next_cursor' => $response['body']['next_cursor'] ?? null );
	foreach ( $response['body']['items'] as $item ) { if ( in_array( $item['type'], array( 'plugins', 'themes' ), true ) && transfer_file_path_valid( $item['type'] . '/' . $item['installed_id'] ) ) { $result[ $item['type'] ][] = array( 'id' => $item['installed_id'], 'name' => $item['identity']['name'] ?? $item['installed_id'], 'active' => $item['active'] ); } }
	return $result;
}
function migration_status() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	$result = agent_http( 'agent/transfers/status', (object) array(), agent_state() ); if ( is_wp_error( $result ) ) { return $result; }
	require_once __DIR__ . '/transfer-files.php';
	foreach ( $result['items'] ?? array() as $index => $job ) {
		if ( empty( $job['file_selection'] ) || ! in_array( $job['status'], array( 'awaiting_source', 'approved' ), true ) ) { continue; }
		try {
			$root = transfer_file_workspace( $job['id'] ); $progress = null;
			if ( $job['source_site_id'] === agent_state()['site_id'] && is_file( $root . '/progress.json' ) && ! is_link( $root . '/progress.json' ) && filesize( $root . '/progress.json' ) < 1024 ) { $progress = json_decode( file_get_contents( $root . '/progress.json' ), true ); }
			if ( $job['destination_site_id'] === agent_state()['site_id'] && ( agent_state()['remote_transfer']['job']['id'] ?? null ) === $job['id'] && is_file( $root . '/incoming.zip' ) && ! is_link( $root . '/incoming.zip' ) ) { $progress = array( 'bytes' => filesize( $root . '/incoming.zip' ), 'total' => agent_state()['remote_transfer']['job']['archive']['bytes'] ?? 0 ); }
			if ( is_array( $progress ) && is_int( $progress['bytes'] ?? null ) && is_int( $progress['total'] ?? null ) && $progress['total'] > 0 && $progress['bytes'] >= 0 && $progress['bytes'] <= $progress['total'] ) { $result['items'][ $index ]['progress'] = $progress; }
		} catch ( \Throwable $error ) { /* Progress never changes transfer authority or its result. */ }
	}
	return $result;
}
function migration_sync() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	// Only transfer work: this button must not install unrelated queued plugin updates.
	if ( empty( agent_state()['transfer_previews'] ) ) { $r = agent_check_in(); if ( is_wp_error( $r ) ) { return $r; } }
	require_once __DIR__ . '/agent-transfers.php';
	$r = agent_prepare_transfer(); if ( is_wp_error( $r ) ) { return $r; }
	require_once __DIR__ . '/agent-transfer-execution.php';
	$r = agent_run_transfer(); if ( is_wp_error( $r ) ) { return $r; }
	return migration_status();
}
function migration_workbench_page() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { echo '<p>' . esc_html( $access->get_error_message() ) . '</p>'; return; }
	wp_enqueue_style( 'sunrise-migrations', plugins_url( '../assets/migrations.css', __FILE__ ), array(), VERSION );
	wp_enqueue_script( 'sunrise-migrations', plugins_url( '../assets/migrations.js', __FILE__ ), array(), VERSION, true );
	wp_localize_script( 'sunrise-migrations', 'sunriseMigrations', array( 'api' => rest_url( 'sunrise/v1/transfers/workbench' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'draft' => migration_draft(), 'site' => array( 'id' => agent_state()['site_id'], 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ) ) );
	echo '<div id="sunrise-migration-workbench"><p class="migration-lead">' . esc_html__( 'Choose what moves, and where it goes.', 'sunrise' ) . '</p><div class="migration-route"><div><span class="migration-label">Source · copy from</span><strong id="migration-source"></strong><small id="migration-source-url"></small></div><button type="button" id="migration-swap" aria-label="Reverse migration direction">⇄</button><div><span class="migration-label">Destination · write to</span><strong id="migration-destination"></strong><small id="migration-destination-url"></small></div><label class="migration-peer-label">Connected site<select id="migration-peer"><option value="">Loading sites…</option></select><span id="migration-peer-status" role="status"></span></label></div><p id="migration-direction" aria-live="polite"></p><div class="migration-card"><div class="migration-card-heading"><h2>What to migrate</h2><label>Preset <select id="migration-preset"><option value="settings">Site settings</option><option value="content">Content, settings &amp; media</option><option value="files">Plugins &amp; themes</option><option value="custom">Custom selection</option></select></label></div>';
	$scopes = array( 'posts' => array( 'Posts & pages', 'Content, custom post types, metadata and relationships.' ), 'options' => array( 'Site settings', 'Site title, tagline, date, time and timezone settings.' ), 'media' => array( 'Media files', 'Original uploads and generated files; attachment records move with the database.' ), 'plugins' => array( 'Plugins', 'Selected plugin files; Sunrise stays with its destination.' ), 'themes' => array( 'Themes', 'Selected theme files, including child themes.' ), 'tables' => array( 'Database preflight', 'Inventory and validate all WordPress tables without writing to the destination.' ) );
	foreach ( $scopes as $scope => $labels ) {
		echo '<div class="migration-scope"><label class="migration-toggle"><input type="checkbox" value="' . esc_attr( $scope ) . '" class="migration-scope-input"><span aria-hidden="true"></span><strong>' . esc_html( $labels[0] ) . '</strong></label><p>' . esc_html( $labels[1] ) . '</p>';
		if ( in_array( $scope, array( 'plugins', 'themes' ), true ) ) {
			echo '<div class="migration-file-controls" data-file-scope="' . esc_attr( $scope ) . '"><fieldset><legend class="screen-reader-text">Choose ' . esc_html( $scope ) . '</legend>';
			foreach ( array( 'all' => 'All ' . $scope, 'active' => 'Only active ' . $scope, 'include' => 'Only selected ' . $scope, 'exclude' => 'All ' . $scope . ' except those selected' ) as $mode => $label ) { echo '<label><input type="radio" name="migration-' . esc_attr( $scope ) . '-mode" value="' . esc_attr( $mode ) . '"> ' . esc_html( $label ) . '</label>'; }
			echo '</fieldset><div class="migration-file-list" id="migration-' . esc_attr( $scope ) . '-list" role="group" aria-label="Source ' . esc_attr( $scope ) . '"></div><small>Sunrise, symbolic links, hidden files and runtime configuration are excluded. Active themes include the parent theme.</small></div>';
		} elseif ( 'media' === $scope ) {
			echo '<fieldset id="migration-media-controls"><legend class="screen-reader-text">Media files</legend><label><input type="radio" name="migration-media-mode" value="last"> New and changed files since the last successful migration</label><label><input type="radio" name="migration-media-mode" value="all"> All media files</label><label><input type="radio" name="migration-media-mode" value="date"> Files added or modified since <input type="datetime-local" id="migration-media-date" aria-label="Media since date (UTC)"> UTC</label><small>Copies uploads, including original files and thumbnails. Attachment records require a database or content migration.</small></fieldset>';
		} elseif ( 'options' === $scope ) { echo '<details id="migration-settings"><summary>Choose settings</summary><div>'; foreach ( array( 'blogname' => 'Site title', 'blogdescription' => 'Tagline', 'date_format' => 'Date format', 'time_format' => 'Time format', 'start_of_week' => 'Week starts on', 'timezone_string' => 'Timezone', 'gmt_offset' => 'UTC offset' ) as $name => $label ) { echo '<label><input type="checkbox" value="' . esc_attr( $name ) . '"> ' . esc_html( $label ) . '</label>'; } echo '</div></details>'; }
		elseif ( 'tables' === $scope ) { echo '<small>Read-only preflight. Database writes remain disabled until backup and crash recovery are complete.</small>'; }
		else { echo '<small class="migration-unavailable">Not available yet</small>'; }
		echo '</div>';
	}
	echo '<div class="migration-range"><label>Include <select id="migration-since"><option value="all">All selected data</option><option value="date">Added or changed since a date</option><option value="last">Since the last successful transfer</option></select></label><label id="migration-date-label" hidden>Since (UTC) <input type="date" id="migration-date"></label><p id="migration-range-note"></p></div></div><div class="migration-card migration-actions"><div><p id="migration-ready" role="status"></p><small id="migration-save" role="status"></small></div><button type="button" id="migration-prepare" class="button button-primary">Prepare migration</button></div><div class="migration-card"><div class="migration-card-heading"><h2>Migration status</h2><button type="button" class="button" id="migration-sync">Sync transfer now</button></div><p id="migration-status" role="status" aria-live="polite"></p><div id="migration-jobs"></div><details><summary>Activity log</summary><ol id="migration-log"></ol></details></div></div>';
}
