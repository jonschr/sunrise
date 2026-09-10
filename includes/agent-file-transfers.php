<?php
namespace Sunrise;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/transfer-files.php';

function agent_file_continue() {
	$args = array( get_current_user_id() ); if ( ! wp_next_scheduled( 'sunrise_transfer_continue', $args ) ) { wp_schedule_single_event( time() + 30, 'sunrise_transfer_continue', $args ); }
}

function agent_file_journals( $job ) {
	$root = transfer_file_workspace( $job['id'] ); $path = $root . '/journal.json';
	if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 5 * MB_IN_BYTES ) { return array(); }
	$journal = json_decode( file_get_contents( $path ), true, 32 );
	if ( ! is_array( $journal ) || ( $journal['id'] ?? null ) !== $job['id'] || ( $journal['plan_json'] ?? null ) !== $job['plan_json'] || ( $journal['plan_hash'] ?? null ) !== $job['plan_hash'] || ( $journal['owner'] ?? null ) !== get_current_user_id() || ( $journal['installation_id'] ?? null ) !== installation_identity()['id'] || ! in_array( $journal['state'] ?? '', array( 'committed', 'restored' ), true ) ) { return array(); }
	return array( array( 'id' => $job['id'], 'plan_hash' => $job['plan_hash'], 'state' => $journal['state'] ) );
}

/** Freeze a private ZIP once, then resume bounded uploads through the authenticated relay. */
function agent_file_source( $task ) {
	try {
		if ( ! class_exists( 'ZipArchive' ) ) { throw new \RuntimeException( 'sunrise_file_zip_required' ); }
		$root = transfer_file_workspace( $task['id'] ); $path = $root . '/source.zip'; $meta = $root . '/manifest.json';
		$manifest = is_file( $meta ) && ! is_link( $meta ) && filesize( $meta ) <= 1048576 ? json_decode( file_get_contents( $meta ), true, 32 ) : null;
		if ( ! $manifest ) {
			$manifest = transfer_file_manifest( $task['file_selection'], isset( $task['checkpoint'] ) ? $task['checkpoint'] : array() ); if ( is_wp_error( $manifest ) ) { return $manifest; }
			if ( ! $manifest['files'] ) { return array( 'no_changes' => true ); }
			if ( file_exists( $path ) ) { unlink( $path ); } if ( is_link( $path ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); }
			$zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) { throw new \RuntimeException( 'sunrise_file_zip' ); }
			try { foreach ( $manifest['files'] as $file ) { if ( ! $zip->addFile( transfer_file_local_path( $file['path'] ), $file['path'] ) ) { throw new \RuntimeException( 'sunrise_file_zip' ); } } } finally { if ( ! $zip->close() ) { throw new \RuntimeException( 'sunrise_file_zip' ); } }
			chmod( $path, 0600 ); $manifest['archive'] = array( 'bytes' => filesize( $path ), 'sha256' => hash_file( 'sha256', $path ) );
			if ( $manifest['archive']['bytes'] > 512 * MB_IN_BYTES || strlen( wp_json_encode( $manifest ) ) > 900000 ) { throw new \RuntimeException( 'sunrise_file_size_limit' ); }
			transfer_file_write_json( $meta, $manifest );
		}
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) !== $manifest['archive']['bytes'] || $manifest['selection'] !== transfer_file_selection( $task['file_selection'] ) ) { throw new \RuntimeException( 'sunrise_file_changed' ); }
		$state = agent_state(); $base = 'agent/transfers/' . $task['id'] . '/archive/'; $result = agent_http( $base . 'start', $manifest['archive'], $state ); if ( is_wp_error( $result ) ) { agent_file_continue(); return $result; }
		if ( ! isset( $result['uploaded'] ) || ! is_int( $result['uploaded'] ) || $result['uploaded'] < 0 || $result['uploaded'] > $manifest['archive']['bytes'] || ( $result['uploaded'] < $manifest['archive']['bytes'] && 0 !== $result['uploaded'] % 262144 ) ) { throw new \RuntimeException( 'sunrise_file_response' ); }
		$handle = fopen( $path, 'rb' ); if ( ! $handle ) { throw new \RuntimeException( 'sunrise_file_storage' ); } $until = microtime( true ) + 8;
		try {
			$offset = $result['uploaded']; for ( $count = 0; $offset < $manifest['archive']['bytes'] && $count < 8 && microtime( true ) < $until; ++$count ) {
				if ( 0 !== fseek( $handle, $offset ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); } $bytes = fread( $handle, 262144 ); if ( false === $bytes || '' === $bytes ) { throw new \RuntimeException( 'sunrise_file_storage' ); }
				$result = agent_http( $base . 'put', array( 'offset' => $offset, 'data' => base64_encode( $bytes ) ), $state ); if ( is_wp_error( $result ) ) { agent_file_continue(); return $result; }
				if ( ! isset( $result['uploaded'] ) || $result['uploaded'] !== $offset + strlen( $bytes ) ) { throw new \RuntimeException( 'sunrise_file_response' ); } $offset = $result['uploaded'];
			}
		} finally { fclose( $handle ); }
		transfer_file_write_json( $root . '/progress.json', array( 'bytes' => $offset, 'total' => $manifest['archive']['bytes'] ) );
		return $offset === $manifest['archive']['bytes'] ? $manifest : false;
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_file_source', 'Could not prepare the file archive.' ); }
}

