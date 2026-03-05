<?php
/**
 * Asset Loader class.
 * Handles all asset enqueueing for the file manager.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// Check if the class already exists to prevent redeclaration errors.
if( !class_exists('KFM_Asset_Loader') ) {

    /**
     * Handles all asset enqueueing for KFM — UIKit, CodeMirror, kfm-app.js,
     * admin styles, and JS localisation data.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_Asset_Loader {

        /**
         * enqueues the UIkit assets.
         * This method enqueues the UIkit CSS and JavaScript files from a CDN.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function enqueue_uikit(): void {
            wp_enqueue_style(  'uikit',       'https://cdn.jsdelivr.net/npm/uikit@latest/dist/css/uikit.min.css', [], null, false );
            wp_enqueue_script( 'uikit',       'https://cdn.jsdelivr.net/npm/uikit@latest/dist/js/uikit.min.js',   [], null, true  );
            wp_enqueue_script( 'uikit-icons', 'https://cdn.jsdelivr.net/npm/uikit@latest/dist/js/uikit-icons.min.js', [ 'uikit' ], null, true );
        }

        /**
         * enqueues CodeMirror, the KFM stylesheet, and kfm-app.js with its
         * localised data object. 
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function enqueue_file_manager(): void {

            // enqueue CodeMirror with a basic mode to get the editor assets loaded.
            $cm = wp_enqueue_code_editor( [ 'type' => 'text/plain' ] );
            wp_enqueue_style(  'wp-codemirror' );
            wp_enqueue_script( 'wp-codemirror' );

            // enqueue KFM's custom stylesheet
            wp_enqueue_style(
                'kfm-style',
                KFM_PLUGIN_URL . 'assets/css/kfm-style.css',
                [ 'uikit', 'wp-codemirror' ],
                KFM_VERSION
            );

            // enqueue the main KFM app script
            wp_enqueue_script(
                'kfm-app',
                KFM_PLUGIN_URL . 'assets/js/kfm-app.js',
                [ 'jquery', 'uikit', 'uikit-icons', 'wp-codemirror' ],
                KFM_VERSION,
                true
            );

            // Merge CodeMirror settings into localisation data and pass to JS
            $data               = $this->kfm_localize_data();
            $data['cmSettings'] = $cm;
            wp_localize_script( 'kfm-app', 'KFM', $data );
        }

        /**
         * Enqueues the shared admin stylesheet used on settings, permissions,
         * security, and audit-log pages (everything except the file browser).
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function enqueue_admin_styles(): void {
            wp_enqueue_style( 'kfm-admin', KFM_PLUGIN_URL . 'assets/css/kfm-admin.css', [ 'uikit' ], KFM_VERSION );
        }

        /**
         * Localizes the data for the JavaScript.
         * This method prepares an array of data to be passed to the JavaScript code,
         * including the AJAX URL, nonce, base path, blocked and readonly extensions,
         * chmod floor, allowed operations for the current user,
         * and internationalized strings for various messages.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         *
         */
        private function kfm_localize_data(): array {

            // return the data array to be passed to JS via wp_localize_script
            return [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'kfm_nonce' ),
                'basePath'     => KFM_Settings::get_display_base(),
                'blockedExts'  => KFM_Settings::get_blocked_exts(),
                'readonlyExts' => KFM_Settings::get_readonly_exts(),
                'chmodFloor'   => KFM_Settings::get_chmod_floor(),
                // Pass current user's allowed ops so JS can hide/disable buttons
                'allowedOps'   => $this->current_user_allowed_ops(),
                'i18n'         => [
                    'confirmDelete'    => __( 'Delete selected item(s)? This cannot be undone.', 'kpfm' ),
                    'confirmOverwrite' => __( 'Destination already exists. Overwrite?', 'kpfm' ),
                    'errorGeneric'     => __( 'An error occurred. Please try again.', 'kpfm' ),
                    'saved'            => __( 'File saved successfully.', 'kpfm' ),
                    'loading'          => __( 'Loading…', 'kpfm' ),
                    'warnDangerousFn'  => __( 'This file contains potentially dangerous functions (eval, exec, system, etc.).\n\nSave anyway?', 'kpfm' ),
                ],
            ];
        }

        /**
         * Returns array of op slugs the current user is allowed to perform
         * This method checks the current user's permissions against the defined
         * operations in KFM_Permissions and returns an array of allowed operation slugs.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         *
         */
        private function current_user_allowed_ops(): array {

            // hold the allowed ops for the current user
            $allowed = [];

            // Loop through all defined ops and check if the user has permission for each
            foreach ( array_keys( KFM_Permissions::OPS ) as $op ) {

                // Map op back to a representative action for the check
                $action_map = [
                    'list'   => 'kfm_list',
                    'read'   => 'kfm_read',
                    'write'  => 'kfm_write',
                    'upload' => 'kfm_upload',
                    'rename' => 'kfm_rename',
                    'delete' => 'kfm_delete',
                    'chmod'  => 'kfm_chmod',
                ];

                // Check if the user has permission for this op and add to allowed array if so
                if ( KFM_Permissions::current_user_can_op( $action_map[ $op ] ) ) {
                    $allowed[] = $op;
                }
            }

            // Return the array of allowed ops for the current user
            return $allowed;
        }
    }
}