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

            // Only apply rate limiting to defined write actions
            if ( ! in_array( $action, self::WRITE_ACTIONS, true ) ) {
                return true;
            }

            // Get the unique key for the current user or IP address
            $key   = self::key();
            $count = (int) get_transient( $key );

            // If the count exceeds the maximum allowed operations, block the action and log it
            if ( $count >= self::MAX_WRITE_OPS ) {
                KFM_Audit_Log::write( $action, '', 'blocked - rate limit exceeded' );
                return new WP_Error(
                    'kfm_rate_limit',
                    __( 'Too many operations. Please wait a moment and try again.', 'kpfm' )
                );
            }

            // Increment or create counter and set it to expire after the defined time window
            if ( $count === 0 ) {
                set_transient( $key, 1, self::WINDOW_SECS );
            } else {
                set_transient( $key, $count + 1, self::WINDOW_SECS );
            }

            // default to allowing the action if we haven't hit the limit
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
                    return sanitize_text_field( explode( ',', $_SERVER[ $k ] )[0] );
                }
            }

            // default to a placeholder IP if none of the server variables are set (should not happen in normal circumstances)
            return '0.0.0.0';
        }
    }
}