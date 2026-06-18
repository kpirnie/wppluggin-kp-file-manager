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
        const MAX_READ_OPS  = 120;
        const WINDOW_SECS   = 60;

        // List of actions that are considered "write" operations and should be rate-limited
        const WRITE_ACTIONS = [
            'kfm_write', 'kfm_create_file', 'kfm_create_dir',
            'kfm_delete', 'kfm_rename', 'kfm_copy', 'kfm_move',
            'kfm_chmod', 'kfm_upload',
        ];

        // list of rate-limited read actions
        const READ_ACTIONS = [
            'kfm_list', 'kfm_read', 'kfm_download',
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
            if ( in_array( $action, self::WRITE_ACTIONS, true ) ) {
                return self::check_limit( $action, 'w', self::MAX_WRITE_OPS );
            }
            if ( in_array( $action, self::READ_ACTIONS, true ) ) {
                return self::check_limit( $action, 'r', self::MAX_READ_OPS );
            }
            return true;
        }

        /**
         * Check the rate limit for a given action and bucket.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @param string $action The action being performed (for logging purposes).
         * @param string $bucket A short identifier for the type of action (e.g., 'w' for write, 'r' for read).
         * @param int $max The maximum number of allowed actions within the time window.
         * @return true|WP_Error Returns true if the action is allowed, or a WP_Error if the rate limit has been exceeded.
         */
        private static function check_limit( string $action, string $bucket, int $max ): true|WP_Error {
            $key       = self::key() . '_' . $bucket;
            $count_key = $key . '_count';
            $start_key = $key . '_start';

            $start = get_transient( $start_key );
            if ( $start === false ) {
                set_transient( $start_key, time(), self::WINDOW_SECS );
                set_transient( $count_key, 1,      self::WINDOW_SECS );
                return true;
            }

            $count = (int) get_transient( $count_key );
            if ( $count >= $max ) {
                KFM_Audit_Log::write( $action, '', 'blocked - rate limit exceeded' );
                return new WP_Error( 'kfm_rate_limit', __( 'Too many operations. Please wait a moment and try again.', 'kpfm' ) );
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
            $remote = isset( $_SERVER['REMOTE_ADDR'] )
                ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] )
                : '0.0.0.0';

            /**
             * Filters the list of trusted reverse-proxy IPs.
             *
             * When REMOTE_ADDR matches an entry here, KFM will inspect
             * HTTP_CF_CONNECTING_IP and HTTP_X_FORWARDED_FOR for the real client IP.
             * Leave empty (the default) to use REMOTE_ADDR unconditionally.
             *
             * Example (add to wp-config.php or a must-use plugin):
             *   add_filter( 'kfm_trusted_proxies', fn() => [ '10.0.0.1', '192.168.1.1' ] );
             *
             * @param string[] $proxies Array of trusted proxy IP addresses.
             */
            $trusted = (array) apply_filters( 'kfm_trusted_proxies', [] );

            if ( ! empty( $trusted ) && in_array( $remote, $trusted, true ) ) {
                if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                    return sanitize_text_field( trim( explode( ',', $_SERVER['HTTP_CF_CONNECTING_IP'] )[0] ) );
                }
                if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                    return sanitize_text_field( trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
                }
            }

            return $remote;
        }
    }
}