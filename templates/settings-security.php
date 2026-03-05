<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'File Manager – Security Settings', 'kfm-file-manager' ); ?></h1>
    <?php settings_errors( 'kfm_options_group' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'kfm_security_group' ); ?>
        <table class="form-table" role="presentation">

            <!-- Blocked Upload Extensions -->
            <tr>
                <th scope="row"><label for="kfm_blocked_exts"><?php esc_html_e( 'Blocked Upload Extensions', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <textarea name="<?php echo esc_attr( KFM_Settings::OPTION_BLOCKED_EXTS ); ?>"
                              id="kfm_blocked_exts" class="large-text" rows="3"
                    ><?php echo esc_textarea( get_option( KFM_Settings::OPTION_BLOCKED_EXTS, KFM_Settings::default_blocked_exts() ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Comma-separated list of extensions that cannot be uploaded. Lowercase, no dots. MIME type is also verified server-side regardless of this list.', 'kfm-file-manager' ); ?></p>
                    <p><button type="button" class="button button-small" id="kfm-reset-blocked">
                        <?php esc_html_e( 'Reset to defaults', 'kfm-file-manager' ); ?>
                    </button></p>
                </td>
            </tr>

            <!-- Read-only Extensions -->
            <tr>
                <th scope="row"><label for="kfm_readonly_exts"><?php esc_html_e( 'Read-only Extensions', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr( KFM_Settings::OPTION_READONLY_EXTS ); ?>"
                           id="kfm_readonly_exts" class="regular-text"
                           value="<?php echo esc_attr( get_option( KFM_Settings::OPTION_READONLY_EXTS, '' ) ); ?>"
                           placeholder="e.g. php,py">
                    <p class="description"><?php esc_html_e( 'Comma-separated extensions that can be viewed but not edited or saved. Leave blank to allow editing all types.', 'kfm-file-manager' ); ?></p>
                </td>
            </tr>

            <!-- Path Denylist -->
            <tr>
                <th scope="row"><label for="kfm_path_denylist"><?php esc_html_e( 'Hidden / Blocked Paths', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <textarea name="<?php echo esc_attr( KFM_Settings::OPTION_PATH_DENYLIST ); ?>"
                              id="kfm_path_denylist" class="large-text" rows="5"
                              placeholder="plugins/kfm-file-manager&#10;.git&#10;mu-plugins"
                    ><?php echo esc_textarea( get_option( KFM_Settings::OPTION_PATH_DENYLIST, '' ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One entry per line, relative to the base directory. Matching paths and all their contents are hidden and inaccessible to all users including admins.', 'kfm-file-manager' ); ?></p>
                </td>
            </tr>

            <!-- Chmod Floor -->
            <tr>
                <th scope="row"><label for="kfm_chmod_floor"><?php esc_html_e( 'Minimum Permissions Floor', 'kfm-file-manager' ); ?></label></th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr( KFM_Settings::OPTION_CHMOD_FLOOR ); ?>"
                           id="kfm_chmod_floor" class="small-text"
                           value="<?php echo esc_attr( KFM_Settings::get_chmod_floor() ); ?>"
                           placeholder="0" maxlength="4" style="font-family:monospace">
                    <p class="description"><?php esc_html_e( 'Minimum octal permissions that can be set (e.g. 0400). Use 0 to disable the floor. World-writable (o+w) is always blocked regardless of this setting.', 'kfm-file-manager' ); ?></p>
                </td>
            </tr>

            <!-- Email Alerts -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Destructive Operation Alerts', 'kfm-file-manager' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_AUDIT_ALERTS ); ?>"
                               value="1" <?php checked( get_option( KFM_Settings::OPTION_AUDIT_ALERTS, '0' ), '1' ); ?>>
                        <?php printf(
                            esc_html__( 'Email %s when a delete or chmod operation is performed.', 'kfm-file-manager' ),
                            '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
                        ); ?>
                    </label>
                </td>
            </tr>

        </table>

        <?php submit_button( __( 'Save Security Settings', 'kfm-file-manager' ) ); ?>
    </form>

    <hr>
    <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:4px;padding:14px 18px;max-width:800px">
        <h3 style="margin-top:0;font-size:14px"><?php esc_html_e( 'Security measures always active', 'kfm-file-manager' ); ?></h3>
        <ul style="margin:0;font-size:13px">
            <li><?php esc_html_e( 'All paths verified against sandbox via realpath() — no path traversal possible.', 'kfm-file-manager' ); ?></li>
            <li><?php esc_html_e( 'Every AJAX request requires a valid WordPress nonce and matching referer origin.', 'kfm-file-manager' ); ?></li>
            <li><?php esc_html_e( 'Upload MIME type verified server-side via finfo regardless of extension.', 'kfm-file-manager' ); ?></li>
            <li><?php esc_html_e( 'PHP content is rejected on upload regardless of file extension.', 'kfm-file-manager' ); ?></li>
            <li><?php esc_html_e( 'Write operations are rate-limited to 60 per minute per user.', 'kfm-file-manager' ); ?></li>
            <li><?php esc_html_e( 'World-writable permissions (o+w) are always blocked.', 'kfm-file-manager' ); ?></li>
            <li><?php esc_html_e( 'All write/delete/chmod operations are logged to the Audit Log.', 'kfm-file-manager' ); ?></li>
        </ul>
    </div>
</div>

<script>
jQuery( function( $ ) {
    $( '#kfm-reset-blocked' ).on( 'click', function () {
        $( '#kfm_blocked_exts' ).val( '<?php echo esc_js( KFM_Settings::default_blocked_exts() ); ?>' );
    } );
} );
</script>
