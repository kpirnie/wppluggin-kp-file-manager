<?php
/**
 * Settings page content for the Security tab.
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
    <h1><?php _e( 'Security Settings', 'kpfm' ); ?></h1>
    <?php settings_errors( 'kfm_options_group' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'kfm_security_group' ); ?>
        <table class="form-table" role="presentation">

            <!-- Blocked Upload Extensions -->
            <tr>
                <th scope="row"><label for="kfm_blocked_exts"><?php _e( 'Blocked Upload Extensions', 'kpfm' ); ?></label></th>
                <td>
                    <textarea name="<?php echo esc_attr( KFM_Settings::OPTION_BLOCKED_EXTS ); ?>"
                              id="kfm_blocked_exts" class="large-text" rows="3"
                    ><?php echo esc_textarea( get_option( KFM_Settings::OPTION_BLOCKED_EXTS, KFM_Settings::default_blocked_exts() ) ); ?></textarea>
                    <p class="description"><?php _e( 'Comma-separated list of extensions that cannot be uploaded. Lowercase, no dots. MIME type is also verified server-side regardless of this list.', 'kpfm' ); ?></p>
                    <p><button type="button" class="button button-small" id="kfm-reset-blocked">
                        <?php _e( 'Reset to defaults', 'kpfm' ); ?>
                    </button></p>
                </td>
            </tr>

            <!-- Read-only Extensions -->
            <tr>
                <th scope="row"><label for="kfm_readonly_exts"><?php _e( 'Read-only Extensions', 'kpfm' ); ?></label></th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr( KFM_Settings::OPTION_READONLY_EXTS ); ?>"
                           id="kfm_readonly_exts" class="regular-text"
                           value="<?php echo esc_attr( get_option( KFM_Settings::OPTION_READONLY_EXTS, '' ) ); ?>"
                           placeholder="e.g. php,py">
                    <p class="description"><?php _e( 'Comma-separated extensions that can be viewed but not edited or saved. Leave blank to allow editing all types.', 'kpfm' ); ?></p>
                </td>
            </tr>

            <!-- Path Denylist -->
            <tr>
                <th scope="row"><label for="kfm_path_denylist"><?php _e( 'Hidden / Blocked Paths', 'kpfm' ); ?></label></th>
                <td>
                    <textarea name="<?php echo esc_attr( KFM_Settings::OPTION_PATH_DENYLIST ); ?>"
                              id="kfm_path_denylist" class="large-text" rows="5"
                              placeholder="plugins/kfm-file-manager&#10;.git&#10;mu-plugins"
                    ><?php echo esc_textarea( get_option( KFM_Settings::OPTION_PATH_DENYLIST, '' ) ); ?></textarea>
                    <p class="description"><?php _e( 'One entry per line, relative to the base directory. Matching paths and all their contents are hidden and inaccessible to all users including admins.', 'kpfm' ); ?></p>
                </td>
            </tr>

            <!-- Chmod Floor -->
            <tr>
                <th scope="row"><label for="kfm_chmod_floor"><?php _e( 'Minimum Permissions Floor', 'kpfm' ); ?></label></th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr( KFM_Settings::OPTION_CHMOD_FLOOR ); ?>"
                           id="kfm_chmod_floor" class="small-text"
                           value="<?php echo esc_attr( KFM_Settings::get_chmod_floor() ); ?>"
                           placeholder="0" maxlength="4" style="font-family:monospace">
                    <p class="description"><?php esc_html_e( 'Minimum octal permissions that can be set (e.g. 0400). Use 0 to disable the floor. World-writable (o+w) is always blocked regardless of this setting.', 'kpfm' ); ?></p>
                </td>
            </tr>

            <!-- Email Alerts -->
            <tr>
                <th scope="row"><?php _e( 'Destructive Operation Alerts', 'kpfm' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr( KFM_Settings::OPTION_AUDIT_ALERTS ); ?>"
                               value="1" <?php checked( get_option( KFM_Settings::OPTION_AUDIT_ALERTS, '0' ), '1' ); ?>>
                        <?php printf(
                            esc_html__( 'Email %s when a delete or chmod operation is performed.', 'kpfm' ),
                            '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
                        ); ?>
                    </label>
                </td>
            </tr>

            <!-- Alert Email Addresses -->
            <tr>
                <th scope="row"><label for="kfm_alert_emails"><?php _e( 'Alert Email Address(es)', 'kpfm' ); ?></label></th>
                <td>
                    <input type="text"
                        name="<?php echo esc_attr( KFM_Settings::OPTION_ALERT_EMAILS ); ?>"
                        id="kfm_alert_emails" class="large-text"
                        value="<?php echo esc_attr( get_option( KFM_Settings::OPTION_ALERT_EMAILS, '' ) ); ?>"
                        placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                    <p class="description"><?php printf(
                        __( 'Semicolon-delimited list of addresses to notify. Leave blank to use the site admin address (%s).', 'kpfm' ),
                        '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
                    ); ?></p>
                </td>
            </tr>

        </table>

        <?php submit_button( __( 'Save Security Settings', 'kpfm' ) ); ?>
    </form>

    <hr>
    <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:4px;padding:14px 18px;max-width:800px">
        <h3 style="margin-top:0;font-size:14px"><?php _e( 'Security measures always active', 'kpfm' ); ?></h3>
        <ul style="margin:0;font-size:13px">
            <li><?php _e( 'All paths verified against sandbox via realpath() — no path traversal possible.', 'kpfm' ); ?></li>
            <li><?php _e( 'Every AJAX request requires a valid WordPress nonce and matching referer origin.', 'kpfm' ); ?></li>
            <li><?php _e( 'Upload MIME type verified server-side via finfo regardless of extension.', 'kpfm' ); ?></li>
            <li><?php _e( 'PHP content is rejected on upload regardless of file extension.', 'kpfm' ); ?></li>
            <li><?php _e( 'Write operations are rate-limited to 60 per minute per user.', 'kpfm' ); ?></li>
            <li><?php _e( 'World-writable permissions (o+w) are always blocked.', 'kpfm' ); ?></li>
            <li><?php _e( 'All write/delete/chmod operations are logged to the Audit Log.', 'kpfm' ); ?></li>
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
