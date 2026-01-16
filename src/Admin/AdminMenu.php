<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Admin;

class AdminMenu {

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_manual_trigger' ] );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Installment Payments',
            'Payment Plans',
            'manage_woocommerce',
            'wcip-plans',
            [ $this, 'render_list_page' ]
        );
    }

    /**
     * HTML page rendering (Router)
     */
    public function render_list_page() {
        $action = $_GET['action'] ?? 'list';
        $id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        // Route: View Details
        if ( $action === 'view' && $id > 0 ) {
            $view = new PlanDetailView();
            $view->render( $id );
            return;
        }

        // Route: List Table
        $table = new PlanListTable();
        $table->prepare_items();

        ?>
        <div class="wrap wcip-plans-table">
            <h1 class="wp-heading-inline">Payment Plans</h1>
            
            <form method="post" class="wcip-header-action">
                <button type="submit" name="wcip_manual_trigger" class="page-title-action">
                    ⚡ Run Scheduler
                </button>
            </form>
            
            <hr class="wp-header-end">

            <form id="wcip-plans-filter" method="get">
                <input type="hidden" name="page" value="wcip-plans" />
                
                <?php 
                // Uncomment below if we add search functionality to our Table class later
                // $table->search_box( 'Search', 'wcip-search' ); 
                ?>

                <?php $table->display(); ?>
            </form>
        </div>

        <style>
            .wcip-header-action {
                display: inline-block;
                position: relative;
                top: -3px;
                margin-left: 4px;
            }
            button.page-title-action {
                cursor: pointer;
            }
        </style>
        <?php
    }
    
    public function handle_manual_trigger() {
        if ( isset( $_POST['wcip_manual_trigger'] ) && current_user_can( 'manage_woocommerce' ) ) {
            // Log for debugging
            error_log( 'WCIP Debug: Manual scheduler trigger from admin' );
            
            // Execute the action
            do_action( 'wcip_manual_trigger' );
            
            // Add native admin notice
            add_action( 'admin_notices', function() {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success:</strong> The scheduler has been manually triggered. Check logs for details.</p>
                </div>
                <?php
            });
        }
    }
}