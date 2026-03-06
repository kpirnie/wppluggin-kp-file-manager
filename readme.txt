=== KP File Manager ===
Contributors: kevp75
Donate link: https://paypal.me/kevinpirnie
Tags: file manager, file browser, code editor, file upload, wp-content
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.57
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A secure, role-aware file manager for WordPress. Browse, edit, upload, and manage files directly from the admin — no FTP required. Sandboxed to wp-content with per-role permissions, audit logging, and a built-in syntax-highlighting code editor.

== Description ==

KP File Manager gives WordPress administrators and authorized roles a full-featured file management interface directly inside the admin panel — no FTP client required. Built on UIKit 3 with a CodeMirror-powered text editor, it is designed from the ground up with security as the primary concern.

**Features**

* Browse, create, rename, move, copy, and delete files and directories
* Built-in CodeMirror text editor with syntax highlighting for PHP, JavaScript, CSS, HTML, JSON, XML, SQL, Markdown, and more
* Drag-and-drop file upload with client-side and server-side extension and MIME type validation
* Resizable tree panel for directory navigation
* Sortable file listing by name, size, permissions, and modified date
* Permissions (chmod) management with octal input and checkbox grid
* Dark / light mode toggle with preference saved to localStorage
* Keyboard shortcut support in the editor (Ctrl+S / Cmd+S to save, Ctrl+/ to comment, Ctrl+F to find)
* Gutenberg block and `[kpfm_file_manager]` shortcode for front-end embedding

**Security**

* All paths resolved via `realpath()` and verified against the configured sandbox — path traversal is impossible
* Every AJAX request requires a valid WordPress nonce and matching referer origin
* Per-role permission matrix — granular control over list, read, write, upload, rename, delete, and chmod per WordPress role including an anonymous pseudo-role
* Upload blocked by extension (configurable) and verified by MIME type server-side via `finfo` regardless of extension
* PHP content rejected on upload regardless of file extension or disguised MIME type
* Configurable read-only extensions — files can be viewed in the editor but not saved
* Configurable path denylist — specific paths and their contents are hidden and inaccessible to all users
* Dotfiles hidden by default (configurable)
* World-writable permissions (o+w) always blocked
* Minimum permissions floor (configurable)
* Write operations rate-limited to 60 per minute per user
* Full audit log of all write, delete, chmod, and upload operations — stored as a 500-entry ring buffer with user, IP, path, and result; includes pagination and filtering
* Optional email alerts to admin on delete and chmod operations
* Administrators always retain full access regardless of permission matrix settings

**Admin Pages**

* **File Manager** — the file browser and editor
* **Settings** — base directory and dotfile visibility
* **Permissions** — role × operation matrix
* **Security** — blocked extensions, read-only extensions, path denylist, chmod floor, email alerts
* **Audit Log** — paginated, filterable log of all write operations

== Installation ==

1. Upload the `kp-file-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **File Manager** in the admin sidebar
4. Configure access and security options under **File Manager → Settings**, **Permissions**, and **Security**

No build step is required. UIKit 3 is loaded from jsDelivr CDN. CodeMirror is bundled with WordPress core.

== Frequently Asked Questions ==

= What directory can users access? =

By default, the entire `wp-content` directory. You can restrict this to any subdirectory (e.g. `uploads`) under **File Manager → Settings → Base Directory**. Users can never access anything outside `wp-content` regardless of settings.

= How do I control what each role can do? =

Go to **File Manager → Permissions**. Each WordPress role gets its own row in the matrix. Check or uncheck operations (List, Read, Write, Upload, Rename, Delete, Chmod) per role. Administrators always have full access and cannot be restricted.

= Can I allow access to non-logged-in users? =

Yes. The anonymous pseudo-role appears as its own row in the Permissions matrix. Granting operations to anonymous allows visitors to perform those actions. Use with extreme caution — restrict to List and Read only at minimum, and set a narrow Base Directory.

= How do I block certain file types from being uploaded? =

Go to **File Manager → Security → Blocked Upload Extensions**. Add any extension to the comma-separated list. MIME type is also verified server-side regardless of extension — a renamed `.php` file saved as `.jpg` will be detected and rejected.

= Can I prevent certain files or directories from being visible? =

Yes. Go to **File Manager → Security → Hidden / Blocked Paths** and add one relative path per line. Those paths and all their contents will be invisible and inaccessible to all users. Dotfiles can also be hidden globally under **Settings**.

= Can I make PHP files read-only? =

Yes. Go to **File Manager → Security → Read-only Extensions** and add `php` (or any other extension). Files with those extensions can be opened and viewed in the editor but the Save button will be disabled and the server will reject write attempts.

= Where is the audit log stored? =

In the WordPress database as a `wp_options` entry, stored as a ring buffer capped at 500 entries. It is viewable and filterable under **File Manager → Audit Log** and can be cleared from that page.

= Does this plugin work with multisite? =

It has not been tested on WordPress multisite installations. Single-site use only is recommended at this time.

= Does it support the Gutenberg block editor? =

Yes. Search for "KP File Manager" in the block inserter to embed the file manager on any page or post. Height, tree panel visibility, and alignment are configurable from the block sidebar.

== Screenshots ==

1. File browser with tree panel, dark mode enabled
2. Built-in CodeMirror editor with save notification
3. Role permissions matrix
4. Security settings page
5. Audit log with filtering

== Changelog ==

= 1.0.57 =
* Initial release
* Full file manager with CRUD operations (copy, move, create, delete, rename, chmod)
* CodeMirror text editor with syntax highlighting
* Role-based access control
