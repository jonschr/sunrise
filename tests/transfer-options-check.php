<?php
/** Transaction/recovery fixture. Native settings and test journals are restored; no remote service call. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Disposable local administrator required.' ); }
require_once WP_PLUGIN_DIR . '/sunrise/includes/transfer-options.php';
require_once WP_PLUGIN_DIR . '/sunrise/includes/agent-jobs.php';
function options_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); }
global $wpdb;
$saved = Sunrise\transfer_option_snapshot( array( 'blogname', 'blogdescription' ) ); if ( is_wp_error( $saved ) ) { throw new RuntimeException( 'Snapshot unavailable' ); }
$fence = get_option( 'sunrise_remote_job_fence' ); if ( $fence ) { throw new RuntimeException( 'Existing execution requires review' ); }
$other_owner = 0; $identity = Sunrise\installation_identity(); $states = Sunrise\agent_states(); $ids = array(); $trigger = 'sunrise_test_' . bin2hex( random_bytes( 4 ) );
$make = function ( $name = 'Sunrise transactional title' ) use ( $saved, &$ids ) {
	$source = $saved; $source['site_id'] = wp_generate_uuid4(); $source['site_url'] = 'https://transfer-source.example/'; $source['home_url'] = $source['site_url'];
	foreach ( $source['items'] as &$item ) { $item['value'] = 'blogname' === $item['name'] ? $name : 'Sunrise transactional tagline'; $item['fingerprint'] = hash( 'sha256', $item['value'] ); } unset( $item );
	$preview = Sunrise\transfer_option_preview( $source ); if ( is_wp_error( $preview ) ) { throw new RuntimeException( 'Preview failed' ); }
	$id = wp_generate_uuid4(); $ids[] = $id; $json = wp_json_encode( $preview['plan'] ); $hash = hash( 'sha256', $json );
	update_option( 'sunrise_remote_job_fence', array( 'kind' => 'transfer', 'site_id' => Sunrise\agent_state()['site_id'], 'job_id' => $id, 'plan_hash' => $hash, 'start_until' => time() + 60 ), false );
	return array( $id, $json, $hash );
};
try {
	$attempt = $make();
	$held = Sunrise\agent_execution_lock(); options_check( is_wp_error( Sunrise\transfer_options_commit( ...$attempt ) ), 'The update/transfer process lock excludes another writer' ); flock( $held, LOCK_UN ); fclose( $held );
	$result = Sunrise\transfer_options_commit( ...$attempt ); options_check( ! is_wp_error( $result ) && 'committed' === $result['state'] && 'Sunrise transactional title' === get_option( 'blogname' ) && 'Sunrise transactional tagline' === get_option( 'blogdescription' ), 'Selected settings and a recovery journal commit together; WordPress caches refresh' );
	options_check( Sunrise\transfer_options_commit( ...$attempt )['replayed'], 'Retry reads the committed journal without writing again' );
	options_check( count( Sunrise\transfer_option_journals() ) === 1, 'The owner can review the committed journal' );
	$owner = get_current_user_id(); $other_owner = wp_create_user( 'sunrise-recovery-' . wp_generate_uuid4(), wp_generate_password( 40 ), '' );
	if ( is_wp_error( $other_owner ) ) { throw new RuntimeException( 'Could not create ownership fixture' ); }
	$other = new WP_User( $other_owner ); $other->set_role( 'administrator' ); wp_set_current_user( $other_owner );
	options_check( is_wp_error( Sunrise\transfer_option_journals() ) && is_wp_error( Sunrise\transfer_options_commit( $attempt[0], null, $attempt[2], true ) ), 'A different administrator cannot list or restore the owner journal' );
	wp_set_current_user( $owner );
	$key = 'sunrise_transfer_backup_' . $owner . '_' . $attempt[0]; $original_journal = get_option( $key );
	$copied = json_decode( $original_journal, true ); $copied['installation_id'] = wp_generate_uuid4(); update_option( $key, wp_json_encode( $copied ), false );
	options_check( ! Sunrise\transfer_option_journals() && is_wp_error( Sunrise\transfer_options_commit( $attempt[0], null, $attempt[2], true ) ), 'A copied installation journal is neither shown nor restorable' );
	update_option( $key, $original_journal, false );
	wp_cache_set( 'alloptions', array( 'blogname' => 'Stale cache after a process crash' ), 'options' );
	Sunrise\transfer_options_commit( ...$attempt );
	options_check( 'Sunrise transactional title' === get_option( 'blogname' ), 'A committed replay repairs a stale option cache without reapplying data' );
	$wrong = Sunrise\transfer_options_commit( $attempt[0], null, str_repeat( '0', 64 ), true ); options_check( is_wp_error( $wrong ) && 'sunrise_transfer_conflict' === $wrong->get_error_code(), 'Restore requires the exact reviewed journal hash' );
	update_option( 'blogdescription', 'A newer administrator edit' );
	$restore = Sunrise\transfer_options_commit( $attempt[0], null, $attempt[2], true ); options_check( is_wp_error( $restore ) && 'sunrise_transfer_destination_changed' === $restore->get_error_code() && 'Sunrise transactional title' === get_option( 'blogname' ) && 'A newer administrator edit' === get_option( 'blogdescription' ), 'Restore refuses to overwrite newer edits and makes no partial change' );
	update_option( 'blogdescription', 'Sunrise transactional tagline' );
	$restore = Sunrise\transfer_options_commit( $attempt[0], null, $attempt[2], true ); options_check( ! is_wp_error( $restore ) && 'restored' === $restore['state'] && Sunrise\transfer_option_snapshot( array( 'blogname', 'blogdescription' ) ) === $saved, 'Exact restore returns selected settings to their original values' );
	options_check( Sunrise\transfer_options_commit( $attempt[0], null, $attempt[2], true )['replayed'] && 'restored' === Sunrise\transfer_options_commit( ...$attempt )['state'], 'Repeated restore and a replayed original grant cannot reapply changes' );
	$stale = $make(); update_option( 'blogdescription', 'Concurrent change before execution' );
	$failed = Sunrise\transfer_options_commit( ...$stale ); options_check( is_wp_error( $failed ) && 'sunrise_transfer_destination_changed' === $failed->get_error_code() && get_option( 'blogname' ) === $saved['items'][1]['value'], 'All destination preconditions are checked before any write' );
	foreach ( $saved['items'] as $item ) { update_option( $item['name'], $item['value'] ); }
	$rollback = $make( 'Sunrise rollback fixture' );
	// Fail the second alphabetical update inside MySQL, after the first row was changed in the transaction.
	$sql = "CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$wpdb->options}` FOR EACH ROW BEGIN IF NEW.option_name='blogname' AND NEW.option_value='Sunrise rollback fixture' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'; END IF; END";
	if ( false === $wpdb->query( $sql ) ) { throw new RuntimeException( 'Could not install rollback fixture' ); }
	$failed = Sunrise\transfer_options_commit( ...$rollback );
	options_check( is_wp_error( $failed ) && Sunrise\transfer_option_snapshot( array( 'blogname', 'blogdescription' ) ) === $saved, 'A database failure rolls back every setting' );
	$key = 'sunrise_transfer_backup_' . get_current_user_id() . '_' . $rollback[0];
	options_check( ! $wpdb->get_row( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s", $key ) ), 'A rolled-back transfer has no committed journal' );
	options_check( $identity === Sunrise\installation_identity() && $states === Sunrise\agent_states(), 'Settings writes and restore preserve installation identity and administrator connections' );
	foreach ( array( array( 'start_of_week', '9' ), array( 'timezone_string', 'Invalid/Zone' ), array( 'gmt_offset', '30' ), array( 'blogname', '<script>x</script>' ), array( 'blogdescription', 'O:4:"Evil":0:{}' ), array( 'cron', '{}' ) ) as $bad ) {
		$rejected = false; try { Sunrise\transfer_native_value( ...$bad ); } catch ( InvalidArgumentException $error ) { $rejected = true; } options_check( $rejected, 'Invalid or protected setting is rejected: ' . $bad[0] );
	}
} finally {
	if ( isset( $owner ) ) { wp_set_current_user( $owner ); }
	if ( is_int( $other_owner ) && $other_owner > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $other_owner ); }
	$wpdb->query( "DROP TRIGGER IF EXISTS `{$trigger}`" );
	foreach ( $saved['items'] as $item ) { update_option( $item['name'], $item['value'] ); }
	foreach ( $ids as $id ) { delete_option( 'sunrise_transfer_backup_' . get_current_user_id() . '_' . $id ); }
	delete_option( 'sunrise_remote_job_fence' );
}
WP_CLI::success( 'Transactional settings and conditional recovery passed; fixtures removed.' );
