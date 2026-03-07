<?php
/**
 * Plugin Name:       KP File Manager
 * Plugin URI:        https://kevinpirnie.com
 * Description:       A secure, role-aware file manager for WordPress. Browse, edit, upload, and manage files directly from the admin — no FTP required. Sandboxed to wp-content with per-role permissions, audit logging, and a built-in syntax-highlighting code editor.
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
add_action( 'plugins_loaded', function() {

    // In multisite, if the plugin is network-activated, check if the current site is disabled before initializing
    if ( is_multisite()
        && is_plugin_active_for_network( plugin_basename( __FILE__ ) )
        && ! is_network_admin()
    ) {

        // For AJAX requests originating from the network admin, skip the site check
        $referer = wp_get_referer();
        $is_network_ajax = wp_doing_ajax() && $referer
            && strpos( $referer, network_admin_url() ) === 0;

        // If not an AJAX request from the network admin, check if the current site is in the disabled list
        if ( ! $is_network_ajax ) {
            $disabled = get_site_option( 'kfm_disabled_sites', [] );
            if ( in_array( (int) get_current_blog_id(), (array) $disabled, true ) ) return;
        }
    }

    // Initialize the plugin.
    KFM_Plugin::init();
} );

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