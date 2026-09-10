<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-inventory.php';

/** Canonical paths only. No symlinks, runtime configuration, or Sunrise's own code. */
function transfer_file_path_valid( $path ) {
	if ( ! is_string( $path ) || strlen( $path ) > 512 || ! preg_match( '//u', $path ) || preg_match( '/[\\\\\x00-\x1f\x7f:]/', $path ) ) { return false; }
	$parts = explode( '/', $path );
	if ( count( $parts ) < 2 || ! in_array( $parts[0], array( 'plugins', 'themes', 'media' ), true ) || ( 'plugins' === $parts[0] && 'sunrise' === strtolower( $parts[1] ) ) ) { return false; }
	foreach ( $parts as $part ) { if ( '' === $part || '.' === $part[0] || in_array( strtolower( $part ), array( 'wp-config.php', 'web.config', 'sunrise-installation.php', 'node_modules' ), true ) || preg_match( '/[. ]$/D', $part ) ) { return false; } }
	// Uploads are data. Never install executable server files via a media selection.
	return 'media' !== $parts[0] || ! preg_match( '/\.(?:php[0-9]*|phps|phtml|pht|phar|cgi|pl|py|sh|shtml|htaccess|ini)(?:\.|$)/i', end( $parts ) );
}

function transfer_file_root( $scope ) {
	if ( 'plugins' === $scope ) { return WP_PLUGIN_DIR; }
	if ( 'themes' === $scope ) { return get_theme_root(); }
	if ( 'media' === $scope ) { $uploads = wp_upload_dir( null, false ); if ( ! $uploads['error'] ) { return $uploads['basedir']; } }
	throw new \RuntimeException( 'sunrise_file_root' );
}

/** Reject links at every existing component, including dangling links and linked roots. */
function transfer_file_local_path( $path ) {
	if ( ! transfer_file_path_valid( $path ) ) { throw new \RuntimeException( 'sunrise_file_path' ); }
	$parts = explode( '/', $path ); $root = rtrim( transfer_file_root( array_shift( $parts ) ), '/\\' );
	if ( ! is_dir( $root ) || is_link( $root ) || wp_normalize_path( realpath( $root ) ) !== wp_normalize_path( $root ) ) { throw new \RuntimeException( 'sunrise_file_root' ); }
	$full = $root;
	foreach ( $parts as $index => $part ) { $full .= '/' . $part; if ( is_link( $full ) || ( file_exists( $full ) && $index < count( $parts ) - 1 && ! is_dir( $full ) ) ) { throw new \RuntimeException( 'sunrise_file_link' ); } }
	return $full;
}

function transfer_file_catalog( $scope ) {
	if ( ! in_array( $scope, array( 'plugins', 'themes' ), true ) ) { throw new \InvalidArgumentException( 'sunrise_file_scope' ); }
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$all = 'plugins' === $scope ? get_plugins() : wp_get_themes(); $items = array();
	foreach ( $all as $id => $item ) {
		if ( 'plugins' === $scope && ( 'sunrise/sunrise.php' === $id || 0 === strpos( $id, 'sunrise/' ) ) ) { continue; }
		$root = 'plugins' === $scope && false !== strpos( $id, '/' ) ? explode( '/', $id )[0] : $id;
		if ( ! transfer_file_path_valid( $scope . '/' . $root ) ) { continue; }
		$items[] = array( 'id' => $id, 'root' => $root, 'name' => 'plugins' === $scope ? $item['Name'] : $item->get( 'Name' ), 'active' => 'plugins' === $scope ? is_plugin_active( $id ) : in_array( $id, array( get_stylesheet(), get_template() ), true ) );
	}
	usort( $items, function ( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } ); return $items;
}

