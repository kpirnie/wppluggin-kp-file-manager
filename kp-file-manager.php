<?php
/**
 * Plugin Name:       KP – File Manager
 * Plugin URI:        https://kevinpirnie.com
 * Description:       A secure file manager for WordPress with a built-in CodeMirror text editor. All operations are sandboxed inside wp-content.
 * Version:           1.0.57
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Kevin Pirnie
 * License:           GPLv3
 * Text Domain:       kpfm
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// Define constants.
define( 'KFM_VERSION',    '1.0.57' );
define( 'KFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// autoload classes in the includes directory with a KFM_ prefix
spl_autoload_register( function ( string $class_name ): void {
    // Only autoload classes that start with KFM_
    if ( strpos( $class_name, 'KFM_' ) !== 0 ) return;
    $file = KFM_PLUGIN_DIR . 'includes/class-'
            . strtolower( str_replace( '_', '-', $class_name ) )
            . '.php';

    // If the file exists, require it.
    if ( file_exists( $file ) ) require_once $file;
} );

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'KFM_Plugin', 'init' ] );

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_Plugin') ) {

    /**
     * Main plugin class.
     * Handles initialization, admin menu, asset loading, Gutenberg block registration,
     * and shortcode rendering.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    final class KFM_Plugin {

        /**
         * Initializes the plugin: registers settings, AJAX handlers, admin menu, assets, and Gutenberg block.
         * This method is hooked to 'plugins_loaded' to ensure it runs after all plugins are loaded
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @static
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public static function init(): void {

            // Initialize settings and permissions
            $settings = new KFM_Settings();
            $settings->register();

            // Initialize file manager and AJAX handlers
            $fm   = new KFM_File_Manager( $settings );
            $ajax = new KFM_Ajax( $fm, $settings );
            $ajax->register();

            // Initialize asset loader
            $assets = new KFM_Asset_Loader();

            // Admin menu and page renders
            $admin_menu = new KFM_Admin_Menu( $assets );
            $admin_menu->register();

            // Gutenberg block
            $block = new KFM_Block();
            $block->register();

            // Shortcode
            $shortcode = new KFM_Shortcode( $assets );
            $shortcode->register();
        }
    }
}