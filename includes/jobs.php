<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

function job_error( $code, $message, $status = 409 ) {
	return new \WP_Error( 'sunrise_' . $code, $message, array( 'status' => $status ) );
}

function core_database_upgrade( $expected_version ) {
	if ( (int) get_option( 'db_version' ) === (int) $expected_version ) { return true; }
	$args = array( 'timeout' => 60 );
	if ( 'local' === wp_get_environment_type() ) { $args['sslverify'] = false; }
	$response = wp_remote_post( admin_url( 'upgrade.php?step=upgrade_db' ), $args );
	wp_cache_flush(); wp_cache_delete( 'alloptions', 'options' );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
		return job_error( 'database_upgrade_failed', __( 'Core files changed but WordPress could not run its database upgrade.', 'sunrise' ) );
	}
	return (int) get_option( 'db_version' ) === (int) $expected_version ? true : job_error( 'database_upgrade_pending', __( 'Core files changed but the database upgrade is still pending.', 'sunrise' ) );
}

function job_permission( $task, $remote = false ) {
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	if ( ! $remote && 'refresh' !== $task['action'] && get_option( 'sunrise_remote_job_fence' ) ) { return job_error( 'execution_interrupted', 'A central installation must finish or be reconciled before further updates.' ); }
	if ( ! current_user_can( 'manage_options' ) ) {
		return job_error( 'forbidden', __( 'The requesting user no longer has permission.', 'sunrise' ), 403 );
	}
	if ( 'refresh' === $task['action'] ) {
		return true;
	}
	$caps = 'auto_updates' === $task['action'] ? array( 'update_plugins', 'update_themes', 'update_core' ) : array( 'update_' . ( 'core' === $task['type'] ? 'core' : $task['type'] . 's' ) );
	foreach ( $caps as $cap ) {
		if ( ! current_user_can( $cap ) ) {
			return job_error( 'forbidden', __( 'The requesting user cannot perform this update.', 'sunrise' ), 403 );
		}
	}
	if ( ! wp_is_file_mod_allowed( 'sunrise' ) ) {
		return job_error( 'file_modifications_disabled', __( 'File modifications are disabled by the site.', 'sunrise' ), 403 );
	}
	return true;
}

function enqueue_job( $task ) {
	if ( ! is_array( $task ) || array_diff( array_keys( $task ), array( 'request_id', 'action', 'type', 'id', 'version' ) ) || empty( $task['request_id'] ) || ! is_string( $task['request_id'] ) || ! wp_is_uuid( $task['request_id'], 4 ) || ! isset( $task['action'] ) || ! in_array( $task['action'], array( 'refresh', 'update', 'auto_updates' ), true ) ) {
		return job_error( 'invalid_job', __( 'Supply a UUID v4 request_id and a supported action.', 'sunrise' ), 400 );
	}
	if ( 'update' === $task['action'] ) {
		if ( ! isset( $task['type'], $task['id'], $task['version'] ) || ! in_array( $task['type'], array( 'plugin', 'theme', 'core' ), true ) || ! is_string( $task['id'] ) || ! is_string( $task['version'] ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $task['version'] ) ) {
			return job_error( 'invalid_job', __( 'Specify an installed item and the exact offered version.', 'sunrise' ), 400 );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$items = 'plugin' === $task['type'] ? get_plugins() : ( 'theme' === $task['type'] ? wp_get_themes() : array( 'wordpress' => true ) );
		if ( ! isset( $items[ $task['id'] ] ) ) {
			return job_error( 'unknown_item', __( 'The requested item is not installed.', 'sunrise' ), 400 );
		}
	} elseif ( array_intersect( array_keys( $task ), array( 'type', 'id', 'version' ) ) ) {
		return job_error( 'invalid_job', __( 'Only update jobs accept an item and version.', 'sunrise' ), 400 );
	}
	$allowed = job_permission( $task );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_queue', 60 ) ) {
		return job_error( 'busy', __( 'The queue is busy. Retry with the same request_id.', 'sunrise' ) );
	}
	try {
		$id = strtolower( $task['request_id'] );
		$task['request_id'] = $id;
		$existing = get_option( 'sunrise_job_' . $id );
		if ( $existing ) {
			if ( $existing['task'] != $task || $existing['user_id'] !== get_current_user_id() ) {
				return job_error( 'request_conflict', __( 'This request_id already belongs to a different request.', 'sunrise' ) );
			}
			return job_response( $id );
		}
		// ponytail: one active job and 20 retained results per site; use a durable queue if throughput grows.
		$ids = get_option( 'sunrise_job_ids', array() );
		foreach ( $ids as $old_id ) {
			$old = get_option( 'sunrise_job_' . $old_id );
			if ( $old && in_array( $old['status'], array( 'queued', 'running', 'interrupted' ), true ) ) {
				return job_error( 'busy', __( 'An earlier job is queued, running, or needs inspection.', 'sunrise' ) );
			}
		}
		$job = array( 'id' => $id, 'task' => $task, 'user_id' => get_current_user_id(), 'application_password_uuid' => rest_get_authenticated_app_password(), 'status' => 'queued', 'created_at' => time(), 'started_at' => null, 'finished_at' => null, 'result' => null );
		if ( ! add_option( 'sunrise_job_' . $id, $job, '', false ) ) {
			return job_error( 'storage_failed', __( 'Could not store the job.', 'sunrise' ), 500 );
		}
		$ids[] = $id;
		$expired = count( $ids ) > 20 ? array_shift( $ids ) : null;
		if ( ! update_option( 'sunrise_job_ids', $ids, false ) ) {
			delete_option( 'sunrise_job_' . $id );
			return job_error( 'storage_failed', __( 'Could not store the job index.', 'sunrise' ), 500 );
		}
		if ( $expired ) {
			delete_option( 'sunrise_job_' . $expired );
			wp_clear_scheduled_hook( 'sunrise_run_job', array( $expired ) );
		}
		$scheduled = wp_schedule_single_event( time(), 'sunrise_run_job', array( $id ), true );
		if ( is_wp_error( $scheduled ) || ! $scheduled ) {
			// The authenticated /run route remains usable when a host disables scheduling.
			$job['cron_scheduled'] = false;
			update_option( 'sunrise_job_' . $id, $job, false );
		}
		return job_response( $id );
	} finally {
		\WP_Upgrader::release_lock( 'sunrise_queue' );
	}
}

