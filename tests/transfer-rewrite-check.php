<?php
/** php tests/transfer-rewrite-check.php; pure checks, no WordPress/database/network needed. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/transfer.php';
function verify_rewrite( $actual, $expected ) { if ( $actual !== $expected ) { throw new RuntimeException( 'Rewrite mismatch: ' . var_export( $actual, true ) ); } }
function reject_rewrite( $input, $format = 'serialized', $urls = array( 'https://a.example' => 'https://longer.example' ) ) {
	try { Sunrise\transfer_rewrite( $input, $format, $urls ); } catch ( InvalidArgumentException $error ) { return; }
	throw new RuntimeException( 'Unsafe value accepted.' );
}
$urls = array( 'https://a.example' => 'https://b.example', 'https://a.example/wp-content/uploads' => 'https://media.example/files', 'https://b.example' => 'https://c.example' );
$text = '<a href="https://a.example/path">https://a.example?x=1</a> https://a.example.evil/ https://a.example:8080/ https://a.example/wp-content/uploads/a.jpg https://b.example/test https://other.example/?to=https://a.example#part';
verify_rewrite( Sunrise\transfer_rewrite( $text, 'text', $urls ), '<a href="https://b.example/path">https://b.example?x=1</a> https://a.example.evil/ https://a.example:8080/ https://media.example/files/a.jpg https://c.example/test https://other.example/?to=https://b.example#part' );
verify_rewrite( Sunrise\transfer_rewrite( 'https:\/\/a.example\/test', 'text', $urls ), 'https:\/\/b.example\/test' );
$value = array( 'unicode' => '🙂 https://a.example/é', 'zero' => 0, 'float' => 1.2, 'false' => false, 'null' => null, 'nested' => array( 'url' => 'https://a.example/a' ) );
$expected = $value; $expected['unicode'] = '🙂 https://b.example/é'; $expected['nested']['url'] = 'https://b.example/a';
verify_rewrite( Sunrise\transfer_rewrite( serialize( $value ), 'serialized', $urls ), serialize( $expected ) );
verify_rewrite( Sunrise\transfer_rewrite( serialize( serialize( $value ) ), 'serialized', $urls ), serialize( serialize( $expected ) ) );
verify_rewrite( Sunrise\transfer_rewrite( '{ "n":900719925474099312345, "value":"https:\/\/a.example\/p", "empty":{} }', 'json', $urls ), '{ "n":900719925474099312345, "value":"https://b.example/p", "empty":{} }' );
verify_rewrite( Sunrise\transfer_rewrite( 'https://a.example/wp-site https://a.example/wp/path', 'text', array( 'https://a.example/wp' => 'https://d.example/install' ) ), 'https://a.example/wp-site https://d.example/install/path' );
foreach ( array( 'N;', 'b:0;', 'i:-1;', 'd:INF;', 's:3:"abc";', 'a:0:{}' ) as $value ) { verify_rewrite( Sunrise\transfer_rewrite( $value, 'serialized', $urls ), $value ); }
foreach ( array( 's:1:"long";', 's:99999999999999999999:"x";', 'a:999999999999:{}', 'a:1:{i:0;s:1:"a";i:1;s:1:"b";}', 'b:3;', 'N;garbage', 'a:1:{i:0;R:1;}', 'a:1:{b:1;s:1:"x";}', 'C:4:"Evil":0:{}', 'O:4:"Evil":0:{}', 'E:8:"Test:One";', 'S:3:"abc";' ) as $bad ) { reject_rewrite( $bad ); }
$loads = 0; spl_autoload_register( function () use ( &$loads ) { ++$loads; } );
reject_rewrite( serialize( 'O:4:"Evil":0:{}' ) ); verify_rewrite( $loads, 0 );
reject_rewrite( 'i:999999999999999999999999;' );
reject_rewrite( 'a:2:{i:1;N;s:1:"1";N;}' );
reject_rewrite( 'a:2:{s:1:"x";N;s:1:"x";N;}' );
reject_rewrite( serialize( array( 'https://a.example' => 'key' ) ) );
reject_rewrite( '{"https://a.example":"key"}', 'json' );
reject_rewrite( '{"broken":}', 'json' );
$deep = 'value'; for ( $i = 0; $i < 40; ++$i ) { $deep = array( $deep ); } reject_rewrite( serialize( $deep ) );
reject_rewrite( serialize( array_fill( 0, 6000, '' ) ) );
reject_rewrite( str_repeat( 'a', 2097153 ), 'text' );
foreach ( array( 'http://user:pass@example.com', 'ftp://a.example', 'https://a.example?secret=x', 'https://a.example/#part', 'https://a.example/../x' ) as $url ) { reject_rewrite( 'value', 'text', array( $url => 'https://b.example' ) ); }
echo "PASS: Bounded serialization/JSON rewriting, Unicode lengths, exact URL boundaries, non-cascading maps, malformed/object/reference rejection and resource limits.\n";
