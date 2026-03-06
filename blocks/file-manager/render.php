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

$height    = isset( $attributes['height'] )   ? absint( $attributes['height'] )       : 600;
$show_tree = isset( $attributes['showTree'] ) ? (bool) $attributes['showTree']        : true;
$align     = isset( $attributes['align'] )    ? sanitize_key( $attributes['align'] )  : '';

// Clamp height
$height = max( 300, min( 1200, $height ) );

// Enqueue front-end assets via the asset loader so localisation stays in sync.
// KFM_Asset_Loader::enqueue_file_manager() handles UIKit, CodeMirror, kfm-style,
// kfm-app, and — critically — wp_localize_script with the full KFM data object
// (including allowedOps, blockedExts, readonlyExts, chmodFloor, etc.).
$asset_loader = new KFM_Asset_Loader();
$asset_loader->enqueue_uikit();
$asset_loader->enqueue_file_manager();

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