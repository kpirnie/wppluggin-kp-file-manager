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

            // Final check to ensure the resolved path is within the sandbox
            if ( $real === false ) return false;

            // Strict sandbox check — must start with the base path
            $base = rtrim( $this->base_path, DIRECTORY_SEPARATOR );
            if ( $real !== $base && strpos( $real, $base . DIRECTORY_SEPARATOR ) !== 0 ) return false;

            // return the resolved absolute path
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
            $base = rtrim( $this->base_path, DIRECTORY_SEPARATOR );
            return ltrim( substr( $abs, strlen( $base ) ), DIRECTORY_SEPARATOR );
        }

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

            // Denylist entries can be exact paths or directories (with trailing slash).
            $denylist = KFM_Settings::get_path_denylist();
            if ( empty( $denylist ) ) return false;

            // Check if the absolute path matches or is contained within any denylist entry
            $rel = $this->relative( $abs_path );

            // loop through denylist and check for matches
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

            // No matches found, not denied
            return false;
        }

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

            // Resolve the path and verify it's a directory within the sandbox
            $path = $this->resolve( $rel );
            if ( ! $path || ! is_dir( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Directory not found.', 'kp-file-manager' ) );
            }

            // Check against the path denylist before attempting to read
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kp-file-manager' ) );
            }

            // Read the directory contents, applying dotfile visibility and building the items array
            $show_dots = KFM_Settings::show_dotfiles();
            $items     = [];

            // WP.org note: opendir/readdir/closedir have no direct WP_Filesystem equivalent
            // for iterating raw directory contents. WP_Filesystem::dirlist() exists but returns
            // a different structure and does not support the dotfile filtering we need here.
            $dir = opendir( $path );
            if ( ! $dir ) {
                return new WP_Error( 'kfm_no_read', __( 'Cannot read directory.', 'kfm-file-manager' ) );
            }

            // Loop through directory entries
            while ( ( $entry = readdir( $dir ) ) !== false ) {

                // Skip . and .. entries, and dotfiles if configured to hide them
                if ( $entry === '.' || $entry === '..' ) continue;
                if ( ! $show_dots && $entry[0] === '.' ) continue;

                // Build the full absolute path and relative path for this entry
                $full     = $path . DIRECTORY_SEPARATOR . $entry;
                $rel_path = $this->relative( $full );

                // Check against the path denylist and skip if denied
                if ( $this->is_denied( $full ) ) continue;

                // Add the entry to the items array with its metadata
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

            // Close the directory handle
            closedir( $dir );

            // Directories first, then alphabetical — case-insensitive
            usort( $items, static function ( $a, $b ) {
                if ( $a['type'] !== $b['type'] ) return $a['type'] === 'dir' ? -1 : 1;
                return strcasecmp( $a['name'], $b['name'] );
            } );

            // Return the structured listing data including the current path, items, and breadcrumbs
            return [
                'current'     => $this->relative( $path ),
                'base'        => $this->base_path,
                'items'       => $items,
                'breadcrumbs' => $this->breadcrumbs( $path ),
            ];
        }

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

            // Build the breadcrumb trail by traversing up from the current path to the base path
            $crumbs  = [];
            $current = $abs_path;

            // Loop until we reach the base path, adding each directory to the breadcrumbs array
            while ( true ) {

                // Add the current directory to the breadcrumbs array with its name and relative path
                $crumbs[] = [
                    'name' => $current === $this->base_path ? basename( $this->base_path ) : basename( $current ),
                    'rel'  => $this->relative( $current ),
                ];

                // If we've reached the base path, stop. Otherwise, move up to the parent directory.
                if ( $current === $this->base_path ) break;
                $parent = dirname( $current );

                // Additional safety check to prevent infinite loops in case of unexpected path issues
                if ( $parent === $current || strpos( $parent, $this->base_path ) !== 0 ) break;
                $current = $parent;
            }

            // reverse them before returning
            return array_reverse( $crumbs );
        }

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

            // Resolve the path and verify it's a file within the sandbox
            $path = $this->resolve( $rel );

            // Check for existence, file type, and denylist before attempting to read
            if ( ! $path || ! is_file( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'File not found.', 'kp-file-manager' ) );
            }

            // Check if the file is denied
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this file is restricted.', 'kp-file-manager' ) );
            }

            // Check for readonly extension before attempting to read
            if ( ! is_readable( $path ) ) {
                return new WP_Error( 'kfm_no_read', __( 'File is not readable.', 'kp-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->get_contents().
            // Not used here because initialising WP_Filesystem outside of an admin credentials
            // flow outputs HTML that corrupts REST API JSON responses and breaks Gutenberg.
            $content = file_get_contents( $path );
            if ( $content === false ) {
                return new WP_Error( 'kfm_read_error', __( 'Could not read file.', 'kp-file-manager' ) );
            }

            // Return the file data including name, relative path, content, extension, and size
            return [
                'name'    => basename( $path ),
                'rel'     => $rel,
                'content' => $content,
                'ext'     => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
                'size'    => strlen( $content ),
            ];
        }

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

            // Resolve the path and verify it's a file within the sandbox
            $path = $this->resolve( $rel );

            // Check for existence, file type, and denylist before attempting to write
            if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kp-file-manager' ) );

            // make sure we have a valid path before doing any file operations, to avoid PHP warnings
            if ( is_dir( $path ) ) return new WP_Error( 'kfm_is_dir', __( 'Path is a directory.', 'kp-file-manager' ) );

            // Check if the file is denied
            if ( $this->is_denied( $path ) ) return new WP_Error( 'kfm_denied', __( 'Access to this file is restricted.', 'kp-file-manager' ) );
            
            // Check for readonly extension before attempting to write
            $ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            // Check if the file extension is in the readonly list and block writing if so
            $readonly = KFM_Settings::get_readonly_exts();
            if ( in_array( $ext, $readonly, true ) ) {
                return new WP_Error( 'kfm_readonly', sprintf( __( '.%s files are read-only.', 'kp-file-manager' ), $ext ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->put_contents().
            // Not used here — see class-level docblock for explanation.
            if ( file_put_contents( $path, $content ) === false ) {
                return new WP_Error( 'kfm_write_error', __( 'Could not write file. Check permissions.', 'kp-file-manager' ) );
            }

            // return true on success
            return true;
        }

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

            // Resolve the path and verify it's within the sandbox
            $path = $this->resolve( $rel );

            // Check for validity and existence before attempting to create
            if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kp-file-manager' ) );
            if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', __( 'File already exists.', 'kp-file-manager' ) );

            // check the blocked extensions
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( $ext !== '' && in_array( $ext, KFM_Settings::get_blocked_exts(), true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( __( 'Cannot create .%s files — file type is blocked.', 'kp-file-manager' ), $ext )
                );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->put_contents() with empty string.
            // Not used here — see class-level docblock for explanation.
            if ( file_put_contents( $path, '' ) === false ) {
                return new WP_Error( 'kfm_write_error', __( 'Could not create file.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

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

            // Resolve the path and verify it's within the sandbox
            $path = $this->resolve( $rel );

            // Check for validity and existence before attempting to create
            if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kp-file-manager' ) );
            if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', __( 'Directory already exists.', 'kp-file-manager' ) );

            // wp_mkdir_p() is the correct WordPress function here — it handles recursive
            // directory creation and is safe to use without WP_Filesystem initialisation.
            if ( ! wp_mkdir_p( $path ) ) {
                return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create directory.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

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

            // Resolve the path and verify it exists within the sandbox
            $path = $this->resolve( $rel );

            // Check for existence before attempting to delete, and verify it's not the root of the sandbox
            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kp-file-manager' ) );
            }

            // Never allow the sandbox root itself to be deleted
            if ( rtrim( $path, DIRECTORY_SEPARATOR ) === rtrim( $this->base_path, DIRECTORY_SEPARATOR ) ) {
                return new WP_Error( 'kfm_protected', __( 'Cannot delete the root directory.', 'kp-file-manager' ) );
            }

            // Check if the path is denied
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kp-file-manager' ) );
            }

            // If it's a directory, delete recursively. Otherwise, delete the file directly.
            if ( is_dir( $path ) ) return $this->delete_dir_recursive( $path );

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->delete().
            // Not used here — see class-level docblock for explanation.
            if ( ! unlink( $path ) ) {
                return new WP_Error( 'kfm_delete_fail', __( 'Could not delete file.', 'kp-file-manager' ) );
            }

            // true on success
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

            // Use RecursiveDirectoryIterator and RecursiveIteratorIterator to traverse the directory tree
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            // Loop through entries
            foreach ( $entries as $entry ) {
                
                // WP.org note: unlink/rmdir used here intentionally.
                // WP_Filesystem::delete( $path, true ) would be the equivalent but requires
                // initialisation — see class-level docblock.
                $ok = $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
                if ( ! $ok ) return new WP_Error( 'kfm_delete_fail', sprintf( __( 'Could not remove: %s', 'kp-file-manager' ), $entry->getFilename() ) );
            }

            // Finally, remove the now-empty directory itself
            if ( ! rmdir( $path ) ) {
                return new WP_Error( 'kfm_delete_fail', __( 'Could not remove directory.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

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

            // Resolve the path and verify it exists within the sandbox
            $path = $this->resolve( $rel );

            // Check for existence before attempting to rename, and verify it's not the root of the sandbox
            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kp-file-manager' ) );
            }

            // Sanitise — basename only, no path traversal allowed
            $new_name = basename( $new_name );
            if ( $new_name === '' || $new_name === '.' || $new_name === '..' ) {
                return new WP_Error( 'kfm_bad_name', __( 'Invalid name.', 'kp-file-manager' ) );
            }

            // block bad extenstions
            $new_ext = strtolower( pathinfo( $new_name, PATHINFO_EXTENSION ) );
            if ( $new_ext !== '' && in_array( $new_ext, KFM_Settings::get_blocked_exts(), true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( __( 'Cannot rename to .%s — file type is blocked.', 'kp-file-manager' ), $new_ext )
                );
            }

            // Check if the new name is the same as the old name (case-insensitive) and skip if so
            $dest = dirname( $path ) . DIRECTORY_SEPARATOR . $new_name;
            if ( file_exists( $dest ) ) {
                return new WP_Error( 'kfm_exists', __( 'A file or directory with that name already exists.', 'kp-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->move().
            // Not used here — see class-level docblock for explanation.
            if ( ! rename( $path, $dest ) ) {
                return new WP_Error( 'kfm_rename_fail', __( 'Rename failed.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

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

            // Resolve the source and destination paths, verifying they exist within the sandbox
            $src  = $this->resolve( $rel_src );
            $dest = $this->resolve( $rel_dest );

            // Check for existence of source, validity of destination, and verify source is not the root of the sandbox
            if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', __( 'Source not found.', 'kp-file-manager' ) );
            if ( ! $dest ) return new WP_Error( 'kfm_bad_path', __( 'Invalid destination.', 'kp-file-manager' ) );

            // If destination is a directory set the proper directory
            if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );

            // one last extension check... just in case....
            $dest_ext = strtolower( pathinfo( basename( $dest ), PATHINFO_EXTENSION ) );
            if ( $dest_ext !== '' && in_array( $dest_ext, KFM_Settings::get_blocked_exts(), true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( __( 'Cannot copy to .%s — file type is blocked.', 'kp-file-manager' ), $dest_ext )
                );
            }

            // check if the source exists and copy the directory
            if ( is_dir( $src ) ) return $this->copy_dir( $src, $dest );

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->copy().
            // Not used here — see class-level docblock for explanation.
            if ( ! copy( $src, $dest ) ) {
                return new WP_Error( 'kfm_copy_fail', __( 'Copy failed.', 'kp-file-manager' ) );
            }

            // true on success
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

            // Create the destination directory if it doesn't exist
            if ( ! wp_mkdir_p( $dest ) ) {
                return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create destination directory.', 'kp-file-manager' ) );
            }

            // Use RecursiveDirectoryIterator and RecursiveIteratorIterator to traverse the source directory tree
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            // Loop through entries and copy files/directories to the destination, preserving structure
            /** @var RecursiveDirectoryIterator $entries */
            foreach ( $entries as $entry ) {

                // Build the target path by appending the subpath to the destination directory
                $target = $dest . DIRECTORY_SEPARATOR . $entries->getSubPathName();

                // If it's a directory, create it. If it's a file, copy it. Preserve permissions if possible.
                if ( $entry->isDir() ) {
                    if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
                        return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create subdirectory.', 'kp-file-manager' ) );
                    }
                } else {
                    // WP.org note: WP_Filesystem equivalent is $wp_filesystem->copy().
                    // Not used here — see class-level docblock for explanation.
                    if ( ! copy( $entry->getPathname(), $target ) ) {
                        return new WP_Error( 'kfm_copy_fail', sprintf( __( 'Could not copy file: %s', 'kp-file-manager' ), $entry->getFilename() ) );
                    }
                }
            }

            // true on success
            return true;
        }

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

            // Resolve the source and destination paths, verifying they exist within the sandbox
            $src  = $this->resolve( $rel_src );
            $dest = $this->resolve( $rel_dest );

            // Check for existence of source, validity of destination, and verify source is not the root of the sandbox
            if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', __( 'Source not found.', 'kp-file-manager' ) );
            if ( ! $dest ) return new WP_Error( 'kfm_bad_path', __( 'Invalid destination.', 'kp-file-manager' ) );

            // if the destination is a directory make sure it's setup properly for the move
            if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );

            // check if the destination already exists to prevent overwriting
            if ( file_exists( $dest ) ) {
                return new WP_Error( 'kfm_exists', __( 'Destination already exists.', 'kp-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->move().
            // Not used here — see class-level docblock for explanation.
            if ( ! rename( $src, $dest ) ) {
                return new WP_Error( 'kfm_move_fail', __( 'Move failed.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

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

            // Resolve the path and verify it exists within the sandbox
            $path = $this->resolve( $rel );

            // Check for existence before attempting to change permissions, and verify it's not the root of the sandbox
            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kp-file-manager' ) );
            }

            // make sure the octal mode is valid before doing any file operations
            if ( ! preg_match( '/^[0-7]{3,4}$/', $mode_octal ) ) {
                return new WP_Error( 'kfm_bad_perms', __( 'Invalid permission value. Use octal like 0644.', 'kp-file-manager' ) );
            }

            // Enforce the minimum permissions floor
            $floor = KFM_Settings::get_chmod_floor();
            if ( $floor !== '0' && $floor !== '' ) {

                // Convert octal strings to decimal for comparison
                $requested = octdec( $mode_octal );
                $minimum   = octdec( $floor );

                // If the requested permissions are less than the minimum floor, block it
                if ( $requested < $minimum ) {
                    return new WP_Error( 'kfm_chmod_floor', sprintf(
                        __( 'Permissions cannot be set below the minimum floor of %s.', 'kp-file-manager' ),
                        $floor
                    ) );
                }
            }

            // World-writable (o+w) is always blocked regardless of settings
            $dec = octdec( $mode_octal );
            if ( $dec & 0002 ) {
                return new WP_Error( 'kfm_chmod_world_write', __( 'World-writable permissions (o+w) are not permitted.', 'kp-file-manager' ) );
            }

            // WP.org note: WP_Filesystem equivalent is $wp_filesystem->chmod().
            // Not used here — see class-level docblock for explanation.
            if ( ! chmod( $path, octdec( $mode_octal ) ) ) {
                return new WP_Error( 'kfm_chmod_fail', __( 'Could not change permissions.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

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

            // Resolve the destination directory and verify it's a directory within the sandbox
            $dest_dir = $this->resolve( $dest_rel );

            // Check for validity and existence before attempting to upload
            if ( ! $dest_dir || ! is_dir( $dest_dir ) ) {
                return new WP_Error( 'kfm_not_found', __( 'Destination directory not found.', 'kp-file-manager' ) );
            }

            // Check against the path denylist before attempting to upload
            if ( $this->is_denied( $dest_dir ) ) {
                return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kp-file-manager' ) );
            }

            // Basic upload error check
            if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
                return new WP_Error( 'kfm_upload_err', sprintf( __( 'Upload error code: %d', 'kp-file-manager' ), $file['error'] ) );
            }

            // Sanitise the filename and check for validity
            $name = sanitize_file_name( basename( $file['name'] ) );
            if ( $name === '' ) {
                return new WP_Error( 'kfm_bad_name', __( 'Invalid file name.', 'kp-file-manager' ) );
            }

            // check for dangerous filenames that could be executed if accessed directly, regardless of extension
            $dangerous_names = [ '.htaccess', '.htpasswd', '.env', '.user.ini', 'php.ini', 'web.config' ];
            if ( in_array( strtolower( $name ), $dangerous_names, true ) ) {
                return new WP_Error( 'kfm_blocked_name', __( 'That filename is not permitted.', 'kp-file-manager' ) );
            }

            // Check for blocked extensions before attempting to upload
            $ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            $blocked = KFM_Settings::get_blocked_exts();
            if ( in_array( $ext, $blocked, true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( __( 'File type .%s is not permitted.', 'kp-file-manager' ), $ext )
                );
            }

            // WP.org note: wp_check_filetype_and_ext() is used here to validate the file's MIME type and extension against WordPress's allowed list.
            // Caveat: it validates against WordPress's allowed mime types list. If a type you
            // need isn't in that list, hook the 'upload_mimes' filter to add it.
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $name );

            // If WordPress couldn't determine a valid type, block it
            if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
                return new WP_Error( 'kfm_blocked_mime',
                    sprintf( __( 'File type could not be verified or is not permitted (.%s).', 'kp-file-manager' ), $ext )
                );
            }

            // Block PHP content regardless of extension — wp_check_filetype_and_ext already
            // does this for most cases, but we double-check the real MIME explicitly.
            $php_mimes = [ 'application/x-php', 'text/x-php', 'application/x-httpd-php' ];
            if ( in_array( $check['type'], $php_mimes, true ) ) {
                return new WP_Error( 'kfm_blocked_mime', __( 'Files containing PHP code are not permitted.', 'kp-file-manager' ) );
            }

            // Extension reported by WordPress must match what was submitted — catches renamed files
            if ( $check['ext'] !== $ext ) {
                return new WP_Error( 'kfm_mime_mismatch',
                    sprintf( __( 'File content (%s) does not match extension (.%s).', 'kp-file-manager' ), $check['type'], $ext )
                );
            }

            // Final path check to ensure the resolved destination plus filename is still within the sandbox and not denied
            if ( ! $this->resolve( $this->relative( $dest_dir ) . '/' . $name ) ) {
                return new WP_Error( 'kfm_bad_path', __( 'Invalid upload destination.', 'kp-file-manager' ) );
            }

            // Build the target path for the uploaded file
            $target = $dest_dir . DIRECTORY_SEPARATOR . $name;

            // WP.org note: move_uploaded_file() is intentionally used here — it is the correct
            // PHP function for handling uploaded files from $_FILES and verifies the file was
            // actually uploaded via HTTP POST
            // WordPress uses it internally too. There is no WP_Filesystem equivalent.
            if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
                return new WP_Error( 'kfm_upload_fail', __( 'Could not move uploaded file.', 'kp-file-manager' ) );
            }

            // return true on success
            return true;
        }

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