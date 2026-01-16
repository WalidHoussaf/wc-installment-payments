<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Core;

class WebhookDispatcher {

    private string $webhook_url;
    private string $secret_key;

    public function __construct() {
        $this->webhook_url = defined( 'WCIP_WEBHOOK_URL' )
            ? WCIP_WEBHOOK_URL
            : (string) get_option( 'wcip_webhook_url', '' );
        $this->secret_key = defined( 'WCIP_WEBHOOK_SECRET' )
            ? WCIP_WEBHOOK_SECRET
            : (string) get_option( 'wcip_webhook_secret', '' );
    }

    /**
     * Register the listener
     */
    public function register(): void {
        add_action( 'wcip_payment_failed_final', [ $this, 'dispatch_failure_alert' ], 10, 2 );
    }

    /**
     * Prepare and send the Webhook
     * @param int $payment_id
     * @param int $plan_id
     */
    public function dispatch_failure_alert( int $payment_id, int $plan_id ): void {
        if ( empty( $this->webhook_url ) || empty( $this->secret_key ) ) {
            return;
        }
        global $wpdb;

        $sql = "
            SELECT 
                pay.amount, pay.due_date, pay.last_error, pay.attempts,
                plan.order_id, plan.customer_id, plan.total_amount
            FROM {$wpdb->prefix}wcip_installment_payments AS pay
            JOIN {$wpdb->prefix}wcip_installment_plans AS plan ON pay.plan_id = plan.id
            WHERE pay.id = %d
        ";

        $data = $wpdb->get_row( $wpdb->prepare( $sql, $payment_id ) );

        if ( ! $data ) {
            return;
        }

        $user           = get_userdata( (int) $data->customer_id );
        $customer_email = $user ? $user->user_email : 'unknown@example.com';

        $payload = [
            'event'     => 'payment.failed_final',
            'timestamp' => time(),
            'data'      => [
                'plan_id'  => $plan_id,
                'order_id' => $data->order_id,
                'customer' => [
                    'id'    => (int) $data->customer_id,
                    'email' => $customer_email,
                ],
                'debt'     => [
                    'amount'     => (float) $data->amount,
                    'currency'   => 'EUR',
                    'due_date'   => $data->due_date,
                    'attempts'   => (int) $data->attempts,
                    'last_error' => $data->last_error,
                ],
            ],
        ];

        $body_json = json_encode( $payload );

        if ( ! is_string( $body_json ) ) {
            return;
        }

        $signature = hash_hmac( 'sha256', $body_json, $this->secret_key );

        $response = wp_remote_post( $this->webhook_url, [
            'method'   => 'POST',
            'blocking' => false,
            'headers'  => [
                'Content-Type'     => 'application/json',
                'X-WCIP-Signature' => $signature,
                'X-WCIP-Event'     => 'payment_failed_final',
            ],
            'body'     => $body_json,
            'timeout'  => 5,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'WCIP Webhook Error: ' . $response->get_error_message() );
        } else {
            error_log( "WCIP Webhook sent for payment #{$payment_id} to {$this->webhook_url}" );
        }
    }
}
