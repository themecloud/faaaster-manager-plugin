<?php


include_once('product-state.php');


class SiteState
{
    private static $site_state = array();

    public function get_site_full_state()
    {
        $plugins_state = array();
        $themes_state = array();
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        //$plugins = get_plugins();
        $plugins = get_plugins();

        //var_dump ($plugins);
        $pluginsupdates = get_plugin_updates();
        //var_dump ($pluginsupdates);
        foreach ($plugins as $slug => $plugin) {

            if (isset($pluginsupdates[$slug])) {
                $pluginupdate = (array) $pluginsupdates[$slug];
                $pluginupdate = (array) $pluginupdate['update'];
                $plugin['update'] = $pluginupdate['new_version'];
            } else {
                $plugin['update'] = "";
            }

            $state = new ProductState($slug, $slug, $plugin['Title'], $plugin['Description'], 'plugin', $plugin['Version'], $plugin['update'], 1);
            $state->set_active($slug);
            $plugins_state[] = $state->get_wp_info();
        }

        $themes = wp_get_themes(array('errors' => null));
        foreach ($themes as $slug => $theme) {
            if($theme['Version']){
                $state = new ProductState($slug, $slug, $theme['Name'], $theme->get('Description'), 'theme', $theme['Version'], $theme['update'], 0, 1);
                $state->set_active($slug);
                $state->set_screenshot(self::get_theme_screenshot_url($slug));
                $themes_state[] = $state->get_wp_info();
            }

        }

        return array(
            'site_info' => self::get_site_info(),
            'plugins'   => $plugins_state,
            'themes'    => $themes_state
        );
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
            "autoload_size" => $autoload_size
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

        return self::$site_state;
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

        private static function getAutoloadSize(){
            require_once ABSPATH . 'wp-load.php';
            global $wpdb;
            $autoload_size = $wpdb->get_results("SELECT SUM(LENGTH(option_value)) FROM wp_options WHERE autoload = 'yes'");
            return $autoload_size;
        }
}
