<?php
/**
 * File Manager class.
 * Performs all filesystem operations, always sandboxed inside the configured base path.
 * Applies: upload security, readonly-ext enforcement, dotfile visibility, path denylist, chmod floor.
 *
 * Uses the WordPress WP_Filesystem API for all file I/O operations.
 * Native PHP functions are only retained where no WP equivalent exists:
 * - realpath()           : symlink resolution for sandbox enforcement
 * - move_uploaded_file() : required for $_FILES handling (WP uses it internally too)
 * - file_exists()/is_dir()/is_file() in resolve() : path resolution before WP_Filesystem is needed
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
         * Initialises WP_Filesystem and returns the global instance.
         *
         * Loads the required admin file.php (idempotent) and calls WP_Filesystem()
         * which, when no FTP credentials are needed, silently sets up the direct
         * transport without outputting any HTML.
         *
         * @package KP - File Manager
         * @since 1.0.0
         * @author Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return WP_Filesystem_Base|WP_Error The filesystem instance or error on failure.
         *
         */
        private function filesystem(): WP_Filesystem_Base|WP_Error {
            global $wp_filesystem;

            // If already initialised, return it immediately
            if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
                return $wp_filesystem;
            }

            // Load the WP file utilities (safe to call multiple times)
            require_once ABSPATH . 'wp-admin/includes/file.php';

            // Initialise — returns true on success.  Passing false for credentials
            // avoids any interactive prompt; the direct transport is used when possible.
            if ( ! WP_Filesystem( false ) ) {
                return new WP_Error(
                    'kfm_filesystem_init',
                    esc_html__( 'Could not initialise the WordPress filesystem. Check your server configuration or FTP credentials.', 'kp-file-manager' )
                );
            }

            return $wp_filesystem;
        }

        /**
         * Resolves a relative path to an absolute path, verifying it stays
         * inside the sandbox. Returns false on any violation.
         *
         * Note: This method intentionally uses native PHP file_exists() and realpath()
         * because it performs path resolution and sandbox enforcement *before* any
         * filesystem I/O takes place. WP_Filesystem has no equivalent for symlink
         * resolution (realpath).
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

            // realpath() is the only reliable way to resolve symlinks and canonicalise
            // paths for sandbox enforcement — no WP_Filesystem equivalent exists.
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
         * Uses $wp_filesystem->dirlist() for directory enumeration and
         * individual WP_Filesystem methods for file metadata.
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
                return new WP_Error( 'kfm_not_found', esc_html__( 'Directory not found.', 'kp-file-manager' ) );
            }

            // Check against the path denylist before attempting to read
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', esc_html__( 'Access to this path is restricted.', 'kp-file-manager' ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use WP_Filesystem::dirlist() — returns an associative array keyed by entry name.
            // Third param false = non-recursive (single level only).
            $entries = $fs->dirlist( $path, true, false );
            if ( $entries === false ) {
                return new WP_Error( 'kfm_no_read', esc_html__( 'Cannot read directory.', 'kp-file-manager' ) );
            }

            // Read the directory contents, applying dotfile visibility and building the items array
            $show_dots = KFM_Settings::show_dotfiles();
            $items     = [];

            // Loop through directory entries returned by WP_Filesystem
            foreach ( $entries as $entry_name => $entry_info ) {

                // Skip dotfiles if configured to hide them
                if ( ! $show_dots && isset( $entry_name[0] ) && $entry_name[0] === '.' ) continue;

                // Build the full absolute path and relative path for this entry
                $full     = $path . DIRECTORY_SEPARATOR . $entry_name;
                $rel_path = $this->relative( $full );

                // Check against the path denylist and skip if denied
                if ( $this->is_denied( $full ) ) continue;

                // Determine type from the dirlist info ('d' = directory, 'f' = file, etc.)
                $is_dir = ( isset( $entry_info['type'] ) && $entry_info['type'] === 'd' );

                // Build the permission string — dirlist may return symbolic (e.g. 'rwxr-xr-x')
                // or numeric depending on the transport. We normalise to a 4-char octal string.
                $perms = '';
                if ( ! empty( $entry_info['permsn'] ) ) {
                    // Numeric permissions provided directly by some transports
                    $perms = $entry_info['permsn'];
                } else {
                    // Fall back to $wp_filesystem->getchmod() which returns an octal string
                    $perms = $fs->getchmod( $full );
                }

                // Add the entry to the items array with its metadata
                $items[] = [
                    'name'  => $entry_name,
                    'rel'   => $rel_path,
                    'type'  => $is_dir ? 'dir' : 'file',
                    'size'  => ! $is_dir ? ( isset( $entry_info['size'] ) ? (int) $entry_info['size'] : $fs->size( $full ) ) : 0,
                    'mtime' => isset( $entry_info['lastmodunix'] ) ? (int) $entry_info['lastmodunix'] : $fs->mtime( $full ),
                    'perms' => $perms,
                    'ext'   => ! $is_dir ? strtolower( pathinfo( $entry_name, PATHINFO_EXTENSION ) ) : '',
                ];
            }

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
         * Uses $wp_filesystem->get_contents() for file reading.
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
                return new WP_Error( 'kfm_not_found', esc_html__( 'File not found.', 'kp-file-manager' ) );
            }

            // Check if the file is denied
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', esc_html__( 'Access to this file is restricted.', 'kp-file-manager' ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Check readability via WP_Filesystem
            if ( ! $fs->is_readable( $path ) ) {
                return new WP_Error( 'kfm_no_read', esc_html__( 'File is not readable.', 'kp-file-manager' ) );
            }

            // Use WP_Filesystem::get_contents() to read the file
            $content = $fs->get_contents( $path );
            if ( $content === false ) {
                return new WP_Error( 'kfm_read_error', esc_html__( 'Could not read file.', 'kp-file-manager' ) );
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
         * Uses $wp_filesystem->put_contents() for file writing.
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
            if ( ! $path ) return new WP_Error( 'kfm_bad_path', esc_html__( 'Invalid path.', 'kp-file-manager' ) );

            // make sure we have a valid path before doing any file operations, to avoid PHP warnings
            if ( is_dir( $path ) ) return new WP_Error( 'kfm_is_dir', esc_html__( 'Path is a directory.', 'kp-file-manager' ) );

            // Check if the file is denied
            if ( $this->is_denied( $path ) ) return new WP_Error( 'kfm_denied', esc_html__( 'Access to this file is restricted.', 'kp-file-manager' ) );
            
            // Check for readonly extension before attempting to write
            $ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            // Check if the file extension is in the readonly list and block writing if so
            $readonly = KFM_Settings::get_readonly_exts();
            if ( in_array( $ext, $readonly, true ) ) {
                return new WP_Error( 'kfm_readonly', sprintf( esc_html__( '.%s files are read-only.', 'kp-file-manager' ), $ext ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use WP_Filesystem::put_contents() to write — FS_CHMOD_FILE applies
            // the standard WP file permissions constant (typically 0644).
            if ( ! $fs->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
                return new WP_Error( 'kfm_write_error', esc_html__( 'Could not write file. Check permissions.', 'kp-file-manager' ) );
            }

            // return true on success
            return true;
        }

        /**
         * Creates a new empty file at the given relative path.
         *
         * Uses $wp_filesystem->put_contents() with an empty string.
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
            if ( ! $path ) return new WP_Error( 'kfm_bad_path', esc_html__( 'Invalid path.', 'kp-file-manager' ) );
            if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', esc_html__( 'File already exists.', 'kp-file-manager' ) );

            // check the blocked extensions
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( $ext !== '' && in_array( $ext, KFM_Settings::get_blocked_exts(), true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( esc_html__( 'Cannot create .%s files — file type is blocked.', 'kp-file-manager' ), $ext )
                );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use WP_Filesystem::put_contents() with empty content to create the file
            if ( ! $fs->put_contents( $path, '', FS_CHMOD_FILE ) ) {
                return new WP_Error( 'kfm_write_error', esc_html__( 'Could not create file.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

        /**
         * Creates a new directory at the given relative path.
         *
         * Uses $wp_filesystem->mkdir() for directory creation.
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
            if ( ! $path ) return new WP_Error( 'kfm_bad_path', esc_html__( 'Invalid path.', 'kp-file-manager' ) );
            if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', esc_html__( 'Directory already exists.', 'kp-file-manager' ) );

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use $wp_filesystem->mkdir() which handles permissions via FS_CHMOD_DIR.
            // Fall back to wp_mkdir_p() if the WP_Filesystem method fails, since wp_mkdir_p()
            // handles recursive creation and works without WP_Filesystem initialisation.
            if ( ! $fs->mkdir( $path, FS_CHMOD_DIR ) ) {
                // Fallback: wp_mkdir_p() handles recursive directory creation
                if ( ! wp_mkdir_p( $path ) ) {
                    return new WP_Error( 'kfm_mkdir_fail', esc_html__( 'Could not create directory.', 'kp-file-manager' ) );
                }
            }

            // true on success
            return true;
        }

        /**
         * Deletes a file or directory (recursively).
         *
         * Uses $wp_filesystem->delete() which supports recursive deletion.
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
                return new WP_Error( 'kfm_not_found', esc_html__( 'Item not found.', 'kp-file-manager' ) );
            }

            // Never allow the sandbox root itself to be deleted
            if ( rtrim( $path, DIRECTORY_SEPARATOR ) === rtrim( $this->base_path, DIRECTORY_SEPARATOR ) ) {
                return new WP_Error( 'kfm_protected', esc_html__( 'Cannot delete the root directory.', 'kp-file-manager' ) );
            }

            // Check if the path is denied
            if ( $this->is_denied( $path ) ) {
                return new WP_Error( 'kfm_denied', esc_html__( 'Access to this path is restricted.', 'kp-file-manager' ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use $wp_filesystem->delete() — second param enables recursive deletion for directories.
            // Third param 'type' helps the filesystem transport pick the correct operation:
            //   'd' for directory, 'f' for file.
            $is_dir = $fs->is_dir( $path );
            $type   = $is_dir ? 'd' : 'f';

            if ( ! $fs->delete( $path, $is_dir, $type ) ) {
                $label = $is_dir ? esc_html__( 'Could not delete directory.', 'kp-file-manager' ) : esc_html__( 'Could not delete file.', 'kp-file-manager' );
                return new WP_Error( 'kfm_delete_fail', $label );
            }

            // true on success
            return true;
        }

        /**
         * Renames a file or directory within the same parent directory.
         *
         * Uses $wp_filesystem->move() for the rename operation.
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
                return new WP_Error( 'kfm_not_found', esc_html__( 'Item not found.', 'kp-file-manager' ) );
            }

            // Sanitise — basename only, no path traversal allowed
            $new_name = basename( $new_name );
            if ( $new_name === '' || $new_name === '.' || $new_name === '..' ) {
                return new WP_Error( 'kfm_bad_name', esc_html__( 'Invalid name.', 'kp-file-manager' ) );
            }

            // block bad extensions
            $new_ext = strtolower( pathinfo( $new_name, PATHINFO_EXTENSION ) );
            if ( $new_ext !== '' && in_array( $new_ext, KFM_Settings::get_blocked_exts(), true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( esc_html__( 'Cannot rename to .%s — file type is blocked.', 'kp-file-manager' ), $new_ext )
                );
            }

            // Check if the new name already exists to prevent overwriting
            $dest = dirname( $path ) . DIRECTORY_SEPARATOR . $new_name;
            if ( file_exists( $dest ) ) {
                return new WP_Error( 'kfm_exists', esc_html__( 'A file or directory with that name already exists.', 'kp-file-manager' ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use $wp_filesystem->move() — functionally equivalent to rename() for
            // same-filesystem moves. The third param (overwrite) is false to be safe.
            if ( ! $fs->move( $path, $dest, false ) ) {
                return new WP_Error( 'kfm_rename_fail', esc_html__( 'Rename failed.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

        /**
         * Copies a file or directory to a destination within the sandbox.
         *
         * Uses $wp_filesystem->copy() for single files and a recursive helper
         * using WP_Filesystem methods for directories.
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
            if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', esc_html__( 'Source not found.', 'kp-file-manager' ) );
            if ( ! $dest ) return new WP_Error( 'kfm_bad_path', esc_html__( 'Invalid destination.', 'kp-file-manager' ) );

            // If destination is a directory set the proper directory
            if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );

            // one last extension check... just in case....
            $dest_ext = strtolower( pathinfo( basename( $dest ), PATHINFO_EXTENSION ) );
            if ( $dest_ext !== '' && in_array( $dest_ext, KFM_Settings::get_blocked_exts(), true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( esc_html__( 'Cannot copy to .%s — file type is blocked.', 'kp-file-manager' ), $dest_ext )
                );
            }

            // check if the source is a directory and copy recursively
            if ( is_dir( $src ) ) return $this->copy_dir( $src, $dest );

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use $wp_filesystem->copy() — third param (overwrite) false, fourth applies perms.
            if ( ! $fs->copy( $src, $dest, false, FS_CHMOD_FILE ) ) {
                return new WP_Error( 'kfm_copy_fail', esc_html__( 'Copy failed.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

        /**
         * Recursively copies a directory and all its contents.
         *
         * Uses $wp_filesystem->mkdir(), dirlist(), and copy() to traverse
         * and duplicate the directory tree.
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

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Create the destination directory if it doesn't exist
            if ( ! $fs->is_dir( $dest ) ) {
                if ( ! $fs->mkdir( $dest, FS_CHMOD_DIR ) && ! wp_mkdir_p( $dest ) ) {
                    return new WP_Error( 'kfm_mkdir_fail', esc_html__( 'Could not create destination directory.', 'kp-file-manager' ) );
                }
            }

            // Use WP_Filesystem::dirlist() to get a non-recursive listing of the source,
            // then recurse manually to preserve the same structure.
            $entries = $fs->dirlist( $src, true, false );
            if ( $entries === false ) {
                return new WP_Error( 'kfm_copy_fail', esc_html__( 'Could not read source directory.', 'kp-file-manager' ) );
            }

            // Loop through entries and copy files/directories to the destination
            foreach ( $entries as $entry_name => $entry_info ) {

                $src_entry  = $src . DIRECTORY_SEPARATOR . $entry_name;
                $dest_entry = $dest . DIRECTORY_SEPARATOR . $entry_name;

                if ( isset( $entry_info['type'] ) && $entry_info['type'] === 'd' ) {
                    // Recurse into subdirectories
                    $result = $this->copy_dir( $src_entry, $dest_entry );
                    if ( is_wp_error( $result ) ) return $result;
                } else {
                    // Copy individual files via WP_Filesystem
                    if ( ! $fs->copy( $src_entry, $dest_entry, false, FS_CHMOD_FILE ) ) {
                        return new WP_Error( 'kfm_copy_fail', sprintf( esc_html__( 'Could not copy file: %s', 'kp-file-manager' ), $entry_name ) );
                    }
                }
            }

            // true on success
            return true;
        }

        /**
         * Moves a file or directory to a destination within the sandbox.
         *
         * Uses $wp_filesystem->move() for the operation.
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
            if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', esc_html__( 'Source not found.', 'kp-file-manager' ) );
            if ( ! $dest ) return new WP_Error( 'kfm_bad_path', esc_html__( 'Invalid destination.', 'kp-file-manager' ) );

            // if the destination is a directory make sure it's setup properly for the move
            if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );

            // check if the destination already exists to prevent overwriting
            if ( file_exists( $dest ) ) {
                return new WP_Error( 'kfm_exists', esc_html__( 'Destination already exists.', 'kp-file-manager' ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use $wp_filesystem->move() — overwrite false for safety.
            if ( ! $fs->move( $src, $dest, false ) ) {
                return new WP_Error( 'kfm_move_fail', esc_html__( 'Move failed.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

        /**
         * Changes the permissions of a file or directory.
         *
         * Uses $wp_filesystem->chmod() for the operation.
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
                return new WP_Error( 'kfm_not_found', esc_html__( 'Item not found.', 'kp-file-manager' ) );
            }

            // make sure the octal mode is valid before doing any file operations
            if ( ! preg_match( '/^[0-7]{3,4}$/', $mode_octal ) ) {
                return new WP_Error( 'kfm_bad_perms', esc_html__( 'Invalid permission value. Use octal like 0644.', 'kp-file-manager' ) );
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
                        esc_html__( 'Permissions cannot be set below the minimum floor of %s.', 'kp-file-manager' ),
                        $floor
                    ) );
                }
            }

            // World-writable (o+w) is always blocked regardless of settings
            $dec = octdec( $mode_octal );
            if ( $dec & 0002 ) {
                return new WP_Error( 'kfm_chmod_world_write', esc_html__( 'World-writable permissions (o+w) are not permitted.', 'kp-file-manager' ) );
            }

            // Initialise WP_Filesystem
            $fs = $this->filesystem();
            if ( is_wp_error( $fs ) ) return $fs;

            // Use $wp_filesystem->chmod() — accepts the path, octal int mode, and
            // an optional recursive flag (false here, single item only).
            if ( ! $fs->chmod( $path, octdec( $mode_octal ), false ) ) {
                return new WP_Error( 'kfm_chmod_fail', esc_html__( 'Could not change permissions.', 'kp-file-manager' ) );
            }

            // true on success
            return true;
        }

        /**
         * Validates and moves an uploaded file into the sandbox.
         *
         * Note: move_uploaded_file() is intentionally retained here — it is the
         * correct PHP function for $_FILES handling, verifies the file was uploaded
         * via HTTP POST, and has no WP_Filesystem equivalent. WordPress itself uses
         * it internally in wp_handle_upload().
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
                return new WP_Error( 'kfm_not_found', esc_html__( 'Destination directory not found.', 'kp-file-manager' ) );
            }

            // Check against the path denylist before attempting to upload
            if ( $this->is_denied( $dest_dir ) ) {
                return new WP_Error( 'kfm_denied', esc_html__( 'Access to this path is restricted.', 'kp-file-manager' ) );
            }

            // Basic upload error check
            if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
                return new WP_Error( 'kfm_upload_err', sprintf( esc_html__( 'Upload error code: %d', 'kp-file-manager' ), $file['error'] ) );
            }

            // Sanitise the filename and check for validity
            $name = sanitize_file_name( basename( $file['name'] ) );
            if ( $name === '' ) {
                return new WP_Error( 'kfm_bad_name', esc_html__( 'Invalid file name.', 'kp-file-manager' ) );
            }

            // check for dangerous filenames that could be executed if accessed directly, regardless of extension
            $dangerous_names = [ '.htaccess', '.htpasswd', '.env', '.user.ini', 'php.ini', 'web.config' ];
            if ( in_array( strtolower( $name ), $dangerous_names, true ) ) {
                return new WP_Error( 'kfm_blocked_name', esc_html__( 'That filename is not permitted.', 'kp-file-manager' ) );
            }

            // Check for blocked extensions before attempting to upload
            $ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            $blocked = KFM_Settings::get_blocked_exts();
            if ( in_array( $ext, $blocked, true ) ) {
                return new WP_Error( 'kfm_blocked_ext',
                    sprintf( esc_html__( 'File type .%s is not permitted.', 'kp-file-manager' ), $ext )
                );
            }

            // wp_check_filetype_and_ext() validates the file's MIME type and extension
            // against WordPress's allowed list. If a type you need isn't in that list,
            // hook the 'upload_mimes' filter to add it.
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $name );

            // If WordPress couldn't determine a valid type, block it
            if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
                return new WP_Error( 'kfm_blocked_mime',
                    sprintf( esc_html__( 'File type could not be verified or is not permitted (.%s).', 'kp-file-manager' ), $ext )
                );
            }

            // Block PHP content regardless of extension — wp_check_filetype_and_ext already
            // does this for most cases, but we double-check the real MIME explicitly.
            $php_mimes = [ 'application/x-php', 'text/x-php', 'application/x-httpd-php' ];
            if ( in_array( $check['type'], $php_mimes, true ) ) {
                return new WP_Error( 'kfm_blocked_mime', esc_html__( 'Files containing PHP code are not permitted.', 'kp-file-manager' ) );
            }

            // Extension reported by WordPress must match what was submitted — catches renamed files
            if ( $check['ext'] !== $ext ) {
                return new WP_Error( 'kfm_mime_mismatch',
                    sprintf( esc_html__( 'File content (%s) does not match extension (.%s).', 'kp-file-manager' ), $check['type'], $ext )
                );
            }

            // Final path check to ensure the resolved destination plus filename is still within the sandbox and not denied
            if ( ! $this->resolve( $this->relative( $dest_dir ) . '/' . $name ) ) {
                return new WP_Error( 'kfm_bad_path', esc_html__( 'Invalid upload destination.', 'kp-file-manager' ) );
            }

            // Build the target path for the uploaded file
            $target = $dest_dir . DIRECTORY_SEPARATOR . $name;

            // move_uploaded_file() is intentionally used here — it verifies the file was
            // actually uploaded via HTTP POST. There is no WP_Filesystem equivalent.
            // WordPress uses it internally in wp_handle_upload().
            if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
                return new WP_Error( 'kfm_upload_fail', esc_html__( 'Could not move uploaded file.', 'kp-file-manager' ) );
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