function job_response( $id ) {
	$job = get_option( 'sunrise_job_' . $id );
	if ( ! $job ) {
		return job_error( 'job_not_found', __( 'Job not found or no longer retained.', 'sunrise' ), 404 );
	}
	unset( $job['application_password_uuid'] );
	$pending = in_array( $job['status'], array( 'queued', 'running' ), true );
	$response = new \WP_REST_Response( $job, $pending ? 202 : 200 );
	if ( $pending ) {
		$response->header( 'Retry-After', '15' );
	}
	return $response;
}

/** Explicit recovery only: a lost response is never permission to repeat a file operation. */
function acknowledge_job( $id ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_worker' ) ) {
		return job_error( 'busy', __( 'The worker lock is still active. Inspect the site and wait for it to expire.', 'sunrise' ) );
	}
	try {
		$job = get_option( 'sunrise_job_' . $id );
		if ( ! $job || ! in_array( $job['status'], array( 'interrupted', 'running' ), true ) || ( 'running' === $job['status'] && time() - $job['started_at'] < HOUR_IN_SECONDS ) ) {
			return job_error( 'not_interrupted', __( 'Only interrupted jobs or jobs running for over an hour can be acknowledged.', 'sunrise' ) );
		}
		$job['status'] = 'acknowledged';
		$job['acknowledged_by'] = get_current_user_id();
		$job['finished_at'] = time();
		if ( ! update_option( 'sunrise_job_' . $id, $job, false ) ) {
			return job_error( 'storage_failed', __( 'Could not acknowledge the job.', 'sunrise' ), 500 );
		}
		return job_response( $id );
	} finally {
		\WP_Upgrader::release_lock( 'sunrise_worker' );
	}
}