function transfer_file_selection( $selection ) {
	if ( ! is_array( $selection ) || ! $selection || array_diff( array_keys( $selection ), array( 'plugins', 'themes', 'media' ) ) ) { throw new \InvalidArgumentException( 'sunrise_file_selection' ); }
	foreach ( $selection as $scope => $value ) {
		if ( ! is_array( $value ) || ! isset( $value['mode'] ) ) { throw new \InvalidArgumentException( 'sunrise_file_selection' ); }
		if ( 'media' === $scope ) {
			if ( count( $value ) !== 2 || ! array_key_exists( 'since', $value ) || ! in_array( $value['mode'], array( 'all', 'date', 'last' ), true ) || ( 'date' === $value['mode'] ? ! is_int( $value['since'] ) || $value['since'] < 0 || $value['since'] > time() : null !== $value['since'] ) ) { throw new \InvalidArgumentException( 'sunrise_file_selection' ); }
		} else {
			if ( count( $value ) !== 2 || ! isset( $value['items'] ) || ! in_array( $value['mode'], array( 'all', 'active', 'include', 'exclude' ), true ) || ! is_array( $value['items'] ) || array_values( $value['items'] ) !== $value['items'] || count( $value['items'] ) > 500 || count( array_filter( $value['items'], 'is_string' ) ) !== count( $value['items'] ) || count( array_unique( $value['items'] ) ) !== count( $value['items'] ) ) { throw new \InvalidArgumentException( 'sunrise_file_selection' ); }
			foreach ( $value['items'] as $id ) { if ( ! transfer_file_path_valid( $scope . '/' . $id ) ) { throw new \InvalidArgumentException( 'sunrise_file_selection' ); } }
		}
	}
	ksort( $selection, SORT_STRING ); foreach ( $selection as &$value ) { if ( isset( $value['items'] ) ) { sort( $value['items'], SORT_STRING ); } } unset( $value ); return $selection;
}

/** Hash file contents, not just dates: incremental transfers must detect backdated replacements. */
function transfer_file_manifest( $selection, $checkpoint = array() ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	try {
		$selection = transfer_file_selection( $selection ); $files = array(); $skipped = 0; $bytes = 0; $visited = 0; $roots = array();
		foreach ( $selection as $scope => $value ) {
			if ( 'media' === $scope ) { $roots[] = 'media'; continue; }
			$catalog = transfer_file_catalog( $scope ); $ids = array_column( $catalog, 'id' );
			if ( array_diff( $value['items'], $ids ) ) { throw new \RuntimeException( 'sunrise_file_component_missing' ); }
			foreach ( $catalog as $item ) {
				$selected = 'all' === $value['mode'] || ( 'active' === $value['mode'] && $item['active'] ) || ( 'include' === $value['mode'] && in_array( $item['id'], $value['items'], true ) ) || ( 'exclude' === $value['mode'] && ! in_array( $item['id'], $value['items'], true ) );
				if ( 'exclude' === $value['mode'] && in_array( $item['root'], array_map( function ( $id ) { return explode( '/', $id )[0]; }, $value['items'] ), true ) ) { $selected = false; }
				if ( $selected ) { $roots[] = $scope . '/' . $item['root']; }
			}
		}
		$walk = function ( $relative, $full ) use ( &$walk, &$files, &$skipped, &$bytes, &$visited, $selection, $checkpoint ) {
			// ponytail: 10,000 entries / 512 MiB per transfer; paginate manifests before lifting this ceiling.
			if ( ++$visited > 10000 ) { throw new \RuntimeException( 'sunrise_file_count_limit' ); }
			if ( is_link( $full ) ) { throw new \RuntimeException( 'sunrise_file_link' ); }
			if ( ! transfer_file_path_valid( $relative ) && 'media' !== $relative ) { ++$skipped; return; }
			if ( is_dir( $full ) ) { $entries = scandir( $full ); if ( false === $entries ) { throw new \RuntimeException( 'sunrise_file_unreadable' ); } foreach ( $entries as $entry ) { if ( '.' !== $entry && '..' !== $entry ) { $walk( $relative . '/' . $entry, $full . '/' . $entry ); } } return; }
			if ( ! is_file( $full ) || ! is_readable( $full ) ) { throw new \RuntimeException( 'sunrise_file_unreadable' ); }
			$before = stat( $full ); $hash = hash_file( 'sha256', $full ); clearstatcache( true, $full ); $after = stat( $full );
			if ( ! $hash || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || $before['ino'] !== $after['ino'] ) { throw new \RuntimeException( 'sunrise_file_changed' ); }
			if ( 0 === strpos( $relative, 'media/' ) ) { $range = $selection['media']; if ( ( 'date' === $range['mode'] && $after['mtime'] < $range['since'] ) || ( 'last' === $range['mode'] && isset( $checkpoint[ $relative ] ) && hash_equals( $checkpoint[ $relative ], $hash ) ) ) { return; } }
			$bytes += $after['size']; if ( $bytes > 512 * MB_IN_BYTES || count( $files ) >= 5000 ) { throw new \RuntimeException( 'sunrise_file_size_limit' ); }
			$files[ $relative ] = array( 'path' => $relative, 'bytes' => $after['size'], 'sha256' => $hash, 'modified' => $after['mtime'] );
		};
		foreach ( array_unique( $roots ) as $relative ) { $root = 'media' === $relative ? transfer_file_root( 'media' ) : transfer_file_local_path( $relative ); if ( 'media' === $relative && wp_normalize_path( realpath( $root ) ) !== wp_normalize_path( $root ) ) { throw new \RuntimeException( 'sunrise_file_link' ); } $walk( $relative, $root ); }
		ksort( $files, SORT_STRING );
		return array( 'schema' => 1, 'scope' => 'files', 'site_id' => agent_state()['site_id'], 'selection' => $selection, 'files' => array_values( $files ), 'bytes' => $bytes, 'skipped' => $skipped );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_file_manifest', 'Could not prepare files: ' . $error->getMessage(), array( 'status' => 409 ) ); }
}

function transfer_file_entry( $file ) {
	if ( ! is_array( $file ) || count( $file ) !== 4 || ! isset( $file['path'], $file['bytes'], $file['sha256'], $file['modified'] ) || ! transfer_file_path_valid( $file['path'] ) || ! is_int( $file['bytes'] ) || $file['bytes'] < 0 || $file['bytes'] > 512 * MB_IN_BYTES || ! is_string( $file['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/D', $file['sha256'] ) || ! is_int( $file['modified'] ) || $file['modified'] < 0 ) { throw new \InvalidArgumentException( 'sunrise_file_manifest' ); }
}

/** Bounded reads; the destination verifies the complete approved hash before committing. */
function transfer_file_chunk( $file, $offset ) {
	transfer_file_entry( $file ); if ( ! is_int( $offset ) || $offset < 0 || $offset > $file['bytes'] || 0 !== $offset % 262144 ) { throw new \InvalidArgumentException( 'sunrise_file_offset' ); }
	$path = transfer_file_local_path( $file['path'] ); $handle = fopen( $path, 'rb' ); if ( ! $handle ) { throw new \RuntimeException( 'sunrise_file_unreadable' ); }
	try {
		$stat = fstat( $handle );
		if ( $stat['size'] !== $file['bytes'] || $stat['mtime'] !== $file['modified'] || 0 !== fseek( $handle, $offset ) ) { throw new \RuntimeException( 'sunrise_file_changed' ); }
		$data = fread( $handle, 262144 ); if ( false === $data ) { throw new \RuntimeException( 'sunrise_file_unreadable' ); } return $data;
	} finally { fclose( $handle ); }
}

/** Temporary bytes and recovery copies must be outside every known public document root. */
function transfer_file_workspace( $id = null ) {
	if ( null !== $id && ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) ) { throw new \InvalidArgumentException( 'sunrise_file_id' ); }
	$base = realpath( get_temp_dir() ); if ( ! $base || ! is_writable( $base ) ) { throw new \RuntimeException( 'sunrise_file_private_storage' ); }
	foreach ( array( ABSPATH, WP_CONTENT_DIR, isset( $_SERVER['DOCUMENT_ROOT'] ) ? $_SERVER['DOCUMENT_ROOT'] : '' ) as $public ) {
		$public = $public ? realpath( $public ) : false;
		if ( $public && ( $base === $public || 0 === strpos( $base . '/', rtrim( $public, '/' ) . '/' ) ) ) { throw new \RuntimeException( 'sunrise_file_private_storage' ); }
	}
	$identity = installation_identity(); $root = $base . '/sunrise-files-' . hash( 'sha256', ABSPATH . '|' . $identity['id'] . '|' . get_current_user_id() );
	foreach ( null === $id ? array( $root ) : array( $root, $root . '/' . $id ) as $dir ) {
		if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) || ! chmod( $dir, 0700 ) ) { throw new \RuntimeException( 'sunrise_file_private_storage' ); }
	}
	transfer_file_prune( $root, $id );
	return null === $id ? $root : $root . '/' . $id;
}

