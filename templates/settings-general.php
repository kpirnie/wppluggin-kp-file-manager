<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'File Manager - General Settings', 'kfm-file-manager' ); ?></h1>
    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'kfm-file-manager' ); ?></p></div>
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
                <th scope="row"><label for="kfm_base_path"><?php esc_html_e( 'Base Directory', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <div style="display:flex;align-items:center;gap:6px">
                        <input type="text"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_PATH ); ?>"
                               id="kfm_base_path" class="regular-text"
                               value="<?php echo esc_attr( get_option( KFM_Settings::OPTION_PATH, '' ) ); ?>"
                               placeholder="leave blank for all of wp-content...">
                    </div>
                    <p class="description">
                        Enter a path inside <code>wp-content/</code><br />
                        <strong><?php esc_html_e( 'Resolved:', 'kfm-file-manager' ); ?></strong>
                        <code><?php echo esc_html( KFM_Settings::get_base_path() ); ?></code>
                    </p>
                    <?php if ( ! is_dir( KFM_Settings::get_base_path() ) ) : ?>
                    <div class="notice notice-error inline" style="margin-top:8px">
                        <p><?php esc_html_e( 'The configured directory does not exist.', 'kfm-file-manager' ); ?></p>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Show Dotfiles -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Show Dotfiles', 'kfm-file-manager' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_SHOW_DOTFILES ); ?>"
                               value="1" <?php checked( KFM_Settings::show_dotfiles() ); ?>>
                        <?php esc_html_e( 'Show files and directories beginning with a dot (.env, .htaccess, .git, etc.)', 'kfm-file-manager' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Disabled by default. Specific dotfiles can also be hidden via the path denylist on the Security page.', 'kfm-file-manager' ); ?></p>
                </td>
            </tr>

            <?php if ( is_multisite() && is_network_admin() ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Site Access', 'kfm-file-manager' ); ?></th>
                <td>
                    <p class="description" style="margin-bottom:10px"><?php esc_html_e( 'Enable or disable KFM on individual sites. All sites are enabled by default.', 'kfm-file-manager' ); ?></p>
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
                            <span style="color:#a00;font-size:11px;margin-left:6px"><?php esc_html_e( 'Disabled', 'kfm-file-manager' ); ?></span>
                        <?php else : ?>
                            <span style="color:#166534;font-size:11px;margin-left:6px"><?php esc_html_e( 'Enabled', 'kfm-file-manager' ); ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endif; ?>

            <!-- Audit Log Max Entries -->
            <tr>
                <th scope="row"><label for="kfm_log_max_entries"><?php esc_html_e( 'Audit Log Max Entries', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <input type="number"
                        name="<?php echo esc_attr( KFM_Settings::OPTION_LOG_ENTRIES ); ?>"
                        id="kfm_log_max_entries" class="small-text"
                        value="<?php echo esc_attr( KFM_Settings::get_log_max_entries() ); ?>"
                        min="10" max="10000" step="10">
                    <p class="description"><?php esc_html_e( 'Maximum number of audit log entries to retain (10–10000). Older entries are dropped automatically. A very high value may bloat your database — 100–500 is recommended for most sites.', 'kfm-file-manager' ); ?></p>
                </td>
            </tr>

        </table>
        <?php submit_button( __( 'Save Settings', 'kfm-file-manager' ) ); ?>
    </form>
</div>
