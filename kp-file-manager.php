<?php
/**
 * Plugin Name:       KP – File Manager
 * Plugin URI:        https://kevinpirnie.com
 * Description:       A secure, role-aware file manager for WordPress with a built-in CodeMirror text editor. All operations are sandboxed inside wp-content.
 * Version:           1.0.57
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Kevin Pirnie
 * License:           GPL-2.0+
 * Text Domain:       kpfm
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

// Define constants.
define( 'KFM_VERSION',    '1.0.57' );
define( 'KFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files.
require_once KFM_PLUGIN_DIR . 'includes/class-kfm-settings.php';
require_once KFM_PLUGIN_DIR . 'includes/class-kfm-permissions.php';
require_once KFM_PLUGIN_DIR . 'includes/class-kfm-audit-log.php';
require_once KFM_PLUGIN_DIR . 'includes/class-kfm-rate-limiter.php';
require_once KFM_PLUGIN_DIR . 'includes/class-kfm-file-manager.php';
require_once KFM_PLUGIN_DIR . 'includes/class-kfm-ajax.php';

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'KFM_Plugin', 'init' ] );

/**
 * Main plugin class.
 * Handles initialization, admin menu, asset loading, Gutenberg block registration, 
 * and shortcode rendering.
 * 
 * @package KPFileManager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 * 
 */
final class KFM_Plugin {

    /** Admin page hook suffixes – populated in admin_menu, used in enqueue_assets */
    private static array $admin_hooks = [];

    /**
     * Initializes the plugin: registers settings, AJAX handlers, admin menu, assets, and Gutenberg block.
     * This method is hooked to 'plugins_loaded' to ensure it runs after all plugins are loaded 
     * 
     * @package KPFileManager
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

        // Admin menu and assets
        add_action( 'admin_menu',            [ self::class, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'init',                  [ self::class, 'register_block' ] );
        add_action( 'init',                  [ self::class, 'maybe_render_frontend' ] );
    }

    /**
     * Adds the admin menu items for the file manager.
     * This includes the top-level "File Manager" menu and its submenus: 
     *      "Browse Files", "Settings", "Permissions", "Security", and "Audit Log".
     * Each menu item is linked to a corresponding render method that outputs the page content.
     *  
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     * @return void
     * 
     */
    public static function admin_menu(): void {

        // Top-level → file browser
        $browse = add_menu_page(
            __( 'File Manager', 'kpfm' ),
            __( 'File Manager', 'kpfm' ),
            'read',
            'kp-file-manager',
            [ self::class, 'render_page' ],
            'dashicons-media-document',
            80
        );

        // file browser submenu (duplicate link for convenience) + settings submenus
        $browse2 = add_submenu_page( 'kp-file-manager',
            __( 'Browse Files', 'kpfm' ),
            __( 'Browse Files', 'kpfm' ),
            'read', 'kp-file-manager', [ self::class, 'render_page' ]
        );

        // settings submenu
        $settings = add_submenu_page( 'kp-file-manager',
            __( 'General Settings', 'kpfm' ),
            __( 'Settings', 'kpfm' ),
            'manage_options', 'kfm-settings', [ self::class, 'render_settings' ]
        );

        // permissions submenu
        $perms = add_submenu_page( 'kp-file-manager',
            __( 'Role Permissions', 'kpfm' ),
            __( 'Permissions', 'kpfm' ),
            'manage_options', 'kfm-permissions', [ self::class, 'render_permissions' ]
        );

        // security submenu
        $security = add_submenu_page( 'kp-file-manager',
            __( 'Security Settings', 'kpfm' ),
            __( 'Security', 'kpfm' ),
            'manage_options', 'kfm-security', [ self::class, 'render_security' ]
        );

        // audit log submenu
        $audit = add_submenu_page( 'kp-file-manager',
            __( 'Audit Log', 'kpfm' ),
            __( 'Audit Log', 'kpfm' ),
            'manage_options', 'kfm-audit', [ self::class, 'render_audit' ]
        );

        // Store the hooks for our pages so we can target them when enqueuing assets
        self::$admin_hooks = array_filter( [ $browse, $browse2, $settings, $perms, $security, $audit ] );
    }

    /**
     * Renders the main file manager page. 
     * This method checks if the current user has permission to access 
     * the file manager and then includes the corresponding template.
     * 
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     * @return void
     * 
     */
    public static function render_page(): void {

        // Permission check
        if ( ! KFM_Settings::current_user_allowed() ) {
            wp_die( __( 'You do not have permission to access the File Manager.', 'kpfm' ) );
        }

        // Render the file manager page
        include KFM_PLUGIN_DIR . 'templates/file-manager-page.php';
    }

