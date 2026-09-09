<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

/** Count each plugin by its installed ID, not its potentially ambiguous display name. */
function aggregate_inventory( $snapshots ) {
	$plugins = array();
	$totals = array( 'sites' => count( $snapshots ), 'plugin_updates' => 0, 'theme_updates' => 0, 'core_updates' => 0, 'missing_sites' => 0 );
	foreach ( $snapshots as $site_id => $snapshot ) {
		if ( empty( $snapshot['inventory'] ) ) {
			++$totals['missing_sites'];
			continue;
		}
		$data = $snapshot['inventory'];
		$totals['core_updates'] += true === $data['core']['update_available'] ? 1 : 0;
		foreach ( $data['themes'] as $theme ) {
			$totals['theme_updates'] += true === $theme['update_available'] ? 1 : 0;
		}
		foreach ( $data['plugins'] as $plugin ) {
			$id = $plugin['id'];
			if ( ! isset( $plugins[ $id ] ) ) {
				$plugins[ $id ] = array( 'id' => $id, 'name' => $plugin['name'], 'installed' => 0, 'updates' => 0, 'unknown' => 0, 'sites' => array() );
			}
			++$plugins[ $id ]['installed'];
			if ( true === $plugin['update_available'] ) {
				++$plugins[ $id ]['updates'];
				++$totals['plugin_updates'];
				$plugins[ $id ]['sites'][] = $site_id;
			} elseif ( null === $plugin['update_available'] ) {
				++$plugins[ $id ]['unknown'];
			}
		}
	}
	usort( $plugins, function ( $a, $b ) {
		return ( $b['updates'] <=> $a['updates'] ) ?: strcasecmp( $a['name'], $b['name'] );
	} );
	return array( 'totals' => $totals, 'plugins' => $plugins );
}

function network_page() {
	$sites = array_merge( array( 'local' => array( 'url' => home_url( '/' ) ) ), connections() );
	$snapshots = array( 'local' => array( 'inventory' => inventory(), 'checked_at' => time() ) );
	$options = get_options( array_map( function ( $id ) { return snapshot_key( $id ); }, array_keys( connections() ) ) );
	foreach ( connections() as $id => $connection ) {
		$snapshots[ $id ] = ! empty( $options[ snapshot_key( $id ) ] ) ? $options[ snapshot_key( $id ) ] : array();
	}
	$network = aggregate_inventory( $snapshots );
	echo '<h2>' . esc_html__( 'Network overview', 'sunrise' ) . '</h2><p>' . esc_html__( 'Known updates from the latest inventory for each site. Missing or stale information is not a clean bill of health.', 'sunrise' ) . '</p><div class="sunrise-totals">';
	foreach ( array( 'sites' => __( 'Sites', 'sunrise' ), 'plugin_updates' => __( 'Plugin updates', 'sunrise' ), 'theme_updates' => __( 'Theme updates', 'sunrise' ), 'core_updates' => __( 'Core updates', 'sunrise' ) ) as $key => $label ) {
		echo '<div><strong>' . esc_html( $network['totals'][ $key ] ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}
	echo '</div>';
	if ( connections() ) {
		echo '<p><button type="button" class="button button-primary" id="sunrise-refresh-network">' . esc_html__( 'Refresh network inventory', 'sunrise' ) . '</button> <span id="sunrise-refresh-status" role="status" aria-live="polite"></span></p>';
	}
	if ( $network['totals']['missing_sites'] ) {
		echo '<p>' . esc_html( sprintf( __( '%d sites have no cached inventory yet and are not included in update totals.', 'sunrise' ), $network['totals']['missing_sites'] ) ) . '</p>';
	}
	echo '<h2>' . esc_html__( 'Plugin updates across the network', 'sunrise' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Plugin', 'sunrise' ) . '</th><th>' . esc_html__( 'Sites with updates', 'sunrise' ) . '</th><th>' . esc_html__( 'Affected sites', 'sunrise' ) . '</th></tr></thead><tbody>';
	foreach ( $network['plugins'] as $plugin ) {
		echo '<tr><td><strong>' . esc_html( $plugin['name'] ) . '</strong><br><small>' . esc_html( $plugin['id'] ) . '</small></td><td><strong>' . esc_html( $plugin['updates'] ) . '</strong> / ' . esc_html( $plugin['installed'] ) . '<br><small>' . esc_html( sprintf( __( '%d unknown', 'sunrise' ), $plugin['unknown'] ) ) . '</small></td><td>';
		foreach ( $plugin['sites'] as $id ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'sunrise', 'site' => $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( wp_parse_url( $sites[ $id ]['url'], PHP_URL_HOST ) ) . '</a><br>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table><h2>' . esc_html__( 'Sites', 'sunrise' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Site', 'sunrise' ) . '</th><th>' . esc_html__( 'Known available updates', 'sunrise' ) . '</th><th>' . esc_html__( 'Inventory status', 'sunrise' ) . '</th></tr></thead><tbody>';
	foreach ( $sites as $id => $site ) {
		$snapshot = $snapshots[ $id ];
		echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => 'sunrise', 'site' => $id ), admin_url( 'admin.php' ) ) ) . '"><strong>' . esc_html( $site['url'] ) . '</strong></a></td><td>';
		if ( ! empty( $snapshot['inventory'] ) ) {
			$counts = aggregate_inventory( array( $id => $snapshot ) )['totals'];
			echo esc_html( sprintf( __( '%1$d plugins · %2$d themes · %3$d core', 'sunrise' ), $counts['plugin_updates'], $counts['theme_updates'], $counts['core_updates'] ) );
		} else {
			echo esc_html__( 'Unknown', 'sunrise' );
		}
		echo '</td><td>';
		if ( ! empty( $snapshot['checked_at'] ) ) {
			echo esc_html( sprintf( __( 'Synced %s ago', 'sunrise' ), human_time_diff( $snapshot['checked_at'] ) ) );
			if ( time() - $snapshot['checked_at'] > HOUR_IN_SECONDS ) {
				echo '<br><strong>' . esc_html__( 'Stale', 'sunrise' ) . '</strong>';
			}
		} else {
			echo esc_html__( 'Not synced yet', 'sunrise' );
		}
		if ( ! empty( $snapshot['error'] ) ) {
			echo '<br><strong>' . esc_html__( 'Unavailable — showing last known information', 'sunrise' ) . '</strong><details><summary>' . esc_html__( 'Connection details', 'sunrise' ) . '</summary>' . esc_html( $snapshot['error'] ) . '</details>';
		}
		if ( 'local' !== $id ) {
			form_start( $id, 'refresh_peer' );
			submit_button( __( 'Refresh', 'sunrise' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}
