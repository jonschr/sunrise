<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) || get_option( 'sunrise_remote_job_fence' ) ) { throw new RuntimeException( 'Idle disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/transfer-files.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-jobs.php';
$assert = function ( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
$slug = 'sunrise-files-check-' . wp_generate_uuid4(); $dir = WP_PLUGIN_DIR . '/' . $slug; $id = wp_generate_uuid4(); $root = null; $lock = null;
$identity = Sunrise\installation_identity(); $agents = Sunrise\agent_states(); $media = wp_upload_dir( null, false )['basedir'] . '/' . $slug;
$remove = function ( $path ) use ( &$remove ) { if ( is_link( $path ) || is_file( $path ) ) { unlink( $path ); } elseif ( is_dir( $path ) ) { foreach ( scandir( $path ) as $name ) { if ( '.' !== $name && '..' !== $name ) { $remove( $path . '/' . $name ); } } rmdir( $path ); } };
try {
 mkdir( $dir ); file_put_contents( $dir . '/fixture.php', "<?php\n/** Plugin Name: File transfer fixture */\n" ); file_put_contents( $dir . '/large.txt', str_repeat( 'chunk-', 50000 ) ); wp_clean_plugins_cache( true );
 $selection = array( 'plugins' => array( 'mode' => 'include', 'items' => array( $slug . '/fixture.php' ) ) );
 $manifest = Sunrise\transfer_file_manifest( $selection ); $assert( ! is_wp_error( $manifest ) && count( $manifest['files'] ) === 2, 'Selected plugin snapshot' );
 $all = array_column( Sunrise\transfer_file_catalog( 'plugins' ), 'id' );
 $excluded = Sunrise\transfer_file_manifest( array( 'plugins' => array( 'mode' => 'exclude', 'items' => array_values( array_diff( $all, array( $slug . '/fixture.php' ) ) ) ) ) ); $assert( ! is_wp_error( $excluded ) && $excluded['files'] === $manifest['files'], 'All except selected' );
 foreach ( array( 'plugins/../wp-config.php', 'plugins/sunrise/sunrise.php', 'media/test.php.jpg', 'media/../bad.jpg', 'themes/test/.env', 'plugins/test/file:stream', 'media/test.php8', 'media/test.php81', 'media/test.phps', 'themes/test/name.' ) as $path ) { $assert( ! Sunrise\transfer_file_path_valid( $path ), 'Unsafe path accepted: ' . $path ); }
 symlink( __FILE__, $dir . '/linked.txt' ); $assert( is_wp_error( Sunrise\transfer_file_manifest( $selection ) ), 'Source link must fail' ); unlink( $dir . '/linked.txt' );
 $manifest['site_id'] = wp_generate_uuid4();
 $wire = array(); foreach ( $manifest['files'] as $index => $file ) { $wire[ $index ] = array(); for ( $offset = 0; $offset < max( 1, $file['bytes'] ); $offset += 262144 ) { $wire[ $index ][ $offset ] = Sunrise\transfer_file_chunk( $file, $offset ); } }
 file_put_contents( $dir . '/large.txt', 'Destination original' ); unlink( $dir . '/fixture.php' );
 $preview = Sunrise\transfer_file_preview( $manifest ); $assert( ! is_wp_error( $preview ), 'Destination preview' );
 $plan = wp_json_encode( $preview['plan'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $hash = hash( 'sha256', $plan );
 $root = Sunrise\transfer_file_workspace( $id ); file_put_contents( $root . '/0.part', substr( $wire[0][0], 0, 3 ) );
 foreach ( $manifest['files'] as $index => $file ) { foreach ( $wire[ $index ] as $offset => $bytes ) { Sunrise\transfer_file_stage( $id, $index, $file, $offset, $bytes ); Sunrise\transfer_file_stage( $id, $index, $file, $offset, $bytes ); } }
 $root = Sunrise\transfer_file_workspace( $id ); $lock = Sunrise\agent_execution_lock(); $assert( is_resource( $lock ), 'Executor lock' );
 $assert( is_wp_error( Sunrise\transfer_files_commit( $id, $plan, $hash, $lock ) ), 'No write without approved fence' );
 update_option( 'sunrise_remote_job_fence', array( 'kind' => 'transfer', 'job_id' => $id, 'site_id' => Sunrise\agent_state()['site_id'], 'plan_hash' => $hash, 'start_until' => time() + 60 ), false );
 file_put_contents( $dir . '/large.txt', 'Newer destination edit' ); $assert( is_wp_error( Sunrise\transfer_files_commit( $id, $plan, $hash, $lock ) ) && ! file_exists( $dir . '/fixture.php' ), 'Preconditions protect every file before writes' ); file_put_contents( $dir . '/large.txt', 'Destination original' );
 add_filter( 'file_mod_allowed', '__return_false' ); $assert( is_wp_error( Sunrise\transfer_files_commit( $id, $plan, $hash, $lock ) ) && ! file_exists( $dir . '/fixture.php' ), 'Host file modification policy honored' ); remove_filter( 'file_mod_allowed', '__return_false' );
 $result = Sunrise\transfer_files_commit( $id, $plan, $hash, $lock ); $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Commit' );
 foreach ( $manifest['files'] as $file ) { $assert( hash_file( 'sha256', Sunrise\transfer_file_local_path( $file['path'] ) ) === $file['sha256'], 'Exact installed bytes' ); }
 $assert( is_wp_error( Sunrise\transfer_files_commit( $id, $plan, $hash, $lock ) ), 'Committed journal prevents replay' );
 file_put_contents( $dir . '/large.txt', 'Edited after transfer' ); $assert( is_wp_error( Sunrise\transfer_files_restore( $id, $hash, $lock ) ) && file_exists( $dir . '/fixture.php' ), 'Restore protects newer edits without partial restoration' ); file_put_contents( $dir . '/large.txt', str_repeat( 'chunk-', 50000 ) );
 $restored = Sunrise\transfer_files_restore( $id, $hash, $lock ); $assert( ! is_wp_error( $restored ) && ! file_exists( $dir . '/fixture.php' ) && file_get_contents( $dir . '/large.txt' ) === 'Destination original', 'Original files restored; additions removed' );
 mkdir( $media ); file_put_contents( $media . '/one.jpg', 'one' );
 $selection = array( 'media' => array( 'mode' => 'last', 'since' => null ) ); $first = Sunrise\transfer_file_manifest( $selection ); $assert( ! is_wp_error( $first ), 'Media manifest' ); $checkpoint = array_column( $first['files'], 'sha256', 'path' );
 $next = Sunrise\transfer_file_manifest( $selection, $checkpoint ); $assert( ! is_wp_error( $next ) && ! $next['files'], 'Unchanged media skipped' );
 file_put_contents( $media . '/one.jpg', 'two' ); touch( $media . '/one.jpg', 1 ); $next = Sunrise\transfer_file_manifest( $selection, $checkpoint ); $assert( ! is_wp_error( $next ) && count( $next['files'] ) === 1 && strpos( $next['files'][0]['path'], $slug ) !== false, 'Backdated changed media detected by hash' );
 $old = time() - 8 * DAY_IN_SECONDS; foreach ( new FilesystemIterator( $root ) as $entry ) { touch( $entry->getPathname(), $old ); } touch( $root, $old );
 delete_user_meta( 1, 'sunrise_file_pruned_at' ); Sunrise\transfer_file_prune( dirname( $root ), wp_generate_uuid4() ); $assert( is_dir( $root ), 'Fenced recovery copies retained' );
 delete_option( 'sunrise_remote_job_fence' ); delete_user_meta( 1, 'sunrise_file_pruned_at' ); Sunrise\transfer_file_prune( dirname( $root ), wp_generate_uuid4() ); $assert( ! is_dir( $root ), 'Completed recovery copies expire after seven days' );
 $assert( Sunrise\installation_identity() === $identity && Sunrise\agent_states() === $agents, 'Destination identity and connections retained' );
 WP_CLI::success( 'File selection, manifests, chunk retries, checksums, guarded writes, recovery, incremental media and destination identity passed.' );
} finally {
 if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN ); fclose( $lock ); } delete_option( 'sunrise_remote_job_fence' ); $remove( $dir ); $remove( $media ); if ( $root ) { $remove( $root ); } wp_clean_plugins_cache( true );
}
