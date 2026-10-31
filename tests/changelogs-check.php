<?php
/** Disposable fixture only; provider replies are intercepted and options restored. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
require_once dirname( __DIR__ ) . '/includes/changelogs.php';
require_once dirname( __DIR__ ) . '/vendor/plugin-update-checker/plugin-update-checker.php';
class SunriseChangelogFixtureApi extends \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api {
 public $requests = array();
 protected function getUpdateDetectionStrategies( $branch ) { return array(); }
 public function getBranch( $name ) { return null; }
 public function getTag( $name ) { return null; }
 public function getLatestTag() { return null; }
 public function getLatestCommitTime( $ref ) { return null; }
 public function getRemoteFile( $path, $ref = 'master' ) { $this->requests[] = array( $path, $ref ); return "## 2.0\n* Repository Markdown notes."; }
}
$cache_option = 'sunrise_changelog_cache_' . get_current_user_id();
$cache = get_option( $cache_option, null ); $updates = get_site_transient( 'update_plugins' );
$checks = 0; $calls = 0; $mode = 'json';
$assert = static function ( $ok, $message ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $message ); } $checks++; };
$provider = static function ( $result, $action, $args ) use ( &$calls, &$mode ) {
 if ( 'plugin_information' !== $action || 'sunrise-changelog-fixture' !== $args->slug ) { return $result; }
 $calls++;
 if ( 'throw' === $mode ) { throw new RuntimeException( 'Provider unavailable' ); }
 if ( 'missing' === $mode ) { return false; }
 if ( 'link' === $mode ) { return (object) array( 'slug' => $args->slug, 'version' => '2.0', 'sections' => array( 'changelog' => '<a href="https://vendor.invalid/?license=fixture">Changelog</a>' ) ); }
 $json = '{"name":"Fixture","slug":"sunrise-changelog-fixture","version":"2.0","sections":{"changelog":"<h4>2.0</h4><p>Fixed issue.</p><script>bad()</script><a href=\"https://vendor.invalid/?key=fixture\">Details</a>"}}';
 $info = \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo::fromJson( $json );
 if ( 'markdown' === $mode ) { $parser = new Parsedown(); $info->sections['changelog'] = $parser->text( "## 2.0\n* Markdown release notes." ); }
 $info = $info->toWpFormat();
 if ( 'object' === $mode ) { $info->sections = (object) $info->sections; }
 if ( 'large' === $mode ) { $info->sections['changelog'] = '<h4>2.0</h4><p>' . str_repeat( 'Long release note. ', 2500 ) . '</p>'; }
 if ( 'wrong' === $mode ) { $info->plugin = 'another/plugin.php'; }
 return $info;
};
add_filter( 'plugins_api', $provider, PHP_INT_MAX, 3 );
try {
 $id = 'sunrise-changelog-fixture/plugin.php'; $offer = (object) array( 'slug' => 'sunrise-changelog-fixture', 'new_version' => '2.0' );
 $json = Sunrise\plugin_changelog_info( $id, $offer );
 $assert( '2.0' === $json['version'] && false !== strpos( $json['changelog'], 'Fixed issue.' ), 'PUC JSON native plugin info failed' );
 $assert( false === strpos( $json['changelog'], 'script' ) && false === strpos( $json['changelog'], 'fixture' ) && false === strpos( $json['changelog'], 'href' ), 'URLs or markup leaked' );
 $assert( false === has_filter( 'http_request_args', 'Sunrise\\changelog_http_args' ) && false === has_filter( 'pre_http_request', 'Sunrise\\changelog_http_guard' ), 'Temporary HTTP hooks leaked' );
 $directory = sys_get_temp_dir() . '/sunrise-changelog-' . wp_generate_uuid4(); mkdir( $directory, 0700 );
 try {
  foreach ( array( 'CHANGES.md', 'CHANGELOG.md', 'changes.md', 'changelog.md' ) as $filename ) {
   file_put_contents( $directory . '/' . $filename, 'Installed filename marker.' );
   $api = new SunriseChangelogFixtureApi( 'https://github.com/fixture/plugin' );
   $notes = $api->getRemoteChangelog( 'v2.0', $directory );
   $assert( false !== strpos( $notes, 'Repository Markdown notes' ) && array( array( $filename, 'v2.0' ) ) === $api->requests, 'PUC repository filename/ref failed: ' . $filename );
   unlink( $directory . '/' . $filename );
  }
 } finally { foreach ( glob( $directory . '/*' ) as $file ) { unlink( $file ); } rmdir( $directory ); }
 $mode = 'markdown'; $markdown = Sunrise\plugin_changelog_info( $id, $offer ); $assert( false !== strpos( $markdown['changelog'], '<h2>2.0</h2>' ) && false !== strpos( $markdown['changelog'], 'Markdown release notes' ), 'PUC Markdown headings lost' );
 $mode = 'object'; $assert( false !== strpos( Sunrise\plugin_changelog_info( $id, $offer )['changelog'], 'Fixed issue.' ), 'Object sections from native/EDD-style responses failed' );
 $mode = 'large'; $large = Sunrise\plugin_changelog_info( $id, $offer ); $assert( $large['truncated'] && strlen( $large['changelog'] ) <= 32000, 'Oversized notes were not marked as excerpts' );
 foreach ( array( 'wrong', 'throw', 'missing', 'link' ) as $mode ) { $assert( null === Sunrise\plugin_changelog_info( $id, $offer ), 'Invalid/link-only provider info accepted: ' . $mode ); }
 $assert( null === Sunrise\plugin_changelog_info( $id, (object) array( 'slug' => '../bad' ) ), 'Unsafe slug accepted' );
 $mode = 'json'; delete_option( $cache_option );
 $inventory = array(); $response = array();
 for ( $i = 0; $i < 5; $i++ ) { $key = $id . $i; $inventory[] = array( 'type' => 'plugins', 'installed_id' => $key, 'version' => '1.0', 'offered_version' => '2.0' ); $response[ $key ] = $offer; }
 set_site_transient( 'update_plugins', (object) array( 'response' => $response ) );
 $before = $calls; $first = Sunrise\agent_plugin_changelogs( $inventory ); $second = Sunrise\agent_plugin_changelogs( $inventory ); $third = Sunrise\agent_plugin_changelogs( $inventory );
 $assert( 2 === count( $first ) && 2 === count( $second ) && 1 === count( $third ) && 5 === $calls - $before, 'Lookup budget or rotation failed' );
 $assert( array() === Sunrise\agent_plugin_changelogs( $inventory ), 'Cached offers were queried again' );
 $inventory[0]['version'] = '1.1'; $assert( 1 === count( Sunrise\agent_plugin_changelogs( $inventory ) ), 'Installed-version changes did not invalidate the cache' );
 $state = get_option( $cache_option ); foreach ( $state as &$entry ) { $entry['checked'] = time() - 13 * HOUR_IN_SECONDS; $entry['retry_at'] = time() + 7200; } unset( $entry ); update_option( $cache_option, $state, false );
 $assert( array() === Sunrise\agent_plugin_changelogs( $inventory ), 'Retry-After backoff ignored' );
 $assert( 2 === count( Sunrise\agent_plugin_changelogs( $inventory, 'replacement-connection' ) ), 'A replacement connection incorrectly shared the prior receipt/cache' );
 $assert( array() === Sunrise\agent_plugin_changelogs( array() ) && array() === get_option( $cache_option ), 'Uninstalled components remain cached' );
 $GLOBALS['sunrise_changelog_deadline'] = microtime( true ) + 3; $GLOBALS['sunrise_changelog_requests'] = 6;
 $assert( is_wp_error( Sunrise\changelog_http_guard( false ) ), 'Repository HTTP count budget ignored' );
 $GLOBALS['sunrise_changelog_retry_at'] = 0; Sunrise\changelog_http_response( array( 'headers' => array( 'retry-after' => '7200' ) ) );
 $assert( $GLOBALS['sunrise_changelog_retry_at'] >= time() + 7199, 'HTTP Retry-After was not recorded' );
 $args = Sunrise\changelog_http_args( array( 'timeout' => 30, 'redirection' => 10 ) );
 $assert( $args['timeout'] <= 3 && $args['redirection'] <= 3 && $args['reject_unsafe_urls'] && 500000 === $args['limit_response_size'], 'Provider HTTP bounds were not applied' );
 WP_CLI::success( $checks . ' changelog checks passed: PUC JSON/Markdown, identity, sanitization, exceptions, bounded rotating collection, caching and retry backoff.' );
} finally {
 remove_filter( 'plugins_api', $provider, PHP_INT_MAX );
 if ( null === $cache ) { delete_option( $cache_option ); } else { update_option( $cache_option, $cache, false ); }
 if ( false === $updates ) { delete_site_transient( 'update_plugins' ); } else { set_site_transient( 'update_plugins', $updates ); }
}
