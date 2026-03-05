<?php
/**
 * Server-side render callback for the kfm/file-manager block.
 *
 * $attributes is populated by block.json defaults + editor values.
 * $content    is empty (no inner blocks).
 * $block      is the WP_Block instance.
 */
defined( 'ABSPATH' ) || exit;

if ( ! KFM_Settings::current_user_allowed() ) {
    echo '<p class="kfm-block-no-access">'
         . esc_html__( 'You do not have permission to access the File Manager.', 'kfm-file-manager' )
         . '</p>';
    return;
}

$height    = isset( $attributes['height'] )   ? absint( $attributes['height'] )          : 600;
$show_tree = isset( $attributes['showTree'] ) ? (bool) $attributes['showTree']           : true;
$align     = isset( $attributes['align'] )    ? sanitize_key( $attributes['align'] )     : '';

// Clamp height
$height = max( 300, min( 1200, $height ) );

// Enqueue front-end assets (idempotent)
wp_enqueue_style( 'uikit', 'https://cdn.jsdelivr.net/npm/uikit@3/dist/css/uikit.min.css', [], '3' );
wp_enqueue_script( 'uikit', 'https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit.min.js', [], '3', true );
wp_enqueue_script( 'uikit-icons', 'https://cdn.jsdelivr.net/npm/uikit@3/dist/js/uikit-icons.min.js', [ 'uikit' ], '3', true );
wp_enqueue_code_editor( [ 'type' => 'text/plain' ] );
wp_enqueue_style( 'wp-codemirror' );
wp_enqueue_script( 'wp-codemirror' );

wp_enqueue_style(
    'kfm-style',
    KFM_PLUGIN_URL . 'assets/css/kfm-style.css',
    [ 'uikit', 'wp-codemirror' ],
    KFM_VERSION
);

wp_enqueue_script(
    'kfm-app',
    KFM_PLUGIN_URL . 'assets/js/kfm-app.js',
    [ 'jquery', 'uikit', 'uikit-icons', 'wp-codemirror' ],
    KFM_VERSION,
    true
);

wp_localize_script( 'kfm-app', 'KFM', [
    'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
    'nonce'    => wp_create_nonce( 'kfm_nonce' ),
    'basePath' => KFM_Settings::get_display_base(),
    'i18n'     => [
        'confirmDelete'    => __( 'Delete selected item(s)?  This cannot be undone.', 'kfm-file-manager' ),
        'confirmOverwrite' => __( 'Destination already exists.  Overwrite?', 'kfm-file-manager' ),
        'errorGeneric'     => __( 'An error occurred.  Please try again.', 'kfm-file-manager' ),
        'saved'            => __( 'File saved successfully.', 'kfm-file-manager' ),
        'loading'          => __( 'Loading\u2026', 'kfm-file-manager' ),
    ],
] );

$wrapper_attrs = get_block_wrapper_attributes( [
    'class' => 'kfm-block-wrap' . ( $align ? ' align' . $align : '' ),
    'style' => '--kfm-block-height:' . $height . 'px;' . ( $show_tree ? '' : '--kfm-tree-w:0px;' ),
] );

echo '<div ' . $wrapper_attrs . '>';

// Inline the tree-hide flag so JS can pick it up without needing a separate var
if ( ! $show_tree ) {
    echo '<style>#kfm-tree-panel,#kfm-resizer{display:none!important}</style>';
}

// Render the full file manager markup
include KFM_PLUGIN_DIR . 'templates/file-manager-page.php';

echo '</div>';
