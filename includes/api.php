<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

function permission( $request = null ) {
	if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
		return new \WP_Error( 'sunrise_https_required', __( 'HTTPS is required outside an explicitly local environment.', 'sunrise' ), array( 'status' => 403 ) );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'sunrise_forbidden', __( 'An authorized WordPress administrator is required.', 'sunrise' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}
	return agent_access();
}

function register_routes() {
	register_rest_route( 'sunrise/v1', '/dashboard', array( 'methods' => 'POST', 'callback' => function ( $request ) { require_once __DIR__ . '/dashboard.php'; return dashboard_request( $request ); }, 'permission_callback' => __NAMESPACE__ . '\\permission' ) );
	foreach ( array( '' => array( 'GET', 'POST' ), '/status' => array( 'GET' ), '/catalog' => array( 'GET' ), '/peers' => array( 'GET' ), '/sync' => array( 'POST' ) ) as $path => $methods ) {
		register_rest_route( 'sunrise/v1', '/transfers/workbench' . $path, array( 'methods' => $methods, 'callback' => function ( $request ) use ( $path ) {
			require_once __DIR__ . '/migrations.php'; $access = transfer_inventory_access(); if ( is_wp_error( $access ) ) { return $access; }
			if ( '/status' === $path ) { return migration_status(); }
			if ( '/peers' === $path ) { return migration_peers( $request->get_param( 'after' ) ); }
			if ( '/catalog' === $path ) { return migration_file_catalog( $request->get_param( 'site' ), $request->get_param( 'after' ) ); }
			if ( '/sync' === $path ) { return migration_sync(); }
			if ( 'GET' === $request->get_method() ) { return migration_draft(); }
			if ( strlen( $request->get_body() ) > 16384 ) { return new \WP_Error( 'sunrise_migration_size', 'Selection too large.', array( 'status' => 413 ) ); }
			return save_migration_draft( $request->get_json_params() );
		}, 'permission_callback' => __NAMESPACE__ . '\\permission' ) );
	}

	foreach ( array( 'snapshot', 'preview' ) as $operation ) {
		register_rest_route( 'sunrise/v1', '/transfers/' . $operation, array( 'methods' => 'POST', 'callback' => function ( $request ) use ( $operation ) {
			require_once __DIR__ . '/transfer-inventory.php';
			if ( strlen( $request->get_body() ) > 131072 ) { return new \WP_Error( 'sunrise_transfer_size', 'Transfer request too large.', array( 'status' => 413 ) ); }
			$data = $request->get_json_params();
			if ( 'preview' === $operation ) { return transfer_option_preview( $data ); }
			if ( ! is_array( $data ) || count( $data ) !== 1 || ! isset( $data['names'] ) ) { return new \WP_Error( 'sunrise_transfer_selection', 'Supply the selected setting names.', array( 'status' => 400 ) ); }
			return transfer_option_snapshot( $data['names'] );
		}, 'permission_callback' => __NAMESPACE__ . '\\permission' ) );
	}
	register_rest_route( 'sunrise/v1', '/transfers/inventory', array( 'methods' => 'GET', 'callback' => function ( $request ) {
		require_once __DIR__ . '/transfer-inventory.php';
		$after = $request->get_param( 'after' );
		if ( null !== $after && ( ! is_scalar( $after ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $after ) ) ) { return new \WP_Error( 'sunrise_transfer_cursor', 'Invalid inventory cursor.', array( 'status' => 400 ) ); }
		return transfer_inventory( $request->get_param( 'scope' ), null === $after ? 0 : (int) $after );
	}, 'permission_callback' => __NAMESPACE__ . '\\permission' ) );
	register_rest_route( 'sunrise/v1', '/agent/check-in', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\agent_sync', 'permission_callback' => __NAMESPACE__ . '\\permission' ) );
	register_rest_route( 'sunrise/v1', '/controller/refresh/(?P<id>[a-f0-9]{16})', array( 'methods' => 'POST', 'callback' => function ( $request ) {
		require_once __DIR__ . '/jobs.php';
		require_once __DIR__ . '/controller.php';
		return refresh_peer( $request['id'] );
	}, 'permission_callback' => __NAMESPACE__ . '\\permission' ) );
	register_rest_route( 'sunrise/v1', '/inventory', array(
		'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\inventory', 'permission_callback' => __NAMESPACE__ . '\\permission',
	) );
	register_rest_route( 'sunrise/v1', '/policy', array(
		array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\policy', 'permission_callback' => __NAMESPACE__ . '\\permission' ),
		array( 'methods' => 'POST', 'callback' => function ( $request ) {
			return save_policy( $request->get_json_params() );
		}, 'permission_callback' => __NAMESPACE__ . '\\permission' ),
	) );
	register_rest_route( 'sunrise/v1', '/jobs', array(
		'methods' => 'POST', 'callback' => function ( $request ) {
			require_once __DIR__ . '/jobs.php';
			return enqueue_job( $request->get_json_params() );
		}, 'permission_callback' => __NAMESPACE__ . '\\permission',
	) );
	foreach ( array( '' => 'GET', '/run' => 'POST', '/acknowledge' => 'POST' ) as $suffix => $method ) {
		register_rest_route( 'sunrise/v1', '/jobs/(?P<id>[a-f0-9-]{36})' . $suffix, array(
			'methods' => $method, 'callback' => function ( $request ) use ( $suffix ) {
				require_once __DIR__ . '/jobs.php';
				if ( '/acknowledge' === $suffix ) {
					return acknowledge_job( $request['id'] );
				}
				if ( '/run' === $suffix ) {
					$result = run_job( $request['id'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				return job_response( $request['id'] );
			}, 'permission_callback' => __NAMESPACE__ . '\\permission',
		) );
	}
	add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/sunrise/v1/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Authorization, Cookie' );
			$response->header( 'X-Robots-Tag', 'noindex' );
		}
		return $response;
	}, 10, 3 );
}

/** Read only the cached offers; never send package URLs or license credentials to the controller. */
function offer_summary( $offer ) {
	$offer = (object) $offer;
	return array(
		'version' => isset( $offer->new_version ) ? $offer->new_version : ( isset( $offer->current ) ? $offer->current : null ),
		'package_available' => ! empty( $offer->package ) || ! empty( $offer->packages->full ),
		'requires_wp' => isset( $offer->requires ) ? $offer->requires : null,
		'requires_php' => isset( $offer->requires_php ) ? $offer->requires_php : ( isset( $offer->php_version ) ? $offer->php_version : null ),
	);
}

function inventory() {
	global $wp_db_version;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$core    = get_site_transient( 'update_core' );
	$version = get_bloginfo( 'version' );
	$offers  = array();
	$auto_disabled = ( new \WP_Automatic_Updater() )->is_disabled();
	foreach ( isset( $core->updates ) ? $core->updates : array() as $offer ) {
		if ( 'upgrade' === $offer->response && version_compare( $offer->current, $version, '>' ) ) {
			$summary = offer_summary( $offer );
			$selected = empty( $offer->disable_autoupdate ) && \Core_Upgrader::should_update_to_version( $offer->current );
			$summary['auto_update_selected'] = ! $auto_disabled && (bool) apply_filters( 'auto_update_core', $selected, $offer );
			$offers[] = $summary;
		}
	}
	$core_known = isset( $core->version_checked, $core->updates ) && $version === $core->version_checked && ! empty( $core->updates );
	$data = array(
		'api_version' => 1, 'plugin_version' => VERSION, 'site_url' => home_url( '/' ),
		'environment' => wp_get_environment_type(), 'php_version' => PHP_VERSION,
		'policy' => policy(),
		'capabilities' => array(
			'update_plugins' => current_user_can( 'update_plugins' ),
			'update_themes' => current_user_can( 'update_themes' ),
			'update_core' => current_user_can( 'update_core' ),
			'file_modifications_allowed' => wp_is_file_mod_allowed( 'sunrise' ),
			'automatic_updater_disabled' => $auto_disabled,
			'core_auto_update_constant' => defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
		),
		'cron' => array(
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_native_checks' => array( 'core' => wp_next_scheduled( 'wp_version_check' ), 'plugins' => wp_next_scheduled( 'wp_update_plugins' ), 'themes' => wp_next_scheduled( 'wp_update_themes' ) ),
			'remote_job_runner' => true,
		),
		'core' => array( 'version' => $version, 'database_upgrade_required' => (int) get_option( 'db_version' ) !== (int) $wp_db_version, 'update_available' => $offers ? true : ( $core_known ? false : null ), 'updates' => $offers, 'last_check_attempt' => isset( $core->last_checked ) ? $core->last_checked : null ),
		'plugins' => array(), 'themes' => array(), 'must_use_plugins' => array(), 'dropins' => array(),
	);
	foreach ( array( 'plugins' => get_plugins(), 'themes' => wp_get_themes() ) as $type => $items ) {
		$cache = get_site_transient( 'update_' . $type );
		$singular = 'plugins' === $type ? 'plugin' : 'theme';
		$native = (array) get_site_option( 'auto_update_' . $type, array() );
		$enabled = wp_is_auto_update_enabled_for_type( $singular );
		foreach ( $items as $id => $item ) {
			$item_version = 'plugins' === $type ? $item['Version'] : $item->get( 'Version' );
			$offer = isset( $cache->response[ $id ] ) ? (object) $cache->response[ $id ] : null;
			$known = isset( $cache->no_update[ $id ], $cache->checked[ $id ] ) && $item_version === $cache->checked[ $id ];
			$filter_item = $offer ? clone $offer : (object) array( $singular => $id );
			$filter_item->{$singular} = $id;
			$selected = ( ! empty( $filter_item->autoupdate ) || ( $enabled && in_array( $id, $native, true ) ) ) && empty( $filter_item->disable_autoupdate );
			$data[ $type ][] = array(
				'id' => $id, 'name' => 'plugins' === $type ? $item['Name'] : $item->get( 'Name' ), 'version' => $item_version,
				'active' => 'plugins' === $type ? is_plugin_active( $id ) : get_stylesheet() === $id,
				'active_parent' => 'themes' === $type && get_template() === $id && get_stylesheet() !== $id,
				'update_available' => $offer && version_compare( $offer->new_version, $item_version, '>' ) ? true : ( $known ? false : null ),
				'update' => $offer ? offer_summary( $offer ) : null,
				'last_check_attempt' => isset( $cache->last_checked ) ? $cache->last_checked : null,
				'auto_update_selected' => $enabled && (bool) apply_filters( 'auto_update_' . $singular, $selected, $filter_item ),
				'policy' => item_policy( $type, $id ),
			);
		}
	}
	foreach ( array( 'must_use_plugins' => get_mu_plugins(), 'dropins' => get_dropins() ) as $type => $items ) {
		foreach ( $items as $id => $item ) {
			$data[ $type ][] = array( 'id' => $id, 'name' => $item['Name'], 'version' => $item['Version'], 'update_available' => null, 'managed_by_sunrise' => false );
		}
	}
	return $data;
}
