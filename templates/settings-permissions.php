<?php 
/**
 * Role permissions settings page template.
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
    <h1><?php _e( 'Role Permissions', 'kp-file-manager' ); ?></h1>

    <?php settings_errors( 'kfm_permissions' ); ?>

    <p><?php _e( 'Control which operations each role can perform. Administrators always have full access and cannot be restricted.', 'kp-file-manager' ); ?></p>

    <form method="post">
        <?php wp_nonce_field( 'kfm_save_permissions' ); ?>
        <input type="hidden" name="kfm_save_permissions" value="1">

        <?php
        $matrix = KFM_Permissions::get_matrix();
        $ops    = KFM_Permissions::OPS;
        $roles  = KFM_Permissions::all_roles();
        ?>

        <table class="widefat kfm-perms-table" style="max-width:900px;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="min-width:140px;padding:10px 12px;text-align:left;background:#f8f9fa;border-bottom:2px solid #ccd0d4">
                        <?php _e( 'Role', 'kp-file-manager' ); ?>
                    </th>
                    <?php foreach ( $ops as $op => $desc ) : ?>
                    <th style="padding:10px 8px;text-align:center;background:#f8f9fa;border-bottom:2px solid #ccd0d4;min-width:80px">
                        <span title="<?php echo esc_attr( $desc ); ?>" style="cursor:help;border-bottom:1px dotted #666">
                            <?php echo esc_html( ucfirst( $op ) ); ?>
                        </span>
                    </th>
                    <?php endforeach; ?>
                    <th style="padding:10px 8px;text-align:center;background:#f8f9fa;border-bottom:2px solid #ccd0d4;min-width:80px">
                        <?php _e( 'All', 'kp-file-manager' ); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $roles as $slug => $label ) :
                    $is_admin   = ( $slug === 'administrator' );
                    $role_ops   = $matrix[ $slug ] ?? [];
                    $row_bg     = $is_admin ? '#f0f7ff' : '';
                    $is_anon    = ( $slug === 'anonymous' );
                ?>
                <tr style="background:<?php echo esc_attr( $row_bg ); ?>;border-bottom:1px solid #e2e8f0"
                    data-role="<?php echo esc_attr( $slug ); ?>">

                    <td style="padding:9px 12px;font-weight:600">
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $is_admin ) : ?>
                            <span style="font-size:10px;background:#1e87f0;color:#fff;padding:1px 6px;border-radius:3px;font-weight:normal;margin-left:5px;vertical-align:middle">
                                <?php _e( 'Full', 'kp-file-manager' ); ?>
                            </span>
                        <?php elseif ( $is_anon ) : ?>
                            <span style="font-size:10px;background:#f59e0b;color:#fff;padding:1px 6px;border-radius:3px;font-weight:normal;margin-left:5px;vertical-align:middle">
                                <?php _e( 'Anon', 'kp-file-manager' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <?php foreach ( array_keys( $ops ) as $op ) :
                        $checked  = ! empty( $role_ops[ $op ] );
                        $disabled = $is_admin;
                    ?>
                    <td style="padding:9px 8px;text-align:center">
                        <input type="checkbox"
                               class="kfm-perm-cb"
                               data-role="<?php echo esc_attr( $slug ); ?>"
                               name="kfm_perms[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $op ); ?>]"
                               value="1"
                               <?php checked( $checked || $disabled ); ?>
                               <?php disabled( $disabled ); ?>
                               style="width:16px;height:16px;cursor:<?php echo $disabled ? 'not-allowed' : 'pointer'; ?>">
                    </td>
                    <?php endforeach; ?>

                    <!-- Toggle-all cell -->
                    <td style="padding:9px 8px;text-align:center">
                        <?php if ( ! $is_admin ) : ?>
                        <button type="button"
                                class="button button-small kfm-toggle-row"
                                data-role="<?php echo esc_attr( $slug ); ?>"
                                style="font-size:11px;padding:0 8px;height:24px">
                            <?php _e( 'Toggle', 'kp-file-manager' ); ?>
                        </button>
                        <?php else : ?>
                        <span style="color:#999;font-size:11px"><?php _e( 'Locked', 'kp-file-manager' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Operation legend -->
        <div style="margin-top:16px;padding:14px 18px;background:#f8f9fa;border:1px solid #e2e8f0;border-radius:4px;max-width:900px">
            <strong style="font-size:12px"><?php _e( 'Operation descriptions:', 'kp-file-manager' ); ?></strong>
            <ul style="margin:8px 0 0;columns:2;font-size:12px;list-style:disc;padding-left:20px">
                <?php foreach ( $ops as $op => $desc ) : ?>
                <li style="margin-bottom:4px"><strong><?php echo esc_html( ucfirst( $op ) ); ?></strong> — <?php echo esc_html( $desc ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <p style="margin-top:16px">
            <?php submit_button( __( 'Save Permissions', 'kp-file-manager' ), 'primary', 'submit', false ); ?>
            &nbsp;
            <button type="button" id="kfm-reset-perms" class="button button-secondary">
                <?php _e( 'Reset to Defaults', 'kp-file-manager' ); ?>
            </button>
        </p>
    </form>
</div>

<script>
jQuery( function( $ ) {
    // Toggle all checkboxes in a row
    $( '.kfm-toggle-row' ).on( 'click', function () {
        var role = $( this ).data( 'role' );
        var $cbs = $( '.kfm-perm-cb[data-role="' + role + '"]' );
        var anyUnchecked = $cbs.filter( ':not(:checked)' ).length > 0;
        $cbs.prop( 'checked', anyUnchecked );
    } );

    // Reset to defaults (admins full, everyone else none)
    $( '#kfm-reset-perms' ).on( 'click', function () {
        if ( ! confirm( '<?php echo esc_js( __( 'Reset all permissions to defaults? Administrators keep full access, all other roles will have no permissions.', 'kp-file-manager' ) ); ?>' ) ) return;
        $( '.kfm-perm-cb:not(:disabled)' ).prop( 'checked', false );
    } );
} );
</script>
