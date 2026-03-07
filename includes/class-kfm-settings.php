<?php
/**
 * Settings class.
 * Handles plugin settings: allowed role, base path, and all security options.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 * 
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_Settings') ) {

    /**
     * Handles plugin settings: allowed role, base path, and all security options.
     * 
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     */
    class KFM_Settings {

        // setup the option keys as constants to avoid typos and make it easier to manage defaults/sanitization
        const OPTION_PATH          = 'kfm_base_path';
        const OPTION_BLOCKED_EXTS  = 'kfm_blocked_exts';
        const OPTION_READONLY_EXTS = 'kfm_readonly_exts';
        const OPTION_PATH_DENYLIST = 'kfm_path_denylist';
        const OPTION_SHOW_DOTFILES = 'kfm_show_dotfiles';
        const OPTION_CHMOD_FLOOR   = 'kfm_chmod_floor';
        const OPTION_AUDIT_ALERTS  = 'kfm_audit_email_alerts';
        const OPTION_LOG_ENTRIES   = 'kfm_log_max_entries';
        const OPTION_ALERT_EMAILS  = 'kfm_alert_emails';
        const OPTION_DISABLED_SITES = 'kfm_disabled_sites';
        const OPTION_ANON_OPS      = 'kfm_anon_ops';

        /**
         * Register the plugin settings and the log clearing handler.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         */
        public function register(): void {
            add_action( 'admin_init', [ $this, 'register_settings' ] );
            add_action( 'admin_post_kfm_clear_log', [ $this, 'handle_clear_log' ] );
        }

        /**
         * Register the plugin settings.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         */
        public function register_settings(): void {

            // General settings group (Settings page)
            $general = [
                self::OPTION_PATH          => [ 'string', [ $this, 'sanitize_path' ],        '' ],
                self::OPTION_SHOW_DOTFILES => [ 'string', 'sanitize_text_field',             '0' ],
                self::OPTION_LOG_ENTRIES  => [ 'integer', [ $this, 'sanitize_log_entries' ],  100 ],
                self::OPTION_DISABLED_SITES => [ 'array', [ $this, 'sanitize_disabled_sites' ], [] ],
            ];

            // loop through the general settings and register them with WordPress, using the defined sanitization callbacks and defaults
            foreach ( $general as $key => $args ) {
                register_setting( 'kfm_options_group', $key, [
                    'type'              => $args[0],
                    'sanitize_callback' => $args[1],
                    'default'           => $args[2],
                ] );
            }

            // Security settings group (Security page)
            $security = [
                self::OPTION_BLOCKED_EXTS  => [ 'string', [ $this, 'sanitize_ext_list' ],    self::default_blocked_exts() ],
                self::OPTION_READONLY_EXTS => [ 'string', [ $this, 'sanitize_ext_list' ],    '' ],
                self::OPTION_PATH_DENYLIST => [ 'string', [ $this, 'sanitize_denylist' ],    '' ],
                self::OPTION_CHMOD_FLOOR   => [ 'string', [ $this, 'sanitize_chmod_floor' ], '0' ],
                self::OPTION_AUDIT_ALERTS  => [ 'string', 'sanitize_text_field',             '0' ],
                self::OPTION_ALERT_EMAILS => [ 'string',  [ $this, 'sanitize_alert_emails' ], ''  ],
            ];

            // loop through the security settings and register them with WordPress, using the defined sanitization callbacks and defaults
            foreach ( $security as $key => $args ) {
                register_setting( 'kfm_security_group', $key, [
                    'type'              => $args[0],
                    'sanitize_callback' => $args[1],
                    'default'           => $args[2],
                ] );
            }
        }

        /**
         * Returns the default list of blocked file extensions.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         */
        public static function default_blocked_exts(): string {
            return 'php,phtml,phar,php3,php4,php5,php7,phps,pht,exe,sh,pl,cgi,htaccess,htpasswd,shtml,asis';
        }

        /**
         * Sanitizes the file path setting.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $value
         * @return string
         */
        public function sanitize_path( string $value ): string {

            // Trim whitespace and slashes, and remove leading dots to prevent directory traversal attempts
            $value = trim( $value, "/\\ \t\n\r" );
            $value = ltrim( $value, '.' );
            $value = trim( $value, "/\\ " );

            // If the value is empty after sanitization, return an empty string to use the default wp-content directory
            if ( $value === '' ) return '';

            // Resolve the path and ensure it's within the wp-content directory to prevent directory traversal
            $wp_content = realpath( WP_CONTENT_DIR );
            $resolved   = realpath( $wp_content . DIRECTORY_SEPARATOR . $value );
            if ( $resolved === false || strpos( $resolved, $wp_content ) !== 0 ) {
                add_settings_error( self::OPTION_PATH, 'kfm_bad_path', __( 'Invalid path - must be inside wp-content.', 'kpfm' ) );
                return '';
            }

            // Return the sanitized path relative to wp-content, ensuring it always uses forward slashes
            return ltrim( str_replace( $wp_content, '', $resolved ), DIRECTORY_SEPARATOR );
        }

        /**
         * Sanitizes the blocked file extensions list.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $value
         * @return string
         */
        public function sanitize_ext_list( string $value ): string {

            // filter out any characters that aren't letters or numbers, and ensure all extensions are lowercase and trimmed
            $exts = array_filter( array_map( function ( $e ) {
                return preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $e ) ) );
            }, explode( ',', $value ) ) );

            // return the cleaned list as a comma-delimited string
            return implode( ',', $exts );
        }

        /**
         * Sanitizes the anonymous operations list.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param mixed $value
         * @return array
         */
        public function sanitize_anon_ops( $value ): array {

            // only allow valid operations and ensure the value is an array; default to list/read if invalid
            $allowed = [ 'list', 'read', 'write', 'upload', 'delete', 'rename', 'chmod' ];
            if ( ! is_array( $value ) ) return [ 'list', 'read' ];

            // sanitize the array values and return only those that are in the allowed list
            return array_values( array_intersect( array_map( 'sanitize_key', $value ), $allowed ) );
        }

        /**
         * Sanitizes the path denylist.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @param string $value
         * @return string
         */
        public function sanitize_denylist( string $value ): string {

            // split the input into lines, trim whitespace
            $lines = array_filter( array_map( 'trim', explode( "\n", $value ) ) );

            // hold the clean lines
            $clean = [];

            // loop through the lines and remove any that are empty after trimming, and also trim slashes and spaces from the remaining lines
            foreach ( $lines as $line ) {
                $line = trim( $line, "/\\ " );
                if ( $line !== '' ) $clean[] = $line;
            }

            // return the cleaned list as a newline-delimited string
            return implode( "\n", $clean );
        }

        /**
         * Sanitizes the chmod floor setting.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $value
         * @return string
         */
        public function sanitize_chmod_floor( string $value ): string {
            return preg_match( '/^[0-7]{3,4}$/', $value ) ? $value : '0';
        }

        /**
         * Sanitizes the log entry count setting.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param mixed $value
         * @return int
         *
         */
        public function sanitize_log_entries( $value ): int {
            return max( 10, min( 10000, (int) $value ) );
        }

        /**
         * Sanitizes the alert email list (semicolon-delimited).
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $value
         * @return string
         *
         */
        public function sanitize_alert_emails( string $value ): string {
            $emails = array_filter( array_map( 'trim', explode( ';', $value ) ), 'is_email' );
            return implode( ';', $emails );
        }

        /**
         * Sanitizes the disabled sites array.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param mixed $value
         * @return array
         *
         */
        public function sanitize_disabled_sites( $value ): array {
            if ( ! is_array( $value ) ) return [];
            return array_values( array_map( 'absint', $value ) );
        }

        /**
         * Returns the base path for the file manager.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         */
        public static function get_base_path(): string {

            // Get the relative path from the option
            $rel        = get_option( self::OPTION_PATH, '' );
            
            // setup the wp-content directory as the base path, and resolve the full path based on the relative value; ensure it is within wp-content to prevent directory traversal
            $wp_content = realpath( WP_CONTENT_DIR );
            if ( $rel === '' ) return $wp_content;
            
            // Resolve the full path and ensure it's within wp-content to prevent directory traversal
            $full = realpath( $wp_content . DIRECTORY_SEPARATOR . $rel );
            if ( $full === false || strpos( $full, $wp_content ) !== 0 ) return $wp_content;
            
            // Return the resolved full path
            return $full;
        }

        /**
         * Returns the display base path for the file manager.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         */
        public static function get_display_base(): string {
            return str_replace( realpath( ABSPATH ), '', self::get_base_path() ) ?: '/';
        }

        /**
         * Returns the list of blocked file extensions.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         */
        public static function get_blocked_exts(): array {

            // get the raw option value, split it into an array, trim whitespace, and filter out any empty values; ensure all extensions are lowercase for consistent checking
            $raw = get_option( self::OPTION_BLOCKED_EXTS, self::default_blocked_exts() );
            return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) ) );
        }

        /**
         * Returns the list of read-only file extensions.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         */
        public static function get_readonly_exts(): array {

            // get the raw option value
            $raw = get_option( self::OPTION_READONLY_EXTS, '' );

            // if there is no option, just return an empty array
            if ( $raw === '' ) return [];

            // split it into an array, trim whitespace, and filter out any empty values; ensure all extensions are lowercase for consistent checking
            return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) ) );
        }

        /**
         * Returns the list of allowed anonymous operations.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         */
        public static function get_anon_ops(): array {
            $val = get_option( self::OPTION_ANON_OPS, [ 'list', 'read' ] );
            return is_array( $val ) ? $val : [ 'list', 'read' ];
        }

        /**
         * Returns the list of denied paths.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         */
        public static function get_path_denylist(): array {
            $raw = get_option( self::OPTION_PATH_DENYLIST, '' );
            if ( $raw === '' ) return [];
            return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
        }

        /**
         * Returns whether to show dotfiles.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return bool
         */
        public static function show_dotfiles(): bool {
            return get_option( self::OPTION_SHOW_DOTFILES, '0' ) === '1';
        }

        /**
         * Returns the chmod floor value.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         */
        public static function get_chmod_floor(): string {
            return get_option( self::OPTION_CHMOD_FLOOR, '0' );
        }

        /**
         * Returns the maximum number of audit log entries to keep.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return int
         *
         */
        public static function get_log_max_entries(): int {
            return max( 10, min( 10000, (int) get_option( self::OPTION_LOG_ENTRIES, 100 ) ) );
        }

        /**
         * Returns the list of email addresses to notify on alert actions.
         * Falls back to the site admin email if none are configured.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         *
         */
        public static function get_alert_emails(): array {
            $raw    = trim( (string) get_option( self::OPTION_ALERT_EMAILS, '' ) );
            if ( $raw === '' ) return [ get_option( 'admin_email' ) ];
            $emails = array_values( array_filter( array_map( 'trim', explode( ';', $raw ) ), 'is_email' ) );
            return empty( $emails ) ? [ get_option( 'admin_email' ) ] : $emails;
        }

        /**
         * Converts an action to its corresponding operation.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $action The action to convert.
         * @return string The corresponding operation.
         */
        private static function action_to_op( string $action ): string {
            $map = [
                'kfm_list'        => 'list',
                'kfm_read'        => 'read',
                'kfm_write'       => 'write',
                'kfm_create_file' => 'write',
                'kfm_create_dir'  => 'write',
                'kfm_copy'        => 'write',
                'kfm_move'        => 'rename',
                'kfm_rename'      => 'rename',
                'kfm_delete'      => 'delete',
                'kfm_chmod'       => 'chmod',
                'kfm_upload'      => 'upload',
            ];

            // return the mapped operation or default to 'write'
            return $map[ $action ] ?? 'write';
        }

        /**
         * Checks if an anonymous operation is allowed.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $action The action to check.
         * @return bool Whether the operation is allowed.
         */
        public static function anon_op_allowed( string $action ): bool {
            return in_array( self::action_to_op( $action ), self::get_anon_ops(), true );
        }

        /**
         * Checks if the current user is allowed to perform an action.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return bool Whether the user is allowed.
         */
        public static function current_user_allowed(): bool {

            // If the user is not logged in, check if the anonymous role has permissions for any operations; if so, allow access to the file manager interface (specific operation checks are handled separately)
            if ( ! is_user_logged_in() ) {
                $anon_ops = KFM_Permissions::get_role_ops( 'anonymous' );
                return in_array( true, $anon_ops, true );
            }

            // All logged-in users pass the gate; op-level checks are handled by KFM_Permissions
            return true;
        }

        /**
         * Handles the clearing of the audit log.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         */
        public function handle_clear_log(): void {

            // Only allow users with manage_options capability to clear the log
            if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
            check_admin_referer( 'kfm_clear_log' );

            // Clear the audit log and redirect back to the settings page with a success message
            KFM_Audit_Log::clear();
            wp_redirect( admin_url( 'admin.php?page=kfm-settings&kfm_log_cleared=1' ) );
            exit;
        }
    }
}