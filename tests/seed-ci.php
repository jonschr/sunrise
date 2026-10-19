<?php
/** Seed only a disposable CI fixture. Credentials never leave this local WordPress database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! current_user_can( 'manage_options' ) || Sunrise\agent_states() ) { throw new RuntimeException( 'Empty disposable local fixture required.' ); }
require_once dirname( __DIR__ ) . '/includes/controller.php';
$owner = get_current_user_id(); $identity = wp_generate_uuid4();
Sunrise\installation_write_anchor( $identity );
update_option( 'sunrise_installation', array_merge( array( 'id' => $identity, 'review' => false, 'anchor_required' => true ), Sunrise\installation_markers( $identity ) ), false );
$secret = bin2hex( random_bytes( 32 ) );
$state = array( 'user_id' => $owner, 'url' => Sunrise\installation_site_url(), 'control_url' => Sunrise\agent_url(), 'enrollment_id' => wp_generate_uuid4(), 'site_id' => wp_generate_uuid4(), 'account_id' => wp_generate_uuid4(), 'network_id' => wp_generate_uuid4(), 'sequence' => 0, 'generation' => 1, 'secret' => Sunrise\credential( $secret, 'agent|' . Sunrise\installation_site_url() . '|' . $owner ), 'digest' => hash( 'sha256', $secret ), 'paused' => false, 'last_success' => time() );
$document = array( 'schema_version' => 2, 'generation' => 1, 'policy' => array( 'site' => 'inherit', 'core' => 'off', 'plugins' => array(), 'themes' => array() ), 'category_defaults' => array( 'plugins' => 'off', 'themes' => 'off' ) );
$decision = array( 'revision' => '1', 'specificity' => 1 );
$document['decisions'] = array( 'core' => $decision, 'plugins' => array(), 'themes' => array(), 'category_defaults' => array( 'plugins' => $decision, 'themes' => $decision ) );
$json = wp_json_encode( $document ); $applied = Sunrise\agent_accept_policy( array( 'generation' => 1, 'hash' => hash( 'sha256', $json ), 'document_json' => $json ), $state );
if ( is_wp_error( $applied ) ) { throw new RuntimeException( 'Invalid fixture policy.' ); }
$state['applied'] = $applied;
if ( ! Sunrise\agent_store( $state ) ) { throw new RuntimeException( 'Could not seed CI connection.' ); }
WP_CLI::success( 'Disposable CI connection seeded without enrollment or remote requests.' );
