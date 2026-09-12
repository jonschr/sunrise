<?php
/** php tests/direct-database-check.php; pure state/progress checks, no WordPress/database/network needed. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
require dirname( __DIR__ ) . '/includes/direct-database.php';

$job = array(
	'id' => '10000000-0000-4000-8000-000000000000', 'status' => 'running', 'role' => 'source', 'peer_site_id' => '20000000-0000-4000-8000-000000000000', 'created_at' => 1700000000,
	'manifest' => array( 'site_id' => '30000000-0000-4000-8000-000000000000', 'tables' => array( array( 'bytes' => 5 ), array( 'bytes' => 10 ) ) ),
	'initiator' => true, 'table_index' => 1, 'offset' => 5,
);
$result = Sunrise\database_job_public( $job );
if ( $result['progress'] !== array( 'bytes' => 10, 'total' => 15 ) || ! $result['initiator'] || $result['cleanup_pending'] || ! $result['cancellable'] || $result['recovery_available'] ) { throw new RuntimeException( 'Direct database progress state is invalid.' ); }
$job['status'] = 'succeeded'; $job['backups'] = array( 'wp_options' => 'sunrise_backup_example_0' );
$result = Sunrise\database_job_public( $job );
if ( $result['progress']['bytes'] !== 15 || $result['cancellable'] || ! $result['recovery_available'] ) { throw new RuntimeException( 'Completed database recovery state is invalid.' ); }
$job['cleanup_pending'] = true; $result = Sunrise\database_job_public( $job );
if ( ! $result['cleanup_pending'] || $result['recovery_available'] ) { throw new RuntimeException( 'Committed database cleanup state is invalid.' ); }
echo "PASS: Direct database progress, cancellation and recovery state are coherent.\n";
