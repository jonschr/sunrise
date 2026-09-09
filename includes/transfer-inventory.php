<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

/** Local read-only inventory. No credential, option body, post content or file byte is returned. */
function transfer_inventory( $scope, $after = 0 ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( ! in_array( $scope, array( 'posts', 'media', 'options', 'plugins', 'themes', 'tables' ), true ) || ! is_int( $after ) || $after < 0 || $after > PHP_INT_MAX - 51 ) { return new \WP_Error( 'sunrise_transfer_selection', 'Choose a supported inventory scope and cursor.', array( 'status' => 400 ) ); }
	$items = array(); $next = null;
	if ( in_array( $scope, array( 'posts', 'media' ), true ) ) {
		global $wpdb;
		$types = 'posts' === $scope ? "('post','page')" : "('attachment')";
		$fields = array( 'ID', 'post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'post_parent', 'post_author', 'post_password', 'post_name', 'menu_order', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'comment_status', 'ping_status', 'post_mime_type', 'guid' );
		$hash_input = implode( ',', array_map( function ( $field ) { return "LENGTH({$field}),':',{$field}"; }, $fields ) );
		// Hash selected raw fields inside MySQL so unusually large content never fills PHP memory.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_type,post_status,LEFT(post_title,240) AS title,post_modified_gmt,post_parent,post_author,
			LENGTH(post_content)+LENGTH(post_excerpt) AS content_bytes,
			CASE WHEN LENGTH(post_content)+LENGTH(post_excerpt)+LENGTH(post_title)>2097152 THEN NULL ELSE SHA2(CONCAT({$hash_input}),256) END AS fingerprint
			FROM {$wpdb->posts} WHERE ID>%d AND post_type IN {$types} AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT 51", $after ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'sunrise_transfer_inventory', 'Could not read transfer inventory.', array( 'status' => 503 ) ); }
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			$id = (int) $row['ID'];
			if ( ! current_user_can( 'read_post', $id ) ) { continue; }
			$items[] = array( 'id' => $id, 'type' => $row['post_type'], 'title' => $row['title'], 'status' => $row['post_status'], 'modified_gmt' => $row['post_modified_gmt'], 'parent_id' => (int) $row['post_parent'], 'author_id' => (int) $row['post_author'], 'content_bytes' => (int) $row['content_bytes'], 'fingerprint' => $row['fingerprint'], 'coverage' => 'post_fields_only', 'exclusions' => array( 'metadata', 'terms', 'comments', 'referenced_records', 'file_bytes' ) );
		}
		if ( count( $rows ) > 50 ) { $next = (int) $rows[49]['ID']; }
	} elseif ( 'options' === $scope ) {
		global $wpdb;
		$names = transfer_option_names();
		foreach ( array_slice( $names, $after, 50 ) as $name ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT LENGTH(option_value) AS bytes,SHA2(option_value,256) AS fingerprint FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
			if ( $row ) { $items[] = array( 'id' => $name, 'bytes' => (int) $row['bytes'], 'fingerprint' => $row['fingerprint'], 'coverage' => 'option_value' ); }
		}
	} elseif ( in_array( $scope, array( 'plugins', 'themes' ), true ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all = 'plugins' === $scope ? get_plugins() : wp_get_themes(); ksort( $all, SORT_STRING );
		foreach ( array_slice( $all, $after, 50, true ) as $id => $item ) {
			$is_plugin = 'plugins' === $scope;
			$items[] = array( 'id' => $id, 'name' => $is_plugin ? $item['Name'] : $item->get( 'Name' ), 'version' => $is_plugin ? $item['Version'] : $item->get( 'Version' ), 'coverage' => 'metadata_only', 'exclusions' => array( 'file_bytes', 'activation', 'settings', 'licenses' ), 'blocked' => $is_plugin && ( 'sunrise/sunrise.php' === $id || 0 === strpos( $id, 'sunrise/' ) ) ? 'controller_identity_component' : null );
		}
		if ( count( $all ) > $after + 50 ) { $next = $after + 50; }
	} else {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME,ENGINE,TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME LIMIT 51 OFFSET %d", $wpdb->esc_like( $wpdb->prefix ) . '%', $after ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'sunrise_transfer_inventory', 'Table metadata is unavailable on this host.', array( 'status' => 503 ) ); }
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			$name = substr( $row['TABLE_NAME'], strlen( $wpdb->prefix ) );
			$items[] = array( 'id' => $name, 'engine' => $row['ENGINE'], 'estimated_rows' => (int) $row['TABLE_ROWS'], 'coverage' => 'metadata_only', 'blocked' => 'A table-specific handler and overwrite approval are required.' );
		}
		if ( count( $rows ) > 50 ) { $next = $after + 50; }
	}
	return array( 'schema' => 1, 'scope' => $scope, 'observed_at' => gmdate( 'c' ), 'items' => $items, 'next_cursor' => $next, 'read_only' => true, 'execution_available' => false );
}

