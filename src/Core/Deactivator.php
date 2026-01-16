<?php

namespace WcInstallmentPayments\Core;

class Deactivator {

    /**
     * Plugin deactivation
     * We don't delete anything, just placeholder
     */
    public static function deactivate(): void {
        // If needed: clear caches, cron jobs, temporary hooks
        wp_clear_scheduled_hook( 'wcip_hourly_process' );
    }
}