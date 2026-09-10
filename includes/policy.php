<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

/** Compare decimal strings without depending on PHP's integer width. */
function agent_choose_policy( $best, $mode, $decision, $identity = null ) {
	$candidate = array( 'mode' => $mode, 'decision' => $decision, 'identity' => $identity );
	if ( ! $best ) { return $candidate; }
	$left = $decision['revision']; $right = $best['decision']['revision'];
	$order = $decision['specificity'] - $best['decision']['specificity'];
	if ( 0 === $order ) { $order = strlen( $left ) - strlen( $right ); if ( 0 === $order ) { $order = strcmp( $left, $right ); } }
	if ( $order > 0 ) { return $candidate; }
	if ( 0 === $order && $mode !== $best['mode'] ) { $best['mode'] = 'off'; }
	return $best;
}

/** Installation-wide result, independent of whoever is logged in. No cross-network requests or credentials. */
function agent_effective_policy() {
	$states = agent_states();
	if ( ! $states ) { return null; }
	$off = array( 'policy' => array( 'site' => 'off', 'core' => 'off', 'plugins' => array(), 'themes' => array() ), 'identities' => array() );
	if ( get_option( 'sunrise_remote_job_fence' ) ) { return $off; }
	$states = array_filter( $states, function ( $state ) { return empty( $state['revoked'] ); } );
	if ( ! $states ) { return null; }
	$documents = array();
	foreach ( $states as $state ) {
		if ( ! agent_owner_valid( $state ) || ! empty( $state['revoked'] ) ) { continue; }
		if ( ! empty( $state['paused'] ) || ! agent_service_matches( $state ) || $state['url'] !== untrailingslashit( site_url() ) ) { return $off; }
		if ( ! empty( $state['site_id'] ) && ! empty( $state['applied'] ) ) { $documents[] = $state['applied']['document']; }
	}
	if ( ! $documents ) { return $off; }
	// Legacy receipt remains usable until its next check-in. Never merge an unordered receipt.
	foreach ( $documents as $doc ) {
		if ( empty( $doc['decisions'] ) ) {
			if ( 1 !== count( $documents ) ) { return $off; }
			$managed = $doc['policy']; $managed['category_defaults'] = $doc['category_defaults'];
			return array( 'policy' => $managed, 'identities' => isset( $doc['identities'] ) ? $doc['identities'] : array() );
		}
	}
	static $cached_documents = null, $cached_result = null;
	if ( $documents === $cached_documents ) { return $cached_result; }
	$policy = array( 'site' => 'inherit', 'core' => 'inherit', 'plugins' => array(), 'themes' => array(), 'category_defaults' => array() );
	$core = null;
	foreach ( $documents as $doc ) { $core = agent_choose_policy( $core, $doc['policy']['core'], $doc['decisions']['core'] ); }
	$policy['core'] = $core['mode'];
	$identities = array();
	foreach ( array( 'plugins', 'themes' ) as $type ) {
		$default = null; $ids = array();
		foreach ( $documents as $doc ) {
			$default = agent_choose_policy( $default, $doc['category_defaults'][ $type ], $doc['decisions']['category_defaults'][ $type ] );
			$ids += array_fill_keys( array_keys( $doc['policy'][ $type ] ), true );
		}
		$policy['category_defaults'][ $type ] = $default['mode'];
		foreach ( $ids as $id => $unused ) {
			$best = $default;
			foreach ( $documents as $doc ) {
				if ( ! isset( $doc['policy'][ $type ][ $id ] ) ) { continue; }
				$identity = isset( $doc['identities'][ $type ][ $id ] ) ? $doc['identities'][ $type ][ $id ] : null;
				$best = agent_choose_policy( $best, $doc['policy'][ $type ][ $id ], $doc['decisions'][ $type ][ $id ], $identity );
				// Keep identity guards even when an item inherits the already-selected category rule.
				if ( $identity && $best['decision'] === $doc['decisions'][ $type ][ $id ] ) { $best['identity'] = $identity; }
			}
			$policy[ $type ][ $id ] = $best['mode'];
			if ( $best['identity'] ) { $identities[ $type ][ $id ] = $best['identity']; }
		}
	}
	$cached_documents = $documents;
	$cached_result = array( 'policy' => $policy, 'identities' => $identities );
	return $cached_result;
}

function policy() {
	if ( is_wp_error( installation_guard() ) ) { return array( 'site' => 'off', 'core' => 'off', 'plugins' => array(), 'themes' => array() ); }
	$effective = agent_effective_policy();
	if ( $effective ) { return $effective['policy']; }
	return wp_parse_args( get_option( 'sunrise_policy', array() ), array( 'site' => 'inherit', 'core' => 'inherit', 'plugins' => array(), 'themes' => array() ) );
}

function item_policy( $type, $id ) {
	$policy = policy();
	$effective = agent_effective_policy();
	$identity = isset( $effective['identities'][ $type ][ $id ] ) ? $effective['identities'][ $type ][ $id ] : null;
	if ( $identity && ( 'matched' !== $identity['status'] || empty( $identity['headers'] ) || $identity['headers'] != agent_item_identity( $type, $id ) ) ) { return 'off'; }
	return isset( $policy[ $type ][ $id ] ) ? $policy[ $type ][ $id ] : ( isset( $policy['category_defaults'][ $type ] ) ? $policy['category_defaults'][ $type ] : $policy['site'] );
}

