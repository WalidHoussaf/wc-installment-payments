<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Payments;

use WcInstallmentPayments\Plugin;

class CheckoutHandler {

    private PlanManager $plan_manager;
    private PaymentManager $payment_manager;

    public function __construct( PlanManager $plan_manager, PaymentManager $payment_manager ) {
        $this->plan_manager    = $plan_manager;
        $this->payment_manager = $payment_manager;
    }

    /**
     * Register hooks
     */
    public function register(): void {
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'process_order_installments' ], 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'process_order_installments_store_api' ], 10, 1 );
    }

    /**
     * Main logic: Order interception
     * @param int $order_id
     * @param array $posted_data
     * @param object $order
     */
    public function process_order_installments( $order_id, $posted_data, $order ): void {
        $this->handle_order_installments( $order, 'classic' );
    }

    /**
     * Block-based checkout (Store API) handler
     */
    public function process_order_installments_store_api( $order ): void {
        $this->handle_order_installments( $order, 'store_api' );
    }

    /**
     * Shared logic to build plan + payments
     */
    private function handle_order_installments( $order, string $source ): void {
        error_log( sprintf( 'WCIP Debug: Processing order %d from %s checkout', (int) $order->get_id(), $source ) );
        
        if ( ! $order || ! method_exists( $order, 'get_total' ) ) {
            error_log( sprintf( 'WCIP Error: Invalid order object in %s checkout handler.', $source ) );
            return;
        }

        $total = (float) $order->get_total();
        error_log( sprintf( 'WCIP Debug: Order %d total is %.2f', (int) $order->get_id(), $total ) );

        if ( $total < 100 ) {
            error_log( sprintf( 'WCIP Info: Order %d total %.2f below threshold.', (int) $order->get_id(), $total ) );
            return;
        }

        $installments_count = 3;
        $frequency_days     = 30;

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        try {
            $order_id = (int) $order->get_id();
            $customer_id = (int) $order->get_customer_id();
            $plan_id = $this->plan_manager->create_plan( $order_id, $customer_id, $total, $installments_count );

            if ( ! $plan_id ) {
                $wpdb->query( 'ROLLBACK' );
                error_log( sprintf( 'WCIP Error: Failed to create plan for order %d. DB error: %s', $order_id, $wpdb->last_error ) );
                return;
            }

            $schedule = $this->calculate_installments( $total, $installments_count );

            foreach ( $schedule as $index => $amount ) {
                $days_offset = $index * $frequency_days;
                $due_date = date( 'Y-m-d H:i:s', strtotime( "+{$days_offset} days" ) );
                $status = ( $index === 0 ) ? 'paid' : 'pending';

                $payment_id = $this->payment_manager->create_payment( $plan_id, (float) $amount, $due_date, $status );

                if ( ! $payment_id ) {
                    $wpdb->query( 'ROLLBACK' );
                    error_log( sprintf( 'WCIP Error: Failed to create installment %d for order %d. DB error: %s', $index, $order_id, $wpdb->last_error ) );
                    return;
                }
            }

            $wpdb->query( 'COMMIT' );

            $order->add_order_note( sprintf( 'Payment plan created: %d installments.', $installments_count ) );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $order_id = $order && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
            error_log( sprintf( 'WCIP Error: Exception for order %d - %s', $order_id, $e->getMessage() ) );
        }
    }

    /**
     * Amount distribution algorithm (Penny Perfect)
     * @param float $total
     * @param int $count
     * @return array
     */
    private function calculate_installments( float $total, int $count ): array {
        $total_cents = (int) round( $total * 100 );
        $base_cents  = (int) floor( $total_cents / $count );
        $remainder   = $total_cents % $count;

        $amounts = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $cents = $base_cents;

            if ( $i < $remainder ) {
                $cents++;
            }

            $amounts[] = $cents / 100;
        }

        return $amounts;
    }
}
