<?php
/**
 * Admin menu class.
 * Handles registration of the admin menu and rendering of admin pages.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_Admin_Menu') ) {
        
    /**
     * Registers the KFM admin menu pages and renders each admin page.
     * Delegates all asset enqueueing to KFM_Asset_Loader.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_Admin_Menu {

        // Store the admin page hooks to target asset loading
        private array $admin_hooks = [];

        // Asset loader instance for enqueuing scripts and styles
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
         * Wires up the WordPress hooks for admin menu and asset enqueuing.
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
            add_action( 'admin_menu',            [ $this, 'admin_menu' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

            // make sure its available at the network level as well
            add_action( 'network_admin_menu',    [ $this, 'admin_menu' ] );
            add_action( 'network_admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        }

        /**
         * Adds the admin menu items for the file manager.
         * This includes the top-level "File Manager" menu and its submenus:
         *      "Browse Files", "Settings", "Permissions", "Security", and "Audit Log".
         * Each menu item is linked to a corresponding render method that outputs the page content.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function admin_menu(): void {

            // Top-level → file browser
            $browse = add_menu_page(
                __( 'File Manager', 'kp-file-manager' ),
                __( 'File Manager', 'kp-file-manager' ),
                'read',
                'kp-file-manager',
                [ $this, 'render_page' ],
                'dashicons-media-document',
                80
            );

            // file browser submenu (duplicate link for convenience) + settings submenus
            $browse2 = add_submenu_page( 'kp-file-manager',
                __( 'Browse Files', 'kp-file-manager' ),
                __( 'Browse Files', 'kp-file-manager' ),
                'read', 'kp-file-manager', [ $this, 'render_page' ]
            );

            // settings submenu
            $settings = add_submenu_page( 'kp-file-manager',
                __( 'General Settings', 'kp-file-manager' ),
                __( 'Settings', 'kp-file-manager' ),
                'manage_options', 'kfm-settings', [ $this, 'render_settings' ]
            );

            // permissions submenu
            $perms = add_submenu_page( 'kp-file-manager',
                __( 'Role Permissions', 'kp-file-manager' ),
                __( 'Permissions', 'kp-file-manager' ),
                'manage_options', 'kfm-permissions', [ $this, 'render_permissions' ]
            );

            // security submenu
            $security = add_submenu_page( 'kp-file-manager',
                __( 'Security Settings', 'kp-file-manager' ),
                __( 'Security', 'kp-file-manager' ),
                'manage_options', 'kfm-security', [ $this, 'render_security' ]
            );

            // audit log submenu
            $audit = add_submenu_page( 'kp-file-manager',
                __( 'Audit Log', 'kp-file-manager' ),
                __( 'Audit Log', 'kp-file-manager' ),
                'manage_options', 'kfm-audit', [ $this, 'render_audit' ]
            );

            // Store the hooks for our pages so we can target them when enqueuing assets
            $this->admin_hooks = array_filter( [ $browse, $browse2, $settings, $perms, $security, $audit ] );
        }

        /**
         * Enqueues the necessary assets for the file manager
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $hook The current admin page hook
         * @return void
         *
         */
        public function enqueue_assets( string $hook ): void {

            // Only enqueue on our plugin's admin pages
            if ( ! in_array( $hook, $this->admin_hooks, true ) ) return;

            // UIkit is needed on all our admin pages
            $this->asset_loader->enqueue_uikit();

            // Only the file-browser pages need CodeMirror and kfm-app
            $browser_hooks = array_slice( $this->admin_hooks, 0, 2 ); // browse + browse2

            // Enqueue CodeMirror and our main app script only on the file browser pages
            if ( in_array( $hook, $browser_hooks, true ) ) {
                $this->asset_loader->enqueue_file_manager();
            } else {

                // Admin pages just need the shared admin styles
                $this->asset_loader->enqueue_admin_styles();
            }
        }

        /**
         * Renders the main file manager page.
         * method checks if the current user has permission to access
         * the file manager and then includes the corresponding template.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function render_page(): void {

            // Permission check
            if ( ! KFM_Settings::current_user_allowed() ) {
                wp_die( __( 'You do not have permission to access the File Manager.', 'kp-file-manager' ) );
            }

            // Render the file manager page
            include KFM_PLUGIN_DIR . 'templates/file-manager-page.php';
        }

        /**
         * Renders the general settings page.
         * method checks if the current user has the 'manage_options' capability and
         * then includes the corresponding template for the general settings page.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function render_settings(): void {

            // Permission check
            if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
                wp_die( __( 'Insufficient permissions.', 'kp-file-manager' ) );
            }

            // Handle network admin save
            if ( is_network_admin() && isset( $_POST['kfm_network_save'] ) ) {
                check_admin_referer( 'kfm_network_save' );
                $disabled = isset( $_POST['kfm_disabled_sites'] )
                    ? array_values( array_map( 'absint', (array) $_POST['kfm_disabled_sites'] ) )
                    : [];
                update_site_option( 'kfm_disabled_sites', $disabled );
                add_settings_error( 'kfm_options_group', 'saved', __( 'Settings saved.', 'kp-file-manager' ), 'updated' );
            }

            // Render the settings page
            include KFM_PLUGIN_DIR . 'templates/settings-general.php';
        }

        /**
         * Renders the role permissions page.
         * method checks if the current user has the 'manage_options' capability,
         * handles form submissions to save permissions, and then includes
         * the corresponding template for the permissions page.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function render_permissions(): void {

            // Permission check
            if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kp-file-manager' ) );

            // Handle form save (not using register_setting for the matrix – manual save)
            if ( isset( $_POST['kfm_save_permissions'] ) ) {

                // Verify nonce and save permissions
                check_admin_referer( 'kfm_save_permissions' );
                // setup the permissions and save them
                $perms = wp_unslash( $_POST['kfm_perms'] );
                KFM_Permissions::save_from_post( $perms ?? [] );
                add_settings_error( 'kfm_permissions', 'saved', __( 'Permissions saved.', 'kp-file-manager' ), 'updated' );
            }

            // Render the permissions page
            include KFM_PLUGIN_DIR . 'templates/settings-permissions.php';
        }

        /**
         * Renders the security settings page.
         * method checks if the current user has the 'manage_options' capability and
         * then includes the corresponding template for the security settings page.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function render_security(): void {

            // Permission check
            if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kp-file-manager' ) );

            // Render the security settings page
            include KFM_PLUGIN_DIR . 'templates/settings-security.php';
        }

        /**
         * Renders the audit log page.
         * method checks if the current user has the 'manage_options' capability,
         * handles form submissions to clear the log, and then includes
         * the corresponding template for the audit log page.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public function render_audit(): void {

            // Permission check
            if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'kp-file-manager' ) );

            // Handle clear
            if ( isset( $_POST['kfm_clear_log'] ) ) {

                // Verify nonce and clear log
                check_admin_referer( 'kfm_clear_log' );
                KFM_Audit_Log::clear();
                add_settings_error( 'kfm_audit', 'cleared', __( 'Audit log cleared.', 'kp-file-manager' ), 'updated' );
            }

            // Render the audit log page
            include KFM_PLUGIN_DIR . 'templates/settings-audit.php';
        }
    }
}