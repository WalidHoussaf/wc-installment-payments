<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Admin;

use WcInstallmentPayments\Plugin;

class PlanDetailView {

    public function render( int $plan_id ): void {
        global $wpdb;

        $plan = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcip_installment_plans WHERE id = %d",
                $plan_id
            )
        );

        if ( ! $plan ) {
            echo '<div class="notice notice-error"><p>Plan not found.</p></div>';
            return;
        }

        $payments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcip_installment_payments WHERE plan_id = %d ORDER BY due_date ASC",
                $plan_id
            )
        );

        $user_id       = (int) $plan->customer_id;
        $user          = get_userdata( $user_id );
        $customer_name = $user ? $user->display_name : 'Guest';
        $avatar        = get_avatar( $user_id, 40 );
        $back_link     = remove_query_arg( [ 'action', 'id' ] );

        $this->render_styles();
        ?>

        <div class="wrap wcip-detail-wrap">
            <div class="wcip-header">
                <h1 class="wp-heading-inline">Plan Details #<?php echo esc_html( $plan->id ); ?></h1>
                <a href="<?php echo esc_url( $back_link ); ?>" class="page-title-action">← Back to list</a>
            </div>
            
            <hr class="wp-header-end">

            <div class="wcip-card">
                <div class="wcip-card-header">
                    <h2>Global Information</h2>
                </div>
                <div class="wcip-card-body">
                    <div class="wcip-summary-grid">
                        
                        <div class="wcip-summary-item">
                            <span class="wcip-label">Customer</span>
                            <div class="wcip-customer-profile">
                                <?php echo $avatar; ?>
                                <div>
                                    <strong><?php echo esc_html( $customer_name ); ?></strong>
                                    <?php if ( $user ) : ?>
                                        <div class="wcip-email"><?php echo esc_html( $user->user_email ); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="wcip-summary-item">
                            <span class="wcip-label">WooCommerce Order</span>
                            <a class="wcip-order-link" href="<?php echo esc_url( admin_url( 'post.php?post=' . $plan->order_id . '&action=edit' ) ); ?>">
                                <strong>#<?php echo esc_html( $plan->order_id ); ?></strong>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        </div>

                        <div class="wcip-summary-item">
                            <span class="wcip-label">Total Amount</span>
                            <span class="wcip-big-amount">
                                <?php echo function_exists('wc_price') ? wc_price($plan->total_amount) : number_format( (float) $plan->total_amount, 2 ) . ' €'; ?>
                            </span>
                        </div>

                        <div class="wcip-summary-item">
                            <span class="wcip-label">Plan Status</span>
                            <?php echo $this->get_status_badge( $plan->status ); ?>
                        </div>

                    </div>
                </div>
            </div>

            <h2 class="wcip-section-title">Payment Schedule</h2>

            <table class="wp-list-table widefat fixed striped table-view-list wcip-schedule-table">
                <thead>
                    <tr>
                        <th class="col-id">ID</th>
                        <th class="col-date">Due Date</th>
                        <th class="col-amount">Amount</th>
                        <th class="col-status">Status</th>
                        <th class="col-stripe">Stripe ID</th>
                        <th class="col-attempts">Attempts</th>
                        <th class="col-error">Last Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $payments as $payment ) :
                        $is_late = ( $payment->status === 'pending' && strtotime( $payment->due_date ) < time() );
                        $row_class = $is_late ? 'row-late' : '';
                        ?>
                        <tr class="<?php echo esc_attr($row_class); ?>">
                            <td class="col-id">#<?php echo esc_html( $payment->id ); ?></td>
                            
                            <td class="col-date">
                                <?php if ($is_late): ?>
                                    <span class="dashicons dashicons-warning" style="color: #d63638; font-size: 16px;"></span>
                                <?php endif; ?>
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $payment->due_date ) ) ); ?>
                            </td>
                            
                            <td class="col-amount">
                                <?php echo function_exists('wc_price') ? wc_price($payment->amount) : number_format( (float) $payment->amount, 2 ) . ' €'; ?>
                            </td>
                            
                            <td class="col-status">
                                <?php echo $this->get_status_badge( (string) $payment->status ); ?>
                            </td>
                            
                            <td class="col-stripe">
                                <?php if ( $payment->stripe_payment_intent_id ) : ?>
                                    <code class="wcip-code"><?php echo esc_html( $payment->stripe_payment_intent_id ); ?></code>
                                <?php else : ?>
                                    <span class="wcip-dash">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="col-attempts">
                                <?php if ($payment->attempts > 0): ?>
                                    <span class="wcip-attempt-badge"><?php echo esc_html( $payment->attempts ); ?></span>
                                <?php else: ?>
                                    <span class="wcip-dash">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="col-error">
                                <?php if ( ! empty($payment->last_error) ) : ?>
                                    <div class="wcip-error-box">
                                        <?php echo esc_html( $payment->last_error ); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="wcip-dash">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function get_status_badge( string $status ): string {
        // Map simplified statuses if needed, otherwise use raw status
        return sprintf(
            '<span class="wcip-badge status-%s">%s</span>',
            esc_attr( $status ),
            esc_html( ucfirst( str_replace('_', ' ', $status) ) )
        );
    }

    private function render_styles(): void {
        ?>
        <style>
            /* Layout & Card */
            .wcip-detail-wrap {
                max-width: 100%; 
                margin-top: 20px;
                box-sizing: border-box;
            }

            .wcip-header {
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .wcip-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
                margin-bottom: 30px;
                overflow: hidden;
                width: 100%;
            }

            .wcip-card-header {
                padding: 12px 20px;
                background: #fcfcfc;
                border-bottom: 1px solid #eaecf1;
            }
            .wcip-card-header h2 { margin: 0; font-size: 14px; font-weight: 600; color: #1d2327; }
            .wcip-card-body { padding: 20px; }

            /* Summary Grid */
            .wcip-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
                gap: 24px;
            }
            .wcip-summary-item { display: flex; flex-direction: column; gap: 8px; }
            .wcip-label { font-size: 11px; text-transform: uppercase; color: #646970; font-weight: 600; letter-spacing: 0.5px; }
            
            /* Customer Profile */
            .wcip-customer-profile { display: flex; align-items: center; gap: 12px; }
            .wcip-customer-profile img { border-radius: 50%; }
            .wcip-email { font-size: 12px; color: #646970; }

            /* Typography & Links */
            .wcip-big-amount { font-size: 18px; font-weight: 500; color: #1d2327; }
            .wcip-order-link { display: inline-flex; align-items: center; gap: 4px; font-size: 16px; text-decoration: none; }
            .wcip-order-link .dashicons { font-size: 14px; width: 14px; height: 14px; color: #787c82; }

            /* Status Badges */
            .wcip-badge {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                background: #e5e5e5;
                color: #555;
                width: fit-content;
                line-height: 1;
            }
            .wcip-badge.status-active, .wcip-badge.status-paid { background: #c6e1c6; color: #1d5c1d; }
            .wcip-badge.status-completed { background: #c8d7e1; color: #2e4453; }
            .wcip-badge.status-pending { background: #f0f0f1; color: #50575e; border: 1px solid #dcdcde; }
            .wcip-badge.status-failed, .wcip-badge.status-failed_final { background: #f8d7da; color: #721c24; }

            /* Table Styles */
            .wcip-section-title { font-size: 18px; font-weight: 500; margin: 0 0 15px 0; }
            
            .wcip-schedule-table { width: 100%; table-layout: auto; }         
            .wcip-schedule-table td { vertical-align: middle; padding: 12px 10px; }
            .wcip-schedule-table th { font-weight: 600; }
            
            /* Columns */
            .col-id { width: 60px; color: #646970; }
            .col-amount { font-weight: 500; }
            .col-status { width: 120px; }
            .col-attempts { text-align: center; width: 80px; }
            
            /* Code & Errors */
            .wcip-code { background: #f0f0f1; padding: 3px 6px; border-radius: 3px; font-size: 11px; color: #444; word-break: break-all; }
            .wcip-dash { color: #a0a0a0; }
            .wcip-error-box { font-size: 11px; color: #d63638; line-height: 1.3; max-width: 300px; }
            
            /* Late Styling */
            .row-late .col-date { color: #d63638; font-weight: 600; }
            .wcip-attempt-badge { background: #f0f0f1; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        </style>
        <?php
    }
}