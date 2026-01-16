<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Payments;

use wpdb;

class PlanManager {

    private wpdb $db;
    private string $table_name;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'wcip_installment_plans';
    }

    /**
     * Create a new payment plan
     */
    public function create_plan( int $order_id, int $customer_id, float $total_amount, int $installments_count ): int|false {
        $result = $this->db->insert(
            $this->table_name,
            [
                'order_id'           => $order_id,
                'customer_id'        => $customer_id,
                'total_amount'       => $total_amount,
                'installments_count' => $installments_count,
                'status'             => 'active',
                'created_at'         => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%f', '%d', '%s', '%s' ]
        );

        return $result ? $this->db->insert_id : false;
    }

    /**
     * Retrieve a plan by its ID
     */
    public function get_plan( int $plan_id ): ?object {
        $query = $this->db->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d LIMIT 1",
            $plan_id
        );

        return $this->db->get_row( $query ) ?: null;
    }

    /**
     * Update the status of a plan
     */
    public function update_status( int $plan_id, string $new_status ): bool {
        return (bool) $this->db->update(
            $this->table_name,
            [ 'status' => $new_status ],
            [ 'id' => $plan_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }
}
