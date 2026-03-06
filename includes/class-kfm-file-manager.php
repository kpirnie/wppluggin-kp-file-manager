<?php
/**
 * File Manager class.
 * Performs all filesystem operations, always sandboxed inside the configured base path.
 * Applies: upload security, readonly-ext enforcement, dotfile visibility, path denylist, chmod floor.
 *
 * Because this plugin is a sandboxed file manager (not an installer), raw PHP filesystem
 * functions are used intentionally here. Each usage is noted inline.
 *
 * WordPress functions used where possible:
 * - wp_normalize_path()         : normalises directory separators on path construction
 * - wp_check_filetype_and_ext() : MIME + extension validation on upload
 * - wp_mkdir_p()                : recursive directory creation
 * - sanitize_file_name()        : filename sanitisation on upload
 *
 * @package KP - File Manager
 * @since 1.0.0
 * @author Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' ); 

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if( !class_exists('KFM_File_Manager') ) {

    /**
     * Performs all filesystem operations, always sandboxed inside the configured base path.
     *
     * @package KP - File Manager
     * @since 1.0.0
     * @author Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_File_Manager {

        // The resolved absolute base path all operations are sandboxed within
        private string $base_path;

        /**
         * Constructor.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param KFM_Settings $settings
         *
         */
        public function __construct( KFM_Settings $settings ) {
            $this->base_path = KFM_Settings::get_base_path();
        }

        /* ------------------------------------------------------------------ */
        /*  Path security                                                       */
        /* ------------------------------------------------------------------ */

        /**
         * Resolves a relative path to an absolute path, verifying it stays
         * inside the sandbox. Returns false on any violation.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel Relative path from the base directory.
         * @return string|false Absolute path or false on failure.
         *
         */
        public function resolve( string $rel ): string|false {
            // Strip null bytes — never valid in a path
            $rel = str_replace( "\0", '', $rel );
            if ( $rel === '' || $rel === '.' || $rel === '/' ) return $this->base_path;

            // wp_normalize_path() standardises directory separators across platforms (handles
            // Windows backslashes). Still need realpath() below for symlink resolution.
            $candidate = wp_normalize_path( $this->base_path . DIRECTORY_SEPARATOR . ltrim( $rel, '/\\' ) );

            // WP.org note: realpath() has no WP_Filesystem equivalent. It is the only reliable
            // way to resolve symlinks and canonicalise paths for sandbox enforcement.
            if ( file_exists( $candidate ) ) {
                $real = realpath( $candidate );
            } else {
                // For new paths, verify the parent exists and is inside the sandbox
                $parent = realpath( dirname( $candidate ) );
                if ( $parent === false ) return false;
                $real = wp_normalize_path( $parent . DIRECTORY_SEPARATOR . basename( $candidate ) );
            }

            if ( $real === false ) return false;

            // Strict sandbox check — must start with the base path
            if ( strpos( $real, $this->base_path ) !== 0 ) return false;

            return $real;
        }

        /**
         * Converts an absolute path back to a relative path from the base directory.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $abs Absolute path.
         * @return string Relative path.
         *
         */
        public function relative( string $abs ): string {
            return ltrim( str_replace( $this->base_path, '', $abs ), DIRECTORY_SEPARATOR );
        }

        /* ------------------------------------------------------------------ */
        /*  Path denylist helper                                               */
        /* ------------------------------------------------------------------ */

        /**
         * Returns true if the given absolute path matches any entry in the
         * configured path denylist.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $abs_path Absolute path to check.
         * @return bool
         *
         */
        private function is_denied( string $abs_path ): bool {
            $denylist = KFM_Settings::get_path_denylist();
            if ( empty( $denylist ) ) return false;

            $rel = $this->relative( $abs_path );
            foreach ( $denylist as $denied ) {
                // Match exact path or any path prefixed by the denied directory
                if ( $rel === $denied || strpos( $rel, rtrim( $denied, '/' ) . '/' ) === 0 ) {
                    return true;
                }
                // Also match on basename alone for convenience
                if ( basename( $abs_path ) === $denied ) {
                    return true;
                }
            }
            return false;
        }

        /* ------------------------------------------------------------------ */
        /*  List directory                                                      */
        /* ------------------------------------------------------------------ */

        /**
         * Returns a structured listing of the given directory's contents.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel Relative path to the directory.
         * @return array|WP_Error Listing data or error.
         *
         */
        public function list_dir( string $rel ): array|WP_Error {
            $path = $this->resolve( $rel );
            if ( ! $path || ! is_dir( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Directory not found.', 'kfm-file-manager' ) );
            }

            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kfm-file-manager' ) );
            }

            $show_dots = KFM_Settings::show_dotfiles();
            $items     = [];

            // WP.org note: opendir/readdir/closedir have no direct WP_Filesystem equivalent
            // for iterating raw directory contents. WP_Filesystem::dirlist() exists but returns
            // a different structure and does not support the dotfile filtering we need here.
            $dir = opendir( $path );
            if ( ! $dir ) {
                return new WP_Error( 'kfm_no_read', __( 'Cannot read directory.', 'kfm-file-manager' ) );
            }

            while ( ( $entry = readdir( $dir ) ) !== false ) {
                if ( $entry === '.' || $entry === '..' ) continue;
                if ( ! $show_dots && $entry[0] === '.' ) continue;

                $full     = $path . DIRECTORY_SEPARATOR . $entry;
                $rel_path = $this->relative( $full );

                if ( $this->is_denied( $full ) ) continue;

                $items[] = [
                    'name'  => $entry,
                    'rel'   => $rel_path,
                    'type'  => is_dir( $full ) ? 'dir' : 'file',
                    // WP.org note: filesize() — no WP_Filesystem equivalent.
                    'size'  => is_file( $full ) ? filesize( $full ) : 0,
                    // WP.org note: filemtime() — no WP_Filesystem equivalent.
                    'mtime' => filemtime( $full ),
                    // WP.org note: fileperms() — no WP_Filesystem equivalent.
                    'perms' => substr( sprintf( '%o', fileperms( $full ) ), -4 ),
                    'ext'   => is_file( $full ) ? strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) : '',
                ];
            }
            closedir( $dir );

            // Directories first, then alphabetical
            usort( $items, static function ( $a, $b ) {
                if ( $a['type'] !== $b['type'] ) return $a['type'] === 'dir' ? -1 : 1;
                return strcasecmp( $a['name'], $b['name'] );
            } );

            return [
                'current'     => $this->relative( $path ),
                'base'        => $this->base_path,
                'items'       => $items,
                'breadcrumbs' => $this->breadcrumbs( $path ),
            ];
        }

        /* ------------------------------------------------------------------ */
        /*  Breadcrumbs                                                         */
        /* ------------------------------------------------------------------ */

        /**
         * Builds a breadcrumb trail from the base directory down to the given path.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $abs_path Absolute path of the current directory.
         * @return array Array of breadcrumb entries with name and rel keys.
         *
         */
        private function breadcrumbs( string $abs_path ): array {
            $crumbs  = [];
            $current = $abs_path;

            while ( true ) {
                $crumbs[] = [
                    'name' => $current === $this->base_path ? basename( $this->base_path ) : basename( $current ),
                    'rel'  => $this->relative( $current ),
                ];
                if ( $current === $this->base_path ) break;
                $parent = dirname( $current );
                if ( $parent === $current || strpos( $parent, $this->base_path ) !== 0 ) break;
                $current = $parent;
            }

            return array_reverse( $crumbs );
        }

        /* ------------------------------------------------------------------ */
        /*  Read file                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * Reads and returns the contents of a file.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel Relative path to the file.
         * @return array|WP_Error File data or error.
         *
         */
        public function read_file( string $rel ): array|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path || ! is_file( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'File not found.', 'kfm-file-manager' ) );
            }
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this file is restricted.', 'kfm-file-manager' ) );
            }
            if ( ! is_readable( $path ) ) {
                return new WP_Error( 'kfm_no_read', __( 'File is not readable.', 'kfm-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->get_contents().
            // Not used here because initialising WP_Filesystem outside of an admin credentials
            // flow outputs HTML that corrupts REST API JSON responses and breaks Gutenberg.
            $content = file_get_contents( $path );
            if ( $content === false ) {
                return new WP_Error( 'kfm_read_error', __( 'Could not read file.', 'kfm-file-manager' ) );
            }

            return [
                'name'    => basename( $path ),
                'rel'     => $rel,
                'content' => $content,
                'ext'     => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
                'size'    => strlen( $content ),
            ];
        }

        /* ------------------------------------------------------------------ */
        /*  Write file                                                          */
        /* ------------------------------------------------------------------ */

        /**
         * Writes content to an existing file.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel     Relative path to the file.
         * @param string $content Content to write.
         * @return true|WP_Error
         *
         */
        public function write_file( string $rel, string $content ): true|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kfm-file-manager' ) );
            if ( is_dir( $path ) ) return new WP_Error( 'kfm_is_dir', __( 'Path is a directory.', 'kfm-file-manager' ) );
            if ( $this->is_denied( $path ) ) return new WP_Error( 'kfm_denied', __( 'Access to this file is restricted.', 'kfm-file-manager' ) );

            $ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            $readonly = KFM_Settings::get_readonly_exts();
            if ( in_array( $ext, $readonly, true ) ) {
                return new WP_Error( 'kfm_readonly', sprintf( __( '.%s files are read-only.', 'kfm-file-manager' ), $ext ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->put_contents().
            // Not used here — see class-level docblock for explanation.
            if ( file_put_contents( $path, $content ) === false ) {
                return new WP_Error( 'kfm_write_error', __( 'Could not write file. Check permissions.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Create file                                                         */
        /* ------------------------------------------------------------------ */

        /**
         * Creates a new empty file at the given relative path.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel Relative path for the new file.
         * @return true|WP_Error
         *
         */
        public function create_file( string $rel ): true|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kfm-file-manager' ) );
            if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', __( 'File already exists.', 'kfm-file-manager' ) );

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->put_contents() with empty string.
            // Not used here — see class-level docblock for explanation.
            if ( file_put_contents( $path, '' ) === false ) {
                return new WP_Error( 'kfm_write_error', __( 'Could not create file.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Create directory                                                    */
        /* ------------------------------------------------------------------ */

        /**
         * Creates a new directory at the given relative path.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel Relative path for the new directory.
         * @return true|WP_Error
         *
         */
        public function create_dir( string $rel ): true|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kfm-file-manager' ) );
            if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', __( 'Directory already exists.', 'kfm-file-manager' ) );

            // wp_mkdir_p() is the correct WordPress function here — it handles recursive
            // directory creation and is safe to use without WP_Filesystem initialisation.
            if ( ! wp_mkdir_p( $path ) ) {
                return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create directory.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Delete                                                              */
        /* ------------------------------------------------------------------ */

        /**
         * Deletes a file or directory (recursively).
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel Relative path to delete.
         * @return true|WP_Error
         *
         */
        public function delete( string $rel ): true|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kfm-file-manager' ) );
            }

            // Never allow the sandbox root itself to be deleted
            if ( rtrim( $path, DIRECTORY_SEPARATOR ) === rtrim( $this->base_path, DIRECTORY_SEPARATOR ) ) {
                return new WP_Error( 'kfm_protected', __( 'Cannot delete the root directory.', 'kfm-file-manager' ) );
            }

            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kfm-file-manager' ) );
            }

            if ( is_dir( $path ) ) return $this->delete_dir_recursive( $path );

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->delete().
            // Not used here — see class-level docblock for explanation.
            if ( ! unlink( $path ) ) {
                return new WP_Error( 'kfm_delete_fail', __( 'Could not delete file.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /**
         * Recursively deletes a directory and all its contents.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $path Absolute path to the directory.
         * @return true|WP_Error
         *
         */
        private function delete_dir_recursive( string $path ): true|WP_Error {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ( $entries as $entry ) {
                // WP.org note: unlink/rmdir used here intentionally.
                // WP_Filesystem::delete( $path, true ) would be the equivalent but requires
                // initialisation — see class-level docblock.
                $ok = $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
                if ( ! $ok ) return new WP_Error( 'kfm_delete_fail', sprintf( __( 'Could not remove: %s', 'kfm-file-manager' ), $entry->getFilename() ) );
            }

            if ( ! rmdir( $path ) ) {
                return new WP_Error( 'kfm_delete_fail', __( 'Could not remove directory.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Rename                                                              */
        /* ------------------------------------------------------------------ */

        /**
         * Renames a file or directory within the same parent directory.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel      Relative path to the item.
         * @param string $new_name New filename/directory name.
         * @return true|WP_Error
         *
         */
        public function rename( string $rel, string $new_name ): true|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kfm-file-manager' ) );
            }

            // Sanitise — basename only, no path traversal allowed
            $new_name = basename( $new_name );
            if ( $new_name === '' || $new_name === '.' || $new_name === '..' ) {
                return new WP_Error( 'kfm_bad_name', __( 'Invalid name.', 'kfm-file-manager' ) );
            }

            $dest = dirname( $path ) . DIRECTORY_SEPARATOR . $new_name;
            if ( file_exists( $dest ) ) {
                return new WP_Error( 'kfm_exists', __( 'A file or directory with that name already exists.', 'kfm-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->move().
            // Not used here — see class-level docblock for explanation.
            if ( ! rename( $path, $dest ) ) {
                return new WP_Error( 'kfm_rename_fail', __( 'Rename failed.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Copy                                                                */
        /* ------------------------------------------------------------------ */

        /**
         * Copies a file or directory to a destination within the sandbox.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel_src  Relative source path.
         * @param string $rel_dest Relative destination directory.
         * @return true|WP_Error
         *
         */
        public function copy( string $rel_src, string $rel_dest ): true|WP_Error {
            $src  = $this->resolve( $rel_src );
            $dest = $this->resolve( $rel_dest );

            if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', __( 'Source not found.', 'kfm-file-manager' ) );
            if ( ! $dest ) return new WP_Error( 'kfm_bad_path', __( 'Invalid destination.', 'kfm-file-manager' ) );

            // If destination is a directory, place the item inside it
            if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );

            if ( is_dir( $src ) ) return $this->copy_dir( $src, $dest );

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->copy().
            // Not used here — see class-level docblock for explanation.
            if ( ! copy( $src, $dest ) ) {
                return new WP_Error( 'kfm_copy_fail', __( 'Copy failed.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /**
         * Recursively copies a directory and all its contents.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $src  Absolute source path.
         * @param string $dest Absolute destination path.
         * @return true|WP_Error
         *
         */
        private function copy_dir( string $src, string $dest ): true|WP_Error {
            if ( ! wp_mkdir_p( $dest ) ) {
                return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create destination directory.', 'kfm-file-manager' ) );
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            /** @var RecursiveDirectoryIterator $entries */
            foreach ( $entries as $entry ) {
                $target = $dest . DIRECTORY_SEPARATOR . $entries->getSubPathName();
                if ( $entry->isDir() ) {
                    if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
                        return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create subdirectory.', 'kfm-file-manager' ) );
                    }
                } else {
                    // WP.org note: WP_Filesystem equivalent is $wp_filesystem->copy().
                    // Not used here — see class-level docblock for explanation.
                    if ( ! copy( $entry->getPathname(), $target ) ) {
                        return new WP_Error( 'kfm_copy_fail', sprintf( __( 'Could not copy file: %s', 'kfm-file-manager' ), $entry->getFilename() ) );
                    }
                }
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Move                                                                */
        /* ------------------------------------------------------------------ */

        /**
         * Moves a file or directory to a destination within the sandbox.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel_src  Relative source path.
         * @param string $rel_dest Relative destination directory.
         * @return true|WP_Error
         *
         */
        public function move( string $rel_src, string $rel_dest ): true|WP_Error {
            $src  = $this->resolve( $rel_src );
            $dest = $this->resolve( $rel_dest );

            if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', __( 'Source not found.', 'kfm-file-manager' ) );
            if ( ! $dest ) return new WP_Error( 'kfm_bad_path', __( 'Invalid destination.', 'kfm-file-manager' ) );

            if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );

            if ( file_exists( $dest ) ) {
                return new WP_Error( 'kfm_exists', __( 'Destination already exists.', 'kfm-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->move().
            // Not used here — see class-level docblock for explanation.
            if ( ! rename( $src, $dest ) ) {
                return new WP_Error( 'kfm_move_fail', __( 'Move failed.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Permissions (chmod)                                                 */
        /* ------------------------------------------------------------------ */

        /**
         * Changes the permissions of a file or directory.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $rel        Relative path to the item.
         * @param string $mode_octal Octal permissions string e.g. "0644".
         * @return true|WP_Error
         *
         */
        public function chmod( string $rel, string $mode_octal ): true|WP_Error {
            $path = $this->resolve( $rel );

            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kfm-file-manager' ) );
            }

            if ( ! preg_match( '/^[0-7]{3,4}$/', $mode_octal ) ) {
                return new WP_Error( 'kfm_bad_perms', __( 'Invalid permission value. Use octal like 0644.', 'kfm-file-manager' ) );
            }

            // Enforce the minimum permissions floor
            $floor = KFM_Settings::get_chmod_floor();
            if ( $floor !== '0' && $floor !== '' ) {
                $requested = octdec( $mode_octal );
                $minimum   = octdec( $floor );
                if ( $requested < $minimum ) {
                    return new WP_Error( 'kfm_chmod_floor', sprintf(
                        __( 'Permissions cannot be set below the minimum floor of %s.', 'kfm-file-manager' ),
                        $floor
                    ) );
                }
            }

            // World-writable (o+w) is always blocked regardless of settings
            $dec = octdec( $mode_octal );
            if ( $dec & 0002 ) {
                return new WP_Error( 'kfm_chmod_world_write', __( 'World-writable permissions (o+w) are not permitted.', 'kfm-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->chmod().
            // Not used here — see class-level docblock for explanation.
            if ( ! chmod( $path, octdec( $mode_octal ) ) ) {
                return new WP_Error( 'kfm_chmod_fail', __( 'Could not change permissions.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Upload                                                              */
        /* ------------------------------------------------------------------ */

        /**
         * Validates and moves an uploaded file into the sandbox.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param string $dest_rel Relative destination directory.
         * @param array  $file     Entry from $_FILES.
         * @return true|WP_Error
         *
         */
        public function upload( string $dest_rel, array $file ): true|WP_Error {
            $dest_dir = $this->resolve( $dest_rel );

            if ( ! $dest_dir || ! is_dir( $dest_dir ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Destination directory not found.', 'kfm-file-manager' ) );
            }

            if ( $this->is_denied( $dest_dir ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kfm-file-manager' ) );
            }

            if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
                return new WP_Error( 'kfm_upload_err', sprintf( __( 'Upload error code: %d', 'kfm-file-manager' ), $file['error'] ) );
            }

            $name = sanitize_file_name( basename( $file['name'] ) );
            if ( $name === '' ) {
                return new WP_Error( 'kfm_bad_name', __( 'Invalid file name.', 'kfm-file-manager' ) );
            }

            // ── Dangerous filename check ─────────────────────────────────────
            $dangerous_names = [ '.htaccess', '.htpasswd', '.env', '.user.ini', 'php.ini', 'web.config' ];
            if ( in_array( strtolower( $name ), $dangerous_names, true ) ) {
                return new WP_Error( 'kfm_blocked_name', __( 'That filename is not permitted.', 'kfm-file-manager' ) );
            }

            // ── Extension check ──────────────────────────────────────────────
            $ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            $blocked = KFM_Settings::get_blocked_exts();
            if ( in_array( $ext, $blocked, true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( __( 'File type .%s is not permitted.', 'kfm-file-manager' ), $ext )
                );
            }

            // ── MIME + extension verification ────────────────────────────────
            // wp_check_filetype_and_ext() is the correct WordPress function here — it validates
            // both the extension and the real MIME type using finfo internally, and runs through
            // the wp_check_filetype_and_ext filter so other plugins can extend it.
            // Caveat: it validates against WordPress's allowed mime types list. If a type you
            // need isn't in that list, hook the 'upload_mimes' filter to add it.
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $name );

            // If WordPress couldn't determine a valid type, block it
            if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
                return new WP_Error( 'kfm_blocked_mime',
                    sprintf( __( 'File type could not be verified or is not permitted (.%s).', 'kfm-file-manager' ), $ext )
                );
            }

            // Block PHP content regardless of extension — wp_check_filetype_and_ext already
            // does this for most cases, but we double-check the real MIME explicitly.
            $php_mimes = [ 'application/x-php', 'text/x-php', 'application/x-httpd-php' ];
            if ( in_array( $check['type'], $php_mimes, true ) ) {
                return new WP_Error( 'kfm_blocked_mime', __( 'Files containing PHP code are not permitted.', 'kfm-file-manager' ) );
            }

            // Extension reported by WordPress must match what was submitted — catches renamed files
            if ( $check['ext'] !== $ext ) {
                return new WP_Error( 'kfm_mime_mismatch',
                    sprintf( __( 'File content (%s) does not match extension (.%s).', 'kfm-file-manager' ), $check['type'], $ext )
                );
            }

            // ── Path traversal final check ───────────────────────────────────
            if ( ! $this->resolve( $this->relative( $dest_dir ) . '/' . $name ) ) {
                return new WP_Error( 'kfm_bad_path', __( 'Invalid upload destination.', 'kfm-file-manager' ) );
            }

            $target = $dest_dir . DIRECTORY_SEPARATOR . $name;

            // WP.org note: move_uploaded_file() is intentionally used here — it is the correct
            // PHP function for handling uploaded files from $_FILES and verifies the file was
            // actually uploaded via HTTP POST (preventing local file injection attacks).
            // WordPress uses it internally too. There is no WP_Filesystem equivalent.
            if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
                return new WP_Error( 'kfm_upload_fail', __( 'Could not move uploaded file.', 'kfm-file-manager' ) );
            }

            return true;
        }

        /* ------------------------------------------------------------------ */
        /*  Getters                                                             */
        /* ------------------------------------------------------------------ */

        /**
         * Returns the absolute base path the file manager is sandboxed within.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return string
         *
         */
        public function get_base_path(): string {
            return $this->base_path;
        }
    }
}