<?php
/**
 * Direct scheduler trigger endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
    $dir = __DIR__;
    for ( $i = 0; $i < 8; $i++ ) {
        $wp_load = $dir . '/wp-load.php';
        if ( file_exists( $wp_load ) ) {
            require_once $wp_load;
            break;
        }
        $dir = dirname( $dir );
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    if ( function_exists( 'status_header' ) ) {
        status_header( 500 );
    } else {
        http_response_code( 500 );
    }
    echo 'WordPress bootstrap failed.';
    exit;
}

if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
    if ( function_exists( 'status_header' ) ) {
        status_header( 403 );
    } else {
        http_response_code( 403 );
    }
    echo 'Forbidden.';
    exit;
}

$nonce = isset( $_GET['wcip_nonce'] )
    ? sanitize_text_field( wp_unslash( $_GET['wcip_nonce'] ) )
    : '';

if ( ! wp_verify_nonce( $nonce, 'wcip_manual_trigger_action' ) ) {
    if ( function_exists( 'status_header' ) ) {
        status_header( 403 );
    } else {
        http_response_code( 403 );
    }
    echo 'Invalid nonce.';
    exit;
}

do_action( 'wcip_manual_trigger' );

$redirect_url = add_query_arg( 'wcip_triggered', '1', admin_url( 'admin.php?page=wcip-plans' ) );
wp_safe_redirect( $redirect_url );
exit;
