<?php

namespace WcInstallmentPayments\Core;

class Activator {

    /**
     * Plugin activation
     */
    public static function activate(): void {
        error_log( 'WCIP Debug: Plugin activation started' );
        global $wpdb;

        // Current plugin version (for migration)
        $current_version = defined( 'WCIP_VERSION' ) ? WCIP_VERSION : '1.0.0';

        // Table for installment plans
        $table_plans = $wpdb->prefix . 'wcip_installment_plans';
        // Table for payments
        $table_payments = $wpdb->prefix . 'wcip_installment_payments';

        error_log( "WCIP Debug: Creating tables: $table_plans, $table_payments" );

        // Charset collation
        $charset_collate = $wpdb->get_charset_collate();

        // We use dbDelta to create tables properly
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_plans = "CREATE TABLE IF NOT EXISTS $table_plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            installments_count INT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX(order_id),
            INDEX(customer_id)
        ) $charset_collate;";

        $sql_payments = "CREATE TABLE IF NOT EXISTS $table_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT UNSIGNED NOT NULL,
            stripe_payment_intent_id VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            due_date DATETIME NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            PRIMARY KEY(id),
            INDEX(plan_id),
            INDEX(status)
        ) $charset_collate;";

        dbDelta( $sql_plans );
        dbDelta( $sql_payments );

        error_log( 'WCIP Debug: Tables created, checking if they exist' );
        
        // Check if tables were created
        $plans_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_plans'" ) === $table_plans;
        $payments_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_payments'" ) === $table_payments;
        
        error_log( "WCIP Debug: Plans table exists: " . ( $plans_exists ? 'YES' : 'NO' ) );
        error_log( "WCIP Debug: Payments table exists: " . ( $payments_exists ? 'YES' : 'NO' ) );

        // Save current version for future migrations
        update_option( 'wcip_plugin_version', $current_version );
        
        error_log( 'WCIP Debug: Plugin activation completed' );
    }
}