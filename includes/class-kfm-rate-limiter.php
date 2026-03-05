<?php
defined( 'ABSPATH' ) || exit;

/**
 * Transient-based rate limiter.
 * Tracks write operations per user (or IP for anonymous) per minute.
 */
class KFM_Rate_Limiter {

    const MAX_WRITE_OPS = 60;   // max write operations per window
    const WINDOW_SECS   = 60;   // rolling window in seconds

    /** Write actions that count against the limit */
    const WRITE_ACTIONS = [
        'kfm_write', 'kfm_create_file', 'kfm_create_dir',
        'kfm_delete', 'kfm_rename', 'kfm_copy', 'kfm_move',
        'kfm_chmod', 'kfm_upload',
    ];

    public static function check( string $action ): true|WP_Error {
        if ( ! in_array( $action, self::WRITE_ACTIONS, true ) ) {
            return true; // read actions are not rate-limited
        }

        $key   = self::key();
        $count = (int) get_transient( $key );

        if ( $count >= self::MAX_WRITE_OPS ) {
            KFM_Audit_Log::write( $action, '', 'blocked – rate limit exceeded' );
            return new WP_Error(
                'kfm_rate_limit',
                __( 'Too many operations. Please wait a moment and try again.', 'kfm-file-manager' )
            );
        }

        // Increment or create counter
        if ( $count === 0 ) {
            set_transient( $key, 1, self::WINDOW_SECS );
        } else {
            set_transient( $key, $count + 1, self::WINDOW_SECS );
        }

        return true;
    }

    private static function key(): string {
        if ( is_user_logged_in() ) {
            return 'kfm_rate_' . get_current_user_id();
        }
        // For anonymous users key on IP
        $ip = self::client_ip();
        return 'kfm_rate_ip_' . md5( $ip );
    }

    private static function client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $k ] )[0] );
            }
        }
        return '0.0.0.0';
    }
}
