<?php
/** Disposable local metadata check; no network traffic. Restores the site's name and icon. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
$name = get_option( 'blogname' ); $icon = get_option( 'site_icon', 0 ); $admin_email = get_option( 'admin_email' ); $id = 0; $file = null;
try {
 update_option( 'blogname', 'Sunrise &amp; profile fixture' ); update_option( 'site_icon', 0 );
 $profile = Sunrise\agent_site_profile();
 if ( 'Sunrise & profile fixture' !== $profile['name'] || null !== $profile['icon'] ) { throw new RuntimeException( 'Name decoding or missing-icon fallback failed' ); }
 update_option( 'admin_email', 'site-admin@example.invalid' );
 if ( isset( Sunrise\agent_site_profile()['admin_email'] ) ) { throw new RuntimeException( 'Emails must not be sent before negotiation' ); }
 $contacts = Sunrise\agent_site_profile( true );
 if ( 'site-admin@example.invalid' !== $contacts['admin_email'] || wp_get_current_user()->user_email !== $contacts['connection_email'] ) { throw new RuntimeException( 'Site and connection-owner emails must remain distinct' ); }
 update_option( 'admin_email', 'changed-admin@example.invalid' );
 if ( 'changed-admin@example.invalid' !== Sunrise\agent_site_profile( true )['admin_email'] ) { throw new RuntimeException( 'Changed administration email was not refreshed' ); }
 update_option( 'admin_email', '' );
 if ( null !== Sunrise\agent_site_profile( true )['admin_email'] ) { throw new RuntimeException( 'Missing email must not become an invented contact' ); }
 $upload = wp_upload_bits( 'sunrise-profile-fixture-' . wp_generate_uuid4() . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jHj8AAAAASUVORK5CYII=' ) );
 if ( $upload['error'] ) { throw new RuntimeException( 'Fixture upload failed' ); } $file = $upload['file'];
 $id = wp_insert_attachment( array( 'post_title' => 'Sunrise profile fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file ); update_option( 'site_icon', $id );
 $profile = Sunrise\agent_site_profile();
 if ( 0 !== strpos( $profile['icon'], 'data:image/png;base64,' ) || $profile !== Sunrise\agent_site_profile() ) { throw new RuntimeException( 'Stable local icon capture failed' ); }
 file_put_contents( $file, str_repeat( 'x', 17000 ) ); clearstatcache();
 if ( null !== Sunrise\agent_site_profile()['icon'] ) { throw new RuntimeException( 'Oversized icon was not rejected' ); }
 WP_CLI::success( 'Site names, negotiated contact emails, changed/missing emails, stable profiles and local icon fallbacks passed' );
} finally { update_option( 'blogname', $name ); update_option( 'site_icon', $icon ); update_option( 'admin_email', $admin_email ); if ( $id ) { wp_delete_attachment( $id, true ); } elseif ( $file ) { unlink( $file ); } }
