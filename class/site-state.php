<?php


include_once('product-state.php');


class SiteState
{
    private static $site_state = array();

    public function get_site_full_state($active_plugins)
    {
        // Set error reporting to suppress notices and warnings
        $error_reporting = error_reporting();
        error_reporting(E_ERROR | E_PARSE);

        // Start output buffering at the highest level
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        try {
            if (! function_exists('get_plugins') || ! function_exists('get_mu_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            // Delete the transients that store update information
            delete_site_transient('update_core');
            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');

            // Include the file that contains the function to check for updates
            require_once(ABSPATH . 'wp-admin/includes/update.php');

            // Trigger WordPress to check for updates
            wp_version_check();
            wp_update_plugins();
            wp_update_themes();

            $wp_plugins         = get_plugins();
            $plugin_update_data = get_site_transient('update_plugins')->response ?? [];
            foreach ($wp_plugins as $name => $plugin) {

                $slug  = explode('/', $name);
                $update_version = array_key_exists($name, $plugin_update_data) ? $plugin_update_data[$name]->new_version : '';
                $active = in_array($name, $active_plugins, true) ? "1" : "0";
                $state = new ProductState($slug[0], $slug[0], $plugin['Name'], "", 'plugin', $plugin['Version'], $update_version, 1, $active);
                $plugins_state[] = $state->get_wp_info();
            }

            $wp_themes         = wp_get_themes();
            $current_theme     = wp_get_theme();
            $theme_update_data = get_site_transient('update_themes')->response ?? [];
            foreach ($wp_themes as $theme) {
                $stylesheet = $theme->get_stylesheet();
                $update_version = array_key_exists($stylesheet, $theme_update_data) ? $theme_update_data[$stylesheet]['new_version'] : '';
                $active = $stylesheet === $current_theme->get_stylesheet() ? "1" : "0";
                $state = new ProductState($stylesheet, $stylesheet, $theme['Name'], "", 'theme', $theme->get('Version'), $update_version, 1, $active);
                // $state->set_active($slug);
                // $state->set_screenshot(self::get_theme_screenshot_url($slug));
                $themes_state[] = $state->get_wp_info();
            }

            return array(
                'site_info' => self::get_site_info(),
                'plugins'   => $plugins_state,
                'themes'    => $themes_state
            );
        } catch (Exception $e) {
            // Restore error reporting
            error_reporting($error_reporting);
            ob_end_clean();
            return array(
                'site_info' => self::get_site_info(),
                'plugins'   => [],
                'themes'    => []
            );
        }
        // Restore error reporting
        error_reporting($error_reporting);
        ob_end_clean();
    }

    private static function get_theme_screenshot_url($slug)
    {
        $theme_folder = get_theme_root();
        $theme_folder .= '/' . $slug;

        //file extensions https://codex.wordpress.org/Theme_Development#Screenshot
        $file_name = "";
        if (file_exists($theme_folder . '/screenshot.png')) {
            $file_name = 'screenshot.png';
        } else if (file_exists($theme_folder . '/screenshot.jpg')) {
            $file_name = 'screenshot.jpg';
        } else if (file_exists($theme_folder . '/screenshot.jpeg')) {
            $file_name = 'screenshot.jpeg';
        } else if (file_exists($theme_folder . '/screenshot.gif')) {
            $file_name = 'screenshot.gif';
        }

        if (!empty($file_name)) {
            $file = get_theme_root_uri();
            $file .= '/' . $slug . '/' . $file_name;

            return $file;
        } else {
            return "";
        }
    }

    public static function get_site_info($blog_id = null, $reset = false)
    {
        // Set error reporting to suppress notices and warnings
        $error_reporting = error_reporting();
        error_reporting(E_ERROR | E_PARSE);

        // Start output buffering at the highest level
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        try {
            if (self::$site_state != null && $reset === false && is_null($blog_id)) {
                return self::$site_state;
            }

            global $wp_version, $wpdb;

            if ((is_multisite() && is_null($blog_id)) || (is_multisite() && $blog_id == 'multisite')) {
                $home_url = network_admin_url(); // or site_url
                $admin_url = network_admin_url();
                $site_title = get_site_option('site_name');
            } else {
                $home_url = get_home_url($blog_id);
                $admin_url = get_admin_url($blog_id);
                $site_title = get_bloginfo('name');
            }

            $sql_version = $wpdb->get_var("SELECT VERSION() AS version");

            if (is_multisite() && $blog_id && $blog_id != 'multisite') {
                $time_zone = get_blog_option($blog_id, 'timezone_string');
            } else {
                $time_zone = get_option('timezone_string');
            }

            if (empty($time_zone)) {
                $time_zone = date_default_timezone_get();
                if (!$time_zone || empty($time_zone)) {
                    $time_zone = "America/Los_Angeles";
                }
            }

            function get_latest_wp_core_update_info()
            {
                $updates = get_site_transient('update_core');
                // Check if there are any updates available
                if (!empty($updates->updates) && is_array($updates->updates)) {
                    foreach ($updates->updates as $update) {
                        // Check for the latest version that is not the current version
                        if ($update->response == 'upgrade' && version_compare($update->current, get_bloginfo('version'), '>')) {
                            // Return the update information
                            return  $update->current;
                        }
                    }
                }
                return "";
            }


            $server_software = isset($_SERVER['SERVER_SOFTWARE']) && trim($_SERVER['SERVER_SOFTWARE']) !== '' ? $_SERVER['SERVER_SOFTWARE'] : 'unknown';
            $debug_mode = self::isDebugModeActive();
            $indexable = self::isIndexable();
            $autoload_size = self::getAutoloadSize();

            $site_info = array(
                'platform'            => 'wordpress',
                'site_url'            => $home_url,
                'admin_url'           => $admin_url,
                'name'                => $home_url,
                'site_title'          => $site_title,
                'site_screenshot_url' => $home_url,
                'platform_version'    => $wp_version,
                'platform_update'     => get_latest_wp_core_update_info(),
                'php_version'         => PHP_VERSION,
                'mysql_version'       => $sql_version,
                'timezone'            => $time_zone, //todo check on multisite
                'server_type'         => $server_software,
                'server_version'      => $server_software,
                'other_data'          => array(
                    'file_system'     => array(
                        'method' => self::get_fs_method(),
                        'config' => self::check_fs_configs() ? 1 : 0
                    ),
                    "is_network"      => ((is_multisite()) ? 1 : 0),
                    "blog_id"         => $blog_id
                ),
                "is_network"          => ((is_multisite()) ? 1 : 0),
                "debug_mode"     => $debug_mode,
                "indexable"     => $indexable,
                "autoload_size" => $autoload_size,
                "free_disk_space" => self::get_free_disk_space(),
                "total_disk_space" => self::get_total_disk_space(),
            );

            if (is_multisite() && is_numeric($blog_id)) {
                $blog_details = get_blog_details($blog_id);
                if (!empty($blog_details)) {
                    $site_info['other_data']['multisite_data'] = array(
                        'registered'   => $blog_details->registered,
                        'last_updated' => $blog_details->last_updated,
                    );
                }
            }

            self::$site_state = $site_info;
            // Restore error reporting
            error_reporting($error_reporting);
            ob_end_clean();
            return self::$site_state;
        } catch (Exception $e) {
            // Restore error reporting
            error_reporting($error_reporting);
            ob_end_clean();
            return self::$site_state;
        }
    }

    /**
     * @return string direct|ssh2|ftpext|ftpsockets
     */
    public static function get_fs_method()
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/misc.php'); // extract_from_markers() wp-super-cache deactivation fatal error fix

        return get_filesystem_method();
    }

    # Get free disk space
    public static function get_free_disk_space()
    {
        return disk_free_space("/app/www");
    }

    # Get total disk space
    public static function get_total_disk_space()
    {
        return disk_total_space("/app");
    }


    public static function check_fs_configs()
    {

        $fs_method = self::get_fs_method();

        if ($fs_method == "direct") {
            return true;
        }

        $credentials['connection_type'] = $fs_method;
        $credentials['hostname'] = (defined('FTP_HOST')) ? FTP_HOST : "";
        $credentials['username'] = (defined('FTP_USER')) ? FTP_USER : "";
        $credentials['password'] = (defined('FTP_PASS')) ? FTP_PASS : "";
        $credentials['public_key'] = (defined('FTP_PUBKEY')) ? FTP_PUBKEY : "";
        $credentials['private_key'] = (defined('FTP_PRIKEY')) ? FTP_PRIKEY : "";

        if (
            (!empty($credentials['password']) && !empty($credentials['username']) && !empty($credentials['hostname'])) ||
            ('ssh' == $credentials['connection_type'] && !empty($credentials['public_key']) && !empty($credentials['private_key']))
        ) {
            return true;
        } else {
            return false;
        }
    }

    public static function isDebugModeActive()
    {

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG || defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
                return (1);
            }
        } else {
            return (0);
        }
    }
    public static function isIndexable()
    {

        if (1 === (int) get_option('blog_public')) {
            return 0;
        } else {
            return 1;
        }
    }

    private static function getAutoloadSize()
    {
        require_once ABSPATH . 'wp-load.php';
        global $wpdb;
        $prefix = $wpdb->prefix;
        $query = "SELECT SUM(LENGTH(option_value)) as alsize FROM " . $prefix . "options WHERE autoload = 'yes'";

        $autoload_size = $wpdb->get_results($query);
        return $autoload_size[0]->alsize;
    }
}
