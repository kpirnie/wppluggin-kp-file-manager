<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || die( 'Direct access is not allowed!' );

/**
 * Performs all filesystem operations, always sandboxed inside the configured base path.
 * Applies: upload security, readonly-ext enforcement, dotfile visibility, path denylist, chmod floor.
 */
class KFM_File_Manager {

    private string $base_path;

    public function __construct( KFM_Settings $settings ) {
        $this->base_path = KFM_Settings::get_base_path();
    }

    /* ------------------------------------------------------------------ */
    /*  Path security                                                       */
    /* ------------------------------------------------------------------ */

    public function resolve( string $rel ): string|false {
        $rel = str_replace( "\0", '', $rel );
        if ( $rel === '' || $rel === '.' || $rel === '/' ) return $this->base_path;

        $candidate = $this->base_path . DIRECTORY_SEPARATOR . ltrim( $rel, '/\\' );

        if ( file_exists( $candidate ) ) {
            $real = realpath( $candidate );
        } else {
            $parent = realpath( dirname( $candidate ) );
            if ( $parent === false ) return false;
            $real = $parent . DIRECTORY_SEPARATOR . basename( $candidate );
        }

        if ( $real === false ) return false;
        if ( strpos( $real, $this->base_path ) !== 0 ) return false;

        return $real;
    }

    public function relative( string $abs ): string {
        return ltrim( str_replace( $this->base_path, '', $abs ), DIRECTORY_SEPARATOR );
    }

    /* ------------------------------------------------------------------ */
    /*  List directory                                                      */
    /* ------------------------------------------------------------------ */

