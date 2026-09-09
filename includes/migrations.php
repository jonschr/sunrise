<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-inventory.php';

function migration_draft() {
	$draft = get_user_meta( get_current_user_id(), 'sunrise_migration_draft', true );
	return is_array( $draft ) && isset( $draft['connection'] ) && $draft['connection'] === agent_state()['site_id'] ? $draft : array( 'connection' => agent_state()['site_id'], 'direction' => 'pull', 'peer' => null, 'preset' => 'settings', 'scopes' => array( 'options' ), 'since' => 'all', 'date' => '', 'names' => transfer_option_names() );
}
function save_migration_draft( $data ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	$keys = array( 'connection', 'direction', 'peer', 'preset', 'scopes', 'since', 'date', 'names' );
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
	}
	if ( ! $valid ) { return new \WP_Error( 'sunrise_migration_draft', 'Invalid migration selections.', array( 'status' => 400 ) ); }
	if ( ! update_user_meta( get_current_user_id(), 'sunrise_migration_draft', $data ) && migration_draft() !== $data ) { return new \WP_Error( 'sunrise_migration_save', 'Could not save migration selections.', array( 'status' => 503 ) ); }
	return $data;
}
function migration_status() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	return agent_http( 'agent/transfers/status', (object) array(), agent_state() );
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
	wp_localize_script( 'sunrise-migrations', 'sunriseMigrations', array( 'api' => rest_url( 'sunrise/v1/transfers/workbench' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'control' => agent_dashboard_url(), 'draft' => migration_draft(), 'site' => array( 'id' => agent_state()['site_id'], 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ) ) );
	echo '<div id="sunrise-migration-workbench"><p class="migration-lead">' . esc_html__( 'Choose what moves, and where it goes.', 'sunrise' ) . '</p><div class="migration-route"><div><span class="migration-label">Source · copy from</span><strong id="migration-source"></strong><small id="migration-source-url"></small></div><button type="button" id="migration-swap" aria-label="Reverse migration direction">⇄</button><div><span class="migration-label">Destination · write to</span><strong id="migration-destination"></strong><small id="migration-destination-url"></small></div><button type="button" class="button" id="migration-choose">Choose connected site</button></div><p id="migration-direction" aria-live="polite"></p><div class="migration-card"><div class="migration-card-heading"><h2>What to migrate</h2><label>Preset <select id="migration-preset"><option value="settings">Site settings</option><option value="content">Content, settings &amp; media</option><option value="files">Plugins &amp; themes</option><option value="custom">Custom selection</option></select></label></div>';
	$scopes = array( 'posts' => array( 'Posts & pages', 'Content, custom post types, metadata and relationships.' ), 'options' => array( 'Site settings', 'Site title, tagline, date, time and timezone settings.' ), 'media' => array( 'Media library', 'Media records, original uploads and generated files.' ), 'plugins' => array( 'Plugins', 'Selected plugin files; Sunrise stays with its destination.' ), 'themes' => array( 'Themes', 'Selected theme files, including child themes.' ), 'tables' => array( 'Database tables', 'Explicitly selected tables, with destination identity protected.' ) );
	foreach ( $scopes as $scope => $labels ) {
		echo '<div class="migration-scope"><label class="migration-toggle"><input type="checkbox" value="' . esc_attr( $scope ) . '" class="migration-scope-input"><span aria-hidden="true"></span><strong>' . esc_html( $labels[0] ) . '</strong></label><p>' . esc_html( $labels[1] ) . '</p>';
		if ( 'options' !== $scope ) { echo '<small class="migration-unavailable">Not available yet</small>'; }
		else { echo '<details id="migration-settings"><summary>Choose settings</summary><div>'; foreach ( array( 'blogname' => 'Site title', 'blogdescription' => 'Tagline', 'date_format' => 'Date format', 'time_format' => 'Time format', 'start_of_week' => 'Week starts on', 'timezone_string' => 'Timezone', 'gmt_offset' => 'UTC offset' ) as $name => $label ) { echo '<label><input type="checkbox" value="' . esc_attr( $name ) . '"> ' . esc_html( $label ) . '</label>'; } echo '</div></details>'; }
		echo '</div>';
	}
	echo '<div class="migration-range"><label>Include <select id="migration-since"><option value="all">All selected data</option><option value="date">Added or changed since a date</option><option value="last">Since the last successful transfer</option></select></label><label id="migration-date-label" hidden>Since (UTC) <input type="date" id="migration-date"></label><p id="migration-range-note"></p></div></div><div class="migration-card migration-actions"><div><p id="migration-ready" role="status"></p><small id="migration-save" role="status"></small></div><button type="button" id="migration-prepare" class="button button-primary">Prepare migration</button></div><div class="migration-card"><div class="migration-card-heading"><h2>Migration status</h2><button type="button" class="button" id="migration-sync">Sync transfer now</button></div><p id="migration-status" role="status" aria-live="polite"></p><div id="migration-jobs"></div><details><summary>Activity log</summary><ol id="migration-log"></ol></details></div></div>';
}
