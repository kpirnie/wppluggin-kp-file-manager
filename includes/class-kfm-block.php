<?php
/** 
 * Block class.
 * Registers the block for the file manager.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
*/

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// Check if the class already exists to prevent redeclaration errors.
if( !class_exists('KFM_Block') ) {

    /**
     * Handles Gutenberg block registration and server-side rendering for KFM.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_Block {

        /**
         * Wires up the WordPress init hook for block registration.
         * Called once during plugin boot.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function register(): void {
            add_action( 'init', [ $this, 'register_block' ] );
        }

        /**
         * Registers the Gutenberg block for the file manager
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function register_block(): void {

            // Check if Gutenberg is available
            if ( ! function_exists( 'register_block_type' ) ) return;

            // Register block assets
            $block_dir  = KFM_PLUGIN_DIR . 'blocks/file-manager/';
            $block_url  = KFM_PLUGIN_URL . 'blocks/file-manager/';
            $asset_file = $block_dir . 'index.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ], 'version' => KFM_VERSION ];

            // Register block editor script and styles
            wp_register_script( 'kfm-block-editor',       $block_url . 'index.js',   $asset['dependencies'], $asset['version'], true );
            wp_register_style(  'kfm-block-editor-style',  $block_url . 'editor.css', [], KFM_VERSION );
            wp_register_style(  'kfm-block-style',         $block_url . 'style.css',  [], KFM_VERSION );

            // Register the block type with render callback
            register_block_type( $block_dir, [
                'editor_script'   => 'kfm-block-editor',
                'editor_style'    => 'kfm-block-editor-style',
                'style'           => 'kfm-block-style',
                'render_callback' => [ $this, 'render_block' ],
            ] );
        }

        /**
         * Renders the Gutenberg block for the file manager
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param array $attributes The block attributes
         * @return string The rendered block HTML
         *
         */
        public function render_block( array $attributes ): string {

            // start output buffering to capture the included template's output
            ob_start();
            include KFM_PLUGIN_DIR . 'blocks/file-manager/render.php';

            // return the captured output as the block's rendered content
            return ob_get_clean();
        }
    }
}