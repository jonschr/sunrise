<?php
/** Bundled GitHub release updates; never requires a credential on connected sites. */
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/vendor/plugin-update-checker/plugin-update-checker.php';

function plugin_update_checker() {
	static $checker;
	if ( ! $checker ) {
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/jonschr/sunrise', dirname( __DIR__ ) . '/sunrise.php', 'sunrise'
		);
		$checker->setBranch( 'main' );
		$checker->getVcsApi()->enableReleaseAssets( '/^sunrise\.zip$/', \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS );
	}
	return $checker;
}

// Tag pushes publish a packaged release. Never fall back to unreviewed branch commits or source archives.
add_filter( 'puc_vcs_update_detection_strategies-sunrise', function ( $strategies ) {
	return array_intersect_key( $strategies, array( 'latest_release' => true ) );
} );
plugin_update_checker();
