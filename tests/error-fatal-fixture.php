<?php
/** Intentionally terminates a disposable CLI request; the parent runner always restores persisted backup state. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) || get_option( 'sunrise_error_fixture_backup', null ) !== null ) { WP_CLI::error( 'Clean disposable local administrator fixture required.' ); }
$backup = array( 'states' => Sunrise\agent_states(), 'groups' => get_option( 'sunrise_error_groups', null ), 'lock' => get_option( 'sunrise_error_capture_lock', null ) );
if ( ! add_option( 'sunrise_error_fixture_backup', $backup, '', false ) ) { WP_CLI::error( 'Could not preserve fixture state.' ); }
$state = Sunrise\agent_state();$state['errors_enabled'] = true;Sunrise\agent_store( $state );delete_option( 'sunrise_error_groups' );delete_option( 'sunrise_error_capture_lock' );
trigger_error( 'private-fixture-payload', E_USER_ERROR );