    /**
     * Renders the general settings page.
     * This method checks if the current user has the 'manage_options' capability and 
     * then includes the corresponding template for the general settings page.
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     * @return void
     */
    public static function render_settings(): void {

        // Permission check
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kpfm' ) );
        
        // Render the settings page
        include KFM_PLUGIN_DIR . 'templates/settings-general.php';
    }

    /**
     * Renders the role permissions page.
     * This method checks if the current user has the 'manage_options' capability, 
     * handles form submissions to save permissions, and then includes 
     * the corresponding template for the permissions page. 
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     * @return void
     */
    public static function render_permissions(): void {
        
        // Permission check
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kpfm' ) );
        
        // Handle form save (not using register_setting for the matrix – manual save)
        if ( isset( $_POST['kfm_save_permissions'] ) ) {

            // Verify nonce and save permissions
            check_admin_referer( 'kfm_save_permissions' );
            KFM_Permissions::save_from_post( $_POST['kfm_perms'] ?? [] );
            add_settings_error( 'kfm_permissions', 'saved', __( 'Permissions saved.', 'kpfm' ), 'updated' );
        }

        // Render the permissions page
        include KFM_PLUGIN_DIR . 'templates/settings-permissions.php';
    }

    /**
     * Renders the security settings page.
     * This method checks if the current user has the 'manage_options' capability and 
     * then includes the corresponding template for the security settings page.
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     * @return void
     */
    public static function render_security(): void {
        
        // Permission check
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kpfm' ) );
        
        // Render the security settings page
        include KFM_PLUGIN_DIR . 'templates/settings-security.php';
    }

    /**
     * Renders the audit log page.
     * This method checks if the current user has the 'manage_options' capability, 
     * handles form submissions to clear the log, and then includes 
     * the corresponding template for the audit log page.
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     * @return void
     */
    public static function render_audit(): void {
        
        // Permission check
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kpfm' ) );
        
        // Handle clear
        if ( isset( $_POST['kfm_clear_log'] ) ) {

            // Verify nonce and clear log
            check_admin_referer( 'kfm_clear_log' );
            KFM_Audit_Log::clear();
            add_settings_error( 'kfm_audit', 'cleared', __( 'Audit log cleared.', 'kpfm' ), 'updated' );
        }

        // Render the audit log page
        include KFM_PLUGIN_DIR . 'templates/settings-audit.php';
    }

    /**
     * Enqueues the UIkit assets.
     * This method enqueues the UIkit CSS and JavaScript files from a CDN.
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     * @return void
     */
    private static function enqueue_uikit(): void {
        wp_enqueue_style(  'uikit',       'https://cdn.jsdelivr.net/npm/uikit@latest/dist/css/uikit.min.css', [], null, false );
        wp_enqueue_script( 'uikit',       'https://cdn.jsdelivr.net/npm/uikit@latest/dist/js/uikit.min.js',   [], null, true  );
        wp_enqueue_script( 'uikit-icons', 'https://cdn.jsdelivr.net/npm/uikit@latest/dist/js/uikit-icons.min.js', [ 'uikit' ], null, true );
    }

