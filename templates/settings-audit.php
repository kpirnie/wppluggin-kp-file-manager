<?php
/**
 * The audit log page template.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' ); 

// setup the audit log page data
$per_page    = 50;
$all_entries = KFM_Audit_Log::get( );
$total       = count( $all_entries );

// setup the filters to be used in the filter form and for filtering the entries
$filter_user   = sanitize_text_field( $_GET['kfm_user']   ?? '' );
$filter_action = sanitize_key(        $_GET['kfm_action'] ?? '' );
$filter_result = sanitize_key(        $_GET['kfm_result'] ?? '' );

// apply the filters to the entries
if ( $filter_user   ) $all_entries = array_values( array_filter( $all_entries, fn( $e ) => stripos( $e['user'], $filter_user ) !== false ) );
if ( $filter_action ) $all_entries = array_values( array_filter( $all_entries, fn( $e ) => $e['action'] === $filter_action ) );
if ( $filter_result ) {
    if ( $filter_result === 'ok' )    $all_entries = array_values( array_filter( $all_entries, fn( $e ) => $e['result'] === 'ok' ) );
    if ( $filter_result === 'error' ) $all_entries = array_values( array_filter( $all_entries, fn( $e ) => $e['result'] !== 'ok' ) );
}

// calculate pagination values based on the filtered entries
$filtered_total = count( $all_entries );
$page_num       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset         = ( $page_num - 1 ) * $per_page;
$page_entries   = array_slice( $all_entries, $offset, $per_page );
$total_pages    = max( 1, (int) ceil( $filtered_total / $per_page ) );

// Unique actions for filter dropdown
$all_raw     = KFM_Audit_Log::get( );
$all_actions = array_unique( array_column( $all_raw, 'action' ) );
sort( $all_actions );

/**
 * Generates a URL for the audit log page with optional query parameters.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 * 
 * @param array $extra Extra query parameters.
 * @return string The generated URL.
 */
if ( ! function_exists( 'kfm_audit_url' ) ) :
    function kfm_audit_url( array $extra = [] ): string {
        return admin_url( 'admin.php?' . http_build_query( array_merge(
            [ 'page' => 'kfm-audit' ],
            $extra
        ) ) );
    }