/** A receipt is not proof of application. Report only a generic reason, never another network's identity. */
function agent_policy_conflict( $state ) {
	if ( ! empty( $state['paused'] ) || is_wp_error( installation_guard() ) ) { return true; }
	$effective = policy(); $doc = $state['applied']['document'];
	if ( $effective['core'] !== $doc['policy']['core'] ) { return true; }
	foreach ( array( 'plugins', 'themes' ) as $type ) {
		if ( ! isset( $effective['category_defaults'][ $type ] ) || $effective['category_defaults'][ $type ] !== $doc['category_defaults'][ $type ] ) { return true; }
		foreach ( $doc['policy'][ $type ] as $id => $mode ) { if ( item_policy( $type, $id ) !== $mode ) { return true; } }
	}
	return false;
}

function filter_item( $update, $item, $type ) {
	$id   = isset( $item->{$type} ) ? $item->{$type} : '';
	$mode = item_policy( $type . 's', $id );
	if ( 'inherit' === $mode ) {
		return $update;
	}
	// Never turn a provider-disabled offer or a disabled updater back on.
	if ( ! empty( $item->disable_autoupdate ) || ! wp_is_auto_update_enabled_for_type( $type ) ) {
		return false;
	}
	return 'on' === $mode;
}

add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return filter_item( $update, $item, 'plugin' );
}, 5, 2 );
add_filter( 'auto_update_theme', function ( $update, $item ) {
	return filter_item( $update, $item, 'theme' );
}, 5, 2 );

function filter_core( $update, $kind ) {
	$policy = policy();
	$mode   = $policy['core'];
	if ( 'inherit' === $mode ) {
		$mode = 'on' === $policy['site'] ? 'all' : $policy['site'];
	}
	if ( 'inherit' === $mode ) {
		return $update;
	}
	if ( defined( 'WP_AUTO_UPDATE_CORE' ) && ( false === WP_AUTO_UPDATE_CORE || ( 'minor' === WP_AUTO_UPDATE_CORE && 'minor' !== $kind ) ) ) {
		return false;
	}
	return 'off' !== $mode && 'dev' !== $kind && ( 'all' === $mode || 'minor' === $kind );
}

foreach ( array( 'minor', 'major', 'dev' ) as $kind ) {
	add_filter( 'allow_' . $kind . '_auto_core_updates', function ( $update ) use ( $kind ) {
		return filter_core( $update, $kind );
	}, 5 );
}

/** Validate the entire patch before changing anything. Item maps merge; inherit removes an override. */
function save_policy( $patch ) {
	$identity = installation_guard(); if ( is_wp_error( $identity ) ) { return $identity; }
	if ( agent_states() ) {
		return new \WP_Error( 'sunrise_managed_policy', __( 'Use the central service to change managed policy, or pause automatic updates locally.', 'sunrise' ), array( 'status' => 409 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'sunrise_policy', 60 ) ) {
		return new \WP_Error( 'sunrise_busy', __( 'Another policy change is being saved. Please retry.', 'sunrise' ), array( 'status' => 409 ) );
	}
	try {
		return write_policy_patch( $patch );
	} finally {
		\WP_Upgrader::release_lock( 'sunrise_policy' );
	}
}

function write_policy_patch( $patch ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! is_array( $patch ) || array_diff( array_keys( $patch ), array( 'site', 'core', 'plugins', 'themes' ) ) ) {
		return new \WP_Error( 'sunrise_invalid_policy', __( 'Unknown policy field.', 'sunrise' ), array( 'status' => 400 ) );
	}
	$next = policy();
	foreach ( $patch as $field => $value ) {
		$caps = 'site' === $field ? array( 'update_core', 'update_plugins', 'update_themes' ) : array( 'update_' . $field );
		foreach ( $caps as $cap ) {
			if ( ! current_user_can( $cap ) || ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'sunrise_forbidden', __( 'You cannot change this update policy.', 'sunrise' ), array( 'status' => 403 ) );
			}
		}
		if ( in_array( $field, array( 'site', 'core' ), true ) ) {
			$allowed = 'core' === $field ? array( 'inherit', 'off', 'minor', 'all' ) : array( 'inherit', 'on', 'off' );
			if ( ! in_array( $value, $allowed, true ) ) {
				return new \WP_Error( 'sunrise_invalid_policy', __( 'Invalid update policy.', 'sunrise' ), array( 'status' => 400 ) );
			}
			$next[ $field ] = $value;
			continue;
		}
		$installed = 'plugins' === $field ? get_plugins() : wp_get_themes();
		if ( ! is_array( $value ) || count( $value ) > 1000 ) {
			return new \WP_Error( 'sunrise_invalid_policy', __( 'Expected a map of installed items to policies.', 'sunrise' ), array( 'status' => 400 ) );
		}
		foreach ( $value as $id => $mode ) {
			if ( ! in_array( $mode, array( 'inherit', 'on', 'off' ), true ) || ( 'inherit' !== $mode && ! isset( $installed[ $id ] ) ) ) {
				return new \WP_Error( 'sunrise_invalid_policy', __( 'Unknown item or invalid policy.', 'sunrise' ), array( 'status' => 400 ) );
			}
			if ( 'inherit' === $mode ) {
				unset( $next[ $field ][ $id ] );
			} else {
				$next[ $field ][ $id ] = $mode;
			}
		}
	}
	if ( $next !== policy() && ! update_option( 'sunrise_policy', $next, false ) ) {
		return new \WP_Error( 'sunrise_save_failed', __( 'Could not save the update policy.', 'sunrise' ), array( 'status' => 500 ) );
	}
	return $next;
}
