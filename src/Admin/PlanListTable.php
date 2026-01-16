<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class PlanListTable extends \WP_List_Table {

    /**
     * Column configuration
     */
    public function get_columns() {
        return [
            'cb'           => '<input type="checkbox" />',
            'id'           => 'Plan ID',
            'status'       => 'Status',
            'order_id'     => 'Order',
            'customer'     => 'Customer',
            'amount'       => 'Total Amount',
            'progress'     => 'Progress',
            'next_payment' => 'Next Due',
            'created_at'   => 'Creation Date',
        ];
    }

    /**
     * Data retrieval (Query SQL + Pagination)
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcip_installment_plans';

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        $items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM $table_name ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
            ARRAY_A
        );

        $total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );

        $this->items = $items;

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ] );

        $this->_column_headers = [ $this->get_columns(), [], [] ];
    }

    /**
     * Checkbox column
     */
    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="plan_ids[]" value="%d" />', (int) $item['id'] );
    }

    /**
     * Default column display
     */
    public function column_default( $item, $column_name ) {
        return $item[ $column_name ] ?? '';
    }

    /**
     * ID column with hover actions
     */
    public function column_id( $item ) {
        $view_url = add_query_arg( [
            'page'   => 'wcip-plans',
            'action' => 'view',
            'id'     => $item['id'],
        ], admin_url( 'admin.php' ) );

        $actions = [
            'view' => sprintf( '<a href="%s" class="button-link">View Details</a>', esc_url( $view_url ) ),
        ];

        return sprintf(
            '<strong>#%1$s</strong> %2$s',
            esc_html( $item['id'] ),
            $this->row_actions( $actions )
        );
    }

    /**
     * Custom column: Order (Link to WC Order)
     */
    public function column_order_id( $item ) {
        $order_url = admin_url( 'post.php?post=' . $item['order_id'] . '&action=edit' );
        return sprintf( '<a class="wcip-order-link" href="%s"><strong>#%s</strong></a>', esc_url( $order_url ), esc_html( $item['order_id'] ) );
    }

    /**
     * Custom column: Customer (Added Avatar)
     */
    public function column_customer( $item ) {
        $user_id = (int) $item['customer_id'];
        $user    = get_userdata( $user_id );
        
        $avatar = get_avatar( $user_id, 32 );
        $name   = $user ? esc_html( $user->display_name ) : 'Guest';

        return sprintf(
            '<div class="wcip-customer-cell">%s <span class="customer-name">%s</span></div>',
            $avatar,
            $name
        );
    }

    /**
     * Custom column: Amount
     */
    public function column_amount( $item ) {
        $formatted = function_exists( 'wc_price' ) ? wc_price( $item['total_amount'] ) : number_format( (float) $item['total_amount'], 2 ) . ' €';
        return '<span class="wcip-amount">' . $formatted . '</span>';
    }

    /**
     * Custom column: Next Due
     */
    public function column_next_payment( $item ) {
        global $wpdb;
        $next_payment = $wpdb->get_row($wpdb->prepare(
            "SELECT due_date, amount FROM {$wpdb->prefix}wcip_installment_payments WHERE plan_id = %d AND status = 'pending' ORDER BY due_date ASC LIMIT 1",
            $item['id']
        ));
        
        if ($next_payment) {
            $amount = function_exists('wc_price') ? wc_price($next_payment->amount) : number_format($next_payment->amount, 2) . ' €';
            $date = date_i18n(get_option('date_format'), strtotime($next_payment->due_date));
            $is_late = strtotime($next_payment->due_date) < time();
            
            $late_badge = $is_late ? '<span class="wcip-late-badge">!</span>' : '';
            $late_class = $is_late ? 'is-late' : '';

            return sprintf(
                '<div class="wcip-next-payment %s">
                    <div class="next-date">%s %s</div>
                    <div class="next-amount">Amount due: %s</div>
                </div>',
                $late_class,
                $date,
                $late_badge,
                $amount
            );
        }
        
        return '<span class="wcip-dash">-</span>';
    }

    /**
     * Custom column: Progress
     */
    public function column_progress( $item ) {
        $paid_count = 0;
        $total_count = 0;
        
        global $wpdb;
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}wcip_installment_payments WHERE plan_id = %d",
            $item['id']
        ));
        
        foreach ($payments as $payment) {
            $total_count++;
            if ($payment->status === 'paid') {
                $paid_count++;
            }
        }
        
        $percentage = $total_count > 0 ? ($paid_count / $total_count) * 100 : 0;
        
        // Determine color based on completion
        $bar_color_class = $percentage >= 100 ? 'complete' : 'in-progress';

        return sprintf(
            '<div class="wcip-progress-wrapper">
                <div class="wcip-progress-bar">
                    <div class="wcip-progress-fill %s" style="width: %d%%;"></div>
                </div>
                <div class="wcip-progress-text">
                    <span class="count">%d/%d</span>
                    <span class="percent">%d%%</span>
                </div>
            </div>',
            $bar_color_class,
            $percentage,
            $paid_count,
            $total_count,
            round($percentage)
        );
    }

    /**
     * Custom column: Status
     */
    public function column_status( $item ) {
        $status = $item['status'];
        return sprintf(
            '<span class="wcip-badge status-%s">%s</span>',
            esc_attr( $status ),
            esc_html( ucfirst($status) )
        );
    }

    /**
     * Add custom styling
     */
    public function extra_tablenav( $which ) {
        if ( $which === 'top' ) {
            ?>
            <style>
                /* --- Hide Footer Headers --- */
                .wp-list-table tfoot {
                    display: none;
                }

                /* --- General Table Tweaks --- */
                .wp-list-table .column-id { width: 80px; }
                .wp-list-table .column-status { width: 100px; }
                .wp-list-table td { vertical-align: middle; }

                /* --- Status Badges --- */
                .wcip-badge {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    line-height: 1;
                    text-transform: uppercase;
                    background: #e5e5e5;
                    color: #555;
                }
                .wcip-badge.status-active { background: #c6e1c6; color: #1d5c1d; }
                .wcip-badge.status-completed { background: #c8d7e1; color: #2e4453; }
                .wcip-badge.status-failed, .wcip-badge.status-cancelled { background: #f8d7da; color: #721c24; }

                /* --- Customer Cell --- */
                .wcip-customer-cell { display: flex; align-items: center; gap: 10px; }
                .wcip-customer-cell img { border-radius: 50%; width: 32px; height: 32px; }
                .customer-name { font-weight: 500; color: #1d2327; }

                /* --- Progress Bar --- */
                .wcip-progress-wrapper { width: 100%; max-width: 140px; }
                .wcip-progress-bar { height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; margin-bottom: 4px; }
                .wcip-progress-fill { height: 100%; background: #2271b1; transition: width 0.3s ease; }
                .wcip-progress-fill.complete { background: #46b450; }
                .wcip-progress-text { display: flex; justify-content: space-between; font-size: 10px; color: #646970; }

                /* --- Next Payment --- */
                .wcip-next-payment .next-date { font-weight: 500; color: #1d2327; margin-bottom: 2px; }
                .wcip-next-payment .next-amount { font-size: 11px; color: #646970; }
                .wcip-next-payment.is-late .next-date { color: #d63638; }
                .wcip-late-badge { color: #fff; background: #d63638; border-radius: 50%; width: 14px; height: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; margin-left: 4px; }
                
                /* --- Amounts --- */
                .wcip-amount { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; font-weight: 500; }
            </style>
            <?php
        }
    }
}