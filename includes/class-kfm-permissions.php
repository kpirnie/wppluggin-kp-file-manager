<?php
/**
 * File Manager permissions class.
 * Handles the per-role permissions matrix and related logic.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' ); 

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_Permissions') ) {

    /**
     * Handles the permissions matrix for KP File Manager, including getters and saving logic.
     * 
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     */
    class KFM_Permissions {

        // Option key for storing the permissions matrix in the WP options table
        const OPTION_KEY = 'kfm_role_permissions';

        // Logical operations that can be granted or denied per role
        const OPS = [
            'list'   => 'Browse / list directories',
            'read'   => 'Open / view files',
            'write'  => 'Create & edit files and folders',
            'upload' => 'Upload files',
            'rename' => 'Rename & move',
            'delete' => 'Delete files and folders',
            'chmod'  => 'Change permissions',
        ];

        // Default ops for administrators (all true); other roles default to all false
        const ADMIN_DEFAULT_OPS = [ 'list', 'read', 'write', 'upload', 'rename', 'delete', 'chmod' ];

        /**
         * Return the full matrix, filling in safe defaults for any missing roles.
         * 
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array<string, array<string, bool>>
         * 
         */
        public static function get_matrix(): array {

            // Get the saved matrix from the database, which may be incomplete or malformed
            $saved  = get_option( self::OPTION_KEY, [] );

            // Ensure it's an array before processing; if not, reset to empty array to avoid errors
            if ( ! is_array( $saved ) ) $saved = [];

            // Build a complete matrix by iterating over all roles and filling in defaults where needed
            $matrix = [];

            // Loop through all roles (including 'anonymous') and populate the matrix with saved values or defaults
            foreach ( self::all_roles() as $slug => $label ) {

                // If the saved matrix has an entry for this role and it's an array, use it; otherwise, use safe defaults
                if ( isset( $saved[ $slug ] ) && is_array( $saved[ $slug ] ) ) {
                    $matrix[ $slug ] = $saved[ $slug ];
                } else {
                    $matrix[ $slug ] = self::default_ops_for_role( $slug );
                }
            }

            // Return the complete matrix, which is guaranteed to have entries for all roles and all ops
            return $matrix;
        }

        /**
         * Return the ops array for a single role, filling defaults if not set.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $role The role slug to retrieve permissions for.
         * @return array<string, bool>
         */
        public static function get_role_ops( string $role ): array {

            // Get the full matrix and return the ops for the specified role, or defaults if the role is missing
            $matrix = self::get_matrix();
            return $matrix[ $role ] ?? self::default_ops_for_role( $role );
        }

        /**
         * Check if a role can perform a specific operation.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $role The role slug to check.
         * @param string $op   The operation to check.
         * @return bool
         */
        public static function role_can( string $role, string $op ): bool {

            // Get the ops for the role and check if the specified operation is allowed (true)
            $ops = self::get_role_ops( $role );
            return ! empty( $ops[ $op ] );
        }

        /**
         * Check if the current user can perform a specific operation.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $ajax_action The AJAX action to check.
         * @return bool
         */
        public static function current_user_can_op( string $ajax_action ): bool {

            // Map the AJAX action to a logical operation (e.g., 'kfm_read' => 'read')
            $op = self::action_to_op( $ajax_action );

            // Admins bypass matrix entirely
            if ( current_user_can( 'manage_options' ) ) return true;

            // If the user is not logged in, check the 'anonymous' role permissions
            if ( ! is_user_logged_in() ) {
                return self::role_can( 'anonymous', $op );
            }

            // get the current user
            $user = wp_get_current_user();

            // Walk roles in priority order; grant if ANY of the user's roles allows it
            foreach ( (array) $user->roles as $role ) {
                if ( self::role_can( $role, $op ) ) return true;
            }

            // If no roles allow the operation, deny by default
            return false;
        }

        /**
         * Map an AJAX action to a logical operation.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $action The AJAX action to map.
         * @return string
         */
        public static function action_to_op( string $action ): string {
            
            // Map of AJAX actions to logical operations
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

            // Return the mapped operation, or 'write' as a safe default if the action is unrecognized
            return $map[ $action ] ?? 'write';
        }

        /**
         * Get a list of all available roles.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         */
        public static function all_roles(): array {

            // Get all roles from WordPress
            global $wp_roles;

            // Build an array of role slugs and their translated names for display purposes
            $roles = [];

            // Loop through the registered roles in WordPress and add them to the array
            foreach ( $wp_roles->roles as $slug => $role ) {
                $roles[ $slug ] = translate_user_role( $role['name'] );
            }

            // Add a special 'anonymous' role for users who are not logged in, with a translated label
            $roles['anonymous'] = __( 'Anonymous (not logged in)', 'kpfm' );

            // Return the complete list of roles
            return $roles;
        }

        /**
         * Get the default operations for a given role.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $role The role for which to get default operations.
         * @return array
         */
        private static function default_ops_for_role( string $role ): array {

            // setup the return array
            $ops    = [];

            // Administrators get all permissions by default
            $is_all = ( $role === 'administrator' );

            // loop through all defined operations and set to true for admins, false for others
            foreach ( array_keys( self::OPS ) as $op ) {
                $ops[ $op ] = $is_all ? true : false;
            }

            // return the default ops for this role
            return $ops;
        }

        /**
         * Save permissions from a POST request.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param array $raw The raw permission data from the POST request.
         * @return void
         */
        public static function save_from_post( array $raw ): void {

            // hold the new matrix to be saved
            $matrix = [];

            // loop through all roles
            foreach ( self::all_roles() as $slug => $label ) {

                // initialize the ops array for this role
                $matrix[ $slug ] = [];

                // loop through all operations and set to true if the checkbox was checked in the form (exists in $raw), false otherwise
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

            // Save the new matrix to the database, using the defined option key and avoiding autoloading for performance
            update_option( self::OPTION_KEY, $matrix, false );
        }
    }
}