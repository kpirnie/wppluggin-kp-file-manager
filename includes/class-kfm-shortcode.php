<?php
/**
 * Shortcode rendering class.
 * Registers and renders the [kfm_file_manager] shortcode for front-end embedding.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if ( ! class_exists( 'KFM_Shortcode' ) ) {

    /**
     * Registers and renders the [kfm_file_manager] shortcode for front-end embedding.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_Shortcode {

        // hold the asset loader instance to enqueue necessary scripts/styles when rendering the shortcode
        private KFM_Asset_Loader $asset_loader;

        /**
         * Constructor.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param KFM_Asset_Loader $asset_loader
         *
         */
        public function __construct( KFM_Asset_Loader $asset_loader ) {
            $this->asset_loader = $asset_loader;
        }

        /**
         * Creates a shortcode for the file manager
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function register(): void {
            add_action( 'init', [ $this, 'maybe_render_frontend' ] );
        }

        /**
         * Creates a shortcode for the file manager
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function maybe_render_frontend(): void {
            add_shortcode( 'kfm_file_manager', [ $this, 'shortcode' ] );
        }

        /**
         * Shortcode callback to render the file manager on the frontend
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string The rendered HTML for the file manager or an error message
         *
         */
        public function shortcode(): string {

            // Permission check – only render if user has access, otherwise show message
            if ( ! KFM_Settings::current_user_allowed() ) {
                return '<p>' . esc_html__( 'You do not have permission to access the File Manager.', 'kfm-file-manager' ) . '</p>';
            }

            // Enqueue necessary assets for the frontend file manager
            $this->asset_loader->enqueue_uikit();
            $this->asset_loader->enqueue_file_manager();

            // start output buffering to capture the included template's output
            ob_start();
            include KFM_PLUGIN_DIR . 'templates/file-manager-page.php';

            // return the captured output as the shortcode's rendered content
            return ob_get_clean();
        }
    }
}