/** Called by either WP-Cron or the authenticated runner; both share the same lock and authorization. */
function run_job( $id ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_worker' ) ) {
		return job_error( 'busy', __( 'A Sunrise worker is already running.', 'sunrise' ) );
	}
	$previous_user = get_current_user_id();
	$auto_lock = false;
	$started = false;
	$buffer_level = ob_get_level();
	// wp_version_check() can start unrelated automatic updates when called during cron.
	$native_auto_priority = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
	if ( false !== $native_auto_priority ) {
		remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $native_auto_priority );
	}
	try {
		$job = get_option( 'sunrise_job_' . $id );
		if ( ! $job || 'queued' !== $job['status'] ) {
			return;
		}
		wp_set_current_user( $job['user_id'] );
		$result = job_permission( $job['task'] );
		// Transport was checked at enqueue; cron/CLI may not itself be an HTTPS request.
		if ( $job['application_password_uuid'] && ( ! apply_filters( 'wp_is_application_passwords_available', true ) || ! apply_filters( 'wp_is_application_passwords_available_for_user', true, wp_get_current_user() ) || ! \WP_Application_Passwords::get_user_application_password( $job['user_id'], $job['application_password_uuid'] ) ) ) {
			$result = job_error( 'credential_revoked', __( 'The connection credential is no longer available.', 'sunrise' ), 403 );
		}
		if ( ! is_wp_error( $result ) && 'update' === $job['task']['action'] ) {
			$auto_lock = \WP_Upgrader::create_lock( 'auto_updater' );
			if ( ! $auto_lock ) {
				wp_schedule_single_event( time() + 60, 'sunrise_run_job', array( $id ) );
				return job_error( 'busy', __( 'WordPress automatic updates are running; the job remains queued.', 'sunrise' ) );
			}
		}
		$job['status'] = 'running';
		$job['started_at'] = time();
		if ( ! update_option( 'sunrise_job_' . $id, $job, false ) ) {
			return job_error( 'storage_failed', __( 'Could not record job execution; nothing was started.', 'sunrise' ), 500 );
		}
		$started = true;
		register_shutdown_function( function () use ( $id ) {
			$unfinished = get_option( 'sunrise_job_' . $id );
			if ( $unfinished && 'running' === $unfinished['status'] ) {
				$unfinished['status'] = 'interrupted';
				$unfinished['finished_at'] = time();
				$unfinished['result'] = array( 'code' => 'execution_interrupted', 'message' => 'Inspect the site before acknowledging this job. It will not be retried automatically.' );
				update_option( 'sunrise_job_' . $id, $unfinished, false );
			}
		} );
		ob_start();
		if ( ! is_wp_error( $result ) ) {
			$result = execute_task( $job['task'] );
		}
		$job['status'] = is_wp_error( $result ) ? 'failed' : 'completed';
		$job['result'] = is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'message' => __( 'The operation failed. Inspect the error code and the site before retrying.', 'sunrise' ) ) : $result;
		$job['finished_at'] = time();
		if ( ! update_option( 'sunrise_job_' . $id, $job, false ) ) {
			return job_error( 'storage_failed', __( 'The outcome could not be recorded. Inspect the site before retrying.', 'sunrise' ), 500 );
		}
	} catch ( \Throwable $error ) {
		if ( $started ) {
			$job['status'] = 'interrupted';
			$job['finished_at'] = time();
			$job['result'] = array( 'code' => 'execution_interrupted', 'message' => __( 'Inspect the site and PHP error log before acknowledging this job.', 'sunrise' ) );
			update_option( 'sunrise_job_' . $id, $job, false );
		}
		return job_error( 'execution_interrupted', __( 'Execution was interrupted. Inspect the site before retrying.', 'sunrise' ), 500 );
	} finally {
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
		if ( $auto_lock ) {
			\WP_Upgrader::release_lock( 'auto_updater' );
		}
		\WP_Upgrader::release_lock( 'sunrise_worker' );
		wp_set_current_user( $previous_user );
		if ( false !== $native_auto_priority ) {
			add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $native_auto_priority );
		}
	}
}

function execute_task( $task ) {
	if ( 'refresh' === $task['action'] ) {
		$last = (int) get_option( 'sunrise_last_refresh', 0 );
		if ( time() - $last < 300 ) {
			return array( 'code' => 'refresh_throttled', 'next_refresh_at' => $last + 300 );
		}
		update_option( 'sunrise_last_refresh', time(), false );
		plugin_update_check( true );
		foreach ( array( 'core', 'plugins', 'themes' ) as $type ) {
			$cached = get_site_transient( 'update_' . $type );
			if ( is_object( $cached ) ) {
				$cached = clone $cached;
				$cached->last_checked = 0;
				set_site_transient( 'update_' . $type, $cached );
			}
		}
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
		return array( 'code' => 'refresh_attempted', 'message' => __( 'Update checks attempted. Provider caches and network failures can leave availability unknown or stale.', 'sunrise' ) );
	}
	if ( 'auto_updates' === $task['action'] ) {
		$updater = new \WP_Automatic_Updater();
		if ( $updater->is_disabled() ) {
			return job_error( 'automatic_updates_disabled', __( 'WordPress automatic updates are disabled.', 'sunrise' ) );
		}
		$results = null;
		$collect = function ( $updates ) use ( &$results ) {
			$results = array();
			foreach ( $updates as $type => $items ) {
				foreach ( $items as $item ) {
					$results[] = array( 'type' => $type, 'id' => isset( $item->item->{$type} ) ? $item->item->{$type} : ( 'core' === $type ? 'wordpress' : '' ), 'code' => is_wp_error( $item->result ) ? $item->result->get_error_code() : ( $item->result ? 'updated' : 'skipped' ) );
				}
			}
		};
		add_action( 'automatic_updates_complete', $collect );
		try {
			$updater->run();
		} finally {
			remove_action( 'automatic_updates_complete', $collect );
		}
		return array( 'code' => null === $results ? 'no_updates_attempted' : 'automatic_update_run_finished', 'items' => $results );
	}
	return install_update( $task );
}

