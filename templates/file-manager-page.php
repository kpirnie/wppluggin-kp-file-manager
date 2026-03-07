<?php
/**
 * The file manager main page template.
 * 
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' ); 

?>
<div id="kfm-wrap" class="kfm-wrap">

    <!-- ═══════════════════════════════════════════════════════ TOOLBAR -->
    <div class="kfm-toolbar uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
        <div class="uk-flex uk-flex-middle uk-flex-wrap kfm-toolbar-left">

            <div class="uk-button-group kfm-btn-group">
                <button class="uk-button uk-button-primary uk-button-small" id="kfm-btn-new-file">
                    <span uk-icon="icon: file-edit; ratio: 0.85"></span> <?php _e( 'New File', 'kpfm' ); ?>
                </button>
                <button class="uk-button uk-button-primary uk-button-small" id="kfm-btn-new-folder">
                    <span uk-icon="icon: folder; ratio: 0.85"></span> <?php _e( 'New Folder', 'kpfm' ); ?>
                </button>
            </div>

            <button class="uk-button uk-button-default uk-button-small kfm-btn-sep" id="kfm-btn-upload">
                <span uk-icon="icon: upload; ratio: 0.85"></span> <?php _e( 'Upload', 'kpfm' ); ?>
            </button>

            <div class="kfm-toolbar-divider"></div>

            <div class="uk-button-group kfm-btn-group">
                <button class="uk-button uk-button-default uk-button-small kfm-needs-sel" id="kfm-btn-copy" disabled>
                    <span uk-icon="icon: copy; ratio: 0.85"></span> <?php _e( 'Copy', 'kpfm' ); ?>
                </button>
                <button class="uk-button uk-button-default uk-button-small kfm-needs-sel" id="kfm-btn-cut" disabled>
                    <span class="kfm-cut-icon">&#x2702;</span> <?php _e( 'Cut', 'kpfm' ); ?>
                </button>
                <button class="uk-button uk-button-default uk-button-small" id="kfm-btn-paste" disabled>
                    <span uk-icon="icon: push; ratio: 0.85"></span> <?php _e( 'Paste', 'kpfm' ); ?>
                </button>
            </div>

            <div class="kfm-toolbar-divider"></div>

            <div class="uk-button-group kfm-btn-group">
                <button class="uk-button uk-button-default uk-button-small kfm-needs-sel" id="kfm-btn-rename" disabled>
                    <span uk-icon="icon: tag; ratio: 0.85"></span> <?php _e( 'Rename', 'kpfm' ); ?>
                </button>
                <button class="uk-button uk-button-default uk-button-small kfm-needs-sel" id="kfm-btn-chmod" disabled>
                    <span uk-icon="icon: lock; ratio: 0.85"></span> <?php _e( 'Permissions', 'kpfm' ); ?>
                </button>
            </div>

            <button class="uk-button uk-button-danger uk-button-small kfm-btn-sep kfm-needs-sel" id="kfm-btn-delete" disabled>
                <span uk-icon="icon: trash; ratio: 0.85"></span> <?php _e( 'Delete', 'kpfm' ); ?>
            </button>
        </div>

        <div class="uk-flex uk-flex-middle kfm-toolbar-right">
            <span class="kfm-status" id="kfm-status"></span>
            <button class="uk-button uk-button-default uk-button-small kfm-theme-btn" id="kfm-btn-theme" title="<?php _e('Toggle dark / light mode', 'kpfm'); ?>">
                <span id="kfm-theme-icon" aria-hidden="true">&#x1F319;</span>
            </button>
            <button class="uk-button uk-button-default uk-button-small" id="kfm-btn-refresh" uk-tooltip="<?php _e('Refresh', 'kpfm'); ?>">
                <span uk-icon="icon: refresh; ratio: 0.85"></span>
            </button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════ BREADCRUMBS -->
    <nav class="kfm-breadcrumb-wrap" aria-label="Path">
        <ul class="uk-breadcrumb" id="kfm-breadcrumb"></ul>
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
            <div class="uk-overflow-auto kfm-table-scroll">
                <table class="uk-table uk-table-small uk-table-hover uk-table-divider kfm-table" id="kfm-table">
                    <thead>
                        <tr>
                            <th class="kfm-col-check"><input class="uk-checkbox" type="checkbox" id="kfm-check-all" title="<?php _e('Select all', 'kpfm'); ?>"></th>
                            <th class="kfm-col-icon"></th>
                            <th class="kfm-col-name kfm-sortable" data-sort="name"><?php _e('Name', 'kpfm'); ?> <span class="kfm-sort-arrow">&#x25B2;</span></th>
                            <th class="kfm-col-size kfm-sortable" data-sort="size"><?php _e('Size', 'kpfm'); ?></th>
                            <th class="kfm-col-perms kfm-sortable" data-sort="perms"><?php _e('Perms', 'kpfm'); ?></th>
                            <th class="kfm-col-mtime kfm-sortable" data-sort="mtime"><?php _e('Modified', 'kpfm'); ?></th>
                            <th class="kfm-col-actions"><?php _e('Actions', 'kpfm'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="kfm-tbody"></tbody>
                </table>
            </div>

            <div class="kfm-empty uk-text-center uk-padding" id="kfm-empty" style="display:none">
                <span uk-icon="icon: folder; ratio: 3" class="uk-text-muted"></span>
                <p class="uk-text-muted uk-margin-small-top"><?php _e('This folder is empty.', 'kpfm'); ?></p>
            </div>
        </main>
    </div>

    <!-- ═══════════════════════════════════════════════════ UPLOAD ZONE -->
    <div class="kfm-dropzone" id="kfm-dropzone">
        <span uk-icon="icon: upload; ratio: 3"></span>
        <p class="uk-margin-small-top"><?php _e('Drop files here to upload', 'kpfm'); ?></p>
    </div>

    <input type="file" id="kfm-file-input" multiple style="display:none">

</div><!-- /#kfm-wrap -->


<!-- ═══════════════════════════════════════════ GENERIC PROMPT MODAL -->
<div id="kfm-modal-generic" uk-modal="bg-close: false; esc-close: true">
    <div class="uk-modal-dialog uk-margin-auto-vertical">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <div class="uk-modal-header">
            <h3 class="uk-modal-title uk-h5" id="kfm-modal-title"></h3>
        </div>
        <div class="uk-modal-body" id="kfm-modal-body"></div>
        <div class="uk-modal-footer uk-text-right">
            <button class="uk-button uk-button-default uk-button-small uk-modal-close" type="button"><?php _e('Cancel', 'kpfm'); ?></button>
            <button class="uk-button uk-button-primary uk-button-small" type="button" id="kfm-modal-ok"><?php _e('OK', 'kpfm'); ?></button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════ EDITOR MODAL -->
<div id="kfm-editor-modal" uk-modal="bg-close: false; esc-close: true">
    <div class="uk-modal-dialog uk-modal-full kfm-editor-dialog">
        <div class="kfm-editor-header uk-flex uk-flex-between uk-flex-middle">
            <h3 class="uk-h5 uk-margin-remove" id="kfm-editor-title"><?php _e('Edit File', 'kpfm'); ?></h3>
            <div class="uk-flex uk-flex-middle kfm-editor-header-actions">
                <button class="uk-button uk-button-primary uk-button-small" id="kfm-editor-save" type="button">
                    <span uk-icon="icon: check; ratio: 0.85"></span> <?php _e('Save', 'kpfm'); ?>
                </button>
                <button class="uk-modal-close-default kfm-editor-close" type="button" uk-close></button>
            </div>
        </div>
        <div class="kfm-editor-cm-wrap">
            <textarea id="kfm-editor-textarea"></textarea>
        </div>
        <div class="kfm-editor-statusbar">
            <span id="kfm-editor-cursor"><?php _e('Ln 1, Col 1', 'kpfm'); ?></span>
            <span id="kfm-editor-mode" class="uk-margin-left"></span>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════ PERMISSIONS MODAL -->
<div id="kfm-chmod-modal" uk-modal="bg-close: false; esc-close: true">
    <div class="uk-modal-dialog uk-margin-auto-vertical" style="max-width:380px">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <div class="uk-modal-header">
            <h3 class="uk-modal-title uk-h5"><?php _e('Change Permissions', 'kpfm'); ?></h3>
        </div>
        <div class="uk-modal-body">
            <p class="kfm-chmod-path uk-text-muted uk-text-small" id="kfm-chmod-path"></p>
            <table class="uk-table uk-table-small uk-table-divider kfm-chmod-table">
                <thead>
                    <tr>
                        <th></th>
                        <th class="uk-text-center"><?php _e('Read', 'kpfm'); ?></th>
                        <th class="uk-text-center"><?php _e('Write', 'kpfm'); ?></th>
                        <th class="uk-text-center"><?php _e('Execute', 'kpfm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="uk-text-bold"><?php _e('Owner', 'kpfm'); ?></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="256"></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="128"></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="64"></td>
                    </tr>
                    <tr>
                        <td class="uk-text-bold"><?php _e('Group', 'kpfm'); ?></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="32"></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="16"></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="8"></td>
                    </tr>
                    <tr>
                        <td class="uk-text-bold"><?php _e('Other', 'kpfm'); ?></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="4"></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="2"></td>
                        <td class="uk-text-center"><input class="uk-checkbox kfm-perm" type="checkbox" data-bit="1"></td>
                    </tr>
                </tbody>
            </table>
            <div class="uk-flex uk-flex-middle uk-margin-small-top" style="gap:8px">
                <label class="uk-form-label uk-margin-remove" for="kfm-chmod-octal"><?php _e('Octal', 'kpfm'); ?>:</label>
                <input class="uk-input uk-form-small" type="text" id="kfm-chmod-octal" maxlength="4" placeholder="0755" style="width:90px;font-family:monospace">
            </div>
        </div>
        <div class="uk-modal-footer uk-text-right">
            <button class="uk-button uk-button-default uk-button-small uk-modal-close" type="button"><?php _e('Cancel', 'kpfm'); ?></button>
            <button class="uk-button uk-button-primary uk-button-small" type="button" id="kfm-chmod-apply"><?php _e('Apply', 'kpfm'); ?></button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ IMAGE PREVIEW MODAL -->
<div id="kfm-image-modal" uk-modal="bg-close: true; esc-close: true">
    <div class="uk-modal-dialog uk-margin-auto-vertical" style="background:transparent;box-shadow:none;max-width:90vw;width:auto">
        <button class="uk-modal-close-outside" type="button" uk-close style="color:#fff"></button>
        <div style="text-align:center">
            <img id="kfm-image-preview" src="" alt="" style="max-width:85vw;max-height:82vh;border-radius:4px;display:block;margin:0 auto">
            <p id="kfm-image-caption" style="color:#ddd;font-size:12px;margin-top:8px;font-family:monospace"></p>
        </div>
    </div>
</div>