/** Keep uncertain writes for inspection; expire only idle preparation and completed recovery copies. */
function transfer_file_prune( $root, $current ) {
	$last = (int) get_user_meta( get_current_user_id(), 'sunrise_file_pruned_at', true );
	if ( $last > time() - HOUR_IN_SECONDS ) { return; }
	update_user_meta( get_current_user_id(), 'sunrise_file_pruned_at', time() );
	$fence = get_option( 'sunrise_remote_job_fence' ); $retained = agent_state()['remote_transfer']['job']['id'] ?? null;
	foreach ( new \DirectoryIterator( $root ) as $entry ) {
		$id = $entry->getFilename(); if ( ! wp_is_uuid( $id, 4 ) || $id === $current || $id === $retained || $id === ( $fence['job_id'] ?? null ) || $entry->isLink() || ! $entry->isDir() || $entry->getMTime() >= time() - 7 * DAY_IN_SECONDS ) { continue; }
		$dir = $entry->getPathname(); $journal = $dir . '/journal.json';
		if ( file_exists( $journal ) ) {
			if ( is_link( $journal ) || ! is_file( $journal ) || filesize( $journal ) > 5 * MB_IN_BYTES ) { continue; }
			$value = json_decode( file_get_contents( $journal ), true, 32 );
			if ( ! is_array( $value ) || ! in_array( $value['state'] ?? '', array( 'committed', 'restored' ), true ) ) { continue; }
		}
		$files = iterator_to_array( new \FilesystemIterator( $dir ) ); $safe = true;
		foreach ( $files as $file ) { if ( $file->isLink() || ! $file->isFile() || ! preg_match( '/^(?:[0-9]+\.(?:part|backup)|(?:source|incoming)\.zip|(?:manifest|journal|progress)\.json(?:\.tmp)?)$/D', $file->getFilename() ) || $file->getMTime() >= time() - 7 * DAY_IN_SECONDS ) { $safe = false; break; } }
		if ( $safe ) { foreach ( $files as $file ) { unlink( $file->getPathname() ); } rmdir( $dir ); }
	}
}