function install_update( $task ) {
	global $wpdb;
	$type = $task['type'];
	$id = $task['id'];
	$offer = null;
	if ( 'core' === $type ) {
		wp_version_check();
		$cache = get_site_transient( 'update_core' );
		foreach ( isset( $cache->updates ) ? $cache->updates : array() as $candidate ) {
			if ( 'upgrade' === $candidate->response && $task['version'] === $candidate->current ) {
				$offer = $candidate;
				break;
			}
		}
		$current_version = get_bloginfo( 'version' );
	} else {
		if ( 'plugin' === $type ) {
			$items = get_plugins();
			$current_version = isset( $items[ $id ] ) ? $items[ $id ]['Version'] : '';
			wp_update_plugins();
		} else {
			$theme = wp_get_theme( $id );
			$current_version = $theme->exists() ? $theme->get( 'Version' ) : '';
			wp_update_themes();
		}
		$cache = get_site_transient( 'update_' . $type . 's' );
		$offer = isset( $cache->response[ $id ] ) ? (object) $cache->response[ $id ] : null;
		if ( $offer && ( ! isset( $offer->new_version ) || $task['version'] !== $offer->new_version ) ) {
			$offer = null;
		}
	}
	if ( $current_version === $task['version'] ) {
		if ( 'core' === $type ) {
			$core_files = ( static function () { require ABSPATH . WPINC . '/version.php'; return array( 'db_version' => $wp_db_version ); } )();
			$database = core_database_upgrade( $core_files['db_version'] );
			if ( is_wp_error( $database ) ) { return $database; }
		}
		return array( 'code' => 'already_current', 'version' => $current_version );
	}
	if ( ! $current_version || ! $offer || ! version_compare( $task['version'], $current_version, '>' ) ) {
		return job_error( 'offer_changed', __( 'The requested update is no longer offered. Refresh inventory.', 'sunrise' ) );
	}
	$required_php = 'core' === $type ? ( isset( $offer->php_version ) ? $offer->php_version : '' ) : ( isset( $offer->requires_php ) ? $offer->requires_php : '' );
	if ( $required_php && ! is_php_version_compatible( $required_php ) ) {
		return job_error( 'incompatible_php', __( 'This update requires a newer PHP version.', 'sunrise' ) );
	}
	if ( 'core' !== $type && ! empty( $offer->requires ) && ! is_wp_version_compatible( $offer->requires ) ) {
		return job_error( 'incompatible_wordpress', __( 'This update requires a newer WordPress version.', 'sunrise' ) );
	}
	if ( 'core' === $type && ! empty( $offer->mysql_version ) && ! ( file_exists( WP_CONTENT_DIR . '/db.php' ) && empty( $wpdb->is_mysql ) ) && version_compare( $wpdb->db_version(), $offer->mysql_version, '<' ) ) {
		return job_error( 'incompatible_database', __( 'This update requires a newer database server.', 'sunrise' ) );
	}
	$skin = new \WP_Ajax_Upgrader_Skin();
	if ( 'core' === $type ) {
		$result = ( new \Core_Upgrader( $skin ) )->upgrade( $offer, array( 'attempt_rollback' => true ) );
	} elseif ( 'plugin' === $type ) {
		$result = ( new \Plugin_Upgrader( $skin ) )->upgrade( $id );
	} else {
		$result = ( new \Theme_Upgrader( $skin ) )->upgrade( $id );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! $result ) {
		return $skin->get_errors()->has_errors() ? $skin->get_errors() : job_error( 'update_failed', __( 'The updater could not complete. Check filesystem access and the update source.', 'sunrise' ) );
	}
	if ( 'core' === $type ) {
		// Core globals still describe the old version until the next request.
		$installed_core = ( static function () {
			require ABSPATH . WPINC . '/version.php';
			return array( 'version' => $wp_version, 'db_version' => $wp_db_version );
		} )();
		$installed_version = $installed_core['version'];
		$database = core_database_upgrade( $installed_core['db_version'] );
		if ( is_wp_error( $database ) ) { return $database; }
	} elseif ( 'plugin' === $type ) {
		wp_clean_plugins_cache( false );
		$plugins = get_plugins();
		$installed_version = isset( $plugins[ $id ] ) ? $plugins[ $id ]['Version'] : '';
	} else {
		wp_clean_themes_cache( false );
		$installed_version = wp_get_theme( $id )->get( 'Version' );
	}
	if ( $task['version'] !== $installed_version ) {
		return job_error( 'version_mismatch', __( 'The installed version differs from the requested version. Inspect the site.', 'sunrise' ) );
	}
	return array( 'code' => 'updated', 'version' => $installed_version );
}
