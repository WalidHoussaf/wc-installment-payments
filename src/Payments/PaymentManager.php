<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Payments;

use wpdb;

class PaymentManager {

    private wpdb $db;
    private string $table_name;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'wcip_installment_payments';
    }

    /**
     * Create a payment installment
     */
    public function create_payment( int $plan_id, float $amount, string $due_date, string $status = 'pending' ): int|false {
        $result = $this->db->insert(
            $this->table_name,
            [
                'plan_id'                  => $plan_id,
                'stripe_payment_intent_id' => '',
                'amount'                   => $amount,
                'due_date'                 => $due_date,
                'status'                   => $status,
                'attempts'                 => 0,
            ],
            [ '%d', '%s', '%f', '%s', '%s', '%d' ]
        );

        return $result ? $this->db->insert_id : false;
    }

    /**
     * Retrieve pending payments whose date has passed
     * Useful for the Cron Job later
     */
    public function get_due_payments(): array {
        $now = current_time( 'mysql' );
        error_log( "WCIP Debug: get_due_payments() - current time: $now" );
        
        $query = $this->db->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = %s AND due_date <= %s",
            'pending',
            $now
        );

        $results = $this->db->get_results( $query );
        error_log( "WCIP Debug: SQL query executed, found " . count( $results ) . " results" );
        
        return $results;
    }

    /**
     * Record an attempt (success or failure)
     */
    public function log_attempt( int $payment_id, string $status, ?string $stripe_pi_id = null, ?string $error = null ): bool {
        $attempts = (int) $this->db->get_var(
            $this->db->prepare( "SELECT attempts FROM {$this->table_name} WHERE id = %d", $payment_id )
        );

        $data = [
            'status'   => $status,
            'attempts' => $attempts + 1,
        ];

        $format = [ '%s', '%d' ];

        if ( $stripe_pi_id ) {
            $data['stripe_payment_intent_id'] = $stripe_pi_id;
            $format[] = '%s';
        }
        if ( $error ) {
            $data['last_error'] = $error;
            $format[] = '%s';
        }

        return (bool) $this->db->update(
            $this->table_name,
            $data,
            [ 'id' => $payment_id ],
            $format,
            [ '%d' ]
        );
    }

    /**
     * Reschedule a payment for a later date
     */
    public function reschedule_payment( int $payment_id, string $new_date ): bool {
        return (bool) $this->db->update(
            $this->table_name,
            [
                'status'   => 'pending',
                'due_date' => $new_date,
            ],
            [ 'id' => $payment_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark a payment as definitively failed (Unpaid confirmed)
     */
    public function mark_as_failed_final( int $payment_id ): bool {
        return (bool) $this->db->update(
            $this->table_name,
            [ 'status' => 'failed_final' ],
            [ 'id' => $payment_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }
}
