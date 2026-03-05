/**
 * KFM File Manager – Gutenberg Block Editor Script
 *
 * Compiled/loaded by register_block_type() from block.json.
 * Since we ship a plain .js file (no build step required when loaded directly),
 * we use the globally available wp.* APIs.
 */
( function () {
    'use strict';

    const { registerBlockType }         = wp.blocks;
    const { InspectorControls }         = wp.blockEditor;
    const { PanelBody, RangeControl,
            ToggleControl, Notice }     = wp.components;
    const { createElement: el, Fragment } = wp.element;
    const { __ }                        = wp.i18n;

    registerBlockType( 'kfm/file-manager', {

        edit: function ( { attributes, setAttributes } ) {
            const { height, showTree } = attributes;

            return el(
                Fragment,
                null,

                /* ── Inspector sidebar ── */
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        {
                            title      : __( 'File Manager Settings', 'kfm-file-manager' ),
                            initialOpen: true,
                        },
                        el( RangeControl, {
                            label   : __( 'Height (px)', 'kfm-file-manager' ),
                            value   : height,
                            min     : 300,
                            max     : 1200,
                            step    : 50,
                            onChange: function ( v ) { setAttributes( { height: v } ); },
                        } ),
                        el( ToggleControl, {
                            label   : __( 'Show folder tree panel', 'kfm-file-manager' ),
                            checked : showTree,
                            onChange: function ( v ) { setAttributes( { showTree: v } ); },
                        } )
                    )
                ),

                /* ── Editor preview placeholder ── */
                el(
                    'div',
                    { className: 'kfm-block-preview', style: { height: height + 'px' } },

                    el( 'div', { className: 'kfm-block-preview__inner' },

                        el( 'div', { className: 'kfm-block-preview__icon' },
                            el( 'span', { className: 'dashicons dashicons-media-document' } )
                        ),

                        el( 'p', { className: 'kfm-block-preview__title' },
                            __( 'KFM File Manager', 'kfm-file-manager' )
                        ),

                        el( 'p', { className: 'kfm-block-preview__meta' },
                            __( 'Height: ', 'kfm-file-manager' ),
                            el( 'strong', null, height + 'px' ),
                            showTree
                                ? el( 'span', { className: 'kfm-block-preview__badge kfm-badge-on'  }, __( 'Tree: On',  'kfm-file-manager' ) )
                                : el( 'span', { className: 'kfm-block-preview__badge kfm-badge-off' }, __( 'Tree: Off', 'kfm-file-manager' ) )
                        ),

                        el( 'p', { className: 'kfm-block-preview__hint' },
                            __( 'The live file manager will appear on the front end.  Use the sidebar to adjust height and tree visibility.', 'kfm-file-manager' )
                        ),

                        el( Notice, {
                            status     : 'info',
                            isDismissible: false,
                            className  : 'kfm-block-preview__notice',
                        },
                            __( 'Access is controlled by the role setting in File Manager \u2192 Settings.', 'kfm-file-manager' )
                        )
                    )
                )
            );
        },

        // Server-side rendered — no save needed.
        save: function () { return null; },
    } );

} )();