    public function list_dir( string $rel ): array|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path || ! is_dir( $path ) ) {
            return new WP_Error( 'kfm_not_found', __( 'Directory not found.', 'kfm-file-manager' ) );
        }

        // Check path denylist
        $denied = $this->is_denied( $path );
        if ( $denied ) {
            return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kfm-file-manager' ) );
        }

        $show_dots = KFM_Settings::show_dotfiles();
        $denylist  = KFM_Settings::get_path_denylist();
        $items     = [];
        $dir       = opendir( $path );

        if ( ! $dir ) {
            return new WP_Error( 'kfm_no_read', __( 'Cannot read directory.', 'kfm-file-manager' ) );
        }

        while ( ( $entry = readdir( $dir ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' ) continue;

            // Hide dotfiles unless setting enabled
            if ( ! $show_dots && $entry[0] === '.' ) continue;

            $full     = $path . DIRECTORY_SEPARATOR . $entry;
            $rel_path = $this->relative( $full );

            // Check denylist for individual items
            if ( $this->is_denied( $full ) ) continue;

            $items[] = [
                'name'  => $entry,
                'rel'   => $rel_path,
                'type'  => is_dir( $full ) ? 'dir' : 'file',
                'size'  => is_file( $full ) ? filesize( $full ) : 0,
                'mtime' => filemtime( $full ),
                'perms' => substr( sprintf( '%o', fileperms( $full ) ), -4 ),
                'ext'   => is_file( $full ) ? strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) : '',
            ];
        }
        closedir( $dir );

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
    /*  Path denylist helper                                                */
    /* ------------------------------------------------------------------ */

    private function is_denied( string $abs_path ): bool {
        $denylist = KFM_Settings::get_path_denylist();
        if ( empty( $denylist ) ) return false;

        $rel = $this->relative( $abs_path );
        foreach ( $denylist as $denied ) {
            // Match exact path or prefix (directory and its contents)
            if ( $rel === $denied || strpos( $rel, rtrim( $denied, '/' ) . '/' ) === 0 ) {
                return true;
            }
            // Also match basename for convenience
            if ( basename( $abs_path ) === $denied ) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Read file                                                           */
    /* ------------------------------------------------------------------ */

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

    public function write_file( string $rel, string $content ): true|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kfm-file-manager' ) );
        if ( is_dir( $path ) ) return new WP_Error( 'kfm_is_dir', __( 'Path is a directory.', 'kfm-file-manager' ) );
        if ( $this->is_denied( $path ) ) return new WP_Error( 'kfm_denied', __( 'Access to this file is restricted.', 'kfm-file-manager' ) );

        // Readonly extension check
        $ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $readonly = KFM_Settings::get_readonly_exts();
        if ( in_array( $ext, $readonly, true ) ) {
            return new WP_Error( 'kfm_readonly', sprintf( __( '.%s files are read-only.', 'kfm-file-manager' ), $ext ) );
        }

        if ( file_put_contents( $path, $content ) === false ) {
            return new WP_Error( 'kfm_write_error', __( 'Could not write file. Check permissions.', 'kfm-file-manager' ) );
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Create file                                                         */
    /* ------------------------------------------------------------------ */

    public function create_file( string $rel ): true|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kfm-file-manager' ) );
        if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', __( 'File already exists.', 'kfm-file-manager' ) );
        if ( file_put_contents( $path, '' ) === false ) {
            return new WP_Error( 'kfm_write_error', __( 'Could not create file.', 'kfm-file-manager' ) );
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Create directory                                                    */
    /* ------------------------------------------------------------------ */

    public function create_dir( string $rel ): true|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path ) return new WP_Error( 'kfm_bad_path', __( 'Invalid path.', 'kfm-file-manager' ) );
        if ( file_exists( $path ) ) return new WP_Error( 'kfm_exists', __( 'Directory already exists.', 'kfm-file-manager' ) );
        if ( ! mkdir( $path, 0755, true ) ) {
            return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create directory.', 'kfm-file-manager' ) );
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Delete                                                              */
    /* ------------------------------------------------------------------ */

    public function delete( string $rel ): true|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kfm-file-manager' ) );
        }
        if ( rtrim( $path, DIRECTORY_SEPARATOR ) === rtrim( $this->base_path, DIRECTORY_SEPARATOR ) ) {
            return new WP_Error( 'kfm_protected', __( 'Cannot delete the root directory.', 'kfm-file-manager' ) );
        }
        if ( $this->is_denied( $path ) ) {
            return new WP_Error( 'kfm_denied', __( 'Access to this path is restricted.', 'kfm-file-manager' ) );
        }
        if ( is_dir( $path ) ) return $this->delete_dir_recursive( $path );
        if ( ! unlink( $path ) ) return new WP_Error( 'kfm_delete_fail', __( 'Could not delete file.', 'kfm-file-manager' ) );
        return true;
    }

    private function delete_dir_recursive( string $path ): true|WP_Error {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $entries as $entry ) {
            $ok = $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
            if ( ! $ok ) return new WP_Error( 'kfm_delete_fail', sprintf( __( 'Could not remove: %s', 'kfm-file-manager' ), $entry->getFilename() ) );
        }
        if ( ! rmdir( $path ) ) return new WP_Error( 'kfm_delete_fail', __( 'Could not remove directory.', 'kfm-file-manager' ) );
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Rename                                                              */
    /* ------------------------------------------------------------------ */

    public function rename( string $rel, string $new_name ): true|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kfm-file-manager' ) );
        }
        $new_name = basename( $new_name );
        if ( $new_name === '' || $new_name === '.' || $new_name === '..' ) {
            return new WP_Error( 'kfm_bad_name', __( 'Invalid name.', 'kfm-file-manager' ) );
        }
        $dest = dirname( $path ) . DIRECTORY_SEPARATOR . $new_name;
        if ( file_exists( $dest ) ) return new WP_Error( 'kfm_exists', __( 'A file or directory with that name already exists.', 'kfm-file-manager' ) );
        if ( ! rename( $path, $dest ) ) return new WP_Error( 'kfm_rename_fail', __( 'Rename failed.', 'kfm-file-manager' ) );
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Copy                                                                */
    /* ------------------------------------------------------------------ */

    public function copy( string $rel_src, string $rel_dest ): true|WP_Error {
        $src  = $this->resolve( $rel_src );
        $dest = $this->resolve( $rel_dest );
        if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', __( 'Source not found.', 'kfm-file-manager' ) );
        if ( ! $dest ) return new WP_Error( 'kfm_bad_path', __( 'Invalid destination.', 'kfm-file-manager' ) );
        if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );
        if ( is_dir( $src ) ) return $this->copy_dir( $src, $dest );
        if ( ! copy( $src, $dest ) ) return new WP_Error( 'kfm_copy_fail', __( 'Copy failed.', 'kfm-file-manager' ) );
        return true;
    }

    private function copy_dir( string $src, string $dest ): true|WP_Error {
        if ( ! mkdir( $dest, 0755, true ) ) return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create destination directory.', 'kfm-file-manager' ) );
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $entries as $entry ) {
            $target = $dest . DIRECTORY_SEPARATOR . $entries->getSubPathName();
            if ( $entry->isDir() ) {
                if ( ! is_dir( $target ) && ! mkdir( $target, 0755 ) ) return new WP_Error( 'kfm_mkdir_fail', __( 'Could not create subdirectory.', 'kfm-file-manager' ) );
            } else {
                if ( ! copy( $entry->getPathname(), $target ) ) return new WP_Error( 'kfm_copy_fail', sprintf( __( 'Could not copy file: %s', 'kfm-file-manager' ), $entry->getFilename() ) );
            }
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Move                                                                */
    /* ------------------------------------------------------------------ */

    public function move( string $rel_src, string $rel_dest ): true|WP_Error {
        $src  = $this->resolve( $rel_src );
        $dest = $this->resolve( $rel_dest );
        if ( ! $src || ! file_exists( $src ) ) return new WP_Error( 'kfm_not_found', __( 'Source not found.', 'kfm-file-manager' ) );
        if ( ! $dest ) return new WP_Error( 'kfm_bad_path', __( 'Invalid destination.', 'kfm-file-manager' ) );
        if ( is_dir( $dest ) ) $dest = $dest . DIRECTORY_SEPARATOR . basename( $src );
        if ( file_exists( $dest ) ) return new WP_Error( 'kfm_exists', __( 'Destination already exists.', 'kfm-file-manager' ) );
        if ( ! rename( $src, $dest ) ) return new WP_Error( 'kfm_move_fail', __( 'Move failed.', 'kfm-file-manager' ) );
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Permissions (chmod)                                                 */
    /* ------------------------------------------------------------------ */

    public function chmod( string $rel, string $mode_octal ): true|WP_Error {
        $path = $this->resolve( $rel );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'kfm_not_found', __( 'Item not found.', 'kfm-file-manager' ) );
        }
        if ( ! preg_match( '/^[0-7]{3,4}$/', $mode_octal ) ) {
            return new WP_Error( 'kfm_bad_perms', __( 'Invalid permission value. Use octal like 0644.', 'kfm-file-manager' ) );
        }

        // Enforce chmod floor
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

        // Warn on world-writable (o+w = bit 2) — enforced as error
        $dec = octdec( $mode_octal );
        if ( $dec & 0002 ) {
            return new WP_Error( 'kfm_chmod_world_write', __( 'World-writable permissions (o+w) are not permitted.', 'kfm-file-manager' ) );
        }

        if ( ! chmod( $path, octdec( $mode_octal ) ) ) {
            return new WP_Error( 'kfm_chmod_fail', __( 'Could not change permissions.', 'kfm-file-manager' ) );
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Upload                                                              */
    /* ------------------------------------------------------------------ */

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

        // ── MIME verification ────────────────────────────────────────────
        if ( function_exists( 'finfo_open' ) ) {
            $finfo     = finfo_open( FILEINFO_MIME_TYPE );
            $real_mime = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );

            // Block PHP-content regardless of extension
            $php_mimes = [ 'application/x-php', 'text/x-php', 'application/x-httpd-php' ];
            if ( in_array( $real_mime, $php_mimes, true ) ) {
                return new WP_Error( 'kfm_blocked_mime', __( 'Files containing PHP code are not permitted.', 'kfm-file-manager' ) );
            }

            // Extension ↔ MIME whitelist check
            $mime_map = [
                'jpg'   => [ 'image/jpeg' ],
                'jpeg'  => [ 'image/jpeg' ],
                'png'   => [ 'image/png' ],
                'gif'   => [ 'image/gif' ],
                'webp'  => [ 'image/webp' ],
                'svg'   => [ 'image/svg+xml', 'text/html', 'text/plain' ],
                'pdf'   => [ 'application/pdf' ],
                'zip'   => [ 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ],
                'gz'    => [ 'application/gzip', 'application/x-gzip', 'application/octet-stream' ],
                'tar'   => [ 'application/x-tar', 'application/octet-stream' ],
                'txt'   => [ 'text/plain' ],
                'css'   => [ 'text/css', 'text/plain' ],
                'js'    => [ 'application/javascript', 'text/javascript', 'text/plain' ],
                'json'  => [ 'application/json', 'text/plain' ],
                'xml'   => [ 'application/xml', 'text/xml', 'text/plain' ],
                'csv'   => [ 'text/csv', 'text/plain' ],
                'md'    => [ 'text/plain', 'text/markdown' ],
                'mp3'   => [ 'audio/mpeg', 'audio/mp3' ],
                'mp4'   => [ 'video/mp4', 'application/octet-stream' ],
                'woff'  => [ 'font/woff', 'application/font-woff', 'application/octet-stream' ],
                'woff2' => [ 'font/woff2', 'application/octet-stream' ],
                'ttf'   => [ 'font/ttf', 'application/x-font-ttf', 'application/octet-stream' ],
                'ico'   => [ 'image/x-icon', 'image/vnd.microsoft.icon', 'application/octet-stream' ],
            ];

            if ( isset( $mime_map[ $ext ] ) && ! in_array( $real_mime, $mime_map[ $ext ], true ) ) {
                return new WP_Error( 'kfm_mime_mismatch',
                    sprintf( __( 'File content (%s) does not match extension (.%s).', 'kfm-file-manager' ), $real_mime, $ext )
                );
            }
        }

        // ── Path traversal final check ───────────────────────────────────
        if ( ! $this->resolve( $this->relative( $dest_dir ) . '/' . $name ) ) {
            return new WP_Error( 'kfm_bad_path', __( 'Invalid upload destination.', 'kfm-file-manager' ) );
        }

        $target = $dest_dir . DIRECTORY_SEPARATOR . $name;
        if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'kfm_upload_fail', __( 'Could not move uploaded file.', 'kfm-file-manager' ) );
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Getters                                                             */
    /* ------------------------------------------------------------------ */

    public function get_base_path(): string {
        return $this->base_path;
    }
}