/** Deliberate allowlist: identity, credentials, cron, activation, transients and unknown plugin settings never enter generic transfer plans. */
function transfer_option_names() { return array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'start_of_week', 'timezone_string', 'gmt_offset' ); }

function transfer_inventory_page() {
	$scopes = array( 'posts' => 'Posts and pages', 'media' => 'Media records', 'options' => 'Supported settings', 'plugins' => 'Plugins', 'themes' => 'Themes', 'tables' => 'Database tables' );
	$scope = isset( $_GET['transfer_scope'] ) && is_string( $_GET['transfer_scope'] ) ? sanitize_key( $_GET['transfer_scope'] ) : '';
	$after = isset( $_GET['transfer_after'] ) && is_string( $_GET['transfer_after'] ) && preg_match( '/^[0-9]{1,10}$/D', $_GET['transfer_after'] ) ? (int) $_GET['transfer_after'] : 0;
	echo '<h3>' . esc_html__( 'Inspect this site', 'sunrise' ) . '</h3><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="sunrise-migrations"><label for="transfer-scope">' . esc_html__( 'Content to inspect', 'sunrise' ) . '</label> <select id="transfer-scope" name="transfer_scope">';
	foreach ( $scopes as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $scope, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
	echo '</select> <button class="button" id="inspect-transfer">' . esc_html__( 'Inspect', 'sunrise' ) . '</button></form>';
	if ( ! $scope ) { return; }
	$result = transfer_inventory( $scope, $after );
	if ( is_wp_error( $result ) ) { echo '<p role="alert">' . esc_html( $result->get_error_message() ) . '</p>'; return; }
	echo '<p>' . esc_html__( 'Read-only inspection. File contents and database rows are not compared. Posts exclude metadata, categories, comments, and linked media. No destination will be changed.', 'sunrise' ) . '</p><table class="widefat striped" id="transfer-inventory"><thead><tr><th>' . esc_html__( 'Item', 'sunrise' ) . '</th><th>' . esc_html__( 'Comparison covers', 'sunrise' ) . '</th></tr></thead><tbody>';
	$coverage = array( 'post_fields_only' => 'Post fields only', 'option_value' => 'Setting value', 'metadata_only' => 'Name and metadata only' );
	foreach ( $result['items'] as $item ) {
		$label = isset( $item['title'] ) ? $item['title'] : ( isset( $item['name'] ) ? $item['name'] : $item['id'] );
		echo '<tr><td>' . esc_html( $label ) . '<br><small>' . esc_html( $item['id'] ) . '</small></td><td>' . esc_html( $coverage[ $item['coverage'] ] );
		if ( ! empty( $item['blocked'] ) ) { echo '<br>' . esc_html__( 'Not eligible for transfer.', 'sunrise' ); }
		if ( array_key_exists( 'fingerprint', $item ) && ! $item['fingerprint'] ) { echo '<br>' . esc_html__( 'Too large or unavailable for comparison.', 'sunrise' ); }
		echo '</td></tr>';
	}
	if ( ! $result['items'] ) { echo '<tr><td colspan="2">' . esc_html__( 'No items on this page.', 'sunrise' ) . '</td></tr>'; }
	echo '</tbody></table>';
	if ( null !== $result['next_cursor'] ) { echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'sunrise-migrations', 'transfer_scope' => $scope, 'transfer_after' => $result['next_cursor'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next page', 'sunrise' ) . '</a></p>'; }
}

/** Explicitly selected, small native settings only. Site credentials cannot call this local administrator API. */
function transfer_option_snapshot( $names ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	if ( ! is_array( $names ) || ! $names || count( $names ) > 7 || array_values( $names ) !== $names || count( array_filter( $names, 'is_string' ) ) !== count( $names ) || count( array_unique( $names ) ) !== count( $names ) || array_diff( $names, transfer_option_names() ) ) { return new \WP_Error( 'sunrise_transfer_selection', 'Select distinct supported settings.', array( 'status' => 400 ) ); }
	global $wpdb; $items = array(); sort( $names, SORT_STRING );
	foreach ( $names as $name ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s AND LENGTH(option_value)<=8192", $name ), ARRAY_A );
		if ( ! $row || $wpdb->last_error ) { return new \WP_Error( 'sunrise_transfer_value', 'A selected setting is missing, oversized or unreadable.', array( 'status' => 409 ) ); }
		$value = $row['option_value'];
		$items[] = array( 'name' => $name, 'value' => $value, 'fingerprint' => hash( 'sha256', $value ) );
	}
	return array( 'schema' => 1, 'scope' => 'options', 'site_id' => agent_state()['site_id'], 'site_url' => site_url( '/' ), 'home_url' => home_url( '/' ), 'items' => $items );
}

function transfer_inventory_access() {
	$state = agent_state();
	if ( ! current_user_can( 'manage_options' ) || is_wp_error( agent_access() ) || is_wp_error( installation_guard() ) || empty( $state['site_id'] ) || ! empty( $state['revoked'] ) || ! agent_owner_valid( $state ) || ! agent_service_matches( $state ) ) { return new \WP_Error( 'sunrise_transfer_forbidden', 'A valid local administrator connection is required.', array( 'status' => 403 ) ); }
	return true;
}

/** Preview has no execution grant. Source content is untrusted even when its transport was authenticated. */
function transfer_option_preview( $snapshot ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	try {
		if ( ! is_array( $snapshot ) || count( $snapshot ) !== 6 || ! isset( $snapshot['schema'], $snapshot['scope'], $snapshot['site_id'], $snapshot['site_url'], $snapshot['home_url'], $snapshot['items'] ) || 1 !== $snapshot['schema'] || 'options' !== $snapshot['scope'] || ! is_string( $snapshot['site_id'] ) || ! wp_is_uuid( $snapshot['site_id'], 4 ) || $snapshot['site_id'] === agent_state()['site_id'] || ! is_array( $snapshot['items'] ) || ! $snapshot['items'] || count( $snapshot['items'] ) > 7 ) { throw new \InvalidArgumentException( 'Invalid source snapshot.' ); }
		$names = array();
		foreach ( $snapshot['items'] as $item ) {
			if ( ! is_array( $item ) || count( $item ) !== 3 || ! isset( $item['name'], $item['value'], $item['fingerprint'] ) || ! is_string( $item['name'] ) || ! is_string( $item['value'] ) || strlen( $item['value'] ) > 8192 || ! is_string( $item['fingerprint'] ) || ! hash_equals( hash( 'sha256', $item['value'] ), $item['fingerprint'] ) ) { throw new \InvalidArgumentException( 'Invalid source setting.' ); }
			$names[] = $item['name'];
		}
		$destination = transfer_option_snapshot( $names ); if ( is_wp_error( $destination ) ) { return $destination; }
		if ( ! is_string( $snapshot['site_url'] ) || ! is_string( $snapshot['home_url'] ) || ( $snapshot['site_url'] === $snapshot['home_url'] && site_url( '/' ) !== home_url( '/' ) ) ) { throw new \InvalidArgumentException( 'Ambiguous source/destination URL mappings.' ); }
		$urls = array( $snapshot['site_url'] => site_url( '/' ), $snapshot['home_url'] => home_url( '/' ) );
		require_once __DIR__ . '/transfer.php';
		$changes = array(); $existing = array_column( $destination['items'], null, 'name' );
		foreach ( $snapshot['items'] as $item ) {
			$value = transfer_rewrite( $item['value'], 'text', $urls );
			// Serialized settings are outside this native-string scope; dedicated handlers may use transfer_rewrite explicitly.
			if ( is_serialized( $value ) || false !== strpos( $value, "\0" ) || strlen( $value ) > 8192 ) { throw new \InvalidArgumentException( 'Unsupported native setting representation.' ); }
			$old = $existing[ $item['name'] ];
			$changes[] = array( 'name' => $item['name'], 'before' => $old['value'], 'after' => $value, 'source_fingerprint' => $item['fingerprint'], 'expected_destination_fingerprint' => $old['fingerprint'], 'action' => $old['value'] === $value ? 'unchanged' : 'replace' );
		}
		usort( $changes, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		$plan = array( 'schema' => 1, 'scope' => 'options', 'source_site_id' => $snapshot['site_id'], 'destination_site_id' => $destination['site_id'], 'source_site_url' => $snapshot['site_url'], 'source_home_url' => $snapshot['home_url'], 'destination_site_url' => $destination['site_url'], 'destination_home_url' => $destination['home_url'], 'changes' => $changes );
		$encoded = wp_json_encode( $plan ); if ( false === $encoded ) { throw new \InvalidArgumentException( 'Settings must contain valid UTF-8.' ); }
		return array( 'plan' => $plan, 'fingerprint' => hash( 'sha256', $encoded ), 'read_only' => true, 'execution_available' => false, 'authorization_required' => true );
	} catch ( \InvalidArgumentException $error ) { return new \WP_Error( 'sunrise_transfer_preview', $error->getMessage(), array( 'status' => 400 ) ); }
}
