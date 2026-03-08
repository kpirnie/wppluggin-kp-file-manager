<?php
/**
 * AJAX class.
 * Handles all AJAX requests for the file manager.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_Ajax') ) {

    /**
     * Registers and handles all AJAX endpoints for KFM.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     */
    class KFM_Ajax {

        // holding references to the file manager and settings instances
        private KFM_File_Manager $fm;
        private KFM_Settings     $settings;

        // list of all public AJAX actions that this class will handle
        private const PUBLIC_ACTIONS = [
            'kfm_list', 'kfm_read', 'kfm_write', 'kfm_create_file',
            'kfm_create_dir', 'kfm_delete', 'kfm_rename', 'kfm_copy',
            'kfm_move', 'kfm_chmod', 'kfm_upload', 'kfm_download',
        ];

        /**
         * Constructor.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param KFM_File_Manager $fm
         * @param KFM_Settings $settings
         * 
         */
        public function __construct( KFM_File_Manager $fm, KFM_Settings $settings ) {
            $this->fm       = $fm;
            $this->settings = $settings;
        }

        /**
         * Registers the AJAX handlers for both logged-in and non-logged-in users.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         * 
         */
        public function register(): void {

            // loop through each public action and register the AJAX handlers
            foreach ( self::PUBLIC_ACTIONS as $action ) {
                add_action( 'wp_ajax_' . $action,        [ $this, 'dispatch' ] );
                add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'dispatch' ] );
            }
        }

        /**
         * Dispatches the AJAX request based on the action.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         * 
         */
        public function dispatch(): void {
            
            // permission check for the current user
            if ( ! KFM_Settings::current_user_allowed() ) {
                wp_send_json_error( [ 'message' => __( 'Permission denied.', 'kp-file-manager' ) ], 403 );
            }

            // check the nonce for security
            check_ajax_referer( 'kfm_nonce', 'nonce' );

            // check the referer to prevent CSRF attacks
            $referer = wp_get_referer();
            if ( $referer ) {

                // parse the host from the referer and the site URL to compare them
                $ref_host  = wp_parse_url( $referer, PHP_URL_HOST );
                $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

                // if the referer host does not match the site host, block the request and log it
                if ( $ref_host !== $site_host ) {
                    KFM_Audit_Log::write( 'dispatch', '', 'blocked - bad referer: ' . $ref_host );
                    wp_send_json_error( [ 'message' => __( 'Request origin mismatch.', 'kp-file-manager' ) ], 403 );
                }
            }

            // sanitize and validate the action parameter
            $action = sanitize_key( $_REQUEST['action'] ?? '' );

            // check the user's permissions for the requested action and log any unauthorized attempts
            if ( ! KFM_Permissions::current_user_can_op( $action ) ) {
                KFM_Audit_Log::write( $action, $this->rel(), 'blocked - role permission denied' );
                wp_send_json_error( [ 'message' => __( 'Your role does not have permission to perform this operation.', 'kp-file-manager' ) ], 403 );
            }

            // check the rate limit for the requested action and log any blocked attempts
            $rate = KFM_Rate_Limiter::check( $action );
            if ( is_wp_error( $rate ) ) {
                wp_send_json_error( [ 'message' => $rate->get_error_message() ], 429 );
            }

            // map the action to the corresponding handler method and execute it, logging any unknown actions
            switch ( $action ) {
                case 'kfm_list':         $this->handle_list();        break;
                case 'kfm_read':         $this->handle_read();        break;
                case 'kfm_write':        $this->handle_write();       break;
                case 'kfm_create_file':  $this->handle_create_file(); break;
                case 'kfm_create_dir':   $this->handle_create_dir();  break;
                case 'kfm_delete':       $this->handle_delete();      break;
                case 'kfm_rename':       $this->handle_rename();      break;
                case 'kfm_copy':         $this->handle_copy();        break;
                case 'kfm_move':         $this->handle_move();        break;
                case 'kfm_chmod':        $this->handle_chmod();       break;
                case 'kfm_upload':       $this->handle_upload();      break;
                case 'kfm_download':     $this->handle_download();    break;
                default:
                    wp_send_json_error( [ 'message' => __( 'Unknown action.', 'kp-file-manager' ) ], 400 );
            }
        }

        /**
         * Send a JSON response.
         * This method centralizes the response handling for all AJAX requests
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param array|true|WP_Error $result The result to send.
         * @param string $action The action being performed.
         * @param string $path The path of the file or directory.
         * 
         * @return void
         * 
         */
        private function send( array|true|WP_Error $result, string $action = '', string $path = '' ): void {
            
            // if the result is an error, log it and send a JSON error response
            if ( is_wp_error( $result ) ) {
                if ( $action ) KFM_Audit_Log::write( $action, $path, 'error: ' . $result->get_error_message() );
                wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
            }
            
            // if the result is successful, log the action and send a JSON success response
            if ( $action && in_array( $action, KFM_Audit_Log::LOGGED_ACTIONS, true ) ) {
                KFM_Audit_Log::write( $action, $path, 'ok' );
            }

            // if the result is true, send an empty array to indicate success without data; otherwise, send the result data
            wp_send_json_success( $result === true ? [] : $result );
        }

        /**
         * Get the relative path from the request.
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $key The key to retrieve from the request.
         *
         * @return string The sanitized relative path.
         * 
         */
        private function rel( string $key = 'path' ): string {
            return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ?? '' ) );
        }

        /**
         * Handle the 'list' action.
         * This method retrieves the list of files and directories in the specified path
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_list(): void { 
            $this->send( $this->fm->list_dir( $this->rel() ) ); 
        }

        /**
         * Handle the 'read' action.
         * This method reads the contents of a specified file and returns it in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_read(): void { 
            $this->send( $this->fm->read_file( $this->rel() ) );
        }

        /**
         * Handle the 'write' action.
         * This method writes content to a specified file and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_write(): void {
            $path = $this->rel();
            $this->send( $this->fm->write_file( $path, wp_unslash( $_POST['content'] ?? '' ) ), 'kfm_write', $path );
        }

        /**
         * Handle the 'create_file' action.
         * This method creates a new file at the specified path and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_create_file(): void {
            $rel = $this->build_rel( 'dir', 'name' );
            $this->send( $this->fm->create_file( $rel ), 'kfm_create_file', $rel );
        }

        /**
         * Handle the 'create_dir' action.
         * This method creates a new directory at the specified path and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_create_dir(): void {
            $rel = $this->build_rel( 'dir', 'name' );
            $this->send( $this->fm->create_dir( $rel ), 'kfm_create_dir', $rel );
        }

        /**
         * Handle the 'delete' action.
         * This method deletes a specified file or directory and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_delete(): void {
            $path = $this->rel();
            $this->send( $this->fm->delete( $path ), 'kfm_delete', $path );
        }

        /**
         * Handle the 'rename' action.
         * This method renames a specified file or directory and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_rename(): void {
            $path = $this->rel();
            $name = sanitize_file_name( wp_unslash( $_POST['new_name'] ?? '' ) );
            $this->send( $this->fm->rename( $path, $name ), 'kfm_rename', $path . ' → ' . $name );
        }

        /**
         * Handle the 'copy' action.
         * This method copies a specified file or directory and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_copy(): void {
            $path = $this->rel();
            $dest = sanitize_text_field( wp_unslash( $_POST['dest'] ?? '' ) );
            $this->send( $this->fm->copy( $path, $dest ), 'kfm_copy', $path . ' → ' . $dest );
        }

        /**
         * Handle the 'move' action.
         * This method moves a specified file or directory and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_move(): void {  
            $path = $this->rel();
            $dest = sanitize_text_field( wp_unslash( $_POST['dest'] ?? '' ) );
            $this->send( $this->fm->move( $path, $dest ), 'kfm_move', $path . ' → ' . $dest );
        }

        /**
         * Handle the 'chmod' action.
         * This method changes the permissions of a specified file or directory and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_chmod(): void {
            $path = $this->rel();
            $mode = preg_replace( '/[^0-7]/', '', wp_unslash( $_POST['mode'] ) ?? '' );
            $this->send( $this->fm->chmod( $path, $mode ), 'kfm_chmod', $path . ' ' . $mode );
        }

        /**
         * Handle the 'upload' action.
         * This method uploads a specified file and returns the result in the response
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @return void
         * 
         */
        private function handle_upload(): void {
            
            // check if a file was uploaded and return an error if not
            if ( empty( $_FILES['file'] ) ) {
                wp_send_json_error( [ 'message' => __( 'No file received.', 'kp-file-manager' ) ], 400 );
            }
            $dir = $this->rel( 'dir' );
            $this->send( $this->fm->upload( $dir, $_FILES['file'] ), 'kfm_upload', $dir . '/' . ( $_FILES['file']['name'] ?? '' ) );
        }

        /**
         * Handle the 'download' action.
         * Streams the file to the browser.
         * When $_GET['inline'] === '1' the file is served inline (for image preview);
         * otherwise it is forced as an attachment download.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        private function handle_download(): void {
            $rel  = $this->rel();
            $path = $this->fm->resolve( $rel );

            if ( ! $path || ! is_file( $path ) ) {
                wp_send_json_error( [ 'message' => __( 'File not found.', 'kp-file-manager' ) ], 404 );
            }

            $filename = basename( $path );
            $size     = filesize( $path );
            if ( function_exists( 'finfo_open' ) ) {
                $finfo = finfo_open( FILEINFO_MIME_TYPE );
                $mime  = finfo_file( $finfo, $path );
                finfo_close( $finfo );
            } else {
                $mime = 'application/octet-stream';
            }

            // force specific mime types to be inline ONLY
            $safe_inline_mimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-icon' ];
            $inline = isset( $_GET['inline'] ) && $_GET['inline'] === '1'
                    && in_array( $mime, $safe_inline_mimes, true );

            // Read via file manager
            if ( ! is_readable( $path ) ) {
                wp_send_json_error( [ 'message' => __( 'File is not readable.', 'kp-file-manager' ) ], 403 );
            }

            nocache_headers();
            header( 'Content-Type: ' . $mime );
            header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $filename . '"' );
            header( 'Content-Length: ' . $size );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: DENY' );

            // end the output cleanly
            while ( ob_get_level() ) ob_end_clean();

            KFM_Audit_Log::write( 'kfm_download', $rel, 'ok' );
            readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            exit;
            
        }

        /**
         * Build a relative path from the request data.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         * 
         * @param string $dir_key The key for the directory in the POST data.
         * @param string $name_key The key for the name in the POST data.
         * 
         * @return string The relative path.
         * 
         */
        private function build_rel( string $dir_key, string $name_key ): string {
            $dir  = $this->rel( $dir_key );
            $name = sanitize_file_name( wp_unslash( $_POST[ $name_key ] ?? '' ) );
            return $dir !== '' ? $dir . '/' . $name : $name;
        }
    }
}