function transfer_file_write_json( $path, $value ) {
	$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json || is_link( $path ) || is_link( $path . '.tmp' ) ) { throw new \RuntimeException( 'sunrise_file_journal' ); }
	$handle = fopen( $path . '.tmp', 'wb' ); if ( ! $handle ) { throw new \RuntimeException( 'sunrise_file_journal' ); }
	try { chmod( $path . '.tmp', 0600 ); if ( strlen( $json ) !== fwrite( $handle, $json ) || ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) { throw new \RuntimeException( 'sunrise_file_journal' ); } } finally { fclose( $handle ); }
	if ( ! rename( $path . '.tmp', $path ) ) { throw new \RuntimeException( 'sunrise_file_journal' ); }
}

/** Idempotent staging. A repeated chunk must match the bytes already stored. */
function transfer_file_stage( $id, $index, $file, $offset, $bytes ) {
	transfer_file_entry( $file );
	if ( ! is_int( $index ) || $index < 0 || $index >= 5000 || ! is_int( $offset ) || $offset < 0 || 0 !== $offset % 262144 || ! is_string( $bytes ) || strlen( $bytes ) !== min( 262144, $file['bytes'] - $offset ) ) { throw new \InvalidArgumentException( 'sunrise_file_chunk' ); }
	$root = transfer_file_workspace( $id ); $path = $root . '/' . $index . '.part'; if ( is_link( $path ) || file_exists( $root . '/journal.json' ) ) { throw new \RuntimeException( 'sunrise_file_stage_closed' ); }
	$handle = fopen( $path, 'c+b' ); if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { if ( $handle ) { fclose( $handle ); } throw new \RuntimeException( 'sunrise_file_busy' ); }
	try {
		chmod( $path, 0600 ); $size = fstat( $handle )['size'];
		if ( $size < $offset || $size > $file['bytes'] || 0 !== fseek( $handle, $offset ) ) { throw new \RuntimeException( 'sunrise_file_chunk_order' ); }
		if ( $size > $offset && $size < $offset + strlen( $bytes ) ) { if ( fread( $handle, $size - $offset ) !== substr( $bytes, 0, $size - $offset ) || ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) ) { throw new \RuntimeException( 'sunrise_file_chunk_conflict' ); } $size = $offset; }
		if ( $size > $offset ) { if ( ! hash_equals( $bytes, fread( $handle, strlen( $bytes ) ) ) ) { throw new \RuntimeException( 'sunrise_file_chunk_conflict' ); } }
		elseif ( strlen( $bytes ) !== fwrite( $handle, $bytes ) || ! fflush( $handle ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); }
		if ( $offset + strlen( $bytes ) === $file['bytes'] ) { rewind( $handle ); $hash = hash_init( 'sha256' ); hash_update_stream( $hash, $handle ); if ( ! hash_equals( $file['sha256'], hash_final( $hash ) ) ) { throw new \RuntimeException( 'sunrise_file_checksum' ); } return true; }
		return false;
	} finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
}

function transfer_file_write_allowed( $path ) {
	$scope = explode( '/', $path )[0]; $capability = 'plugins' === $scope ? 'update_plugins' : ( 'themes' === $scope ? 'update_themes' : 'upload_files' );
	if ( ! current_user_can( $capability ) || ( 'media' !== $scope && ! wp_is_file_mod_allowed( 'sunrise_migration' ) ) ) { throw new \RuntimeException( 'sunrise_file_modifications_disabled' ); }
}

