<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Custom tables to delete
$tables = [
    $wpdb->prefix . 'wcip_installment_plans',
    $wpdb->prefix . 'wcip_installment_payments',
];

// Deletion
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// Delete plugin-related options
delete_option( 'wcip_plugin_version' );