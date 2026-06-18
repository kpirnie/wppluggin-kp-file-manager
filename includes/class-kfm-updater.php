<?php

/**
 * GitHub Releases update checker.
 * Hooks into WordPress's plugin update system to deliver releases
 * pushed to the GitHub repository without requiring WordPress.org.
 *
 * @package KP - File Manager
 * @since   1.0.58
 * @author  Kevin Pirnie <iam@kevinpirnie.com>
 *
 */

// Exit if accessed directly.
defined('ABSPATH') || die('Direct access is not allowed!');

// make sure the class is only defined once, in case of multiple includes or autoloading issues
if (! class_exists('KFM_Updater')) {

    /**
     * Checks the GitHub Releases API for a newer tag and injects the result
     * into WordPress's update pipeline so the standard "Update now" flow works.
     *
     * @package KP - File Manager
     * @since   1.0.58
     * @author  Kevin Pirnie <iam@kevinpirnie.com>
     *
     */
    class KFM_Updater
    {

        /** GitHub owner/repo slug — adjust if the repo name differs. */
        private const GH_REPO   = 'kpirnie/wppluggin-kp-file-manager';

        /** WordPress.org-style plugin basename (folder/main-file.php). */
        private const PLUGIN_BASENAME = 'kp-file-manager/kp-file-manager.php';

        /** Transient key and cache lifetime (12 hours). */
        private const TRANSIENT    = 'kfm_gh_update_check';
        private const CACHE_SECS   = 43200;

        /**
         * Wires up the WordPress update hooks.
         * Call once from KFM_Plugin::init().
         *
         * @package KP - File Manager
         * @since   1.0.58
         * @author  Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return void
         *
         */
        public static function register(): void
        {
            add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
            add_filter('plugins_api',                           [__CLASS__, 'plugin_info'], 10, 3);
            add_filter('upgrader_process_complete',             [__CLASS__, 'purge_cache'], 10, 2);
        }

        /**
         * Fetches the latest release data from GitHub (cached).
         *
         * @package KP - File Manager
         * @since   1.0.58
         * @author  Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @return object|false Release object on success, false on failure.
         *
         */
        private static function fetch_release(): object|false
        {

            // return cached data if available
            $cached = get_transient(self::TRANSIENT);
            if (false !== $cached) return $cached;

            // hit the GitHub releases API
            $response = wp_remote_get(
                'https://api.github.com/repos/' . self::GH_REPO . '/releases/latest',
                [
                    'timeout' => 10,
                    'headers' => [
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                    ],
                ]
            );

            // bail on error or non-200 status
            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
                return false;
            }

            // decode and cache the release data
            $release = json_decode(wp_remote_retrieve_body($response));
            if (empty($release->tag_name)) return false;

            set_transient(self::TRANSIENT, $release, self::CACHE_SECS);

            return $release;
        }

        /**
         * Injects update data into the WordPress transient when a newer release exists.
         *
         * @package KP - File Manager
         * @since   1.0.58
         * @author  Kevin Pirnie <iam@kevinpirnie.com>\
         *
         * @param  object $transient The current update_plugins transient value.
         * @return object            Modified transient.
         *
         */
        public static function inject_update(object $transient): object
        {

            // WordPress passes an empty object early in the boot cycle — skip it
            if (empty($transient->checked)) return $transient;

            $release = self::fetch_release();
            if (! $release) return $transient;

            // strip leading "v" from tag (e.g. v1.0.58 → 1.0.58)
            $remote_version = ltrim($release->tag_name, 'v');

            // only inject when the remote is actually newer
            if (! version_compare($remote_version, KFM_VERSION, '>')) return $transient;

            // find the zip asset, falling back to the auto-generated zipball
            $zip_url = $release->zipball_url ?? '';
            foreach ($release->assets ?? [] as $asset) {
                if (str_ends_with($asset->name, '.zip')) {
                    $zip_url = $asset->browser_download_url;
                    break;
                }
            }

            // build the update object WordPress expects
            $update                = new stdClass();
            $update->slug          = 'kp-file-manager';
            $update->plugin        = self::PLUGIN_BASENAME;
            $update->new_version   = $remote_version;
            $update->url           = 'https://github.com/' . self::GH_REPO;
            $update->package       = $zip_url;
            $update->icons         = [];
            $update->banners       = [];
            $update->banners_rtl   = [];
            $update->tested        = '';
            $update->requires_php  = '8.2';
            $update->compatibility = new stdClass();

            // inject into the transient
            $transient->response[self::PLUGIN_BASENAME] = $update;

            return $transient;
        }

        /**
         * Populates the plugin info modal ("View version details") from GitHub release data.
         *
         * @package KP - File Manager
         * @since   1.0.58
         * @author  Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param  false|object|array $result Default result.
         * @param  string             $action The API action being performed.
         * @param  object             $args   Request arguments.
         * @return false|object               Modified result or passthrough.
         *
         */
        public static function plugin_info(false|object|array $result, string $action, object $args): false|object|array
        {

            // only handle our own plugin slug
            if ('plugin_information' !== $action || ($args->slug ?? '') !== 'kp-file-manager') {
                return $result;
            }

            $release = self::fetch_release();
            if (! $release) return $result;

            $remote_version = ltrim($release->tag_name, 'v');

            // find the zip asset, same logic as inject_update
            $zip_url = $release->zipball_url ?? '';
            foreach ($release->assets ?? [] as $asset) {
                if (str_ends_with($asset->name, '.zip')) {
                    $zip_url = $asset->browser_download_url;
                    break;
                }
            }

            // build the info object WordPress expects for the modal
            $info                = new stdClass();
            $info->name          = 'KP File Manager';
            $info->slug          = 'kp-file-manager';
            $info->version       = $remote_version;
            $info->author        = '<a href="https://kevinpirnie.com">Kevin Pirnie</a>';
            $info->homepage      = 'https://github.com/' . self::GH_REPO;
            $info->requires_php  = '8.2';
            $info->last_updated  = $release->published_at ?? '';
            $info->sections      = [
                'description' => $release->body ?? __('See GitHub for release notes.', 'kp-file-manager'),
                'changelog'   => $release->body ?? '',
            ];
            $info->download_link = $zip_url;

            return $info;
        }

        /**
         * Busts the cached release data after a plugin update completes.
         *
         * @package KP - File Manager
         * @since   1.0.58
         * @author  Kevin Pirnie <iam@kevinpirnie.com>
         *
         * @param  object $upgrader Upgrader instance (unused).
         * @param  array  $options  Completion data.
         * @return void
         *
         */
        public static function purge_cache(object $upgrader, array $options): void
        {

            // only care about plugin updates that include our basename
            if ('update' !== ($options['action'] ?? '') || 'plugin' !== ($options['type'] ?? '')) return;
            if (! in_array(self::PLUGIN_BASENAME, (array) ($options['plugins'] ?? []), true)) return;

            delete_transient(self::TRANSIENT);
        }
    }
}