/** Internal executor only. A copied plan or local API request is never write authorization. */
function transfer_files_commit( $id, $plan_json, $plan_hash, $held_file ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$root = null; $journal = null; $started = false; $worker = false; $automatic = false; $maintenance = null; $finished = false;
	try {
		$worker = \WP_Upgrader::create_lock( 'sunrise_worker' ); if ( ! $worker || ! ( $automatic = \WP_Upgrader::create_lock( 'auto_updater' ) ) ) { throw new \RuntimeException( 'sunrise_file_busy' ); }
		$state = agent_state(); $fence = get_option( 'sunrise_remote_job_fence' );
		if ( ! is_resource( $held_file ) || ! is_string( $plan_json ) || strlen( $plan_json ) > 2 * MB_IN_BYTES || ! is_string( $plan_hash ) || ! hash_equals( hash( 'sha256', $plan_json ), $plan_hash ) || ! is_array( $fence ) || ( $fence['kind'] ?? '' ) !== 'transfer' || ( $fence['job_id'] ?? '' ) !== $id || ( $fence['site_id'] ?? '' ) !== $state['site_id'] || ( $fence['plan_hash'] ?? '' ) !== $plan_hash || ! isset( $fence['start_until'] ) || ! is_int( $fence['start_until'] ) || $fence['start_until'] <= time() || $fence['start_until'] > time() + 120 ) { throw new \RuntimeException( 'sunrise_file_authorization' ); }
		foreach ( agent_states() as $connection ) { if ( ! empty( $connection['paused'] ) && agent_owner_valid( $connection ) ) { throw new \RuntimeException( 'sunrise_file_paused' ); } }
		$plan = json_decode( $plan_json, true, 32 );
		if ( ! is_array( $plan ) || count( $plan ) !== 5 || ( $plan['schema'] ?? null ) !== 1 || ( $plan['scope'] ?? null ) !== 'files' || ( $plan['destination_site_id'] ?? null ) !== $state['site_id'] || ! isset( $plan['source_site_id'], $plan['changes'] ) || ! is_string( $plan['source_site_id'] ) || ! wp_is_uuid( $plan['source_site_id'], 4 ) || $plan['source_site_id'] === $state['site_id'] || ! is_array( $plan['changes'] ) || count( $plan['changes'] ) > 5000 ) { throw new \RuntimeException( 'sunrise_file_plan' ); }
		$root = transfer_file_workspace( $id ); if ( file_exists( $root . '/journal.json' ) ) { throw new \RuntimeException( 'sunrise_file_inspection_required' ); }
		$seen = array(); $bytes = 0;
		foreach ( $plan['changes'] as $index => $change ) {
			if ( ! is_int( $index ) || ! is_array( $change ) || count( $change ) !== 3 || ! isset( $change['file'], $change['action'] ) || ! array_key_exists( 'before', $change ) || ( null !== $change['before'] && ( ! is_string( $change['before'] ) || ! preg_match( '/^[0-9a-f]{64}$/D', $change['before'] ) ) ) ) { throw new \RuntimeException( 'sunrise_file_plan' ); }
			$file = $change['file']; transfer_file_entry( $file ); transfer_file_write_allowed( $file['path'] ); $bytes += $file['bytes'];
			if ( $bytes > 512 * MB_IN_BYTES || isset( $seen[ strtolower( $file['path'] ) ] ) || $change['action'] !== ( $change['before'] === $file['sha256'] ? 'unchanged' : ( null === $change['before'] ? 'add' : 'replace' ) ) ) { throw new \RuntimeException( 'sunrise_file_plan' ); } $seen[ strtolower( $file['path'] ) ] = true;
			$path = transfer_file_local_path( $file['path'] );
			if ( ( file_exists( $path ) && ! is_file( $path ) ) || ( is_file( $path ) ? hash_file( 'sha256', $path ) : null ) !== $change['before'] ) { throw new \RuntimeException( 'sunrise_file_destination_changed' ); }
			if ( 'unchanged' !== $change['action'] && ( is_link( $root . '/' . $index . '.part' ) || ! is_file( $root . '/' . $index . '.part' ) || filesize( $root . '/' . $index . '.part' ) !== $file['bytes'] || hash_file( 'sha256', $root . '/' . $index . '.part' ) !== $file['sha256'] ) ) { throw new \RuntimeException( 'sunrise_file_checksum' ); }
		}
		$maintenance = '<?php $upgrading = ' . time() . '; // sunrise-transfer:' . $id . "\n";
		$handle = @fopen( ABSPATH . '.maintenance', 'x' ); if ( ! $handle ) { $maintenance = null; throw new \RuntimeException( 'sunrise_file_maintenance' ); }
		try { if ( fwrite( $handle, $maintenance ) !== strlen( $maintenance ) || ! fflush( $handle ) ) { throw new \RuntimeException( 'sunrise_file_maintenance' ); } } finally { fclose( $handle ); }
		$journal = array( 'schema' => 1, 'id' => $id, 'installation_id' => installation_identity()['id'], 'owner' => get_current_user_id(), 'plan_hash' => $plan_hash, 'plan_json' => $plan_json, 'plan' => $plan, 'state' => 'writing', 'at' => time() );
		transfer_file_write_json( $root . '/journal.json', $journal ); $started = true;
		foreach ( $plan['changes'] as $index => $change ) {
			if ( 'unchanged' === $change['action'] ) { continue; } $file = $change['file']; $path = transfer_file_local_path( $file['path'] );
			if ( ( is_file( $path ) ? hash_file( 'sha256', $path ) : null ) !== $change['before'] ) { throw new \RuntimeException( 'sunrise_file_destination_changed' ); }
			if ( ! wp_mkdir_p( dirname( $path ) ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); } transfer_file_local_path( $file['path'] );
			// Same-filesystem temporary file makes the final replacement atomic even when system temp is on another disk.
			$temp = dirname( $path ) . '/.sunrise-' . bin2hex( random_bytes( 24 ) );
			if ( file_exists( $temp ) || is_link( $temp ) ) { throw new \RuntimeException( 'sunrise_file_collision' ); }
			if ( null !== $change['before'] && ( ! copy( $path, $root . '/' . $index . '.backup' ) || ! chmod( $root . '/' . $index . '.backup', 0600 ) || hash_file( 'sha256', $root . '/' . $index . '.backup' ) !== $change['before'] ) ) { throw new \RuntimeException( 'sunrise_file_backup' ); }
			if ( ! copy( $root . '/' . $index . '.part', $temp ) || hash_file( 'sha256', $temp ) !== $file['sha256'] || ! chmod( $temp, is_file( $path ) ? fileperms( $path ) & 0666 : 0644 ) || ! rename( $temp, $path ) ) { if ( is_file( $temp ) ) { unlink( $temp ); } throw new \RuntimeException( 'sunrise_file_storage' ); }
			if ( function_exists( 'opcache_invalidate' ) ) { opcache_invalidate( $path, true ); }
		}
		$journal['state'] = 'committed'; transfer_file_write_json( $root . '/journal.json', $journal ); $finished = true;
		foreach ( array_keys( $plan['changes'] ) as $index ) { if ( is_file( $root . '/' . $index . '.part' ) ) { unlink( $root . '/' . $index . '.part' ); } } if ( is_file( $root . '/incoming.zip' ) ) { unlink( $root . '/incoming.zip' ); }
		wp_clean_plugins_cache( true ); wp_clean_themes_cache( true );
		return array( 'outcome' => 'succeeded', 'files' => count( $plan['changes'] ), 'plan_hash' => $plan_hash );
	} catch ( \Throwable $error ) {
		if ( $finished ) { return array( 'outcome' => 'succeeded', 'files' => count( $plan['changes'] ), 'plan_hash' => $plan_hash ); }
		// Any interrupted multi-file write stays fenced. Recovery must inspect hashes before restoring.
		return new \WP_Error( $started ? 'sunrise_file_uncertain' : 'sunrise_file_commit', 'Files were not fully committed: ' . $error->getMessage(), array( 'started' => $started ) );
	} finally { if ( $maintenance && ( ! $started || $finished ) && @file_get_contents( ABSPATH . '.maintenance' ) === $maintenance ) { unlink( ABSPATH . '.maintenance' ); } if ( $automatic ) { \WP_Upgrader::release_lock( 'auto_updater' ); } if ( $worker ) { \WP_Upgrader::release_lock( 'sunrise_worker' ); } }
}

