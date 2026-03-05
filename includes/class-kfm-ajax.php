<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles all AJAX endpoints for KFM.
 *
 * Auth cascade (in order):
 *   1. Global role gate  – KFM_Settings::current_user_allowed()
 *   2. Nonce + referer
 *   3. Per-role op gate  – KFM_Permissions::current_user_can_op()
 *   4. Rate limit        – KFM_Rate_Limiter::check()
 */
class KFM_Ajax {

    private KFM_File_Manager $fm;
    private KFM_Settings     $settings;

    private const PUBLIC_ACTIONS = [
        'kfm_list', 'kfm_read', 'kfm_write', 'kfm_create_file',
        'kfm_create_dir', 'kfm_delete', 'kfm_rename', 'kfm_copy',
        'kfm_move', 'kfm_chmod', 'kfm_upload',
    ];

    public function __construct( KFM_File_Manager $fm, KFM_Settings $settings ) {
        $this->fm       = $fm;
        $this->settings = $settings;
    }

    public function register(): void {
        foreach ( self::PUBLIC_ACTIONS as $action ) {
            add_action( 'wp_ajax_' . $action,        [ $this, 'dispatch' ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'dispatch' ] );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    public function dispatch(): void {
        // 1 ── Global role gate ──────────────────────────────────────────
        if ( ! KFM_Settings::current_user_allowed() ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'kfm-file-manager' ) ], 403 );
        }

        // 2 ── Nonce ─────────────────────────────────────────────────────
        check_ajax_referer( 'kfm_nonce', 'nonce' );

        // 2b ── Referer origin check ──────────────────────────────────────
        $referer = wp_get_referer();
        if ( $referer ) {
            $ref_host  = wp_parse_url( $referer, PHP_URL_HOST );
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( $ref_host !== $site_host ) {
                KFM_Audit_Log::write( 'dispatch', '', 'blocked – bad referer: ' . $ref_host );
                wp_send_json_error( [ 'message' => __( 'Request origin mismatch.', 'kfm-file-manager' ) ], 403 );
            }
        }

        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        // 3 ── Per-role operation gate ────────────────────────────────────
        if ( ! KFM_Permissions::current_user_can_op( $action ) ) {
            KFM_Audit_Log::write( $action, $this->rel(), 'blocked – role permission denied' );
            wp_send_json_error( [ 'message' => __( 'Your role does not have permission to perform this operation.', 'kfm-file-manager' ) ], 403 );
        }

        // 4 ── Rate limit ──────────────────────────────────────────────────
        $rate = KFM_Rate_Limiter::check( $action );
        if ( is_wp_error( $rate ) ) {
            wp_send_json_error( [ 'message' => $rate->get_error_message() ], 429 );
        }

        // ── Route ────────────────────────────────────────────────────────
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
            default:
                wp_send_json_error( [ 'message' => __( 'Unknown action.', 'kfm-file-manager' ) ], 400 );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function send( array|true|WP_Error $result, string $action = '', string $path = '' ): void {
        if ( is_wp_error( $result ) ) {
            if ( $action ) KFM_Audit_Log::write( $action, $path, 'error: ' . $result->get_error_message() );
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
        }
        if ( $action && in_array( $action, KFM_Audit_Log::LOGGED_ACTIONS, true ) ) {
            KFM_Audit_Log::write( $action, $path, 'ok' );
        }
        wp_send_json_success( $result === true ? [] : $result );
    }

    private function rel( string $key = 'path' ): string {
        return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ?? '' ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Handlers                                                            */
    /* ------------------------------------------------------------------ */

    private function handle_list(): void        { $this->send( $this->fm->list_dir( $this->rel() ) ); }
    private function handle_read(): void        { $this->send( $this->fm->read_file( $this->rel() ) ); }

    private function handle_write(): void {
        $path = $this->rel();
        $this->send( $this->fm->write_file( $path, wp_unslash( $_POST['content'] ?? '' ) ), 'kfm_write', $path );
    }

    private function handle_create_file(): void {
        $rel = $this->build_rel( 'dir', 'name' );
        $this->send( $this->fm->create_file( $rel ), 'kfm_create_file', $rel );
    }

    private function handle_create_dir(): void {
        $rel = $this->build_rel( 'dir', 'name' );
        $this->send( $this->fm->create_dir( $rel ), 'kfm_create_dir', $rel );
    }

    private function handle_delete(): void {
        $path = $this->rel();
        $this->send( $this->fm->delete( $path ), 'kfm_delete', $path );
    }

    private function handle_rename(): void {
        $path = $this->rel();
        $name = sanitize_file_name( wp_unslash( $_POST['new_name'] ?? '' ) );
        $this->send( $this->fm->rename( $path, $name ), 'kfm_rename', $path . ' → ' . $name );
    }

    private function handle_copy(): void {
        $path = $this->rel();
        $dest = sanitize_text_field( wp_unslash( $_POST['dest'] ?? '' ) );
        $this->send( $this->fm->copy( $path, $dest ), 'kfm_copy', $path . ' → ' . $dest );
    }

    private function handle_move(): void {
        $path = $this->rel();
        $dest = sanitize_text_field( wp_unslash( $_POST['dest'] ?? '' ) );
        $this->send( $this->fm->move( $path, $dest ), 'kfm_move', $path . ' → ' . $dest );
    }

    private function handle_chmod(): void {
        $path = $this->rel();
        $mode = preg_replace( '/[^0-7]/', '', $_POST['mode'] ?? '' );
        $this->send( $this->fm->chmod( $path, $mode ), 'kfm_chmod', $path . ' ' . $mode );
    }

    private function handle_upload(): void {
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file received.', 'kfm-file-manager' ) ], 400 );
        }
        $dir = $this->rel( 'dir' );
        $this->send( $this->fm->upload( $dir, $_FILES['file'] ), 'kfm_upload', $dir . '/' . ( $_FILES['file']['name'] ?? '' ) );
    }

    private function build_rel( string $dir_key, string $name_key ): string {
        $dir  = $this->rel( $dir_key );
        $name = sanitize_file_name( wp_unslash( $_POST[ $name_key ] ?? '' ) );
        return $dir !== '' ? $dir . '/' . $name : $name;
    }
}
