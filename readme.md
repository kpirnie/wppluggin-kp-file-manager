# KP File Manager

[![GitHub Issues](https://img.shields.io/github/issues/kpirnie/wppluggin-kp-file-manager?style=for-the-badge&logo=github&color=006400&logoColor=white&labelColor=000)](https://github.com/kpirnie/wppluggin-kp-file-manager/issues)
[![Last Commit](https://img.shields.io/github/last-commit/kpirnie/wppluggin-kp-file-manager?style=for-the-badge&labelColor=000)](https://github.com/kpirnie/wppluggin-kp-file-manager/commits/main)
[![License: MIT](https://img.shields.io/badge/License-MIT-orange.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=000)](LICENSE)

[![PHP](https://img.shields.io/badge/Up%20To-php8.5-777BB4?logo=php&logoColor=white&style=for-the-badge&labelColor=000)](https://php.net)
[![WordPress](https://img.shields.io/badge/Min.%20WP-6.0-3858e9?logo=wordpress&logoColor=white&style=for-the-badge&labelColor=000)](https://php.net)
[![Kevin Pirnie](https://img.shields.io/badge/-KevinPirnie.com-000d2d?style=for-the-badge&labelColor=000&logoColor=white&logo=data:image/svg%2Bxml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIxLjgiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+CiAgPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiLz4KICA8ZWxsaXBzZSBjeD0iMTIiIGN5PSIxMiIgcng9IjQuNSIgcnk9IjEwIi8+CiAgPGxpbmUgeDE9IjIiIHkxPSIxMiIgeDI9IjIyIiB5Mj0iMTIiLz4KICA8bGluZSB4MT0iNC41IiB5MT0iNi41IiB4Mj0iMTkuNSIgeTI9IjYuNSIvPgogIDxsaW5lIHgxPSI0LjUiIHkxPSIxNy41IiB4Mj0iMTkuNSIgeTI9IjE3LjUiLz4KPC9zdmc+Cg==)](https://kevinpirnie.com/)

Secure WordPress file manager. Browse, upload & edit files from the admin — no FTP needed. Role-based permissions, audit logging & syntax highlighting.

---

## Description

KP File Manager gives WordPress administrators and authorized roles a full-featured file management interface directly inside the admin panel — no FTP client required. Built on UIKit 3 with a CodeMirror-powered syntax-highlighting code editor, it is designed from the ground up with security as the primary concern. All file operations are sandboxed to the `wp-content` directory, keeping core WordPress files and sensitive server paths completely out of reach. Access is controlled through a granular per-role permission system, so you decide exactly which user roles can browse, upload, edit, rename, or delete files. Every action is recorded in a detailed audit log, giving you a full history of who did what and when.

---

## Features

- Browse, create, rename, move, copy, and delete files and directories
- Built-in CodeMirror text editor with syntax highlighting for PHP, JavaScript, CSS, HTML, JSON, XML, SQL, Markdown, and more
- Drag-and-drop file upload with client-side and server-side extension and MIME type validation
- Resizable tree panel for directory navigation
- Sortable file listing by name, size, permissions, and modified date
- Permissions (chmod) management with octal input and checkbox grid
- Dark / light mode toggle with preference saved to localStorage
- Keyboard shortcut support in the editor (`Ctrl+S` / `Cmd+S` to save, `Ctrl+/` to comment, `Ctrl+F` to find)
- Gutenberg block and `[kp-file-manager_file_manager]` shortcode for front-end embedding
- Automatic updates delivered via GitHub Releases

---

## Security

- All paths resolved via `realpath()` and verified against the configured sandbox — path traversal is impossible
- Every AJAX request requires a valid WordPress nonce and matching referer origin
- Per-role permission matrix — granular control over list, read, write, upload, rename, delete, and chmod per WordPress role including an anonymous pseudo-role
- Upload blocked by extension (configurable) and verified by MIME type server-side via `finfo` regardless of extension
- PHP content rejected on upload regardless of file extension or disguised MIME type
- Configurable read-only extensions — files can be viewed in the editor but not saved
- Configurable path denylist — specific paths and their contents are hidden and inaccessible to all users
- Dotfiles hidden by default (configurable)
- World-writable permissions (`o+w`) always blocked
- Minimum permissions floor (configurable)
- Write operations rate-limited to 60 per minute per user
- Full audit log of all write, delete, chmod, and upload operations — stored as a 500-entry ring buffer with user, IP, path, and result; includes pagination and filtering
- Optional email alerts to admin on delete and chmod operations
- Administrators always retain full access regardless of permission matrix settings

---

## Admin Pages

| Page | Purpose |
|------|---------|
| **File Manager** | File browser and editor |
| **Settings** | Base directory and dotfile visibility |
| **Permissions** | Role × operation matrix |
| **Security** | Blocked extensions, read-only extensions, path denylist, chmod floor, email alerts |
| **Audit Log** | Paginated, filterable log of all write operations |

---

## Installation

1. Download the latest `.zip` from the [Releases](https://github.com/kpirnie/wppluggin-kp-file-manager/releases) page
2. In WordPress go to **Plugins → Add New → Upload Plugin** and upload the zip
3. Activate the plugin through the **Plugins** menu
4. Navigate to **File Manager** in the admin sidebar
5. Configure access and security options under **File Manager → Settings**, **Permissions**, and **Security**

No build step is required. UIKit 3 is loaded from jsDelivr CDN. CodeMirror is bundled with WordPress core.

Once installed, future updates will appear automatically in **Dashboard → Updates** whenever a new release is pushed to GitHub.

---

## Frequently Asked Questions

**What directory can users access?**

By default, the entire `wp-content` directory. You can restrict this to any subdirectory (e.g. `uploads`) under **File Manager → Settings → Base Directory**. Users can never access anything outside `wp-content` regardless of settings.

**How do I control what each role can do?**

Go to **File Manager → Permissions**. Each WordPress role gets its own row in the matrix. Check or uncheck operations (List, Read, Write, Upload, Rename, Delete, Chmod) per role. Administrators always have full access and cannot be restricted.

**Can I allow access to non-logged-in users?**

Yes. The anonymous pseudo-role appears as its own row in the Permissions matrix. Granting operations to anonymous allows visitors to perform those actions. Use with extreme caution — restrict to List and Read only at minimum, and set a narrow Base Directory.

**How do I block certain file types from being uploaded?**

Go to **File Manager → Security → Blocked Upload Extensions**. Add any extension to the comma-separated list. MIME type is also verified server-side regardless of extension — a renamed `.php` file saved as `.jpg` will be detected and rejected.

**Can I prevent certain files or directories from being visible?**

Yes. Go to **File Manager → Security → Hidden / Blocked Paths** and add one relative path per line. Those paths and all their contents will be invisible and inaccessible to all users. Dotfiles can also be hidden globally under **Settings**.

**Can I make PHP files read-only?**

Yes. Go to **File Manager → Security → Read-only Extensions** and add `php` (or any other extension). Files with those extensions can be opened and viewed in the editor but the Save button will be disabled and the server will reject write attempts.

**Where is the audit log stored?**

In the WordPress database as a `wp_options` entry, stored as a ring buffer capped at 500 entries. It is viewable and filterable under **File Manager → Audit Log** and can be cleared from that page.

**Does this plugin work with multisite?**

It has not been tested on WordPress multisite installations. Single-site use only is recommended at this time.

**Does it support the Gutenberg block editor?**

Yes. Search for "KP File Manager" in the block inserter to embed the file manager on any page or post. Height, tree panel visibility, and alignment are configurable from the block sidebar.

---

## Changelog

### 1.0.58
- Added GitHub Releases update checker — updates now appear in WordPress Dashboard → Updates automatically

### 1.0.57
- Initial release
- Full file manager with CRUD operations (copy, move, create, delete, rename, chmod)
- CodeMirror text editor with syntax highlighting
- Role-based access control

---

## License

[GPLv3](https://www.gnu.org/licenses/gpl-3.0.html) — see `LICENSE` for full terms.

## Author

[Kevin Pirnie](https://kevinpirnie.com) — [Donate](https://paypal.me/kevinpirnie)