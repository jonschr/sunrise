<?php
/** Bundled release updates; static metadata avoids GitHub's shared-IP API limit. */
namespace Sunrise;
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/vendor/plugin-update-checker/plugin-update-checker.php';

function plugin_update_checker() {
	static $checker;
	if ( ! $checker ) {
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://raw.githubusercontent.com/jonschr/sunrise/main/update.json', dirname( __DIR__ ) . '/sunrise.php', 'sunrise'
		);
	}
	return $checker;
}

// A changed manifest may only select the matching packaged release asset.
add_filter( 'puc_request_info_result-sunrise', function ( $info ) {
	if ( ! $info || ! isset( $info->name, $info->version, $info->download_url ) || 'Sunrise' !== $info->name || ! preg_match( '/^\d+\.\d+\.\d+$/D', $info->version ) ) { return null; }
	return 'https://github.com/jonschr/sunrise/releases/download/v' . $info->version . '/sunrise.zip' === $info->download_url ? $info : null;
} );
plugin_update_checker();

// Keep the connector current even when network plugin policies are off. WordPress owns installation and recovery.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	$file = plugin_basename( dirname( __DIR__ ) . '/sunrise.php' );
	if ( ! isset( $item->plugin ) || $file !== $item->plugin ) {
		return $update;
	}
	// A local checkout or symlink must never be replaced by a release ZIP.
	$directory = WP_PLUGIN_DIR . '/' . dirname( $file );
	if ( is_link( $directory ) || file_exists( $directory . '/.git' ) ) {
		return false;
	}
	return empty( $item->disable_autoupdate ) && wp_is_auto_update_enabled_for_type( 'plugin' );
}, 20, 2 );
