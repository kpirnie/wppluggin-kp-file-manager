<?php
/**
 * Rate limiter class.
 * Handles rate limiting for file/folder operations.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' ); 

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_Rate_Limiter') ) {

    /**
     * Handles rate limiting for file/folder operations.
     * 
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     */
    class KFM_Rate_Limiter {

        // Define the maximum number of write operations allowed within the time window
        const MAX_WRITE_OPS = 60;
        const WINDOW_SECS   = 60;

        // List of actions that are considered "write" operations and should be rate-limited
        const WRITE_ACTIONS = [
            'kfm_write', 'kfm_create_file', 'kfm_create_dir',
            'kfm_delete', 'kfm_rename', 'kfm_copy', 'kfm_move',
            'kfm_chmod', 'kfm_upload',
        ];

        /**
         * Check if the current action is within the rate limit.
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $action The action to check.
         * @return true|WP_Error
         */
        public static function check( string $action ): true|WP_Error {
            if ( ! in_array( $action, self::WRITE_ACTIONS, true ) ) return true;

            $key       = self::key();
            $count_key = $key . '_count';
            $start_key = $key . '_start';

            $start = get_transient( $start_key );
            if ( $start === false ) {
                set_transient( $start_key, time(), self::WINDOW_SECS );
                set_transient( $count_key, 1,      self::WINDOW_SECS );
                return true;
            }

            $count = (int) get_transient( $count_key );
            if ( $count >= self::MAX_WRITE_OPS ) {
                KFM_Audit_Log::write( $action, '', 'blocked - rate limit exceeded' );
                return new WP_Error( 'kfm_rate_limit', __( 'Too many operations. Please wait a moment and try again.', 'kp-file-manager' ) );
            }

            $remaining = self::WINDOW_SECS - ( time() - (int) $start );
            set_transient( $count_key, $count + 1, max( 1, $remaining ) );
            return true;
        }

        /**
         * Generate a unique key for the current user or IP address.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         */
        private static function key(): string {

            // For logged-in users, key on user ID
            if ( is_user_logged_in() ) {
                return 'kfm_rate_' . get_current_user_id();
            }

            // For anonymous users key on IP
            $ip = self::client_ip();

            // hash the string to create a consistent length key and avoid issues with long IP addresses (like IPv6)
            return 'kfm_rate_ip_' . md5( $ip );
        }

        /**
         * Get the client's IP address.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         */
        private static function client_ip(): string {

            // Check common server variables for the client's IP address, accounting for proxies and CDNs
            foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
                if ( ! empty( $_SERVER[ $k ] ) ) {
                    return sanitize_text_field( explode( ',', wp_unslash( $_SERVER[ $k ] ) )[0] );
                }
            }

            // default to a placeholder IP if none of the server variables are set (should not happen in normal circumstances)
            return '0.0.0.0';
        }
    }
}