/** Restore only this installation/owner's journal, while holding the same process lock as execution. */
function transfer_files_restore( $id, $plan_hash, $held_file ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; $worker = false; $automatic = false;
	try {
		if ( ! is_resource( $held_file ) || ! is_string( $plan_hash ) || ! preg_match( '/^[0-9a-f]{64}$/D', $plan_hash ) ) { throw new \RuntimeException( 'sunrise_file_authorization' ); }
		$worker = \WP_Upgrader::create_lock( 'sunrise_worker' ); if ( ! $worker || ! ( $automatic = \WP_Upgrader::create_lock( 'auto_updater' ) ) ) { throw new \RuntimeException( 'sunrise_file_busy' ); }
		$fence = get_option( 'sunrise_remote_job_fence' ); if ( $fence && ( ( $fence['kind'] ?? null ) !== 'transfer' || ( $fence['job_id'] ?? null ) !== $id || ( $fence['site_id'] ?? null ) !== agent_state()['site_id'] ) ) { throw new \RuntimeException( 'sunrise_file_busy' ); }
		$root = transfer_file_workspace( $id ); $path = $root . '/journal.json';
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 5 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_file_journal' ); }
		$journal = json_decode( file_get_contents( $path ), true, 32 );
		if ( ! is_array( $journal ) || ( $journal['id'] ?? null ) !== $id || ( $journal['owner'] ?? null ) !== get_current_user_id() || ( $journal['installation_id'] ?? null ) !== installation_identity()['id'] || ( $journal['plan_hash'] ?? null ) !== $plan_hash || ! isset( $journal['plan_json'] ) || ! is_string( $journal['plan_json'] ) || ! hash_equals( $plan_hash, hash( 'sha256', $journal['plan_json'] ) ) || ! in_array( $journal['state'] ?? '', array( 'writing', 'committed', 'restored' ), true ) ) { throw new \RuntimeException( 'sunrise_file_journal' ); }
		$plan = json_decode( $journal['plan_json'], true, 32 ); if ( ( $plan['destination_site_id'] ?? null ) !== agent_state()['site_id'] || ! isset( $plan['changes'] ) || ! is_array( $plan['changes'] ) || count( $plan['changes'] ) > 5000 ) { throw new \RuntimeException( 'sunrise_file_journal' ); }
		$restore = array();
		foreach ( $plan['changes'] as $index => $change ) {
			$file = $change['file']; transfer_file_entry( $file ); transfer_file_write_allowed( $file['path'] ); $destination = transfer_file_local_path( $file['path'] );
			if ( file_exists( $destination ) && ! is_file( $destination ) ) { throw new \RuntimeException( 'sunrise_file_destination_changed' ); }
			$current = is_file( $destination ) ? hash_file( 'sha256', $destination ) : null;
			if ( $current === $change['before'] ) { continue; }
			if ( $current !== $file['sha256'] || 'unchanged' === $change['action'] ) { throw new \RuntimeException( 'sunrise_file_destination_changed' ); }
			$backup = $root . '/' . $index . '.backup';
			if ( null !== $change['before'] && ( is_link( $backup ) || ! is_file( $backup ) || hash_file( 'sha256', $backup ) !== $change['before'] ) ) { throw new \RuntimeException( 'sunrise_file_backup' ); }
			$restore[] = array( 'path' => $destination, 'backup' => null === $change['before'] ? null : $backup, 'after' => $file['sha256'] );
		}
		foreach ( $restore as $item ) {
			if ( hash_file( 'sha256', $item['path'] ) !== $item['after'] ) { throw new \RuntimeException( 'sunrise_file_destination_changed' ); }
			if ( null === $item['backup'] ) { if ( ! unlink( $item['path'] ) ) { throw new \RuntimeException( 'sunrise_file_restore' ); } }
			else {
				$temp = dirname( $item['path'] ) . '/.sunrise-' . bin2hex( random_bytes( 24 ) );
				if ( ! copy( $item['backup'], $temp ) || ! chmod( $temp, 0644 ) || ! rename( $temp, $item['path'] ) ) { if ( is_file( $temp ) ) { unlink( $temp ); } throw new \RuntimeException( 'sunrise_file_restore' ); }
			}
			if ( function_exists( 'opcache_invalidate' ) ) { opcache_invalidate( $item['path'], true ); }
		}
		$journal['state'] = 'restored'; transfer_file_write_json( $path, $journal );
		$maintenance = @file_get_contents( ABSPATH . '.maintenance' ); if ( is_string( $maintenance ) && preg_match( '/^<\?php \$upgrading = [0-9]+; \/\/ sunrise-transfer:' . preg_quote( $id, '/' ) . '\n$/D', $maintenance ) ) { unlink( ABSPATH . '.maintenance' ); }
		wp_clean_plugins_cache( true ); wp_clean_themes_cache( true );
		return array( 'restored' => count( $restore ) );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_file_restore', 'Files could not be restored: ' . $error->getMessage() ); }
	finally { if ( $automatic ) { \WP_Upgrader::release_lock( 'auto_updater' ); } if ( $worker ) { \WP_Upgrader::release_lock( 'sunrise_worker' ); } }
}

function transfer_file_preview( $manifest ) {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
	try {
		if ( ! is_array( $manifest ) || ! isset( $manifest['schema'], $manifest['scope'], $manifest['site_id'], $manifest['files'] ) || 1 !== $manifest['schema'] || 'files' !== $manifest['scope'] || ! is_string( $manifest['site_id'] ) || ! wp_is_uuid( $manifest['site_id'], 4 ) || $manifest['site_id'] === agent_state()['site_id'] || ! is_array( $manifest['files'] ) || count( $manifest['files'] ) > 5000 ) { throw new \InvalidArgumentException( 'sunrise_file_manifest' ); }
		$changes = array(); $seen = array(); $bytes = 0;
		foreach ( $manifest['files'] as $file ) {
			transfer_file_entry( $file ); if ( isset( $seen[ strtolower( $file['path'] ) ] ) ) { throw new \RuntimeException( 'sunrise_file_collision' ); } $seen[ strtolower( $file['path'] ) ] = true;
			$bytes += $file['bytes']; if ( $bytes > 512 * MB_IN_BYTES ) { throw new \RuntimeException( 'sunrise_file_size_limit' ); }
			$path = transfer_file_local_path( $file['path'] ); if ( file_exists( $path ) && ! is_file( $path ) ) { throw new \RuntimeException( 'sunrise_file_collision' ); }
			$before = is_file( $path ) ? hash_file( 'sha256', $path ) : null; if ( false === $before ) { throw new \RuntimeException( 'sunrise_file_unreadable' ); }
			$changes[] = array( 'file' => $file, 'before' => $before, 'action' => $before === $file['sha256'] ? 'unchanged' : ( null === $before ? 'add' : 'replace' ) );
		}
		$plan = array( 'schema' => 1, 'scope' => 'files', 'source_site_id' => $manifest['site_id'], 'destination_site_id' => agent_state()['site_id'], 'changes' => $changes );
		return array( 'plan' => $plan, 'fingerprint' => hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), 'read_only' => true, 'authorization_required' => true );
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_file_preview', 'Could not compare files: ' . $error->getMessage(), array( 'status' => 409 ) ); }
}

/** Native, owner-only recovery: restoring local copies does not grant a new remote migration. */
function transfer_file_recovery_page() {
	$access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return; }
	try { $root = transfer_file_workspace(); } catch ( \Throwable $error ) { return; }
	$entries = array(); foreach ( new \DirectoryIterator( $root ) as $entry ) { if ( ! $entry->isLink() && $entry->isDir() && wp_is_uuid( $entry->getFilename(), 4 ) ) { $entries[ $entry->getFilename() ] = $entry->getMTime(); } } arsort( $entries );
	$shown = 0;
	foreach ( $entries as $id => $mtime ) {
		$path = $root . '/' . $id . '/journal.json'; if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 5 * MB_IN_BYTES ) { continue; }
		$journal = json_decode( file_get_contents( $path ), true, 32 );
		if ( ! is_array( $journal ) || ( $journal['id'] ?? null ) !== $id || ( $journal['owner'] ?? null ) !== get_current_user_id() || ( $journal['installation_id'] ?? null ) !== installation_identity()['id'] || ! is_string( $journal['plan_json'] ?? null ) || ! is_string( $journal['plan_hash'] ?? null ) || ! hash_equals( hash( 'sha256', $journal['plan_json'] ), $journal['plan_hash'] ) || ! in_array( $journal['state'] ?? '', array( 'writing', 'committed', 'restored' ), true ) ) { continue; }
		$plan = json_decode( $journal['plan_json'], true, 32 ); if ( ( $plan['destination_site_id'] ?? null ) !== agent_state()['site_id'] || ! is_array( $plan['changes'] ?? null ) ) { continue; }
		if ( ! $shown ) { echo '<h3>Restore transferred files</h3><p>Restore original files only while their transferred contents still match. Newer edits are protected. Completed recovery copies are kept for seven days; interrupted writes stay available for inspection.</p>'; }
		echo '<details><summary>' . esc_html( wp_date( 'Y-m-d H:i', $mtime ) . ' · ' . count( $plan['changes'] ) . ' files · ' . $journal['state'] ) . '</summary><ul>';
		foreach ( array_slice( $plan['changes'], 0, 100 ) as $change ) { echo '<li>' . esc_html( $change['file']['path'] . ' · ' . $change['action'] ) . '</li>'; } echo '</ul>';
		if ( count( $plan['changes'] ) > 100 ) { echo '<p>Showing the first 100 files. Restore covers this entire transfer.</p>'; }
		if ( 'restored' !== $journal['state'] ) { form_start( 'local', 'transfer_files_restore' ); echo '<input type="hidden" name="transfer_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="plan_hash" value="' . esc_attr( $journal['plan_hash'] ) . '">'; submit_button( 'Restore original files', 'secondary', 'submit', false ); echo '</form>'; }
		echo '</details>'; if ( ++$shown >= 10 ) { break; }
	}
}
