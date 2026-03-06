<?php
/**
 * Uninstall handler.
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all options, site options, and transients created by KFM.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Only run if WordPress is uninstalling the plugin — never directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ── Single-site option keys ───────────────────────────────────────────────────
$options = [
    'kfm_base_path',
    'kfm_blocked_exts',
    'kfm_readonly_exts',
    'kfm_path_denylist',
    'kfm_show_dotfiles',
    'kfm_chmod_floor',
    'kfm_audit_email_alerts',
    'kfm_audit_log',
    'kfm_role_permissions',
    'kfm_log_max_entries',
    'kfm_alert_emails',
    'kfm_site_disabled',
];

// ── Network / multisite ───────────────────────────────────────────────────────
$site_options = [
    'kfm_disabled_sites',
];

if ( is_multisite() ) {

    // Clean up every site in the network
    $sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
    foreach ( $sites as $blog_id ) {
        switch_to_blog( $blog_id );

        foreach ( $options as $key ) {
            delete_option( $key );
        }

        // Remove rate limiter transients for this site
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_kfm_rate_%'
             OR option_name LIKE '_transient_timeout_kfm_rate_%'"
        );

        restore_current_blog();
    }

    // Remove network-level site options
    foreach ( $site_options as $key ) {
        delete_site_option( $key );
    }

} else {

    // Single site cleanup
    foreach ( $options as $key ) {
        delete_option( $key );
    }

    // Remove rate limiter transients
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_kfm_rate_%'
         OR option_name LIKE '_transient_timeout_kfm_rate_%'"
    );
}