endif;
?>
<div class="wrap">
    <h1 style="display:flex;align-items:center;gap:12px">
        <?php esc_html_e( 'Audit Log', 'kpfm' ); ?>
        <span style="font-size:13px;font-weight:normal;color:#666">
            <?php printf( esc_html__( '%d total entries', 'kpfm' ), $total ); ?>
        </span>
    </h1>

    <?php settings_errors( 'kfm_audit' ); ?>

    <!-- ─── Filter bar ────────────────────────────────────── -->
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px">

        <form method="get" style="display:contents">
            <input type="hidden" name="page" value="kfm-audit">

            <input type="text" name="kfm_user" class="regular-text"
                   placeholder="<?php esc_attr_e( 'Filter by user…', 'kpfm' ); ?>"
                   value="<?php echo esc_attr( $filter_user ); ?>"
                   style="max-width:180px">

            <select name="kfm_action">
                <option value=""><?php esc_html_e( 'All actions', 'kpfm' ); ?></option>
                <?php foreach ( $all_actions as $a ) : ?>
                <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $filter_action, $a ); ?>>
                    <?php echo esc_html( str_replace( 'kfm_', '', $a ) ); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="kfm_result">
                <option value=""><?php esc_html_e( 'All results', 'kpfm' ); ?></option>
                <option value="ok"    <?php selected( $filter_result, 'ok' ); ?>><?php esc_html_e( 'OK only', 'kpfm' ); ?></option>
                <option value="error" <?php selected( $filter_result, 'error' ); ?>><?php esc_html_e( 'Errors / blocked', 'kpfm' ); ?></option>
            </select>

            <?php submit_button( __( 'Filter', 'kpfm' ), 'secondary', '', false, [ 'style' => 'height:30px;padding:0 10px' ] ); ?>

            <?php if ( $filter_user || $filter_action || $filter_result ) : ?>
            <a href="<?php echo esc_url( kfm_audit_url() ); ?>" class="button button-secondary" style="height:30px;line-height:30px;padding:0 10px">
                <?php esc_html_e( 'Clear', 'kpfm' ); ?>
            </a>
            <?php endif; ?>
        </form>

        <?php if ( $total > 0 ) : ?>
        <form method="post" style="margin-left:auto">
            <?php wp_nonce_field( 'kfm_clear_log' ); ?>
            <button type="submit" name="kfm_clear_log" value="1" class="button button-secondary"
                    style="color:#a00;border-color:#a00"
                    onclick="return confirm( '<?php echo esc_js( __( 'Permanently clear all log entries?', 'kpfm' ) ); ?>' )">
                <?php esc_html_e( 'Clear Entire Log', 'kpfm' ); ?>
            </button>
        </form>
        <?php endif; ?>

    </div>

    <?php if ( empty( $page_entries ) ) : ?>
    <p><?php esc_html_e( 'No log entries found.', 'kpfm' ); ?></p>

    <?php else : ?>

    <!-- ─── Results count + pagination ───────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <span style="font-size:13px;color:#666">
            <?php printf(
                esc_html__( 'Showing %d-%d of %d', 'kpfm' ),
                $offset + 1,
                min( $offset + $per_page, $filtered_total ),
                $filtered_total
            ); ?>
        </span>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;gap:4px">
            <?php if ( $page_num > 1 ) : ?>
            <a class="button button-small" href="<?php echo esc_url( kfm_audit_url( array_filter( [ 'paged' => $page_num - 1, 'kfm_user' => $filter_user, 'kfm_action' => $filter_action, 'kfm_result' => $filter_result ] ) ) ); ?>">&#8249; <?php esc_html_e( 'Prev', 'kpfm' ); ?></a>
            <?php endif; ?>
            <span style="padding:0 8px;line-height:28px;font-size:13px">
                <?php printf( esc_html__( 'Page %d of %d', 'kpfm' ), $page_num, $total_pages ); ?>
            </span>
            <?php if ( $page_num < $total_pages ) : ?>
            <a class="button button-small" href="<?php echo esc_url( kfm_audit_url( array_filter( [ 'paged' => $page_num + 1, 'kfm_user' => $filter_user, 'kfm_action' => $filter_action, 'kfm_result' => $filter_result ] ) ) ); ?>"><?php esc_html_e( 'Next', 'kpfm' ); ?> &#8250;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ─── Log table ─────────────────────────────────────── -->
    <table class="widefat striped" style="font-size:12px">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time (UTC)', 'kpfm' ); ?></th>
                <th><?php esc_html_e( 'User', 'kpfm' ); ?></th>
                <th><?php esc_html_e( 'IP', 'kpfm' ); ?></th>
                <th><?php esc_html_e( 'Action', 'kpfm' ); ?></th>
                <th><?php esc_html_e( 'Path', 'kpfm' ); ?></th>
                <th><?php esc_html_e( 'Result', 'kpfm' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $page_entries as $entry ) :
                $is_bad = ( $entry['result'] !== 'ok' );
                $action_label = str_replace( 'kfm_', '', $entry['action'] );
            ?>
            <tr style="<?php echo $is_bad ? 'background:#fff5f5' : ''; ?>">
                <td style="white-space:nowrap;font-family:monospace">
                    <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $entry['ts'] ) ); ?>
                </td>
                <td><?php echo esc_html( $entry['user'] ); ?></td>
                <td style="font-family:monospace"><?php echo esc_html( $entry['ip'] ); ?></td>
                <td>
                    <code style="background:<?php echo $is_bad ? '#fee2e2' : '#f0fdf4'; ?>;padding:1px 5px;border-radius:3px;color:<?php echo $is_bad ? '#991b1b' : '#166534'; ?>">
                        <?php echo esc_html( $action_label ); ?>
                    </code>
                </td>
                <td style="font-family:monospace;word-break:break-all;max-width:320px">
                    <?php echo esc_html( $entry['path'] ); ?>
                </td>
                <td style="color:<?php echo $is_bad ? '#991b1b' : '#166534'; ?>;font-weight:<?php echo $is_bad ? '600' : 'normal'; ?>">
                    <?php echo esc_html( $entry['result'] ); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>