    /**
     * Localizes the data for the JavaScript.
     * This method prepares an array of data to be passed to the JavaScript code, 
     * including the AJAX URL, nonce, base path, blocked and readonly extensions, 
     * chmod floor, allowed operations for the current user, 
     * and internationalized strings for various messages. 
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     * @return array
     */
    private static function kfm_localize_data(): array {
        return [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'kfm_nonce' ),
            'basePath'     => KFM_Settings::get_display_base(),
            'blockedExts'  => KFM_Settings::get_blocked_exts(),
            'readonlyExts' => KFM_Settings::get_readonly_exts(),
            'chmodFloor'   => KFM_Settings::get_chmod_floor(),
            // Pass current user's allowed ops so JS can hide/disable buttons
            'allowedOps'   => self::current_user_allowed_ops(),
            'i18n'         => [
                'confirmDelete'   => __( 'Delete selected item(s)? This cannot be undone.', 'kpfm' ),
                'confirmOverwrite'=> __( 'Destination already exists. Overwrite?', 'kpfm' ),
                'errorGeneric'    => __( 'An error occurred. Please try again.', 'kpfm' ),
                'saved'           => __( 'File saved successfully.', 'kpfm' ),
                'loading'         => __( 'Loading…', 'kpfm' ),
                'warnDangerousFn' => __( "This file contains potentially dangerous functions (eval, exec, system, etc.).\n\nSave anyway?", 'kpfm' ),
            ],
        ];
    }

    /**
     * Returns array of op slugs the current user is allowed to perform
     * This method checks the current user's permissions against the defined 
     * operations in KFM_Permissions and returns an array of allowed operation slugs.
     * 
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     * @return array
     * 
     */
    private static function current_user_allowed_ops(): array {
        
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

    /**
     * Enqueues the necessary assets for the file manager
     *
     * @package KPFileManager
     * @since 1.0.0
     * @static
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     * 
     * @param string $hook The current admin page hook
     * @return void
     * 
     */
    public static function enqueue_assets( string $hook ): void {

        // Only enqueue on our plugin's admin pages
        if ( ! in_array( $hook, self::$admin_hooks, true ) ) return;

        // UIkit is needed on all our admin pages
        self::enqueue_uikit();

        // Only the file-browser pages need CodeMirror and kfm-app
        $browser_hooks = array_slice( self::$admin_hooks, 0, 2 ); // browse + browse2

        // Enqueue CodeMirror and our main app script only on the file browser pages
        if ( in_array( $hook, $browser_hooks, true ) ) {
            $cm = wp_enqueue_code_editor( [ 'type' => 'text/plain' ] );
            wp_enqueue_style(  'wp-codemirror' );
            wp_enqueue_script( 'wp-codemirror' );
            wp_enqueue_style(  'kfm-style', KFM_PLUGIN_URL . 'assets/css/kfm-style.css', [ 'uikit', 'wp-codemirror' ], KFM_VERSION );
            wp_enqueue_script( 'kfm-app',   KFM_PLUGIN_URL . 'assets/js/kfm-app.js',     [ 'jquery', 'uikit', 'uikit-icons', 'wp-codemirror' ], KFM_VERSION, true );
            $data = self::kfm_localize_data();
            $data['cmSettings'] = $cm;
            wp_localize_script( 'kfm-app', 'KFM', $data );
        } else {
        
            // Admin pages just need the shared admin styles
            wp_enqueue_style( 'kfm-admin', KFM_PLUGIN_URL . 'assets/css/kfm-admin.css', [ 'uikit' ], KFM_VERSION );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Gutenberg block                                                     */
    /* ------------------------------------------------------------------ */

    public static function register_block(): void {
        if ( ! function_exists( 'register_block_type' ) ) return;

        $block_dir  = KFM_PLUGIN_DIR . 'blocks/file-manager/';
        $block_url  = KFM_PLUGIN_URL . 'blocks/file-manager/';
        $asset_file = $block_dir . 'index.asset.php';
        $asset      = file_exists( $asset_file )
            ? require $asset_file
            : [ 'dependencies' => [ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ], 'version' => KFM_VERSION ];

        wp_register_script( 'kfm-block-editor',      $block_url . 'index.js',   $asset['dependencies'], $asset['version'], true );
        wp_register_style(  'kfm-block-editor-style', $block_url . 'editor.css', [], KFM_VERSION );
        wp_register_style(  'kfm-block-style',        $block_url . 'style.css',  [], KFM_VERSION );

        register_block_type( $block_dir, [
            'editor_script'   => 'kfm-block-editor',
            'editor_style'    => 'kfm-block-editor-style',
            'style'           => 'kfm-block-style',
            'render_callback' => [ self::class, 'render_block' ],
        ] );
    }

    public static function render_block( array $attributes ): string {
        ob_start();
        include KFM_PLUGIN_DIR . 'blocks/file-manager/render.php';
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /*  Shortcode                                                           */
    /* ------------------------------------------------------------------ */

    public static function maybe_render_frontend(): void {
        add_shortcode( 'kfm_file_manager', [ self::class, 'shortcode' ] );
    }

    public static function shortcode(): string {
        if ( ! KFM_Settings::current_user_allowed() ) {
            return '<p>' . esc_html__( 'You do not have permission to access the File Manager.', 'kfm-file-manager' ) . '</p>';
        }
        self::enqueue_uikit();
        wp_enqueue_code_editor( [ 'type' => 'text/plain' ] );
        wp_enqueue_style(  'wp-codemirror' );
        wp_enqueue_script( 'wp-codemirror' );
        wp_enqueue_style(  'kfm-style', KFM_PLUGIN_URL . 'assets/css/kfm-style.css', [ 'uikit', 'wp-codemirror' ], KFM_VERSION );
        wp_enqueue_script( 'kfm-app',   KFM_PLUGIN_URL . 'assets/js/kfm-app.js',     [ 'jquery', 'uikit', 'uikit-icons', 'wp-codemirror' ], KFM_VERSION, true );
        wp_localize_script( 'kfm-app', 'KFM', self::kfm_localize_data() );
        ob_start();
        include KFM_PLUGIN_DIR . 'templates/file-manager-page.php';
        return ob_get_clean();
    }
}
