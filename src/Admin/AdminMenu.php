<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Admin;

class AdminMenu {

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_manual_trigger' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
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

        add_submenu_page(
            'woocommerce',
            'Installment Settings',
            'Installment Settings',
            'manage_woocommerce',
            'wcip-settings',
            [ $this, 'render_settings_page' ]
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
        $direct_trigger_url = add_query_arg(
            'wcip_nonce',
            wp_create_nonce( 'wcip_manual_trigger_action' ),
            WCIP_URL . 'trigger-scheduler.php'
        );

        ?>
        <div class="wrap wcip-plans-table">
            <h1 class="wp-heading-inline">Payment Plans</h1>
            <?php if ( isset( $_GET['wcip_triggered'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success:</strong> The scheduler has been manually triggered. Check logs for details.</p>
                </div>
            <?php endif; ?>
            
            <div class="wcip-header-actions">
                <form method="post" class="wcip-header-form">
                    <?php wp_nonce_field( 'wcip_manual_trigger_action', 'wcip_manual_trigger_nonce' ); ?>
                    <button type="submit" name="wcip_manual_trigger" class="page-title-action">
                        ⚡ Run Scheduler
                    </button>
                </form>
                <a class="page-title-action" href="<?php echo esc_url( $direct_trigger_url ); ?>">Direct URL</a>
            </div>
            
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
            .wcip-header-actions {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin-left: 6px;
                position: relative;
                top: -1px;
            }
            .wcip-header-form {
                margin: 0;
            }

            button.page-title-action {
                cursor: pointer;
            }
        </style>
        <?php
    }
    
    public function register_settings(): void {
        register_setting(
            'wcip_settings',
            'wcip_stripe_api_key',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        register_setting(
            'wcip_settings',
            'wcip_webhook_url',
            [
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => '',
            ]
        );

        register_setting(
            'wcip_settings',
            'wcip_webhook_secret',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );
    }

    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Installment Settings</h1>
            <hr class="wp-header-end">

            <form method="post" action="options.php">
                <?php settings_fields( 'wcip_settings' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wcip_stripe_api_key">Stripe API key</label></th>
                            <td>
                                <input
                                    name="wcip_stripe_api_key"
                                    id="wcip_stripe_api_key"
                                    type="password"
                                    class="regular-text"
                                    value="<?php echo esc_attr( (string) get_option( 'wcip_stripe_api_key', '' ) ); ?>"
                                    autocomplete="new-password"
                                />
                                <p class="description">Used for Stripe API calls (kept hidden).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcip_webhook_url">Webhook URL</label></th>
                            <td>
                                <input
                                    name="wcip_webhook_url"
                                    id="wcip_webhook_url"
                                    type="url"
                                    class="regular-text"
                                    value="<?php echo esc_url( (string) get_option( 'wcip_webhook_url', '' ) ); ?>"
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcip_webhook_secret">Webhook secret</label></th>
                            <td>
                                <input
                                    name="wcip_webhook_secret"
                                    id="wcip_webhook_secret"
                                    type="password"
                                    class="regular-text"
                                    value="<?php echo esc_attr( (string) get_option( 'wcip_webhook_secret', '' ) ); ?>"
                                    autocomplete="new-password"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }
    
    public function handle_manual_trigger() {
        if ( isset( $_POST['wcip_manual_trigger'] ) && current_user_can( 'manage_woocommerce' ) ) {
            $nonce = isset( $_POST['wcip_manual_trigger_nonce'] )
                ? sanitize_text_field( wp_unslash( $_POST['wcip_manual_trigger_nonce'] ) )
                : '';

            if ( ! wp_verify_nonce( $nonce, 'wcip_manual_trigger_action' ) ) {
                return;
            }

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