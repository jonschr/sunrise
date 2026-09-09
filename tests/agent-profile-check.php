<?php
/** Disposable local metadata check; no network traffic. Restores the site's name and icon. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SUNRISE_TEST_SITE' ) || ! SUNRISE_TEST_SITE || 'local' !== wp_get_environment_type() ) { WP_CLI::error( 'Disposable local fixture required.' ); }
$name = get_option( 'blogname' ); $icon = get_option( 'site_icon', 0 ); $id = 0; $file = null;
try {
 update_option( 'blogname', 'Sunrise &amp; profile fixture' ); update_option( 'site_icon', 0 );
 $profile = Sunrise\agent_site_profile();
 if ( 'Sunrise & profile fixture' !== $profile['name'] || null !== $profile['icon'] ) { throw new RuntimeException( 'Name decoding or missing-icon fallback failed' ); }
 $upload = wp_upload_bits( 'sunrise-profile-fixture-' . wp_generate_uuid4() . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jHj8AAAAASUVORK5CYII=' ) );
 if ( $upload['error'] ) { throw new RuntimeException( 'Fixture upload failed' ); } $file = $upload['file'];
 $id = wp_insert_attachment( array( 'post_title' => 'Sunrise profile fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file ); update_option( 'site_icon', $id );
 $profile = Sunrise\agent_site_profile();
 if ( 0 !== strpos( $profile['icon'], 'data:image/png;base64,' ) || $profile !== Sunrise\agent_site_profile() ) { throw new RuntimeException( 'Stable local icon capture failed' ); }
 file_put_contents( $file, str_repeat( 'x', 17000 ) ); clearstatcache();
 if ( null !== Sunrise\agent_site_profile()['icon'] ) { throw new RuntimeException( 'Oversized icon was not rejected' ); }
 WP_CLI::success( 'Site names, local icon capture, stable profiles, missing and oversized icon fallbacks passed' );
} finally { update_option( 'blogname', $name ); update_option( 'site_icon', $icon ); if ( $id ) { wp_delete_attachment( $id, true ); } elseif ( $file ) { unlink( $file ); } }
