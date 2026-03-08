<?php
/** 
 * Audit Log class.
 * Implements a lightweight audit log stored as a ring buffer in wp_options.
 * Keeps the last 500 entries and can email the admin on certain actions.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
*/

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// Check if the class already exists to prevent redeclaration errors.
if( !class_exists('KFM_Audit_Log') ) {

    /**
     * Implements a lightweight audit log stored as a ring buffer in wp_options.
     * Keeps the last configured # of (default 100) entries and can email the admin on certain actions.
     * Each log entry includes timestamp, user, IP, action, path, and result.
     * 
     * Actions that are always logged: write, create_file, create_dir, delete, rename,
     * copy, move, chmod, upload. Alert actions that email the admin on success: delete, chmod.
     * 
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     */
    class KFM_Audit_Log {

        // Option key for storing the audit log in wp_options and max entries to keep.
        const OPTION_KEY  = 'kfm_audit_log';

        // Actions that should always be logged
        const LOGGED_ACTIONS = [
            'kfm_write', 'kfm_create_file', 'kfm_create_dir',
            'kfm_delete', 'kfm_rename', 'kfm_copy', 'kfm_move',
            'kfm_chmod', 'kfm_upload',
        ];

        // Actions that should trigger an email alert on successful execution, if configured
        const ALERT_ACTIONS = [ 'kfm_delete', 'kfm_chmod' ];

        /**
         * Writes an entry to the audit log.
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $action The action being logged.
         * @param string $path The path of the file or directory involved.
         * @param string $result The result of the action (default: 'ok').
         * 
         * @return void
         * 
         */
        public static function write( string $action, string $path, string $result = 'ok' ): void {
            // gather log entry data
            $user    = is_user_logged_in() ? wp_get_current_user()->user_login : 'anonymous';
            $ip      = self::client_ip();
            $entry   = [
                'ts'     => time(),
                'user'   => $user,
                'ip'     => $ip,
                'action' => $action,
                'path'   => $path,
                'result' => $result,
            ];

            // get the existing log
            $log = get_option( self::OPTION_KEY, [] );
            if ( ! is_array( $log ) ) $log = [];

            // append the new entry
            $log[] = $entry;

            // Trim to ring buffer size
            $max = KFM_Settings::get_log_max_entries();
            if ( count( $log ) > $max ) {
                $log = array_slice( $log, -$max );
            }

            // save the updated log back to the database
            update_option( self::OPTION_KEY, $log, false );

            // Email alert for destructive successful ops
            if ( $result === 'ok' && in_array( $action, self::ALERT_ACTIONS, true ) ) {

                // Check if email alerts are enabled for this action
                $enabled = get_option( 'kfm_audit_email_alerts', '0' );
                if ( $enabled === '1' ) {

                    // Send the alert email
                    self::send_alert( $entry );
                }
            }
        }

        /** 
         * Get the audit log entries
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param int $limit number of items to display
         * 
         * @return array
         */
        public static function get( int $limit = 0 ): array {

            // set the maximum number of items to display
            $max = $limit > 0 ? $limit : KFM_Settings::get_log_max_entries();

            // get the log
            $log = get_option( self::OPTION_KEY, [] );

            // make sure it's an array and return either the items or an empty array
            if ( ! is_array( $log ) ) return [];
            return array_reverse( array_slice( $log, -$max ) );
        }

        /** 
         * Clear out the audit log entries
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         */
        public static function clear(): void {

            // update the option storing the log
            update_option( self::OPTION_KEY, [], false );
        }

        /** 
         * Send an alert to the configured email addresses
         * if we are configured to do so
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @param array The entry to send
         * 
         * @return void
         */
        private static function send_alert( array $entry ): void {

            // setup the data necessary to process
            $email_to = KFM_Settings::get_alert_emails();
            $site   = get_bloginfo( 'name' );
            $labels = [
                'kfm_delete' => 'DELETE',
                'kfm_chmod'  => 'CHMOD',
            ];
            $label  = $labels[ $entry['action'] ] ?? strtoupper( $entry['action'] );

            // send out the email notice
            wp_mail(
                $email_to,
                "[{$site}] KFM Security Alert: {$label}",
                sprintf(
                    "Action:  %s\nPath:    %s\nUser:    %s\nIP:      %s\nTime:    %s\n",
                    $entry['action'],
                    $entry['path'],
                    $entry['user'],
                    $entry['ip'],
                    gmdate( 'Y-m-d H:i:s', $entry['ts'] ) . ' UTC'
                )
            );
        }

        /** 
         * Try to get the client's IP address for the logs
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return string
         */
        private static function client_ip(): string {
            foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
                if ( ! empty( $_SERVER[ $k ] ) ) {
                    return sanitize_text_field( explode( ',', wp_unslash( $_SERVER[ $k ] ) )[0] );
                }
            }
            return '0.0.0.0';
        }
    }
}