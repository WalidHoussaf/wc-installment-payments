<?php

declare(strict_types=1);

namespace WcInstallmentPayments\Payments;

class RetryStrategy {

    /**
     * Definition of retry intervals (in days)
     * Attempt 1 failed -> Retry at D+3
     * Attempt 2 failed -> Retry at D+7
     * Attempt 3 failed -> Retry at D+14
     */
    private const RETRY_INTERVALS = [ 3, 7, 14 ];

    /**
     * Calculate the next retry date
     * @param int $current_attempts Number of attempts already made
     * @return string|null The formatted date (Y-m-d H:i:s) or NULL if abandoned
     */
    public function get_next_retry_date( int $current_attempts ): ?string {
        $index = $current_attempts - 1;

        if ( ! isset( self::RETRY_INTERVALS[ $index ] ) ) {
            return null;
        }

        $days_to_add = self::RETRY_INTERVALS[ $index ];

        return date( 'Y-m-d H:i:s', strtotime( "+{$days_to_add} days" ) );
    }

    /**
     * Check if we have reached the limit
     */
    public function is_max_attempts_reached( int $attempts ): bool {
        return $attempts > count( self::RETRY_INTERVALS );
    }
}
