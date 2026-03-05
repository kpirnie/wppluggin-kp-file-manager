<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lightweight audit log stored as a ring buffer in wp_options.
 * Keeps the last 500 entries.
 */
class KFM_Audit_Log {

    const OPTION_KEY  = 'kfm_audit_log';
    const MAX_ENTRIES = 500;

    /** Actions that always get logged regardless of success/fail */
    const LOGGED_ACTIONS = [
        'kfm_write', 'kfm_create_file', 'kfm_create_dir',
        'kfm_delete', 'kfm_rename', 'kfm_copy', 'kfm_move',
        'kfm_chmod', 'kfm_upload',
    ];

    /** Actions that should email the admin on success */
    const ALERT_ACTIONS = [ 'kfm_delete', 'kfm_chmod' ];

    public static function write( string $action, string $path, string $result = 'ok' ): void {
        $user    = is_user_logged_in() ? wp_get_current_user()->user_login : 'anonymous';
        $ip      = self::client_ip();
        $entry   = [
            'ts'     => time(),
            'user'   => $user,
            'ip'     => $ip,
            'action' => $action,
            'path'   => $path,
            'result' => $result,
        ];

        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) $log = [];

        $log[] = $entry;

        // Trim to ring buffer size
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );

        // Email alert for destructive successful ops
        if ( $result === 'ok' && in_array( $action, self::ALERT_ACTIONS, true ) ) {
            $enabled = get_option( 'kfm_audit_email_alerts', '0' );
            if ( $enabled === '1' ) {
                self::send_alert( $entry );
            }
        }
    }

    public static function get( int $limit = 100 ): array {
        $log = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $log ) ) return [];
        return array_reverse( array_slice( $log, -$limit ) );
    }

    public static function clear(): void {
        update_option( self::OPTION_KEY, [], false );
    }

    private static function send_alert( array $entry ): void {
        $admin  = get_option( 'admin_email' );
        $site   = get_bloginfo( 'name' );
        $labels = [
            'kfm_delete' => 'DELETE',
            'kfm_chmod'  => 'CHMOD',
        ];
        $label  = $labels[ $entry['action'] ] ?? strtoupper( $entry['action'] );

        wp_mail(
            $admin,
            "[{$site}] KFM Security Alert: {$label}",
            sprintf(
                "Action:  %s\nPath:    %s\nUser:    %s\nIP:      %s\nTime:    %s\n",
                $entry['action'],
                $entry['path'],
                $entry['user'],
                $entry['ip'],
                gmdate( 'Y-m-d H:i:s', $entry['ts'] ) . ' UTC'
            )
        );
    }

    private static function client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $k ] )[0] );
            }
        }
        return '0.0.0.0';
    }
}
