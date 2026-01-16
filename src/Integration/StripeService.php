<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Integration;

/**
 * Wrapper for the Stripe API.
 * Isolates the dependency on the Stripe SDK.
 */
class StripeService {

    private string $api_key;

    public function __construct() {
        $this->api_key = 'sk_test_placeholder';
    }

    /**
     * Create a PaymentIntent
     * @param float $amount Amount in currency (ex: 100.00)
     * @param string $currency (ex: 'eur')
     * @param string $customer_id Stripe Customer ID
     */
    public function create_intent( float $amount, string $currency, string $customer_id ): array|\WP_Error {
        if ( empty( $this->api_key ) ) {
            return new \WP_Error( 'stripe_config_error', 'Stripe API Key missing' );
        }

        $amount_cents = (int) ( $amount * 100 );

        try {
            return [
                'id'            => 'pi_' . bin2hex( random_bytes( 10 ) ),
                'client_secret' => 'pi_secret_' . bin2hex( random_bytes( 10 ) ),
                'status'        => 'requires_payment_method',
                'amount'        => $amount_cents,
            ];
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'stripe_random_error', $e->getMessage() );
        }
    }

    /**
     * Attempt to charge a saved card (Off-Session)
     * @param float $amount The amount
     * @param string $customer_id The Stripe customer ID
     * @return array Standardized result [ 'success' => bool, 'id' => string, 'error' => string ]
     */
    public function charge_saved_card( float $amount, string $customer_id ): array {
        $is_success = ( rand( 1, 100 ) <= 80 );

        try {
            $simulated_id = 'pi_' . bin2hex( random_bytes( 8 ) );
        } catch ( \Throwable $e ) {
            return [
                'success' => false,
                'id'      => '',
                'error'   => 'random_bytes_error',
            ];
        }

        if ( $is_success ) {
            return [
                'success' => true,
                'id'      => $simulated_id,
                'error'   => null,
            ];
        }

        return [
            'success' => false,
            'id'      => $simulated_id,
            'error'   => 'insufficient_funds',
        ];
    }
}