/** Download before consuming the short write lease. ZIP entries never control extraction paths. */
function agent_file_download( $job ) {
	try {
		$archive = $job['archive'] ?? null;
		if ( ! is_array( $archive ) || ! isset( $archive['bytes'], $archive['sha256'] ) || ! is_int( $archive['bytes'] ) || $archive['bytes'] < 1 || $archive['bytes'] > 512 * MB_IN_BYTES || ! is_string( $archive['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/D', $archive['sha256'] ) || ! class_exists( 'ZipArchive' ) ) { throw new \RuntimeException( 'sunrise_file_archive' ); }
		$root = transfer_file_workspace( $job['id'] ); $path = $root . '/incoming.zip'; if ( is_link( $path ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); }
		$handle = fopen( $path, 'c+b' ); if ( ! $handle ) { throw new \RuntimeException( 'sunrise_file_storage' ); } chmod( $path, 0600 ); $until = microtime( true ) + 8;
		try {
			$offset = fstat( $handle )['size'];
			if ( $offset < $archive['bytes'] && 0 !== $offset % 262144 ) { $offset -= $offset % 262144; if ( ! ftruncate( $handle, $offset ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); } }
			if ( $offset > $archive['bytes'] || ( $offset < $archive['bytes'] && 0 !== $offset % 262144 ) || 0 !== fseek( $handle, $offset ) ) { throw new \RuntimeException( 'sunrise_file_chunk_order' ); }
			for ( $count = 0; $offset < $archive['bytes'] && $count < 8 && microtime( true ) < $until; ++$count ) {
				$result = agent_http( 'agent/transfers/' . $job['id'] . '/archive/get', array( 'offset' => $offset ), agent_state() ); if ( is_wp_error( $result ) ) { agent_file_continue(); return $result; }
				if ( ! is_array( $result ) || ( $result['offset'] ?? null ) !== $offset || ! isset( $result['data'] ) || ! is_string( $result['data'] ) ) { throw new \RuntimeException( 'sunrise_file_response' ); }
				$bytes = base64_decode( $result['data'], true ); if ( false === $bytes || strlen( $bytes ) !== min( 262144, $archive['bytes'] - $offset ) || strlen( $bytes ) !== fwrite( $handle, $bytes ) || ! fflush( $handle ) ) { throw new \RuntimeException( 'sunrise_file_storage' ); } $offset += strlen( $bytes );
			}
		} finally { fclose( $handle ); }
		if ( $offset < $archive['bytes'] ) { return false; }
		if ( hash_file( 'sha256', $path ) !== $archive['sha256'] ) { throw new \RuntimeException( 'sunrise_file_checksum' ); }
		$plan = json_decode( $job['plan_json'], true, 32 ); if ( ( $plan['scope'] ?? null ) !== 'files' || ( $plan['destination_site_id'] ?? null ) !== agent_state()['site_id'] || ! isset( $plan['changes'] ) || ! is_array( $plan['changes'] ) || count( $plan['changes'] ) > 5000 ) { throw new \RuntimeException( 'sunrise_file_plan' ); }
		$zip = new \ZipArchive(); if ( true !== $zip->open( $path, defined( 'ZipArchive::RDONLY' ) ? \ZipArchive::RDONLY : 0 ) ) { throw new \RuntimeException( 'sunrise_file_archive' ); }
		try {
			if ( $zip->numFiles !== count( $plan['changes'] ) ) { throw new \RuntimeException( 'sunrise_file_archive' ); }
			$total = 0;
			foreach ( $plan['changes'] as $index => $change ) {
				$file = $change['file']; transfer_file_entry( $file ); $total += $file['bytes']; $entry = $zip->statIndex( $index );
				if ( $total > 512 * MB_IN_BYTES || ! $entry || $entry['name'] !== $file['path'] || $entry['size'] !== $file['bytes'] ) { throw new \RuntimeException( 'sunrise_file_archive' ); }
				if ( 'unchanged' === $change['action'] ) { continue; }
				$stream = $zip->getStream( $entry['name'] ); if ( ! $stream ) { throw new \RuntimeException( 'sunrise_file_archive' ); }
				try { for ( $offset = 0; $offset < max( 1, $file['bytes'] ); $offset += 262144 ) { $bytes = stream_get_contents( $stream, 262144 ); if ( false === $bytes ) { throw new \RuntimeException( 'sunrise_file_archive' ); } transfer_file_stage( $job['id'], $index, $file, $offset, $bytes ); } } finally { fclose( $stream ); }
			}
		} finally { $zip->close(); }
		return true;
	} catch ( \Throwable $error ) { return new \WP_Error( 'sunrise_file_download', 'Could not verify the transferred file archive.' ); }
}
