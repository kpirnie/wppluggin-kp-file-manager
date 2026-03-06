<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

/**
 * Per-role operation permission matrix.
 *
 * Stored as: kfm_role_permissions → [ role_slug => [ op => bool, … ], … ]
 *
 * Three-tier auth cascade in KFM_Ajax::dispatch():
 *   1. Global role gate (must have minimum role to see the plugin at all)
 *   2. Role-level op check (this class)
 *   3. Anonymous op check (anonymous row in this matrix)
 */
class KFM_Permissions {

    const OPTION_KEY = 'kfm_role_permissions';

    /** All operations the matrix covers */
    const OPS = [
        'list'   => 'Browse / list directories',
        'read'   => 'Open / view files',
        'write'  => 'Create & edit files and folders',
        'upload' => 'Upload files',
        'rename' => 'Rename & move',
        'delete' => 'Delete files and folders',
        'chmod'  => 'Change permissions',
    ];

    /** Operations granted by default to administrator (safety net) */
    const ADMIN_DEFAULT_OPS = [ 'list', 'read', 'write', 'upload', 'rename', 'delete', 'chmod' ];

    /* ------------------------------------------------------------------ */
    /*  Getters                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Return the full matrix, filling in safe defaults for any missing roles.
     *
     * @return array<string, array<string, bool>>
     */
    public static function get_matrix(): array {
        $saved  = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) $saved = [];

        $matrix = [];
        foreach ( self::all_roles() as $slug => $label ) {
            if ( isset( $saved[ $slug ] ) && is_array( $saved[ $slug ] ) ) {
                $matrix[ $slug ] = $saved[ $slug ];
            } else {
                $matrix[ $slug ] = self::default_ops_for_role( $slug );
            }
        }
        return $matrix;
    }

    /**
     * Return the ops array for a single role, filling defaults if not set.
     *
     * @return array<string, bool>
     */
    public static function get_role_ops( string $role ): array {
        $matrix = self::get_matrix();
        return $matrix[ $role ] ?? self::default_ops_for_role( $role );
    }

    /**
     * Check whether a specific role has permission for an operation.
     */
    public static function role_can( string $role, string $op ): bool {
        $ops = self::get_role_ops( $role );
        return ! empty( $ops[ $op ] );
    }

    /**
     * Check whether the current user (or anonymous visitor) can perform $op.
     * Administrators always get full access regardless of matrix.
     */
    public static function current_user_can_op( string $ajax_action ): bool {
        $op = self::action_to_op( $ajax_action );

        // Admins bypass matrix entirely
        if ( current_user_can( 'manage_options' ) ) return true;

        if ( ! is_user_logged_in() ) {
            return self::role_can( 'anonymous', $op );
        }

        $user = wp_get_current_user();

        // Walk roles in priority order; grant if ANY of the user's roles allows it
        foreach ( (array) $user->roles as $role ) {
            if ( self::role_can( $role, $op ) ) return true;
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Map AJAX action names → logical op keys.
     */
    public static function action_to_op( string $action ): string {
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
            'kfm_download'    => 'read',
        ];
        return $map[ $action ] ?? 'write';
    }

    /**
     * All WP roles plus anonymous pseudo-role.
     *
     * @return array<string, string>  slug => label
     */
    public static function all_roles(): array {
        global $wp_roles;
        $roles = [];
        foreach ( $wp_roles->roles as $slug => $role ) {
            $roles[ $slug ] = translate_user_role( $role['name'] );
        }
        $roles['anonymous'] = __( 'Anonymous (not logged in)', 'kfm-file-manager' );
        return $roles;
    }

    /**
     * Safe defaults per role: admins get everything, everyone else gets nothing.
     *
     * @return array<string, bool>
     */
    private static function default_ops_for_role( string $role ): array {
        $ops    = [];
        $is_all = ( $role === 'administrator' );
        foreach ( array_keys( self::OPS ) as $op ) {
            $ops[ $op ] = $is_all ? true : false;
        }
        return $ops;
    }

    /* ------------------------------------------------------------------ */
    /*  Save                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Sanitise and save raw POST data from the permissions form.
     *
     * @param array $raw  $_POST['kfm_perms'] structure
     */
    public static function save_from_post( array $raw ): void {
        $matrix = [];
        foreach ( self::all_roles() as $slug => $label ) {
            $matrix[ $slug ] = [];
            foreach ( array_keys( self::OPS ) as $op ) {
                $matrix[ $slug ][ $op ] = isset( $raw[ $slug ][ $op ] );
            }
            // Administrators always retain full permissions
            if ( $slug === 'administrator' ) {
                foreach ( array_keys( self::OPS ) as $op ) {
                    $matrix[ $slug ][ $op ] = true;
                }
            }
        }
        update_option( self::OPTION_KEY, $matrix, false );
    }
}
