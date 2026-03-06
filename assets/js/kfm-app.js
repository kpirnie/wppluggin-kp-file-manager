/**
 * KFM File Manager – Frontend Application v1.0.5
 * Depends on: jQuery, UIKit 3, UIKit Icons, wp-codemirror
 */
( function ( $, KFM, UIkit ) {
    'use strict';

    /* ─────────────────────────────────────────────────── State ── */

    var state = {
        currentPath : '',
        items       : [],
        selected    : new Set(),
        clipboard   : null,
        sortCol     : 'name',
        sortAsc     : true,
        editorCM    : null,
        editorRel   : null,
        chmodRel    : null,
    };

    /* ─────────────────────────────── Permission helpers ── */

    var ALLOWED_OPS = ( KFM.allowedOps && KFM.allowedOps.length ) ? KFM.allowedOps : [];

    function canDo( op ) {
        return ALLOWED_OPS.indexOf( op ) !== -1;
    }

    /**
     * Hides toolbar buttons and table columns the current user cannot use.
     * Called once on boot before the first directory load.
     */
    function applyOpVisibility() {

        // Write: new file/folder group + copy/cut/paste group
        if ( ! canDo( 'write' ) ) {
            $( '#kfm-btn-new-file' ).closest( '.kfm-btn-group' ).hide();
            $( '#kfm-btn-copy' ).closest( '.kfm-btn-group' ).hide();
        }

        // Upload
        if ( ! canDo( 'upload' ) ) {
            $( '#kfm-btn-upload' ).hide();
        }

        // Rename / chmod group (toolbar)
        if ( ! canDo( 'rename' ) ) $( '#kfm-btn-rename' ).hide();
        if ( ! canDo( 'chmod'  ) ) $( '#kfm-btn-chmod'  ).hide();
        if ( ! canDo( 'rename' ) && ! canDo( 'chmod' ) ) {
            $( '#kfm-btn-rename' ).closest( '.kfm-btn-group' ).hide();
        }

        // Delete toolbar button
        if ( ! canDo( 'delete' ) ) $( '#kfm-btn-delete' ).hide();

        // Perms column (th + all tds rendered later) — inject CSS so it covers dynamic rows too
        if ( ! canDo( 'chmod' ) ) {
            $( '<style id="kfm-hide-perms">.kfm-col-perms { display:none !important; }</style>' )
                .appendTo( 'head' );
        }

        // Check-all column header — hide when no bulk operations are available
        var hasBulk = canDo( 'write' ) || canDo( 'rename' ) || canDo( 'chmod' ) || canDo( 'delete' );
        if ( ! hasBulk ) {
            $( '#kfm-table thead .kfm-col-check' ).hide();
        }

        // Collapse toolbar dividers that now sit between two hidden sections
        $( '.kfm-toolbar-divider' ).each( function () {
            var $d      = $( this );
            var prevVis = $d.prevAll( '.uk-button-group, .uk-button, .kfm-btn-sep' ).filter( ':visible' ).length;
            var nextVis = $d.nextAll( '.uk-button-group, .uk-button, .kfm-btn-sep' ).filter( ':visible' ).length;
            if ( ! prevVis || ! nextVis ) $d.hide();
        } );
    }

    /* ──────────────────────────────────── Client-side security ── */

    var BLOCKED_EXTS    = ( KFM.blockedExts  && KFM.blockedExts.length  ) ? KFM.blockedExts  : [
        'php','phtml','phar','php3','php4','php5','php7','phps','pht','exe','sh','pl','cgi','htaccess','htpasswd','shtml','asis'
    ];
    var READONLY_EXTS   = ( KFM.readonlyExts && KFM.readonlyExts.length ) ? KFM.readonlyExts : [];
    var CHMOD_FLOOR     = KFM.chmodFloor ? parseInt( KFM.chmodFloor, 8 ) : 0;

    var DANGEROUS_NAMES = [ '.htaccess', '.htpasswd', '.env', '.user.ini', 'php.ini', 'web.config' ];
    var DANGEROUS_FN_RE = /\b(eval|base64_decode|exec|system|passthru|shell_exec|popen|proc_open|assert)\s*\(/i;

    function validateUploadFiles( files ) {
        var rejected = [];
        Array.from( files ).forEach( function ( f ) {
            var name = f.name.toLowerCase();
            var ext  = name.lastIndexOf( '.' ) !== -1 ? name.split( '.' ).pop() : '';
            if ( DANGEROUS_NAMES.indexOf( name ) !== -1 ) {
                rejected.push( f.name + ' \u2014 filename not permitted' );
            } else if ( BLOCKED_EXTS.indexOf( ext ) !== -1 ) {
                rejected.push( f.name + ' \u2014 .' + ext + ' files are blocked' );
            }
        } );
        return rejected;
    }

    /* ─────────────────────────────────────────── File type icons ── */

    var ICON_MAP = {
        dir  : { icon: 'folder',       cls: 'kfm-dir-icon'  },
        php  : { icon: 'code',         cls: 'kfm-file-icon' },
        js   : { icon: 'code',         cls: 'kfm-file-icon' },
        ts   : { icon: 'code',         cls: 'kfm-file-icon' },
        css  : { icon: 'paint-bucket', cls: 'kfm-file-icon' },
        html : { icon: 'code',         cls: 'kfm-file-icon' },
        htm  : { icon: 'code',         cls: 'kfm-file-icon' },
        json : { icon: 'code',         cls: 'kfm-file-icon' },
        xml  : { icon: 'code',         cls: 'kfm-file-icon' },
        sql  : { icon: 'database',     cls: 'kfm-file-icon' },
        sh   : { icon: 'terminal',     cls: 'kfm-file-icon' },
        jpg  : { icon: 'image',        cls: 'kfm-file-icon' },
        jpeg : { icon: 'image',        cls: 'kfm-file-icon' },
        png  : { icon: 'image',        cls: 'kfm-file-icon' },
        gif  : { icon: 'image',        cls: 'kfm-file-icon' },
        svg  : { icon: 'image',        cls: 'kfm-file-icon' },
        webp : { icon: 'image',        cls: 'kfm-file-icon' },
        pdf  : { icon: 'file-pdf',     cls: 'kfm-file-icon' },
        zip  : { icon: 'album',        cls: 'kfm-file-icon' },
        gz   : { icon: 'album',        cls: 'kfm-file-icon' },
        tar  : { icon: 'album',        cls: 'kfm-file-icon' },
        rar  : { icon: 'album',        cls: 'kfm-file-icon' },
        mp3  : { icon: 'receiver',     cls: 'kfm-file-icon' },
        mp4  : { icon: 'video-camera', cls: 'kfm-file-icon' },
        txt  : { icon: 'file-text',    cls: 'kfm-file-icon' },
        md   : { icon: 'file-text',    cls: 'kfm-file-icon' },
        csv  : { icon: 'table',        cls: 'kfm-file-icon' },
        _def : { icon: 'file-text',    cls: 'kfm-file-icon' },
    };

    function iconFor( item ) {
        if ( item.type === 'dir' ) return ICON_MAP.dir;
        return ICON_MAP[ item.ext ] || ICON_MAP._def;
    }

    /* ──────────────────────────────────────────────── Utilities ── */

    function humanSize( bytes ) {
        if ( ! bytes ) return '\u2013';
        var units = [ 'B', 'KB', 'MB', 'GB' ], i = 0;
        while ( bytes >= 1024 && i < units.length - 1 ) { bytes /= 1024; i++; }
        return bytes.toFixed( i ? 1 : 0 ) + '\u00a0' + units[ i ];
    }

    function humanDate( ts ) {
        return new Date( ts * 1000 ).toLocaleString();
    }

    function setStatus( msg ) {
        var $s = $( '#kfm-status' );
        $s.text( msg );
        clearTimeout( $s.data( 'timer' ) );
        if ( msg ) $s.data( 'timer', setTimeout( function () { $s.text( '' ); }, 4000 ) );
    }

    function errMsg( err ) {
        if ( typeof err === 'string' ) return err;
        if ( err && err.message ) return err.message;
        return KFM.i18n.errorGeneric || 'An error occurred.';
    }

    function ajax( action, data, method ) {
        return $.ajax( {
            url    : KFM.ajaxUrl,
            method : method || 'POST',
            data   : $.extend( { action: action, nonce: KFM.nonce }, data ),
        } ).then( function ( r ) {
            if ( ! r.success ) return $.Deferred().reject(
                r.data && r.data.message ? r.data.message : ( KFM.i18n.errorGeneric || 'An error occurred.' )
            );
            return r.data;
        }, function ( xhr, status, err ) {
            var msg = ( KFM.i18n.errorGeneric || 'An error occurred.' );
            try {
                var json = JSON.parse( xhr.responseText );
                if ( json.data && json.data.message ) msg = json.data.message;
            } catch(e) {
                if ( err && typeof err === 'string' ) msg = err;
            }
            return $.Deferred().reject( msg );
        } );
    }

    function esc( str ) {
        return $( '<div>' ).text( String( str || '' ) ).html();
    }

    function notify( msg, status, timeout ) {
        UIkit.notification( {
            message : msg,
            status  : status  || 'primary',
            timeout : timeout !== undefined ? timeout : 3000,
            pos     : 'top-center',
        } );
    }

    /* ───────────────────────────────────────── Dark / light mode ── */

    function initTheme() {
        try {
            var saved = localStorage.getItem( 'kfm_theme' );
            if ( saved === 'dark' ) applyTheme( 'dark' );
        } catch(e) {}
    }

    function applyTheme( theme ) {
        if ( theme === 'dark' ) {
            $( '#kfm-wrap' ).addClass( 'kfm-dark' );
            $( 'html' ).attr( 'data-kfm-theme', 'dark' );
            $( '#kfm-theme-icon' ).html( '&#x2600;' );
        } else {
            $( '#kfm-wrap' ).removeClass( 'kfm-dark' );
            $( 'html' ).removeAttr( 'data-kfm-theme' );
            $( '#kfm-theme-icon' ).html( '&#x1F319;' );
        }
    }

    function toggleTheme() {
        var next = $( '#kfm-wrap' ).hasClass( 'kfm-dark' ) ? 'light' : 'dark';
        applyTheme( next );
        try { localStorage.setItem( 'kfm_theme', next ); } catch(e) {}
    }

    /* ────────────────────────────────────────────── Breadcrumbs ── */

    function renderBreadcrumbs( crumbs ) {
        var $bc = $( '#kfm-breadcrumb' ).empty();
        crumbs.forEach( function ( c, i ) {
            if ( i === crumbs.length - 1 ) {
                $bc.append( '<li><span>' + esc( c.name ) + '</span></li>' );
            } else {
                $bc.append( '<li><a href="#" data-rel="' + esc( c.rel ) + '">' + esc( c.name ) + '</a></li>' );
            }
        } );
    }

    /* ───────────────────────────────────────────────── File table ── */

    function renderTable( items ) {
        var $tbody = $( '#kfm-tbody' ).empty();
        $( '#kfm-empty' ).toggle( items.length === 0 );
        $( '#kfm-table' ).toggle( items.length > 0 );
        $( '#kfm-check-all' ).prop( 'checked', false );
        state.selected.clear();
        updateToolbarState();

        var hasBulk = canDo( 'write' ) || canDo( 'rename' ) || canDo( 'chmod' ) || canDo( 'delete' );

        items.forEach( function ( item ) {
            var ico   = iconFor( item );
            var isDir = item.type === 'dir';
            var isRO  = ! isDir && READONLY_EXTS.indexOf( item.ext ) !== -1;

            // ── Name cell ──
            // Directories: always a clickable link — navigation relies on kfm_list;
            //   the server enforces the 'list' permission, no client gate needed.
            // Files: link when read is permitted (opens editor/viewer); plain text otherwise.
            var nameCell;
            if ( isDir ) {
                nameCell = '<a href="#" class="kfm-dir-link" data-rel="' + esc( item.rel ) + '">' + esc( item.name ) + '</a>';
            } else if ( canDo( 'read' ) ) {
                nameCell = '<a href="#" class="kfm-file-link" data-rel="' + esc( item.rel ) + '">' + esc( item.name ) + '</a>'
                         + ( isRO ? ' <span class="kfm-badge-ro" title="Read-only">RO</span>' : '' );
            } else {
                nameCell = esc( item.name )
                         + ( isRO ? ' <span class="kfm-badge-ro" title="Read-only">RO</span>' : '' );
            }

            // ── Row action buttons ──

            // Download — requires read
            var downloadBtn = ( ! isDir && canDo( 'read' ) )
                ? '<a class="kfm-row-btn kfm-btn-download"' +
                    ' href="' + esc( KFM.ajaxUrl ) + '?action=kfm_download&nonce=' + esc( KFM.nonce ) + '&path=' + encodeURIComponent( item.rel ) + '"' +
                    ' title="Download" download>' +
                    '<span uk-icon="icon:download;ratio:0.75"></span>' +
                  '</a>'
                : '';

            // Edit — requires read; hidden for dirs and read-only files
            var editBtn = ( ! isDir && ! isRO && canDo( 'read' ) )
                ? '<button class="kfm-row-btn kfm-btn-edit" data-rel="' + esc( item.rel ) + '" title="Edit">' +
                    '<span uk-icon="icon:pencil;ratio:0.75"></span>' +
                  '</button>'
                : '';

            // Rename — requires rename
            var renameBtn = canDo( 'rename' )
                ? '<button class="kfm-row-btn kfm-btn-rename2" data-rel="' + esc( item.rel ) + '" data-name="' + esc( item.name ) + '" title="Rename">' +
                    '<span uk-icon="icon:tag;ratio:0.75"></span>' +
                  '</button>'
                : '';

            // Chmod — requires chmod
            var permBtn = canDo( 'chmod' )
                ? '<button class="kfm-row-btn kfm-btn-perm" data-rel="' + esc( item.rel ) + '" data-perms="' + esc( item.perms ) + '" title="Permissions">' +
                    '<span uk-icon="icon:lock;ratio:0.75"></span>' +
                  '</button>'
                : '';

            // Delete — requires delete
            var deleteBtn = canDo( 'delete' )
                ? '<button class="kfm-row-btn kfm-row-btn-del kfm-btn-del" data-rel="' + esc( item.rel ) + '" title="Delete">' +
                    '<span uk-icon="icon:trash;ratio:0.75"></span>' +
                  '</button>'
                : '';

            // Check cell — omit entirely when no bulk actions are available
            var checkCell = hasBulk
                ? '<td class="kfm-col-check"><input class="uk-checkbox kfm-row-check" type="checkbox" value="' + esc( item.rel ) + '"></td>'
                : '';

            $tbody.append(
                '<tr data-rel="' + esc( item.rel ) + '" data-type="' + item.type + '">' +
                    checkCell +
                    '<td class="kfm-col-icon"><span uk-icon="icon:' + ico.icon + ';ratio:0.9" class="' + ico.cls + '"></span></td>' +
                    '<td class="kfm-col-name">' + nameCell + '</td>' +
                    '<td class="kfm-col-size">' + ( isDir ? '\u2013' : humanSize( item.size ) ) + '</td>' +
                    '<td class="kfm-col-perms"><code class="kfm-perms-code">' + esc( item.perms ) + '</code></td>' +
                    '<td class="kfm-col-mtime">' + humanDate( item.mtime ) + '</td>' +
                    '<td class="kfm-col-actions">' + downloadBtn + editBtn + renameBtn + permBtn + deleteBtn + '</td>' +
                '</tr>'
            );
        } );

        UIkit.update( $tbody[0] );
    }

    /* ────────────────────────────────────────── Directory tree ── */

    function renderTree( rel, $container ) {
        ajax( 'kfm_list', { path: rel } ).then( function ( data ) {
            var dirs = data.items.filter( function (i) { return i.type === 'dir'; } );
            if ( ! dirs.length ) return;

            var $ul = $( '<ul class="kfm-tree-list"></ul>' ).appendTo( $container );
            dirs.forEach( function ( d ) {
                var $li  = $( '<li class="kfm-tree-item"></li>' ).appendTo( $ul );
                var $row = $( '<div class="kfm-tree-row"></div>' ).appendTo( $li );
                var $tog = $( '<span class="kfm-tree-toggle"></span>' ).appendTo( $row );
                $( '<span uk-icon="icon:chevron-right;ratio:0.8"></span>' ).appendTo( $tog );
                var $a   = $( '<a href="#" class="kfm-tree-link" data-rel="' + esc( d.rel ) + '"></a>' ).appendTo( $row );
                $( '<span uk-icon="icon:folder;ratio:0.85" class="kfm-tree-icon"></span>' ).appendTo( $a );
                $( '<span class="kfm-tree-name">' + esc( d.name ) + '</span>' ).appendTo( $a );
                var $ch  = $( '<div class="kfm-tree-children" style="display:none"></div>' ).appendTo( $li );

                $tog.on( 'click', function () {
                    if ( ! $li.hasClass( 'kfm-tree-item-open' ) && $ch.is( ':empty' ) ) {
                        renderTree( d.rel, $ch );
                    }
                    $ch.toggle();
                    $li.toggleClass( 'kfm-tree-item-open' );
                } );
            } );
            UIkit.update( $ul[0] );
        } );
    }

    /* ────────────────────────────────────────── Load directory ── */

    function loadDir( rel ) {
        setStatus( KFM.i18n.loading || 'Loading\u2026' );
        ajax( 'kfm_list', { path: rel } ).then( function ( data ) {
            state.currentPath = data.current;
            state.items       = data.items;
            renderBreadcrumbs( data.breadcrumbs );
            sortAndRender();
            setStatus( '' );
            if ( rel === '' && $( '#kfm-tree' ).is( ':empty' ) ) renderTree( '', $( '#kfm-tree' ) );
        } ).catch( function ( err ) {
            var msg = errMsg( err );
            setStatus( msg );
            notify( esc( msg ), 'danger', 0 );
        } );
    }

    /* ──────────────────────────────────────────────── Sorting ── */

    function sortAndRender() {
        var sorted = state.items.slice().sort( function ( a, b ) {
            if ( a.type !== b.type ) return a.type === 'dir' ? -1 : 1;
            var va = a[ state.sortCol ], vb = b[ state.sortCol ];
            if ( typeof va === 'string' ) {
                var c = va.localeCompare( vb, undefined, { numeric: true } );
                return state.sortAsc ? c : -c;
            }
            return state.sortAsc ? va - vb : vb - va;
        } );
        renderTable( sorted );
    }

    /* ──────────────────────────────────────── Toolbar state ── */

    function updateToolbarState() {
        var hasSel  = state.selected.size > 0;
        var hasClip = !! state.clipboard;
        $( '.kfm-needs-sel' ).prop( 'disabled', ! hasSel );
        $( '#kfm-btn-paste' ).prop( 'disabled', ! hasClip );
    }

    /* ─────────────────────────────────── UIKit prompt modal ── */

    function promptModal( title, label, defaultVal ) {
        return new Promise( function ( resolve, reject ) {
            var id      = 'kfm-inp-' + Date.now();
            var settled = false;

            $( '#kfm-modal-title' ).text( title );
            $( '#kfm-modal-body' ).html(
                '<label class="uk-form-label">' + esc( label ) + '</label>' +
                '<div class="uk-form-controls uk-margin-small-top">' +
                    '<input id="' + id + '" class="uk-input" type="text" value="' + esc( defaultVal || '' ) + '">' +
                '</div>'
            );

            var modal = UIkit.modal( '#kfm-modal-generic' );
            modal.show();

            setTimeout( function () {
                var $inp = $( '#' + id ).focus().select();
                $inp.on( 'keydown', function (e) { if ( e.key === 'Enter' ) { e.preventDefault(); doOk(); } } );
            }, 120 );

            function doOk() {
                var val = $( '#' + id ).val().trim();
                if ( ! val ) { notify( 'Please enter a name.', 'warning', 2000 ); return; }
                settled = true;
                modal.hide();
                resolve( val );
            }

            $( '#kfm-modal-ok' ).off( 'click.kfm' ).on( 'click.kfm', doOk );

            UIkit.util.once( document, 'hidden', function () {
                if ( ! settled ) reject( 'cancelled' );
            }, { self: false, filter: '#kfm-modal-generic' } );
        } );
    }

    /* ───────────────────────────────────────── Editor / save ── */

    function saveEditor() {
        if ( ! state.editorCM || ! state.editorRel ) return;

        var content = state.editorCM.getValue();

        var ext = ( state.editorRel.split( '.' ).pop() || '' ).toLowerCase();
        if ( [ 'php', 'js', 'sh' ].indexOf( ext ) !== -1 && DANGEROUS_FN_RE.test( content ) ) {
            if ( ! window.confirm( ( KFM.i18n && KFM.i18n.warnDangerousFn ) || "This file contains potentially dangerous functions.\n\nSave anyway?" ) ) {
                return;
            }
        }

        ajax( 'kfm_write', { path: state.editorRel, content: content } ).then( function () {
            notify( '<span uk-icon="icon:check;ratio:0.85"></span>&nbsp; ' + ( ( KFM.i18n && KFM.i18n.saved ) ? KFM.i18n.saved : 'File saved.' ), 'success', 3000 );
            loadDir( state.currentPath );
        } ).catch( function ( err ) {
            notify( '<span uk-icon="icon:warning;ratio:0.85"></span>&nbsp; ' + esc( errMsg( err ) ), 'danger', 0 );
        } );
    }

    function openEditor( rel ) {
        var ext  = ( rel.split( '.' ).pop() || '' ).toLowerCase();
        var isRO = READONLY_EXTS.indexOf( ext ) !== -1;

        state.editorRel = rel;
        ajax( 'kfm_read', { path: rel } ).then( function ( data ) {
            $( '#kfm-editor-title' ).text( ( isRO ? '[Read-only] ' : 'Editing: ' ) + data.name );

            var modal = UIkit.modal( '#kfm-editor-modal' );
            if ( state.editorCM ) { try { state.editorCM.toTextArea(); } catch(e) {} state.editorCM = null; }

            $( '#kfm-editor-textarea' ).val( data.content );
            $( '#kfm-editor-mode' ).text( data.ext || 'text' );

            // Save button visible only when both write is permitted and file is not read-only
            var canSave = canDo( 'write' ) && ! isRO;
            $( '#kfm-editor-save' ).prop( 'disabled', ! canSave ).toggle( canSave );

            modal.show();

            UIkit.util.once( document, 'shown', function () {
                if ( ! wp.codeEditor || ! wp.codeEditor.initialize ) {
                    state.editorCM = null;
                    $( '#kfm-editor-textarea' ).show();
                    return;
                }

                var mimeMap = {
                    php:'application/x-httpd-php', js:'text/javascript', json:'application/json',
                    css:'text/css', html:'text/html', htm:'text/html', xml:'text/xml',
                    sql:'text/x-sql', sh:'text/x-sh', md:'text/x-markdown',
                    txt:'text/plain', ts:'text/typescript',
                };
                var mode = mimeMap[ data.ext ] || 'text/plain';
                $( '#kfm-editor-mode' ).text( mode );

                var init = wp.codeEditor.initialize( $( '#kfm-editor-textarea' ), {
                    codemirror: {
                        mode, lineNumbers:true, lineWrapping:true, indentUnit:4,
                        tabSize:4, indentWithTabs:false, matchBrackets:true, autoCloseBrackets:true,
                        readOnly: ! canSave,
                        extraKeys: { 'Ctrl-S': saveEditor, 'Cmd-S': saveEditor, 'Ctrl-/': 'toggleComment', 'Ctrl-F': 'findPersistent' },
                    },
                } );
                state.editorCM = init.codemirror;
                state.editorCM.on( 'cursorActivity', function (cm) {
                    var c = cm.getCursor();
                    $( '#kfm-editor-cursor' ).text( 'Ln ' + ( c.line + 1 ) + ', Col ' + ( c.ch + 1 ) );
                } );
                setTimeout( function () { state.editorCM.refresh(); }, 50 );
            }, { self: false, filter: '#kfm-editor-modal' } );

        } ).catch( function ( err ) {
            notify( esc( errMsg( err ) ), 'danger', 0 );
        } );
    }

    /* ─────────────────────────────────────────── Permissions ── */

    function openChmod( rel, currentOctal ) {
        state.chmodRel = rel;
        $( '#kfm-chmod-path' ).text( rel );
        applyOctalToCheckboxes( currentOctal );
        $( '#kfm-chmod-octal' ).val( currentOctal );
        UIkit.modal( '#kfm-chmod-modal' ).show();
    }

    function applyOctalToCheckboxes( octal ) {
        var dec = parseInt( octal, 8 );
        $( '.kfm-perm' ).each( function () {
            $( this ).prop( 'checked', !! ( dec & parseInt( $( this ).data( 'bit' ) ) ) );
        } );
    }

    function checkboxesToOctal() {
        var val = 0;
        $( '.kfm-perm:checked' ).each( function () { val += parseInt( $( this ).data( 'bit' ) ); } );
        return '0' + val.toString( 8 ).padStart( 3, '0' );
    }

    /* ─────────────────────────────────────────────── Upload ── */

    function uploadFiles( files ) {
        var rejected = validateUploadFiles( files );
        if ( rejected.length ) {
            notify(
                '<strong>Upload blocked:</strong><br>' + rejected.map( esc ).join( '<br>' ),
                'danger', 0
            );
            $( '#kfm-file-input' ).val( '' );
            return;
        }

        var dir = state.currentPath;
        var p   = $.when();

        Array.from( files ).forEach( function ( file ) {
            p = p.then( function () {
                var fd = new FormData();
                fd.append( 'action', 'kfm_upload' );
                fd.append( 'nonce',  KFM.nonce );
                fd.append( 'dir',    dir );
                fd.append( 'file',   file );
                return $.ajax( { url: KFM.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false } )
                    .then( function (r) {
                        if ( r.success ) {
                            notify( '<span uk-icon="icon:check;ratio:0.85"></span>&nbsp; Uploaded: ' + esc( file.name ), 'success', 2500 );
                        } else {
                            notify( esc( r.data && r.data.message ? r.data.message : 'Upload failed: ' + file.name ), 'danger', 0 );
                        }
                    } );
            } );
        } );
        p.always( function () { loadDir( state.currentPath ); } );
    }

    /* ───────────────────────────────────────── Panel resize ── */

    function initResizer() {
        var $panel = $( '#kfm-tree-panel' );
        $( '#kfm-resizer' ).on( 'mousedown', function (e) {
            e.preventDefault();
            var startX = e.clientX, startW = $panel.width();
            $( document ).on( 'mousemove.kfm', function (e) {
                $panel.css( 'width', Math.max( 120, Math.min( 500, startW + e.clientX - startX ) ) + 'px' );
            } ).on( 'mouseup.kfm', function () { $( document ).off( '.kfm' ); } );
        } );
    }

    /* ─────────────────────────────────────────── Event wiring ── */

    function bindEvents() {

        /* ── Navigation ──
           Directory links are never gated client-side. loadDir fires kfm_list and
           the server enforces the 'list' permission independently. */
        $( '#kfm-breadcrumb' ).on( 'click', 'a[data-rel]', function (e) {
            e.preventDefault(); loadDir( $( this ).data( 'rel' ) );
        } );
        $( '#kfm-tbody' ).on( 'click', '.kfm-dir-link', function (e) {
            e.preventDefault(); loadDir( $( this ).data( 'rel' ) );
        } );
        $( '#kfm-tree' ).on( 'click', '.kfm-tree-link', function (e) {
            e.preventDefault();
            loadDir( $( this ).data( 'rel' ) );
            $( '.kfm-tree-link' ).removeClass( 'kfm-tree-link-active' );
            $( this ).addClass( 'kfm-tree-link-active' );
        } );

        /* ── Selection ── */
        $( '#kfm-tbody' ).on( 'change', '.kfm-row-check', function () {
            $( this ).is( ':checked' ) ? state.selected.add( $( this ).val() ) : state.selected.delete( $( this ).val() );
            updateToolbarState();
        } );
        $( '#kfm-check-all' ).on( 'change', function () {
            var checked = $( this ).is( ':checked' );
            state.selected.clear();
            $( '.kfm-row-check' ).prop( 'checked', checked );
            if ( checked ) $( '.kfm-row-check' ).each( function () { state.selected.add( $( this ).val() ); } );
            updateToolbarState();
        } );

        /* ── Sorting ── */
        $( '#kfm-table thead' ).on( 'click', '.kfm-sortable', function () {
            var col = $( this ).data( 'sort' );
            if ( state.sortCol === col ) state.sortAsc = ! state.sortAsc;
            else { state.sortCol = col; state.sortAsc = true; }
            $( '.kfm-sort-arrow' ).text( '\u25b2' );
            $( this ).find( '.kfm-sort-arrow' ).text( state.sortAsc ? '\u25b2' : '\u25bc' );
            sortAndRender();
        } );

        /* ── Refresh / theme ── */
        $( '#kfm-btn-refresh' ).on( 'click', function () { loadDir( state.currentPath ); } );
        $( '#kfm-btn-theme' ).on( 'click', toggleTheme );

        /* ── New file / folder ── */
        $( '#kfm-btn-new-file' ).on( 'click', function () {
            promptModal( 'New File', 'File name:' ).then( function (name) {
                ajax( 'kfm_create_file', { dir: state.currentPath, name: name } ).then( function () {
                    notify( 'Created: ' + esc( name ), 'success', 2500 );
                    loadDir( state.currentPath );
                } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
            } ).catch( function () {} );
        } );
        $( '#kfm-btn-new-folder' ).on( 'click', function () {
            promptModal( 'New Folder', 'Folder name:' ).then( function (name) {
                ajax( 'kfm_create_dir', { dir: state.currentPath, name: name } ).then( function () {
                    notify( 'Created: ' + esc( name ), 'success', 2500 );
                    loadDir( state.currentPath );
                } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
            } ).catch( function () {} );
        } );

        /* ── Upload ── */
        $( '#kfm-btn-upload' ).on( 'click', function () { $( '#kfm-file-input' ).click(); } );
        $( '#kfm-file-input' ).on( 'change', function () { if ( this.files.length ) uploadFiles( this.files ); } );

        /* ── Drag & drop — only wire when upload is permitted ── */
        if ( canDo( 'upload' ) ) {
            var $wrap = $( '#kfm-wrap' );
            $wrap.on( 'dragover', function (e) { e.preventDefault(); $( '#kfm-dropzone' ).addClass( 'kfm-dropzone-active' ); } );
            $wrap.on( 'dragleave drop', function (e) { e.preventDefault(); $( '#kfm-dropzone' ).removeClass( 'kfm-dropzone-active' ); } );
            $wrap.on( 'drop', function (e) {
                var files = e.originalEvent.dataTransfer.files;
                if ( files.length ) uploadFiles( files );
            } );
        }

        /* ── Editor — file-link and edit-button both open the editor.
           Note: file links are only rendered when canDo('read') is true (see renderTable),
           so these handlers fire only for permitted users. ── */
        $( '#kfm-tbody' ).on( 'click', '.kfm-btn-edit', function () {
            openEditor( $( this ).data( 'rel' ) );
        } );
        $( '#kfm-tbody' ).on( 'click', '.kfm-file-link', function (e) {
            e.preventDefault();
            openEditor( $( this ).data( 'rel' ) );
        } );
        $( '#kfm-tbody' ).on( 'dblclick', 'tr[data-type="file"]', function () {
            if ( ! canDo( 'read' ) ) return;
            var rel = $( this ).data( 'rel' );
            var ext = ( rel.split( '.' ).pop() || '' ).toLowerCase();
            var txt = [ 'php','js','css','html','htm','json','xml','txt','md','sql','sh','ts','csv','log','ini','env','htaccess' ];
            if ( txt.indexOf( ext ) !== -1 || rel.indexOf( '.' ) === -1 ) openEditor( rel );
        } );
        $( '#kfm-editor-save' ).on( 'click', saveEditor );

        UIkit.util.on( '#kfm-editor-modal', 'hidden', function () {
            if ( state.editorCM ) { try { state.editorCM.toTextArea(); } catch(e) {} state.editorCM = null; }
        } );

        /* ── Copy / Cut / Paste ── */
        $( '#kfm-btn-copy' ).on( 'click', function () {
            if ( ! state.selected.size ) return;
            state.clipboard = { paths: Array.from( state.selected ), op: 'copy' };
            updateToolbarState();
            setStatus( state.selected.size + ' item(s) copied.' );
        } );
        $( '#kfm-btn-cut' ).on( 'click', function () {
            if ( ! state.selected.size ) return;
            state.clipboard = { paths: Array.from( state.selected ), op: 'cut' };
            updateToolbarState();
            setStatus( state.selected.size + ' item(s) cut.' );
        } );
        $( '#kfm-btn-paste' ).on( 'click', function () {
            if ( ! state.clipboard ) return;
            var paths  = state.clipboard.paths, op = state.clipboard.op;
            var action = op === 'copy' ? 'kfm_copy' : 'kfm_move';
            var p      = $.when();
            paths.forEach( function (src) { p = p.then( function () { return ajax( action, { path: src, dest: state.currentPath } ); } ); } );
            p.then( function () {
                if ( op === 'cut' ) state.clipboard = null;
                updateToolbarState();
                notify( 'Paste complete.', 'success', 2500 );
                loadDir( state.currentPath );
            } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
        } );

        /* ── Rename (toolbar) ── */
        $( '#kfm-btn-rename' ).on( 'click', function () {
            if ( state.selected.size !== 1 ) { notify( 'Select exactly one item to rename.', 'warning', 2500 ); return; }
            var rel  = Array.from( state.selected )[0];
            var name = rel.split( '/' ).pop();
            promptModal( 'Rename', 'New name:', name ).then( function (newName) {
                ajax( 'kfm_rename', { path: rel, new_name: newName } ).then( function () {
                    notify( 'Renamed.', 'success', 2500 );
                    loadDir( state.currentPath );
                } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
            } ).catch( function () {} );
        } );

        /* ── Rename (row button) ── */
        $( '#kfm-tbody' ).on( 'click', '.kfm-btn-rename2', function () {
            var rel = $( this ).data( 'rel' ), name = $( this ).data( 'name' );
            promptModal( 'Rename', 'New name:', name ).then( function (newName) {
                ajax( 'kfm_rename', { path: rel, new_name: newName } ).then( function () {
                    notify( 'Renamed.', 'success', 2500 );
                    loadDir( state.currentPath );
                } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
            } ).catch( function () {} );
        } );

        /* ── Delete ── */
        function doDelete( paths ) {
            if ( ! paths.length ) return;
            if ( ! window.confirm( KFM.i18n.confirmDelete || 'Delete selected item(s)?' ) ) return;
            var p = $.when();
            paths.forEach( function (rel) { p = p.then( function () { return ajax( 'kfm_delete', { path: rel } ); } ); } );
            p.then( function () {
                notify( 'Deleted.', 'success', 2500 );
                loadDir( state.currentPath );
            } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
        }
        $( '#kfm-btn-delete' ).on( 'click', function () { doDelete( Array.from( state.selected ) ); } );
        $( '#kfm-tbody' ).on( 'click', '.kfm-btn-del', function () { doDelete( [ $( this ).data( 'rel' ) ] ); } );

        /* ── Permissions (toolbar) ── */
        $( '#kfm-btn-chmod' ).on( 'click', function () {
            if ( state.selected.size !== 1 ) { notify( 'Select exactly one item.', 'warning', 2500 ); return; }
            var rel  = Array.from( state.selected )[0];
            var item = state.items.find( function (i) { return i.rel === rel; } );
            openChmod( rel, item ? item.perms : '0644' );
        } );

        /* ── Permissions (row button) ── */
        $( '#kfm-tbody' ).on( 'click', '.kfm-btn-perm', function () {
            openChmod( $( this ).data( 'rel' ), $( this ).data( 'perms' ) );
        } );

        /* ── chmod modal ── */
        $( '#kfm-chmod-modal' ).on( 'change', '.kfm-perm', function () {
            $( '#kfm-chmod-octal' ).val( checkboxesToOctal() );
        } );
        $( '#kfm-chmod-octal' ).on( 'input', function () {
            var v = $( this ).val();
            if ( /^[0-7]{3,4}$/.test( v ) ) applyOctalToCheckboxes( v );
        } );
        $( '#kfm-chmod-apply' ).on( 'click', function () {
            var mode = $( '#kfm-chmod-octal' ).val();

            if ( CHMOD_FLOOR > 0 && parseInt( mode, 8 ) < CHMOD_FLOOR ) {
                notify( 'Permissions cannot be set below the minimum floor (' + KFM.chmodFloor + ').', 'warning', 0 );
                return;
            }
            if ( parseInt( mode, 8 ) & 0o002 ) {
                notify( 'World-writable permissions are not allowed.', 'danger', 0 );
                return;
            }

            ajax( 'kfm_chmod', { path: state.chmodRel, mode: mode } ).then( function () {
                UIkit.modal( '#kfm-chmod-modal' ).hide();
                notify( 'Permissions updated.', 'success', 2500 );
                loadDir( state.currentPath );
            } ).catch( function (err) { notify( esc( errMsg( err ) ), 'danger', 0 ); } );
        } );
    }

    /* ────────────────────────────────────────────── Bootstrap ── */

    $( function () {
        initTheme();
        applyOpVisibility();
        bindEvents();
        initResizer();
        loadDir( '' );
    } );

}( jQuery, window.KFM || {}, window.UIkit ) );