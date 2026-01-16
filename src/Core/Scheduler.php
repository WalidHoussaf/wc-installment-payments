<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Core;

use WcInstallmentPayments\Integration\StripeService;
use WcInstallmentPayments\Payments\PaymentManager;
use WcInstallmentPayments\Payments\PlanManager;
use WcInstallmentPayments\Payments\RetryStrategy;

class Scheduler {

    private PaymentManager $payment_manager;
    private PlanManager $plan_manager;
    private StripeService $stripe_service;
    private RetryStrategy $retry_strategy;

    public function __construct(
        PaymentManager $payment_manager,
        PlanManager $plan_manager,
        StripeService $stripe_service
    ) {
        $this->payment_manager = $payment_manager;
        $this->plan_manager    = $plan_manager;
        $this->stripe_service  = $stripe_service;
        $this->retry_strategy  = new RetryStrategy();
    }

    /**
     * Register the CRON event and the hook
     */
    public function register(): void {
        error_log( 'WCIP Debug: Scheduler register() called' );
        
        add_action( 'wcip_hourly_process', [ $this, 'process_due_payments' ] );
        add_action( 'wcip_manual_trigger', [ $this, 'process_due_payments' ] ); // Manual trigger for testing

        add_action( 'wcip_payment_failed', [ $this, 'handle_payment_failure' ], 10, 2 );

        if ( ! wp_next_scheduled( 'wcip_hourly_process' ) ) {
            wp_schedule_event( time(), 'hourly', 'wcip_hourly_process' );
            error_log( 'WCIP Debug: Scheduled wcip_hourly_process event' );
        } else {
            error_log( 'WCIP Debug: wcip_hourly_process already scheduled' );
        }
    }

    public function process_due_payments(): void {
        error_log( 'WCIP Debug: process_due_payments() called' );
        
        $due_payments = $this->payment_manager->get_due_payments();

        error_log( 'WCIP Debug: Found ' . count( $due_payments ) . ' due payments' );

        if ( empty( $due_payments ) ) {
            return;
        }

        foreach ( $due_payments as $payment ) {
            error_log( "WCIP Debug: Processing payment ID {$payment->id}" );
            try {
                $this->process_single_payment( $payment );
            } catch ( \Throwable $e ) {
                error_log( "WCIP Error: Exception process_single_payment payment_id={$payment->id} - {$e->getMessage()}" );
            }
        }
    }

    /**
     * Process an individual payment in isolation
     */
    private function process_single_payment( object $payment ): void {
        $plan = $this->plan_manager->get_plan( (int) $payment->plan_id );

        if ( ! $plan ) {
            error_log( "WCIP Critical: Payment ID {$payment->id} without parent plan." );
            return;
        }

        $stripe_customer_id = 'cus_test_' . $plan->customer_id;

        $result = $this->stripe_service->charge_saved_card(
            (float) $payment->amount,
            $stripe_customer_id
        );

        if ( $result['success'] ) {
            $this->payment_manager->log_attempt(
                (int) $payment->id,
                'paid',
                $result['id']
            );
        } else {
            $this->payment_manager->log_attempt(
                (int) $payment->id,
                'failed',
                $result['id'],
                $result['error']
            );

            do_action( 'wcip_payment_failed', (int) $payment->id, (int) $plan->id );
        }
    }

    /**
     * Handle post-failure logic (Retry or Abandon)
     */
    public function handle_payment_failure( int $payment_id, int $plan_id ): void {
        global $wpdb;
        $attempts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attempts FROM {$wpdb->prefix}wcip_installment_payments WHERE id = %d",
                $payment_id
            )
        );

        $next_date = $this->retry_strategy->get_next_retry_date( $attempts );

        if ( $next_date ) {
            $this->payment_manager->reschedule_payment( $payment_id, $next_date );
            error_log( "WCIP Info: Payment #{$payment_id} rescheduled to {$next_date} (Attempt {$attempts})" );
            return;
        }

        $this->payment_manager->mark_as_failed_final( $payment_id );
        $this->plan_manager->update_status( $plan_id, 'breach' );

        do_action( 'wcip_payment_failed_final', $payment_id, $plan_id );
        error_log( "WCIP Warning: Payment #{$payment_id} abandoned after {$attempts} attempts." );
    }
}
