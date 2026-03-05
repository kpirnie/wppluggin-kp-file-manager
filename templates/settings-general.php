<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'File Manager – General Settings', 'kfm-file-manager' ); ?></h1>
    <?php settings_errors( 'kfm_options_group' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'kfm_options_group' ); ?>
        <table class="form-table" role="presentation">

            <!-- Minimum Role -->
            <tr>
                <th scope="row"><label for="kfm_allowed_role"><?php esc_html_e( 'Minimum Role', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <select name="<?php echo esc_attr( KFM_Settings::OPTION_ROLE ); ?>" id="kfm_allowed_role">
                        <?php $current = KFM_Settings::get_allowed_role(); ?>
                        <?php foreach ( KFM_Settings::get_role_options() as $slug => $label ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Users at this role or higher can access the File Manager. Use the Permissions page to control what each role can do.', 'kfm-file-manager' ); ?></p>
                    <?php if ( $current === 'anonymous' ) : ?>
                    <div class="notice notice-warning inline" style="margin-top:8px">
                        <p><strong><?php esc_html_e( 'Warning:', 'kfm-file-manager' ); ?></strong>
                        <?php esc_html_e( 'Anonymous access is enabled. Review the Permissions page to ensure the anonymous role can only perform safe operations.', 'kfm-file-manager' ); ?></p>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Base Directory -->
            <tr>
                <th scope="row"><label for="kfm_base_path"><?php esc_html_e( 'Base Directory', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <div style="display:flex;align-items:center;gap:6px">
                        <span style="font-family:monospace;color:#999">wp-content /</span>
                        <input type="text"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_PATH ); ?>"
                               id="kfm_base_path" class="regular-text"
                               value="<?php echo esc_attr( get_option( KFM_Settings::OPTION_PATH, '' ) ); ?>"
                               placeholder="uploads (leave blank for all of wp-content)">
                    </div>
                    <p class="description">
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

        </table>
        <?php submit_button( __( 'Save Settings', 'kfm-file-manager' ) ); ?>
    </form>
</div>
