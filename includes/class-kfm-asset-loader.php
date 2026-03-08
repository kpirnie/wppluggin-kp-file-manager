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
     * Handles all asset enqueueing for KFM — Dashicons, CodeMirror, kfm-app.js,
     * admin styles, and JS localisation data.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_Asset_Loader {

        /**
         * Ensures Dashicons are available for the current page.
         * Previously loaded UIKit from a CDN; now a no-op for scripts since
         * Dashicons are already available in WP admin and enqueued here for
         * front-end (shortcode / block) contexts.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function enqueue_uikit(): void {
            // UIKit removed — enqueue Dashicons so icons work on the frontend too.
            wp_enqueue_style( 'dashicons' );
        }

        /**
         * Enqueues CodeMirror, the KFM stylesheet, and kfm-app.js with its
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

            // Dashicons required on any context (admin already loads them; front-end needs explicit enqueue)
            wp_enqueue_style( 'dashicons' );

            // Enqueue CodeMirror with a basic mode to get the editor assets loaded.
            $cm = wp_enqueue_code_editor( [ 'type' => 'text/plain' ] );
            wp_enqueue_style(  'wp-codemirror' );
            wp_enqueue_script( 'wp-codemirror' );

            // Enqueue KFM's custom stylesheet
            wp_enqueue_style(
                'kfm-style',
                KFM_PLUGIN_URL . 'assets/css/kfm-style.css',
                [ 'dashicons', 'wp-codemirror' ],
                KFM_VERSION
            );

            // Enqueue the main KFM app script (jQuery is the only runtime dependency now)
            wp_enqueue_script(
                'kfm-app',
                KFM_PLUGIN_URL . 'assets/js/kfm-app.js',
                [ 'jquery', 'wp-codemirror' ],
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
            wp_enqueue_style( 'kfm-admin', KFM_PLUGIN_URL . 'assets/css/kfm-admin.css', [], KFM_VERSION );
        }

        /**
         * Localizes the data for the JavaScript.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         *
         */
        private function kfm_localize_data(): array {

            return [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'kfm_nonce' ),
                'basePath'     => KFM_Settings::get_display_base(),
                'blockedExts'  => KFM_Settings::get_blocked_exts(),
                'readonlyExts' => KFM_Settings::get_readonly_exts(),
                'chmodFloor'   => KFM_Settings::get_chmod_floor(),
                'allowedOps'   => $this->current_user_allowed_ops(),
                'i18n'         => [
                    'confirmDelete'    => __( 'Delete selected item(s)? This cannot be undone.', 'kp-file-manager' ),
                    'confirmOverwrite' => __( 'Destination already exists. Overwrite?', 'kp-file-manager' ),
                    'errorGeneric'     => __( 'An error occurred. Please try again.', 'kp-file-manager' ),
                    'saved'            => __( 'File saved successfully.', 'kp-file-manager' ),
                    'loading'          => __( 'Loading…', 'kp-file-manager' ),
                    'warnDangerousFn'  => __( 'This file contains potentially dangerous functions (eval, exec, system, etc.).\n\nSave anyway?', 'kp-file-manager' ),
                ],
            ];
        }

        /**
         * Returns array of op slugs the current user is allowed to perform.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return array
         *
         */
        private function current_user_allowed_ops(): array {

            $allowed = [];

            foreach ( array_keys( KFM_Permissions::OPS ) as $op ) {

                $action_map = [
                    'list'   => 'kfm_list',
                    'read'   => 'kfm_read',
                    'write'  => 'kfm_write',
                    'upload' => 'kfm_upload',
                    'rename' => 'kfm_rename',
                    'delete' => 'kfm_delete',
                    'chmod'  => 'kfm_chmod',
                ];

                if ( KFM_Permissions::current_user_can_op( $action_map[ $op ] ) ) {
                    $allowed[] = $op;
                }
            }

            return $allowed;
        }
    }
}
