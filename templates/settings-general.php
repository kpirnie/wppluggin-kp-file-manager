<?php
/**
 * The template for the General Settings page.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' ); 

?>
<div class="wrap">
    <h1><?php esc_html_e( 'General Settings', 'kp-file-manager' ); ?></h1>
    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'kp-file-manager' ); ?></p></div>
    <?php endif; ?>
    <?php settings_errors( 'kfm_options_group' ); ?>

    <form method="post">
        <?php if ( is_network_admin() ) : ?>
            <?php wp_nonce_field( 'kfm_network_save' ); ?>
            <input type="hidden" name="kfm_network_save" value="1">
        <?php else : ?>
            <?php settings_fields( 'kfm_options_group' ); ?>
        <?php endif; ?>
        <table class="form-table" role="presentation">

            <!-- Base Directory -->
            <tr>
                <th scope="row"><label for="kfm_base_path"><?php _e( 'Base Directory', 'kp-file-manager' ); ?></label></th>
                <td>
                    <div style="display:flex;align-items:center;gap:6px">
                        <input type="text"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_PATH ); ?>"
                               id="kfm_base_path" class="regular-text"
                               value="<?php echo esc_attr( get_option( KFM_Settings::OPTION_PATH, '' ) ); ?>"
                               placeholder="<?php _e( 'leave blank for all of wp-content...', 'kp-file-manager' ); ?>">
                    </div>
                    <p class="description">
                        <?php _e( 'Enter a path inside <code>wp-content/</code><br />', 'kp-file-manager' ); ?>
                        <strong><?php _e( 'Resolved:', 'kp-file-manager' ); ?></strong>
                        <code><?php echo esc_html( KFM_Settings::get_base_path() ); ?></code>
                    </p>
                    <?php if ( ! is_dir( KFM_Settings::get_base_path() ) ) : ?>
                    <div class="notice notice-error inline" style="margin-top:8px">
                        <p><?php _e( 'The configured directory does not exist.', 'kp-file-manager' ); ?></p>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Show Dotfiles -->
            <tr>
                <th scope="row"><?php _e( 'Show Dotfiles', 'kp-file-manager' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_SHOW_DOTFILES ); ?>"
                               value="1" <?php checked( KFM_Settings::show_dotfiles() ); ?>>
                        <?php _e( 'Show files and directories beginning with a dot (.env, .htaccess, .git, etc.)', 'kp-file-manager' ); ?>
                    </label>
                    <p class="description"><?php _e( 'Disabled by default. Specific dotfiles can also be hidden via the path denylist on the Security page.', 'kp-file-manager' ); ?></p>
                </td>
            </tr>

            <?php if ( is_multisite() && is_network_admin() ) : ?>
            <tr>
                <th scope="row"><?php _e( 'Site Access', 'kp-file-manager' ); ?></th>
                <td>
                    <p class="description" style="margin-bottom:10px"><?php _e( 'Enable or disable KFM on individual sites. All sites are enabled by default.', 'kp-file-manager' ); ?></p>
                    <?php
                    $disabled_sites = get_site_option( 'kfm_disabled_sites', [] );
                    $sites = get_sites( [ 'number' => 500 ] );
                    foreach ( $sites as $site ) :
                        $blog_id = (int) $site->blog_id;
                        $details = get_blog_details( $blog_id );
                    ?>
                    <label style="display:block;margin-bottom:6px">
                        <input type="checkbox"
                            name="kfm_disabled_sites[]"
                            value="<?php echo esc_attr( $blog_id ); ?>"
                            <?php checked( in_array( $blog_id, (array) $disabled_sites, true ) ); ?>>
                        <strong><?php echo esc_html( $details->blogname ); ?></strong>
                        <span style="color:#999;font-size:12px"><?php echo esc_html( $details->siteurl ); ?></span>
                        <?php if ( in_array( $blog_id, (array) $disabled_sites, true ) ) : ?>
                            <span style="color:#a00;font-size:11px;margin-left:6px"><?php _e( 'Disabled', 'kp-file-manager' ); ?></span>
                        <?php else : ?>
                            <span style="color:#166534;font-size:11px;margin-left:6px"><?php _e( 'Enabled', 'kp-file-manager' ); ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endif; ?>

            <!-- Audit Log Max Entries -->
            <tr>
                <th scope="row"><label for="kfm_log_max_entries"><?php _e( 'Audit Log Max Entries', 'kp-file-manager' ); ?></label></th>
                <td>
                    <input type="number"
                        name="<?php echo esc_attr( KFM_Settings::OPTION_LOG_ENTRIES ); ?>"
                        id="kfm_log_max_entries" class="small-text"
                        value="<?php echo esc_attr( KFM_Settings::get_log_max_entries() ); ?>"
                        min="10" max="10000" step="10">
                    <p class="description"><?php _e( 'Maximum number of audit log entries to retain (10–10000). Older entries are dropped automatically. A very high value may bloat your database — 100-500 is recommended for most sites.', 'kp-file-manager' ); ?></p>
                </td>
            </tr>

        </table>
        <?php submit_button( __( 'Save Settings', 'kp-file-manager' ) ); ?>
    </form>
</div>
