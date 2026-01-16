<?php
/**
 * Plugin Name:  WC Installment Payments
 * Description:  Adds installment payment management to WooCommerce.
 * Version:      1.0.0
 * Author:       Walid Houssaf
 * Text Domain:  wc-installment-payments
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constants
define( 'WCIP_VERSION', '1.0.0' );
define( 'WCIP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCIP_URL', plugin_dir_url( __FILE__ ) );
define( 'WCIP_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader PSR-4
spl_autoload_register( function ( $class ) {
    $prefix   = 'WcInstallmentPayments\\';
    $base_dir = WCIP_PATH . 'src/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $relative_path = str_replace( '\\', '/', $relative_class ) . '.php';
    $file = $base_dir . $relative_path;

    if ( file_exists( $file ) ) {
        require $file;
    }
});

// Activation / Deactivation (system level)
register_activation_hook(
    __FILE__,
    [ \WcInstallmentPayments\Core\Activator::class, 'activate' ]
);

register_deactivation_hook(
    __FILE__,
    [ \WcInstallmentPayments\Core\Deactivator::class, 'deactivate' ]
);

// Plugin launch
\WcInstallmentPayments\Plugin::get_instance();