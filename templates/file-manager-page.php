<?php
/**
 * The file manager main page template.
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

?>
<div id="kfm-wrap" class="kfm-wrap">

    <!-- ═══════════════════════════════════════════════════════ TOOLBAR -->
    <div class="kfm-toolbar">
        <div class="kfm-toolbar-left">

            <div class="kfm-btn-group">
                <button class="kfm-btn kfm-btn-primary kfm-btn-sm" id="kfm-btn-new-file">
                    <span class="dashicons dashicons-edit"></span><?php _e( 'New File', 'kp-file-manager' ); ?>
                </button>
                <button class="kfm-btn kfm-btn-primary kfm-btn-sm" id="kfm-btn-new-folder">
                    <span class="dashicons dashicons-portfolio"></span><?php _e( 'New Folder', 'kp-file-manager' ); ?>
                </button>
            </div>

            <button class="kfm-btn kfm-btn-sm kfm-btn-sep" id="kfm-btn-upload">
                <span class="dashicons dashicons-upload"></span><?php _e( 'Upload', 'kp-file-manager' ); ?>
            </button>

            <div class="kfm-toolbar-divider"></div>

            <div class="kfm-btn-group">
                <button class="kfm-btn kfm-btn-sm kfm-needs-sel" id="kfm-btn-copy" disabled>
                    <span class="dashicons dashicons-admin-page"></span><?php _e( 'Copy', 'kp-file-manager' ); ?>
                </button>
                <button class="kfm-btn kfm-btn-sm kfm-needs-sel" id="kfm-btn-cut" disabled>
                    <span class="kfm-cut-icon">&#x2702;</span><?php _e( 'Cut', 'kp-file-manager' ); ?>
                </button>
                <button class="kfm-btn kfm-btn-sm" id="kfm-btn-paste" disabled>
                    <span class="dashicons dashicons-migrate"></span><?php _e( 'Paste', 'kp-file-manager' ); ?>
                </button>
            </div>

            <div class="kfm-toolbar-divider"></div>

            <div class="kfm-btn-group">
                <button class="kfm-btn kfm-btn-sm kfm-needs-sel" id="kfm-btn-rename" disabled>
                    <span class="dashicons dashicons-tag"></span><?php _e( 'Rename', 'kp-file-manager' ); ?>
                </button>
                <button class="kfm-btn kfm-btn-sm kfm-needs-sel" id="kfm-btn-chmod" disabled>
                    <span class="dashicons dashicons-lock"></span><?php _e( 'Permissions', 'kp-file-manager' ); ?>
                </button>
            </div>

            <button class="kfm-btn kfm-btn-danger kfm-btn-sm kfm-btn-sep kfm-needs-sel" id="kfm-btn-delete" disabled>
                <span class="dashicons dashicons-trash"></span><?php _e( 'Delete', 'kp-file-manager' ); ?>
            </button>
        </div>

        <div class="kfm-toolbar-right">
            <span class="kfm-status" id="kfm-status"></span>
            <button class="kfm-btn kfm-btn-sm kfm-theme-btn" id="kfm-btn-theme" title="<?php esc_attr_e( 'Toggle dark / light mode', 'kp-file-manager' ); ?>">
                <span id="kfm-theme-icon" aria-hidden="true">&#x1F319;</span>
            </button>
            <button class="kfm-btn kfm-btn-sm" id="kfm-btn-refresh" title="<?php esc_attr_e( 'Refresh', 'kp-file-manager' ); ?>">
                <span class="dashicons dashicons-update"></span>
            </button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════ BREADCRUMBS -->
    <nav class="kfm-breadcrumb-wrap" aria-label="Path">
        <ul class="kfm-breadcrumb" id="kfm-breadcrumb"></ul>
    </nav>

    <!-- ══════════════════════════════════════════════════ MAIN CONTENT -->
    <div class="kfm-body">

        <!-- Tree panel -->
        <aside class="kfm-tree-panel" id="kfm-tree-panel">
            <div class="kfm-tree" id="kfm-tree"></div>
        </aside>

        <!-- Resize handle -->
        <div class="kfm-resizer" id="kfm-resizer"></div>

        <!-- File listing -->
        <main class="kfm-main" id="kfm-main">
            <div class="kfm-table-scroll">
                <table class="kfm-table" id="kfm-table">
                    <thead>
                        <tr>
                            <th class="kfm-col-check"><input type="checkbox" id="kfm-check-all" title="<?php esc_attr_e( 'Select all', 'kp-file-manager' ); ?>"></th>
                            <th class="kfm-col-icon"></th>
                            <th class="kfm-col-name kfm-sortable" data-sort="name"><?php _e( 'Name', 'kp-file-manager' ); ?> <span class="kfm-sort-arrow">&#x25B2;</span></th>
                            <th class="kfm-col-size kfm-sortable" data-sort="size"><?php _e( 'Size', 'kp-file-manager' ); ?></th>
                            <th class="kfm-col-perms kfm-sortable" data-sort="perms"><?php _e( 'Perms', 'kp-file-manager' ); ?></th>
                            <th class="kfm-col-mtime kfm-sortable" data-sort="mtime"><?php _e( 'Modified', 'kp-file-manager' ); ?></th>
                            <th class="kfm-col-actions"><?php _e( 'Actions', 'kp-file-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="kfm-tbody"></tbody>
                </table>
            </div>

            <div class="kfm-empty" id="kfm-empty" style="display:none">
                <span class="dashicons dashicons-portfolio kfm-empty-icon"></span>
                <p><?php _e( 'This folder is empty.', 'kp-file-manager' ); ?></p>
            </div>
        </main>
    </div>

    <!-- ═══════════════════════════════════════════════════ UPLOAD ZONE -->
    <div class="kfm-dropzone" id="kfm-dropzone">
        <span class="dashicons dashicons-upload kfm-dropzone-icon"></span>
        <p><?php _e( 'Drop files here to upload', 'kp-file-manager' ); ?></p>
    </div>

    <input type="file" id="kfm-file-input" multiple style="display:none">

</div><!-- /#kfm-wrap -->


<!-- ═══════════════════════════════════════════ GENERIC PROMPT MODAL -->
<div id="kfm-modal-generic" class="kfm-modal" data-bg-close="false">
    <div class="kfm-modal-dialog">
        <div class="kfm-modal-header">
            <h3 class="kfm-modal-title" id="kfm-modal-title"></h3>
            <button class="kfm-modal-close-btn" type="button" data-kfm-modal-close>
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="kfm-modal-body" id="kfm-modal-body"></div>
        <div class="kfm-modal-footer">
            <button class="kfm-btn kfm-btn-sm" type="button" data-kfm-modal-close><?php _e( 'Cancel', 'kp-file-manager' ); ?></button>
            <button class="kfm-btn kfm-btn-primary kfm-btn-sm" type="button" id="kfm-modal-ok"><?php _e( 'OK', 'kp-file-manager' ); ?></button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════ EDITOR MODAL -->
<div id="kfm-editor-modal" class="kfm-modal kfm-modal-full" data-bg-close="false">
    <div class="kfm-modal-dialog kfm-editor-dialog">
        <div class="kfm-editor-header">
            <h3 class="kfm-editor-title" id="kfm-editor-title"><?php _e( 'Edit File', 'kp-file-manager' ); ?></h3>
            <div class="kfm-editor-header-actions">
                <button class="kfm-btn kfm-btn-primary kfm-btn-sm" id="kfm-editor-save" type="button">
                    <span class="dashicons dashicons-yes"></span><?php _e( 'Save', 'kp-file-manager' ); ?>
                </button>
                <button class="kfm-modal-close-btn kfm-editor-close" type="button" data-kfm-modal-close>
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
        </div>
        <div class="kfm-editor-cm-wrap">
            <textarea id="kfm-editor-textarea"></textarea>
        </div>
        <div class="kfm-editor-statusbar">
            <span id="kfm-editor-cursor"><?php _e( 'Ln 1, Col 1', 'kp-file-manager' ); ?></span>
            <span id="kfm-editor-mode" style="margin-left:16px"></span>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════ PERMISSIONS MODAL -->
<div id="kfm-chmod-modal" class="kfm-modal" data-bg-close="false">
    <div class="kfm-modal-dialog" style="max-width:380px">
        <div class="kfm-modal-header">
            <h3 class="kfm-modal-title"><?php _e( 'Change Permissions', 'kp-file-manager' ); ?></h3>
            <button class="kfm-modal-close-btn" type="button" data-kfm-modal-close>
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="kfm-modal-body">
            <p class="kfm-chmod-path" id="kfm-chmod-path"></p>
            <table class="kfm-chmod-table">
                <thead>
                    <tr>
                        <th></th>
                        <th><?php _e( 'Read', 'kp-file-manager' ); ?></th>
                        <th><?php _e( 'Write', 'kp-file-manager' ); ?></th>
                        <th><?php _e( 'Execute', 'kp-file-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php _e( 'Owner', 'kp-file-manager' ); ?></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="256"></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="128"></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="64"></td>
                    </tr>
                    <tr>
                        <td><?php _e( 'Group', 'kp-file-manager' ); ?></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="32"></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="16"></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="8"></td>
                    </tr>
                    <tr>
                        <td><?php _e( 'Other', 'kp-file-manager' ); ?></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="4"></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="2"></td>
                        <td><input class="kfm-perm" type="checkbox" data-bit="1"></td>
                    </tr>
                </tbody>
            </table>
            <div class="kfm-chmod-octal-row">
                <label class="kfm-form-label" for="kfm-chmod-octal"><?php _e( 'Octal', 'kp-file-manager' ); ?>:</label>
                <input class="kfm-input kfm-input-sm" type="text" id="kfm-chmod-octal" maxlength="4" placeholder="0755" style="width:90px;font-family:monospace">
            </div>
        </div>
        <div class="kfm-modal-footer">
            <button class="kfm-btn kfm-btn-sm" type="button" data-kfm-modal-close><?php _e( 'Cancel', 'kp-file-manager' ); ?></button>
            <button class="kfm-btn kfm-btn-primary kfm-btn-sm" type="button" id="kfm-chmod-apply"><?php _e( 'Apply', 'kp-file-manager' ); ?></button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ IMAGE PREVIEW MODAL -->
<div id="kfm-image-modal" class="kfm-modal kfm-image-modal" data-bg-close="true">
    <div class="kfm-modal-dialog kfm-image-dialog">
        <button class="kfm-image-modal-close" type="button" data-kfm-modal-close>
            <span class="dashicons dashicons-no-alt"></span>
        </button>
        <div style="text-align:center">
            <img id="kfm-image-preview" src="" alt="" style="max-width:85vw;max-height:82vh;border-radius:4px;display:block;margin:0 auto">
            <p id="kfm-image-caption" style="color:#ddd;font-size:12px;margin-top:8px;font-family:monospace"></p>
        </div>
    </div>
</div>
