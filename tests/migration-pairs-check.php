<?php
/** php tests/migration-pairs-check.php; pure checks, no WordPress/database/network needed. */
define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require dirname( __DIR__ ) . '/includes/migration-pairs.php';

$assert = function ( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
$secret = str_repeat( 'ab', 32 ); $body = '{"action":"ping"}';
$signature = Sunrise\migration_pair_signature( $secret, 'post', '/sunrise/v1/migrations/direct/ping', '1700000000', str_repeat( 'cd', 16 ), $body );
$assert( hash_equals( $signature, Sunrise\migration_pair_signature( $secret, 'POST', '/sunrise/v1/migrations/direct/ping', '1700000000', str_repeat( 'cd', 16 ), $body ) ), 'Method normalization failed.' );
$assert( ! hash_equals( $signature, Sunrise\migration_pair_signature( $secret, 'POST', '/sunrise/v1/migrations/direct/ping', '1700000000', str_repeat( 'cd', 16 ), $body . ' ' ) ), 'Body tampering was accepted.' );
$assert( ! hash_equals( $signature, Sunrise\migration_pair_signature( $secret, 'POST', '/sunrise/v1/migrations/direct/export', '1700000000', str_repeat( 'cd', 16 ), $body ) ), 'Route tampering was accepted.' );
$value = array( 'challenge' => str_repeat( 'a', 64 ), 'url' => 'https://example.com' );
$assert( Sunrise\migration_pair_decode( Sunrise\migration_pair_encode( $value ) ) === $value, 'Pairing callback encoding failed.' );
$assert( null === Sunrise\migration_pair_decode( '../bad' ), 'Malformed callback accepted.' );
echo "PASS: Pair signatures bind method, route and body; callback encoding is bounded.\n";
