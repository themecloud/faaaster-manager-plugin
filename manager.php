<?php

/**
 * Created by PhpStorm.
 * User: mher
 * Date: 9/13/17
 * Time: 4:22 PM
 */

namespace Tenweb_Manager;

use twoptimizeUtils;

require_once(TENWEB_INCLUDES_DIR . "/class-login.php");
// require_once(TENWEB_INCLUDES_DIR . '/class-tenweb-services.php');
// require_once(TENWEB_INCLUDES_DIR . "/class-multisite.php");
// require_once(TENWEB_INCLUDES_DIR . "/class-wpadminpagespeedfixes.php");

class Manager
{

    public $access_token = null;
    protected static $instance = null;
    private $menu_ids = array();
    private $user_logged_in = false;
    private $tenweb_screen = false;
    private $class_login;
    private $user_info = array();
    private $user_agreements = array();

    private $cache;
    private $whiteLabel;

    private static $plugins = array();
    private static $pluginsupdates = array();
    private static $themes = array();
    private static $addons = array();

    private static $products_raw_data = array();

    private $other_pages = array(
        "plugins",
        "plugins-network",
        "plugin-install",
        "themes",
        "themes-network",
        "options-general",
        "plugin-editor",
        "theme-editor",
        "site-info-network",
        "site-settings-network",
        "settings-network"
    );


    public function __construct()
    {
        // $this->deactivation_popup();

        $this->init();

        add_action('admin_notices', array($this, 'manager_class_admin_notices'));
        // add_action('get_plugins_updates', [$this, 'plugins_loaded']);
        /* $this->login_wp_user();
        self::redirect_to_requested_page();
        if (!empty($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], '10web_register_url')) {
            add_action('in_admin_header', array($this, 'tenweb_connection'));
        }
        */

        //add_action('init', array($this, 'register_hooks'));
        //add_action('admin_head', array($this, 'admin_head'));
        /*add_action('wp_ajax_' . TENWEB_PREFIX . '_dashboard_login', array($this, 'login_user'));
        add_action('tenweb_send_site_state', array($this, 'tenweb_send_site_state')); */
    }
    public function manager_class_admin_notices()
    {
        echo '<div class="updated"><p><strong>Manager Class</strong>initiated</p></div>';
    }
    public function restapi_init_admin_notices()
    {
        echo '<div class="updated"><p><strong>REST API CLASS</strong> initiated 2</p></div>';
    }


    /* public function admin_head()
    {
        if ($this->user_logged_in) {
            echo '<input type="hidden" id="tenweb_user_is_logged_in" value="1"/>';
        }
        echo '<input type="hidden" id="tenweb_plugin_is_active" value="1"/>';
    }
    */

    private function init()
    {



        add_action('rest_api_init', array($this, 'init_rest_api'));


        // update version

        /* 
        if (TENWEB_VERSION != get_site_option(TENWEB_PREFIX . '_version')) {
            $error_msgs = tenweb_activate("0");
            foreach ($error_msgs as $error_msg) {
                Helper::add_notices($error_msg);
            }
        }
		*/
        $this->load_classes();
        /* // $this->class_login = Login::get_instance();
        // $this->user_logged_in = $this->class_login->check_logged_in();

        //check if 10web hosted website
        /*if (Helper::check_if_manager_mu()) {


            $this->whiteLabel = TenwebWhiteLabel::get_instance();
            $this->whiteLabel->register_hooks();

            $this->cache = TenwebCache::get_instance();
            $this->cache->register_hooks();


            // check if manager plugin in plugins list, deactivate it
            include_once ABSPATH . "wp-admin/includes/plugin.php";
            if (is_plugin_active(TENWEB_SLUG)) {
                $network_wide = is_multisite() ? true : false;
                deactivate_plugins(TENWEB_SLUG, false, $network_wide);
            }
        }
        if ($this->user_logged_in) {
            User::get_instance();
        }
        $this->check_for_update_workspace_id();
        */
    }

    private function login_wp_user()
    {
        if (!empty($_GET['tenweb_wp_login_token']) && is_user_logged_in() === false) {
            User::get_instance()->login();
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

    private function check_for_update_workspace_id()
    {
        $domain_id = get_site_option(TENWEB_PREFIX . '_domain_id');
        $workspace_id = \TenwebServices::get_workspace_id();
        $check = get_site_transient(TENWEB_PREFIX . '_add_workspace_id_checked');
        if ($domain_id && !$workspace_id && !$check) {
            Api::get_instance()->add_workspace_id();
            // save one week
            set_site_transient(TENWEB_PREFIX . '_add_workspace_id_checked', 1, 604800);
        }
    }

    public function register_hooks()
    {
        if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ($this->user_logged_in && is_admin()) {
            $this->set_products();
        }

        //check if 10web not hosted website
        if (!Helper::check_if_manager_mu()) {
            if (get_transient("tenweb_initial_score")) {
                add_action('admin_notices', array($this, 'admin_notice_after_migrate'));
            }
            add_action('wp_ajax_tenweb_send_pagespeed', array($this, 'tenweb_send_pagespeed'));
            add_action('wp_ajax_tenweb_notice_after_migrate_dismissed', array($this, 'tenweb_notice_after_migrate_dismissed'));
        }
        add_action('rest_api_init', array($this, 'init_rest_api'));

        if (is_multisite() === true) {
            add_action('network_admin_menu', array($this, 'add_menu'), 24);
        } else {
            add_action('admin_menu', array($this, 'add_menu'), 24);
        }


        add_action('current_screen', array($this, 'is_tenweb_screen')); //for including login for styles
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('elementor/editor/before_enqueue_scripts', array($this, 'enqueue_scripts'));

        if (is_user_logged_in()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        }


        add_action('admin_init', array($this, 'check_debug_mode'));
        //add_filter('http_request_args', array($this, 'add_request_timeout'), 2, 2);
        if (get_site_option(TENWEB_PREFIX . '_connect_error') !== false) {
            add_action('admin_notices', array($this, 'connection_notices'));
        }

        if ($this->user_logged_in && is_admin()) {

            if (get_site_option(TENWEB_PREFIX . '_activated') !== false) {

                //$this->send_availability_request();
            }

            if (is_multisite() === true) {
                add_action('network_admin_notices', array($this, 'admin_notices'));
            } else {
                add_action('admin_notices', array($this, 'admin_notices'));
            }

            if (User::get_instance()->tenweb_user_logged_in()) {
                add_action('admin_footer', array($this, 'tenweb_user_logged_in_notice'));
            }

            add_action('wp_ajax_check_site_state', array($this, 'check_site_state'));
            add_action('wp_ajax_populate_agreement_info', array($this, 'populate_agreement_info'));


            add_action('deleted_plugin', array($this, 'check_site_state_after_action')); //todo
            add_action('tenweb_check_site_state_after_action', array($this, 'tenweb_check_site_state_after_action'));
            $user_info = Helper::get_tenweb_user_info(false, true);
            $this->user_info = $user_info['client_info'];
            $this->user_agreements = $user_info['agreement_info'];
            $this->add_paid_products_update_msg();

            //init optimizer
            add_filter('plugin_action_links_tenweb_manager', array($this, 'add_action_link'), 10, 2);
            if (!is_admin() && !isset($_GET['elementor-preview'])) {
                add_action('admin_bar_menu', array($this, 'my_admin_bar_menu'), 99999);
            }

            if (is_multisite()) {
                Multisite::get_instance();
            }
        }

        /* if (is_admin()) {
            WpAdminPagespeedFixes::enable();
        } */

        add_filter('site_transient_update_plugins', array($this, 'remove_plugins_from_updates'), 1);
        add_filter('site_transient_update_themes', array($this, 'remove_themes_from_updates'), 1);
    }

    public function add_action_link($links, $file)
    {
        if (TWO_BASENAME === $file) {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=two_settings_page')) . '">' . __('Settings') . '</a>';
            array_unshift($links, $settings_link);
        }

        return $links;
    }

    public function check_site_state()
    {
        Helper::check_site_state();
    }

    public function populate_agreement_info()
    {
        $user_info = Helper::get_tenweb_user_info();
        $this->user_info = $user_info['client_info'];
        $this->user_agreements = $user_info['agreement_info'];
    }

    public function check_site_state_after_action()
    {
        wp_cache_delete('plugins', 'plugins');

        Helper::check_site_state();
    }

    public function tenweb_check_site_state_after_action()
    {
        wp_cache_delete('plugins', 'plugins');
        wp_clean_themes_cache(false);

        Helper::check_site_state();
    }

    public function admin_enqueue_scripts($hook_suffix)
    {
        $screen = get_current_screen();
        $products_diff = Helper::get_products_diff();
        $site_info_diff = Helper::get_site_info_diff($screen);
        wp_enqueue_script(TENWEB_PREFIX . '_populate_agreement_info_js', TENWEB_URL . '/assets/js/populate_agreement_info.js', array('jquery'), TENWEB_VERSION);
        wp_localize_script(TENWEB_PREFIX . '_populate_agreement_info_js', TENWEB_PREFIX . "_populate_agreement_info", array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        ));
        if (($products_diff || $site_info_diff) && (($this->tenweb_screen || in_array($screen->id, $this->other_pages)) || (isset($_GET['page']) && ($_GET['page'] == 'WDD_plugins' || $_GET['page'] == 'WDD_themes' || $_GET['page'] == 'WDD_addons')))) {
            wp_enqueue_script(TENWEB_PREFIX . '_send_state_js', TENWEB_URL . '/assets/js/send_state.js', array('jquery'), TENWEB_VERSION);
            wp_localize_script(TENWEB_PREFIX . '_send_state_js', TENWEB_PREFIX . "_state", array(
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }

        wp_enqueue_script(TENWEB_PREFIX . '_send_pagespeed_js', TENWEB_URL . '/assets/js/send_pagespeed.js', array('jquery'), TENWEB_VERSION);
        wp_localize_script(TENWEB_PREFIX . '_send_pagespeed_js', TENWEB_PREFIX . "_pagespeed", array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        ));

        wp_register_style(TENWEB_PREFIX . '_styles_whitelabel', TENWEB_URL . '/assets/css/whitelabel.css', array(), TENWEB_VERSION);
        wp_enqueue_style(TENWEB_PREFIX . '_styles_whitelabel');

        /*enqueue scripts and styles only on our page*/
        if ($this->tenweb_screen == false) {
            return;
        }
        //$rest_route = get_rest_url() . TENWEB_REST_NAMESPACE . '/action';
        $rest_route = add_query_arg(array(
            'rest_route' => '/' . TENWEB_REST_NAMESPACE . '/action'
        ), get_home_url() . "/");

        $workspace_id = \TenwebServices::get_workspace_id();
        $route = TENWEB_API_URL . '/manager/upate-manager';
        $auth_header = get_site_option(TENWEB_PREFIX . '_access_token');
        $domain_id = get_site_option(TENWEB_PREFIX . '_domain_id');

        wp_enqueue_script('player_api', 'https://www.youtube.com/player_api', array(), TENWEB_VERSION);
        wp_register_script(TENWEB_PREFIX . '_scripts_main', TENWEB_URL . '/assets/js/main.js', array(), TENWEB_VERSION);
        wp_register_script(TENWEB_PREFIX . '_scripts_caret', TENWEB_URL . '/assets/js/jquery.caret.min.js', array(), TENWEB_VERSION);
        wp_register_script(TENWEB_PREFIX . '_scripts_tageditor', TENWEB_URL . '/assets/js/jquery.tag-editor.min.js', array(), TENWEB_VERSION);
        wp_enqueue_script(TENWEB_PREFIX . '_scripts_main');
        wp_enqueue_script(TENWEB_PREFIX . '_scripts_caret');
        wp_enqueue_script(TENWEB_PREFIX . '_scripts_tageditor');
        wp_localize_script(TENWEB_PREFIX . '_scripts_main', TENWEB_PREFIX, array(
            'ajaxurl'         => admin_url('admin-ajax.php'),
            'ajaxnonce'       => wp_create_nonce('wp_rest'),
            'plugin_url'      => TENWEB_URL,
            'action_endpoint' => $route,
            'domain_id'       => $domain_id,
            'auth_header'     => $auth_header,
        ));


        wp_register_style(TENWEB_PREFIX . '_styles_main', TENWEB_URL . '/assets/css/main.css', array(), TENWEB_VERSION);
        wp_enqueue_style(TENWEB_PREFIX . '_styles_main');
        wp_enqueue_style(TENWEB_PREFIX . '_styles_backgrounds', TENWEB_URL . '/assets/css/backgrounds.css', array(), TENWEB_VERSION);

        wp_enqueue_style(TENWEB_PREFIX . '_styles_tageditor', TENWEB_URL . '/assets/css/jquery.tag-editor.css', array(), TENWEB_VERSION);
        //enqueue scripts and styles
    }

    //enqueue scripts on both admin and front
    public function enqueue_scripts()
    {
        if (file_exists(WPMU_PLUGIN_DIR . '/' . TENWEB_LO_SCRITP_PATH)) {
            wp_register_script(TENWEB_PREFIX . '_scripts_lo', WPMU_PLUGIN_URL . '/' . TENWEB_LO_SCRITP_PATH, array(), TENWEB_VERSION);
            wp_enqueue_script(TENWEB_PREFIX . '_scripts_lo');
        }
    }


    public function add_menu()
    {

        $company_name = Helper::get_company_name();
        $debug_mode = get_site_option(TENWEB_PREFIX . '_debug_mode');
        //if (Helper::check_if_manager_mu() && defined('TENWEB_CACHE') && !in_array(TENWEB_CACHE, array('0', 'disabled'))) {
        $this->menu_ids[] = add_menu_page(
            __($company_name . ' Cache', TENWEB_LANG),
            __($company_name . ' Cache', TENWEB_LANG),
            'manage_options',
            TENWEB_PREFIX . '_cache_menu',
            array($this, 'display_cache'),
            Helper::get_cache_icon(),
            4
        );
        //}


        if ((Helper::check_if_manager_mu() && $debug_mode == '1') || !Helper::check_if_manager_mu()) {

            $this->menu_ids[] = add_menu_page(
                __($company_name . ' Manager', TENWEB_LANG),
                __($company_name . ' Manager', TENWEB_LANG),
                'manage_options',
                TENWEB_PREFIX . '_menu',
                array($this, 'display_products'),
                TENWEB_URL_IMG . '/manager-logo.png',
                4
            );
        }

        if ($debug_mode == '1') {

            $this->menu_ids[] = add_submenu_page(
                TENWEB_PREFIX . '_menu',
                __($company_name . ' Products', TENWEB_LANG),
                __($company_name . ' Products', TENWEB_LANG),
                'manage_options',
                TENWEB_PREFIX . '_menu',
                array($this, 'display_products')
            );


            $this->menu_ids[] = add_submenu_page(
                TENWEB_PREFIX . '_menu',
                __($company_name . ' Config', TENWEB_LANG),
                __($company_name . ' Config', TENWEB_LANG),
                'manage_options',
                TENWEB_PREFIX . '_config',
                array($this, 'config_menu')
            );

            $this->menu_ids[] = add_submenu_page(
                TENWEB_PREFIX . '_menu',
                __($company_name . ' Logs', TENWEB_LANG),
                __($company_name . ' Logs', TENWEB_LANG),
                'manage_options',
                TENWEB_PREFIX . '_error_logs',
                array($this, 'error_logs_menu')
            );

            $this->menu_ids[] = add_submenu_page(
                TENWEB_PREFIX . '_menu',
                __($company_name . ' Migration Logs', TENWEB_LANG),
                __($company_name . ' Migration Logs', TENWEB_LANG),
                'manage_options',
                TENWEB_PREFIX . '_migration_logs',
                array($this, 'migration_logs_menu')
            );

            $this->menu_ids[] = add_submenu_page(
                TENWEB_PREFIX . '_menu',
                __($company_name . ' Php info', TENWEB_LANG),
                __($company_name . ' Php info', TENWEB_LANG),
                'manage_options',
                TENWEB_PREFIX . '_php_info',
                array($this, 'php_info_menu')
            );
        }

        if (is_multisite()) {
            foreach ($this->menu_ids as $key => $menu_id) {
                $this->menu_ids[$key] = $menu_id . '-network';
            }
        }
    }

    public function settings_page()
    {
        include_once TENWEB_VIEWS_DIR . '/optimizer-settings.php';
    }

    /* public function display_products()
    {

        $user_name = (isset($this->user_info['name'])) ? $this->user_info['name'] : null;
        // Self update notice
        if ($this->class_login->check_logged_in()) {
            $manager = self::get_product_by('slug', '10web-manager', 'plugin', 'installed');

            if (!is_null($manager) && $manager->has_update()) {
                self::manager_update($manager->id);
            }
        }

        if ($this->class_login->check_logged_in()) {
            include_once TENWEB_VIEWS_DIR . '/products-menu.php';
        } else {
            $registration_link = $this->get_registration_link();
            if (isset($_GET["login"]) && $_GET["login"] == "1") {
                include_once TENWEB_VIEWS_DIR . '/login-menu.php';
            } else {
                $plugin_from = get_site_option("tenweb_manager_installed");
                if ($plugin_from === false) {
                    include_once TENWEB_VIEWS_DIR . '/tenweb-menu.php';
                } else {
                    $plugin_from = json_decode($plugin_from, true);
                    if (is_array($plugin_from)) {
                        $pugin_slug = key($plugin_from);
                        include_once TENWEB_VIEWS_DIR . '/plugins-from.php';
                    } else {
                        include_once TENWEB_VIEWS_DIR . '/tenweb-menu.php';
                    }
                }
            }

        }

    }

    public function display_cache()
    {
        $domain_id = get_site_option(TENWEB_PREFIX . '_domain_id');
        $tenweb_hosting_tools_page = TENWEB_DASHBOARD . '/websites/' . $domain_id . '/hosting/tools';
        include_once TENWEB_VIEWS_DIR . '/cache-menu.php';

    }

    public function error_logs_menu()
    {
        $logs = array_reverse(Helper::get_error_logs());
        $time_zone = date_default_timezone_get();

        include_once TENWEB_VIEWS_DIR . "/error-logs-menu.php";
    }

    public function migration_logs_menu()
    {
        $logs = array_reverse(Helper::get_migration_logs());
        $time_zone = date_default_timezone_get();
        $is_migration = true;

        include_once TENWEB_VIEWS_DIR . "/error-logs-menu.php";
    }

    // show phpinfo for debug
    public function php_info_menu()
    {
        ob_start();
        phpinfo();
        $phpinfo = ob_get_contents();
        ob_end_clean();
        $phpinfo = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', $phpinfo);
        echo "
        <div id='TENWEB_phpinfo'>
            $phpinfo
        </div>
        ";
    }

    public function config_menu()
    {
        include_once TENWEB_VIEWS_DIR . "/configs.php";
    }

    */
    public function set_products($reset = false)
    {
        $in_progress_key = TENWEB_PREFIX . '_setting_products_in_progress';
        $setting_products_in_progress = get_site_transient($in_progress_key);


        $plugins = get_site_option(TENWEB_PREFIX . '_plugins_list');
        $themes = get_site_option(TENWEB_PREFIX . '_themes_list');
        $addons = get_site_option(TENWEB_PREFIX . '_addons_list');

        $transient = get_site_transient(TENWEB_PREFIX . '_client_products_transient');
        if (($transient === false || $reset === true) && !$setting_products_in_progress) {
            set_site_transient($in_progress_key, 1, TW_IN_PROGRESS_LOCK);

            $api = Api::get_instance();
            $products = $api->get_products();

            if (!(empty($products['plugins']) && empty($products['themes']) && empty($products['addons']))) {
                $plugins = $products['plugins'];
                $themes = $products['themes'];
                $addons = $products['addons'];

                update_site_option(TENWEB_PREFIX . '_plugins_list', $plugins);
                update_site_option(TENWEB_PREFIX . '_themes_list', $themes);
                update_site_option(TENWEB_PREFIX . '_addons_list', $addons);

                Helper::calc_request_block('user_products', true);
                $expiration = Helper::get_expiration('user_products');
                $expiration = $expiration['expiration'];
            } else {

                $block_count = Helper::calc_request_block('user_products');

                $expiration = Helper::get_expiration('user_products');
                $expiration = $expiration['block_time'] * $block_count;
            }

            set_site_transient(TENWEB_PREFIX . '_client_products_transient', '1', $expiration);
        }

        //if first api call failed
        $plugins = (!is_array($plugins)) ? array() : $plugins;
        $themes = (!is_array($themes)) ? array() : $themes;
        $addons = (!is_array($addons)) ? array() : $addons;

        self::$products_raw_data = array('plugins' => $plugins, 'themes' => $themes, 'addons' => $addons);

        $products_objects = Helper::get_products_objects($plugins, $themes, $addons);

        self::$plugins = $products_objects['plugins'];
        self::$themes = $products_objects['themes'];
        self::$addons = $products_objects['addons'];

        delete_site_transient($in_progress_key);
    }

    public function remove_plugins_from_updates($plugins)
    {
        if (isset($GLOBALS['tenweb_update_process']) && $GLOBALS['tenweb_update_process'] == true) {
            return $plugins;
        }

        if (empty($plugins) || !is_object($plugins) || empty($plugins->response)) {
            return $plugins;
        }

        if (empty(self::$plugins['installed_products'])) {
            return $plugins;
        }

        foreach (self::$plugins['installed_products'] as $installed_product) {

            $state = $installed_product->get_state();
            if ($state->is_paid == false) {
                continue;
            }

            $wp_slug = $installed_product->get_wp_slug();
            if (isset($plugins->response[$wp_slug])) {
                unset($plugins->response[$wp_slug]);
            }
        }

        return $plugins;
    }

    public function remove_themes_from_updates($themes)
    {

        if (isset($GLOBALS['tenweb_update_process']) && $GLOBALS['tenweb_update_process'] == true) {
            return $themes;
        }

        if (empty($themes) || !is_object($themes) || empty($themes->response)) {
            return $themes;
        }

        if (empty(self::$themes['installed_products'])) {
            return $themes;
        }

        foreach (self::$themes['installed_products'] as $installed_product) {

            $state = $installed_product->get_state();
            if ($state->is_paid == false) {
                continue;
            }

            $wp_slug = $installed_product->slug;
            if (isset($themes->response[$wp_slug])) {
                unset($themes->response[$wp_slug]);
            }
        }

        return $themes;
    }


    public function get_products_raw_data()
    {
        return self::$products_raw_data;
    }

    public function is_tenweb_screen()
    {
        $screen = get_current_screen();
        $this->tenweb_screen = (!($screen == null || !in_array($screen->id, $this->menu_ids)));
    }

    public function init_rest_api()
    {
        require_once TENWEB_INCLUDES_DIR . '/class-rest-api.php';
        RestApi::get_instance();
    }

    /* 
    public function clear_logs()
    {
        if (check_ajax_referer('wp_rest', 'tenweb_nonce', false)) {
            delete_site_transient(TENWEB_PREFIX . '_error_logs');
        }
    }

    public function clear_migration_logs()
    {
        if (check_ajax_referer('wp_rest', 'tenweb_nonce', false)) {
            Helper::flush_migration_log();
        }
    }

    public function clear_cache()
    {
        if (check_ajax_referer('wp_rest', 'tenweb_nonce', false)) {
            Helper::clear_cache();
        }
    }

    public function save_configs()
    {
        if (check_ajax_referer('wp_rest', 'tenweb_nonce', false)) {
            Helper::save_configs($_POST);
        }
    }

    public function delete_banned_ip()
    {
        if (check_ajax_referer('wp_rest', 'tenweb_nonce', false)) {
            if (isset($_POST['ips'])) {
                Helper::delete_banned_ip($_POST['ips']);
            }
        }
    }

    public function check_curl()
    {
        if (check_ajax_referer('wp_rest', 'tenweb_nonce', false)) {

            $urls = array(
                'https://www.google.com/',
                'https://core.10web.io/',
                'https://manager.10web.io/',
                'https://optimizer.10web.io/',
                'https://backup.10web.io/',
                'https://seo.10web.io/'
            );

            $data = array();
            foreach ($urls as $url) {

                $response = wp_remote_get($url);

                if (is_wp_error($response)) {
                    $data[] = array('success' => false, 'url' => $url, 'error_msg' => $response->get_error_message());
                } else {
                    $data[] = array('success' => true, 'url' => $url, 'error_msg' => "");
                }

            }

            die(json_encode($data));
        }
    }

    public function login_user()
    {

        if (!check_ajax_referer('tenweb_login_nonce', 'tenweb_nonce', false)) {
            $error = array(
                'error'   => 'nonce_error',
                'message' => 'Wrong nonce.'
            );
            die(json_encode($error));
        }

        $data = $this->class_login->login();

        if ($this->class_login->check_logged_in()) {
            Helper::get_tenweb_user_info(true);
        } else {
            die(json_encode($this->class_login->get_errors()));
        }
    }
    
	*/
    private function load_classes()
    {
        // include_once TENWEB_INCLUDES_DIR . '/class-api.php';
        include_once TENWEB_INCLUDES_DIR . '/product-state.php';
        // include_once TENWEB_INCLUDES_DIR . '/class-user.php';
        // include_once TENWEB_INCLUDES_DIR . '/class-login.php';
        // include_once TENWEB_INCLUDES_DIR . '/class-product.php';
        // include_once TENWEB_INCLUDES_DIR . '/interface-product-actions.php';
        // include_once TENWEB_INCLUDES_DIR . '/class-installed-plugin.php';
        // include_once TENWEB_INCLUDES_DIR . '/class-installed-theme.php';
        include_once TENWEB_INCLUDES_DIR . '/class-migration-run.php';
        // require_once TENWEB_INCLUDES_DIR . '/class-cache.php';
        // require_once TENWEB_INCLUDES_DIR . '/class-whitelabel.php';

    }
    /*

    private function send_availability_request()
    {

        delete_option(TENWEB_PREFIX . '_activated');

        $api = Api::get_instance();
        if ($api->availability_request() === true) {
            delete_site_transient(TENWEB_PREFIX . '_send_states_transient');
        }

    }

    public function tenweb_connection()
    {
        include_once 'views/connect-popup.php';
        $this->register_from_dashboard();
    }

    private function register_from_dashboard()
    {
        if (!empty($_GET['email']) && !empty($_GET['token'])) {

            $email = sanitize_email($_GET['email']);
            $token = sanitize_text_field($_GET['token']);
            $pwd = md5($token);

            if ($this->class_login->login($email, $pwd, $token) == true && $this->class_login->check_logged_in()) {
                Helper::get_tenweb_user_info(true);
                $domain_id = get_site_option(TENWEB_PREFIX . '_domain_id');
                $url = TENWEB_DASHBOARD . '/websites?website_connected=' . $domain_id;
                if (!empty($_GET['sign_up_from_free_plugin'])) {
                    $url .= '&from_free_plugin=1';
                }
                die('<script>window.location.href="' . $url . '"</script>');
            } else {
                $errors = $this->class_login->get_errors();
                $err_msg = (!empty($errors)) ? $errors['message'] : 'Something went wrong.';
                update_site_option(TENWEB_PREFIX . '_connect_error', $err_msg);
            }

        }
        if (is_multisite()) {
            die('<script>window.location.href="' . network_admin_url() . 'admin.php?page=tenweb_menu' . '"</script>');
        }
        die('<script>window.location.href="' . get_admin_url() . 'admin.php?page=tenweb_menu' . '"</script>');
    }
    */

    /**
     * @param array $args [sign_up_from_free_plugin, client_email, name]
     *
     * @return mixed
     */
    /* 
    public function get_registration_link($args = [])
    {
        $return_url = get_admin_url() . 'admin.php';
        if (is_multisite()) {
            $return_url = network_admin_url() . 'admin.php';
        }

        $return_url_args = array('page' => 'tenweb_menu');
        $register_url_args = array(
            'site_url'   => urlencode(get_site_url()),
            'utm_source' => '10webplugin',
            'utm_medium' => 'freeplugin',
            'nonce'      => wp_create_nonce('10web_register_url')
        );

        if (!empty($args)) {
            $register_url_args = $register_url_args + $args;
            $return_url_args = $return_url_args + $args;
        }

        $register_url_args['return_url'] = urlencode(add_query_arg($return_url_args, $return_url));

        $plugin_from = get_site_option("tenweb_manager_installed");
        if ($plugin_from !== false) {
            $plugin_from = json_decode($plugin_from, true);
            if (is_array($plugin_from) && reset($plugin_from) !== false) {
                $register_url_args['plugin_id'] = reset($plugin_from);
                if (isset($plugin_from["type"])) {
                    $register_url_args['utm_source'] = $plugin_from["type"];
                }
            }
        }

        //    $user = wp_get_current_user();
        //    if(isset($user->data->ID)) {
        //      $register_url_args['email'] = $user->data->user_email;
        //      $register_url_args['first_name'] = get_user_meta($user->data->ID, 'first_name', true);
        //      $register_url_args['last_name'] = get_user_meta($user->data->ID, 'last_name', true);
        //    }

        $url = add_query_arg($register_url_args, TENWEB_DASHBOARD . '/sign-up/');

        return $url;
    }

    public function check_debug_mode()
    {
        if (isset($_GET['tenweb_debug'])) {
            if ($_GET['tenweb_debug'] == '1') {
                update_site_option(TENWEB_PREFIX . '_debug_mode', '1');
            } else {
                delete_site_option(TENWEB_PREFIX . '_debug_mode');
            }
        }

        add_action('wp_ajax_' . TENWEB_PREFIX . '_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_' . TENWEB_PREFIX . '_clear_migration_logs', array($this, 'clear_migration_logs'));
        add_action('wp_ajax_' . TENWEB_PREFIX . '_clear_cache', array($this, 'clear_cache'));
        add_action('wp_ajax_' . TENWEB_PREFIX . '_check_curl', array($this, 'check_curl'));
        add_action('wp_ajax_' . TENWEB_PREFIX . '_save_configs', array($this, 'save_configs'));
        add_action('wp_ajax_' . TENWEB_PREFIX . '_delete_banned_ip', array($this, 'delete_banned_ip'));
    }

    public function multisite_notice()
    {
        ?>
        <div class="notice notice-error">
            <p>"Ten web manager does not support multisite yet."</p>
        </div>
        <?php
    }

    public function tenweb_send_site_state()
    {
        delete_site_transient(TENWEB_PREFIX . '_send_states_transient');
        Helper::check_site_state();
    }

    public function add_request_timeout($args, $url)
    {

        if (strpos($url, '10web.io') !== false) {
            $args['timeout'] = 15;
        }

        return $args;
    }

    public function tenweb_user_logged_in_notice()
    { ?>
        <style>
            @media screen and (max-width: 320px) {
                #wpcontent {
                    margin-bottom: 57px;
                }
            }

            #tenweb_user_logged_in_notice {
                color: #ff0000;
                text-align: center;
                position: fixed;
                bottom: 0px;
                z-index: 9999;
                width: 100%;
                background: #e2e3e5;
                padding: 10px;
                font-size: 14px;
                font-family: Open Sans, sans-serif !important;
                font-weight: 500;
                border-top: 1px solid #d6d6d6;
                margin: 0;
            }
        </style>
        <h3 id="tenweb_user_logged_in_notice">You have logged in with 10web admin account using one time access
            token.</h3>
        <?php
    }

    public function admin_notices()
    {

        if (!$this->tenweb_screen) {
            return;
        }

        $site_info = Helper::get_site_info();
        if ($site_info['other_data']['file_system']['config'] == false) {
            $text = '10web manager plugin does not have filesystem permissions to intall, update and delete products. Please configure your file system connection from
<b>config.php</b> file. ';
            $text .= '<a target="_blank"
             href="https://wordpress.org/support/article/editing-wp-config-php/#wordpress-upgrade-constants">More</a>';
            Helper::add_notices($text);
        }
        if (get_site_option(TENWEB_PREFIX . '_is_available') !== '1') {
            $text = '<b>10Web Manager plugin does not have file system permissions to install, update and delete products. You can download your purchased plugins <a href="' . TENWEB_DASHBOARD . '/websites?from_local=1" target="_blank">here</a>. In order to fix the permission issue, please contact our customer care <a href="https://help.10web.io/hc/en-us/requests/new" target="_blank">team</a>.</b>';
            Helper::add_notices($text);
        }

        $notices = Helper::get_notices();
        foreach ($notices as $notice) {
            echo $notice;
        }

    }

    public function connection_notices()
    {
        $connect_error = get_site_option(TENWEB_PREFIX . '_connect_error');
        $screen = get_current_screen();
        echo '<div class="notice is-dismissible error tenweb_manager_notice ' . ($screen->parent_base == "tenweb_menu" ? "tenweb_menu_notice" : "") . '"><p>' . $connect_error . '</p></div>';

        delete_option(TENWEB_PREFIX . '_connect_error');
    }

    private function add_paid_products_update_msg()
    {

        if (!empty(self::$plugins['installed_products'])) {
            foreach (self::$plugins['installed_products'] as $installed_product) {
                $state = $installed_product->get_state();

                if ($state->is_paid && $installed_product->has_update()) {
                    add_action('after_plugin_row_' . $installed_product->get_wp_slug(), array($this, 'plugin_update_msg'), 10, 2);
                }
            }
        }

        if (!empty(self::$addons['installed_products'])) {
            foreach (self::$addons['installed_products'] as $installed_product) {
                $state = $installed_product->get_state();

                if ($state->is_paid && $installed_product->has_update()) {
                    add_action('after_plugin_row_' . $installed_product->get_wp_slug(), array($this, 'plugin_update_msg'), 10, 2);
                }
            }
        }


        //    if(!empty(self::$themes['installed_products'])) {
        //      foreach(self::$themes['installed_products'] as $installed_product) {
        //
        //        if($installed_product->is_paid && $installed_product->has_update()) {
        //          add_action('after_theme_row_' . $installed_product->slug, array($this, 'theme_update_msg'), 10, 2);
        //
        //        }
        //      }
        //    }
    }

    public function plugin_update_msg($file, $plugin_data)
    {
        $active_class = is_plugin_active($file) ? ' active' : '';
        $wp_list_table = _get_list_table('WP_MS_Themes_List_Table');

        $domain_id = get_site_option(TENWEB_PREFIX . '_domain_id');
        $dashboard_url = TENWEB_DASHBOARD . '/websites/' . $domain_id . '/plugins/installed/';

        $product = null;

        foreach (self::$plugins['installed_products'] as $installed_product) {
            if ($installed_product->get_wp_slug() === $file) {
                $product = $installed_product;
                break;
            }
        }

        if ($product === null) {
            foreach (self::$addons['installed_products'] as $installed_product) {
                if ($installed_product->get_wp_slug() === $file) {
                    $product = $installed_product;
                    break;
                }
            }
        }

        $dashboard_url .= "?show_changelog=" . $product->id;
        $version = $product->latest_versions['paid'];
        ?>
        <tr class="plugin-update-tr <?php echo $active_class; ?>"
            id="<?php echo esc_attr($product->slug . '-update'); ?>"
            data-slug="<?php echo esc_attr($product->slug); ?>"
            data-plugin="<?php echo esc_attr($file); ?>">
            <td colspan="<?php echo esc_attr($wp_list_table->get_column_count()); ?>"
                class="plugin-update colspanchange">
                <div class="update-message notice inline notice-warning notice-alt">
                    <p>
                        There is a new version of <?php echo $plugin_data['Name']; ?> available.
                        <a href="<?php echo $dashboard_url; ?>" target="_blank">
                            View version <?php echo $version; ?> details
                        </a> or
                        <a href="<?php echo $dashboard_url; ?>" target="_blank">update now.</a>
                    </p>
                </div>
            </td>
        </tr>
        <?php

    }
    */

    /* public static function get_plugins()
    {
        return self::$plugins;
    }*/


    public static function get_addons()
    {
        return self::$addons;
    }

    public static function get_themes()
    {
        return self::$themes;
    }

    /**
     * @param string $search_by 'id'|'slug'
     * @param        $search_value
     * @param string $type      'all'|'plugin'|'theme'|addon
     * @param string $search_on 'all'|'installed'|'no_installed'
     *
     * @return null|Product
     */
    public static function get_product_by($search_by, $search_value, $type = "all", $search_on = "all")
    {
        $plugins = get_site_option(TENWEB_PREFIX . '_plugins_list');
        $themes = get_site_option(TENWEB_PREFIX . '_themes_list');
        $addons = get_site_option(TENWEB_PREFIX . '_addons_list');
        $products_objects = Helper::get_products_objects($plugins, $themes, $addons);

        self::$plugins = $products_objects['plugins'];
        self::$themes = $products_objects['themes'];
        self::$addons = $products_objects['addons'];


        $value = null;

        if ($type == "all" || $type == "plugin") {

            if (($search_on == "all" || $search_on == "installed") && !empty(self::$plugins['installed_products'])) {
                $value = self::get_product_from_list(self::$plugins['installed_products'], $search_by, $search_value);
                if ($value != null) {
                    return $value;
                }
            }

            if (($search_on == "all" || $search_on == "no_installed") && !empty(self::$plugins['products'])) {
                $value = self::get_product_from_list(self::$plugins['products'], $search_by, $search_value);
                if ($value != null) {
                    return $value;
                }
            }
        }

        if ($type == "all" || $type == "theme") {

            if (($search_on == "all" || $search_on == "installed") && !empty(self::$themes['installed_products'])) {
                $value = self::get_product_from_list(self::$themes['installed_products'], $search_by, $search_value);
                if ($value != null) {
                    return $value;
                }
            }

            if (($search_on == "all" || $search_on == "no_installed") && !empty(self::$themes['products'])) {
                $value = self::get_product_from_list(self::$themes['products'], $search_by, $search_value);
                if ($value != null) {
                    return $value;
                }
            }
        }

        if ($type == "all" || $type == "addon") {

            if (($search_on == "all" || $search_on == "installed") && !empty(self::$addons['installed_products'])) {
                $value = self::get_product_from_list(self::$addons['installed_products'], $search_by, $search_value);
                if ($value != null) {
                    return $value;
                }
            }

            if (($search_on == "all" || $search_on == "no_installed") && !empty(self::$addons['products'])) {
                $value = self::get_product_from_list(self::$addons['products'], $search_by, $search_value);
                if ($value != null) {
                    return $value;
                }
            }
        }

        return null;
    }


    /**
     * @param array  $products_list list of WDDProduct objects
     * @param string $search_by     'id'|'slug'
     * @param        $search_value
     *
     * @return null|Product
     * */
    public static function get_product_from_list($products_list, $search_by = "id", $search_value)
    {

        foreach ($products_list as $product) {
            if ($search_by == "id") {
                if ($product->id == $search_value) {
                    return $product;
                }
            } else if ($search_by == "slug") {
                if ($product->slug == $search_value) {
                    return $product;
                }
            }
        }

        return null;
    }

    public static function manager_update($id)
    {
        $html = '
<div id="twebman_overlay" class="twebman_update_modal">
    <div class="twebman_update"><p>' . __("New version of 10WEB Manager is available!", TENWEB_LANG) . '</p> <a
                id="self_update" data-id="' . $id . '">' . __("Please update now", TENWEB_LANG) . '<span
                    class="spinner"></span></a>';
        $html .= '
    </div>
</div>';
        echo $html;
    }

    public function deactivation_popup()
    {
        add_action('admin_footer', array($this, 'display_deactivation_popup'));
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tenweb_manager_deactivate')) {
            add_action('admin_init', array($this, 'submit_and_deactivate'));
        }
    }

    public function display_deactivation_popup()
    {
        $screen = get_current_screen();

        if ($screen->base === 'plugins') {

            $deactivate_url =
                add_query_arg(
                    array(
                        'action'   => 'deactivate',
                        'plugin'   => TENWEB_SLUG,
                        '_wpnonce' => wp_create_nonce('deactivate-plugin_' . TENWEB_SLUG)
                    ),
                    admin_url('plugins.php')
                );

            $admin = wp_get_current_user();

            wp_enqueue_style('10web-manager-deactivation-popup', TENWEB_URL . '/assets/css/deactivation-popup.css', array(), TENWEB_VERSION);
            wp_enqueue_script('10web-manager-deactivation-popup', TENWEB_URL . '/assets/js/deactivation-popup.js', array(), TENWEB_VERSION);
            include_once 'views/deactivation-popup.php';
        }
    }

    public function submit_and_deactivate()
    {

        if (isset($_POST["tenweb_submit_and_deactivate"])) {

            if ($_POST["tenweb_submit_and_deactivate"] == 2 || $_POST["tenweb_submit_and_deactivate"] == 3) {

                $data = array();

                $admin_data = wp_get_current_user();

                $data["reason"] = isset($_POST["tenweb_manager_reasons"]) ? $_POST["tenweb_manager_reasons"] : "";
                $data["site_url"] = site_url();
                $data["product_id"] = TENWEB_MANAGER_ID;

                if ($data["reason"] === "reason_plugin_is_hard_to_use_technical_problems") {

                    $data["additional_details"] = (!empty($_POST['tenweb_technical_textarea'])) ? $_POST['tenweb_technical_textarea'] : "";
                    $data["email"] = isset($_POST["tenweb_technical_email"]) ? $_POST["tenweb_technical_email"] : $admin_data->data->user_email;
                } else if ($data["reason"] === "reason_i_dont_understand_how_to_use") {

                    $data["additional_details"] = (!empty($_POST['tenweb_dont_install_textarea'])) ? $_POST['tenweb_dont_install_textarea'] : "";
                    $data["email"] = isset($_POST["tenweb_dont_install_email"]) ? $_POST["tenweb_dont_install_email"] : $admin_data->data->user_email;
                }

                $user_first_name = get_user_meta($admin_data->ID, "first_name", true);
                $user_last_name = get_user_meta($admin_data->ID, "last_name", true);

                $data["name"] = $user_first_name || $user_last_name ? $user_first_name . " " . $user_last_name : $admin_data->data->user_login;

                $response = wp_remote_post(
                    TEN_WEB_LIB_DEACTIVATION_URL,
                    array(
                        'method'      => 'POST',
                        'timeout'     => 45,
                        'redirection' => 5,
                        'httpversion' => '1.0',
                        'blocking'    => true,
                        'headers'     => array("Accept" => "application/x.10webcore.v1+json"),
                        'body'        => $data,
                        'cookies'     => array()
                    )
                );

                $response_body = (!is_wp_error($response) && isset($response["body"])) ? json_decode($response["body"], true) : null;
            }

            if ($_POST["tenweb_submit_and_deactivate"] == 2 || $_POST["tenweb_submit_and_deactivate"] == 1) {
                $deactivate_url =
                    add_query_arg(
                        array(
                            'action'   => 'deactivate',
                            'plugin'   => TENWEB_SLUG,
                            '_wpnonce' => wp_create_nonce('deactivate-plugin_' . TENWEB_SLUG)
                        ),
                        admin_url('plugins.php')
                    );
                echo '<script>window.location.href="' . $deactivate_url . '";</script>';
            }
        }
    }

    public static function get_instance()
    {
        if (null == self::$instance) {

            self::$instance = new self;
        }

        return self::$instance;
    }

    public static function redirect_to_requested_page()
    {
        if (!empty($_GET['redirect'])) {
            switch ($_GET['redirect']) {
                case 'edit_front_page':
                    $front_page_id = get_site_option('page_on_front', 0);
                    if ($front_page_id) {
                        wp_redirect(admin_url("/post.php?post=" . $front_page_id . "&action=elementor"), 301);
                        break;
                    }
                    wp_redirect(admin_url(), 301);
                    break;
                case 'view_front_page':
                    wp_redirect(get_site_url(), 301);
                    break;
                default:
                    break;
            }
        }
    }


    public function admin_notice_after_migrate()
    {

        wp_enqueue_style(TENWEB_PREFIX . '_notice', TENWEB_URL . '/assets/css/migrate-notice.css', array(), TENWEB_VERSION);
        $user_agreement = get_option('tenweb_user_agreements');
        $subscription = $user_agreement['subscription_id'];

        if (get_transient("tenweb_initial_score") && get_transient("tenweb_hosted_site_region") && $subscription == 211) {
            $migrated_score = get_option("tenweb_migrated_score");
            $initial_score = get_transient("tenweb_initial_score");
            $migrated_mobile_score = $migrated_score['mobile_score'];
            $migrated_desktop_score = $migrated_score['desktop_score'];
            $migrated_score_mob_bg_color = 'red';
            $migrated_score_pc_bg_color = 'red';
            if ($migrated_mobile_score >= 90) {
                $migrated_score_mob_bg_color = 'green';
            }
            if ($migrated_desktop_score >= 90) {
                $migrated_score_pc_bg_color = 'green';
            }

            $migrated_url = $migrated_score['url'];
            $migrated_domain_id = 0;
            if (get_transient('tenweb_migrate_domain_id_notice')) {
                $migrated_domain_id = get_transient('tenweb_migrate_domain_id_notice');
            }
            $initial_score_pc_bg_color = 'red';
            $initial_score_mob_bg_color = 'red';
            if ($initial_score['mobile_score'] && $initial_score['desktop_score']) {
                $initial_mobile_score = $initial_score['mobile_score'];
                $initial_desktop_score = $initial_score['desktop_score'];
                if ($initial_mobile_score >= 90) {
                    $initial_score_mob_bg_color = 'green';
                }
                if ($initial_desktop_score >= 90) {
                    $initial_score_pc_bg_color = 'green';
                }
            } else {
                $initial_mobile_score = '-';
                $initial_desktop_score = '-';
                $initial_score_pc_bg_color = 'gray';
                $initial_score_mob_bg_color = 'gray';
            }

            global $tenweb_regions;

            $hosted_site_region = get_transient("tenweb_hosted_site_region");
            $datacenter = substr($hosted_site_region, 0, strrpos($hosted_site_region, '-'));
            $datacenter_name = null;
            if ($tenweb_regions[$datacenter]) {
                $datacenter_name = $tenweb_regions[$datacenter];
            }

            echo '
<div class="notice notice-success  tenweb-after-migrate">
             <div class="tenweb-after-migrate-notice">
             <div class="tenweb-info">
                <span class="tenweb-logo">
                  <img src="' . TENWEB_URL_IMG . '/10web-logo-slogan.svg' . '" /> 
                 </span>
            <h4>The Copy of Your Website on 10Web Hosting is Ready</h4>
            <p class="info-head">Location / Data Center</p>
            <p>' . $datacenter_name . '</p>
            <hr class="tenweb-info-divider"/>
            <img class="tenweb-google-partner" src="' . TENWEB_URL_IMG . '/google-partner.png' . '" /> 
             </div>
             
             <div class="tenweb-scores">
             <h1>You got an optimized PageSpeed score & 99.9% Uptime</h1>
             <div class="scores-info">
             <div class="old-score">
                 <h4>Your Live Website</h4>
                 <hr/>
                 <div class="score-item mobile tenweb_' . $initial_score_mob_bg_color . '">                 
                  <img src="' . TENWEB_URL_IMG . '/mobile_icon.svg' . '" />
                    <span class="score-data">' . $initial_mobile_score . '</span> <span class="score-type">Mobile Score</span>
                </div>
                 <hr/>
                <div class="score-item tenweb_' . $initial_score_pc_bg_color . '">
                 <img src="' . TENWEB_URL_IMG . '/desktop_icon.svg' . '" />
                 <span class="score-data">' . $initial_desktop_score . '</span> <span class="score-type">Desktop Score</span>
                </div>
             </div>
             <div class="new-score">
             <h4>Copy of Your Website <span>on 10Web</span></h4>
              <hr/>
             <div class="score-item mobile tenweb_' . $migrated_score_mob_bg_color . '">             
                <img src="' . TENWEB_URL_IMG . '/mobile_icon.svg' . '" />
                <span class="score-data">' . $migrated_mobile_score . '</span> <span class="score-type">Mobile Score</span>
                </div>
                 <hr/>
                <div class="score-item tenweb_' . $migrated_score_pc_bg_color . '">
                 <img src="' . TENWEB_URL_IMG . '/desktop_icon.svg' . '" />
                <span class="score-data">' . $migrated_desktop_score . '</span> <span class="score-type">Desktop Score</span>
                </div>
             </div>
</div>
<p>You can always check your score with <a href="https://developers.google.com/speed/pagespeed/insights/?url=' . $migrated_url . ' " target="_blank"> Google PageSpeed Insights</a> or <a href="https://gtmetrix.com/">GTMetrix</a></p>
<a class="tenweb-explore tenweb-pagespeed" href="' . TENWEB_DASHBOARD . '/websites/' . $migrated_domain_id . '/overview?fromBanner=1" data-label="2" target="_blank">EXPLORE & TEST</a>
<a class="tenweb-after-migrate-dismissed tenweb-pagespeed " href="#" data-label="3"><img src="' . TENWEB_URL_IMG . '/close-notice.svg' . '" /></a>
</div>
          </div>   
         </div>';
        }
    }

    public function tenweb_notice_after_migrate_dismissed()
    {
        if (isset($_POST['label']) && $_POST['label'] == 3) {
            $api = Api::get_instance();
            $result = $api->send_pagespeed($_POST['label']);
            delete_transient('tenweb_initial_score');
            delete_transient('tenweb_hosted_site_region');
            delete_transient('tenweb_migrate_domain_id_notice');
            delete_transient('tenweb_send_pagespeed_1');
            delete_transient('tenweb_send_pagespeed_2');
            delete_transient('tenweb_send_pagespeed_3');
        }
    }

    public function tenweb_send_pagespeed()
    {
        $api = Api::get_instance();
        if ($_POST['label']) {
            $label = $_POST['label'];
            $result = false;

            if (!get_transient('tenweb_send_pagespeed_' . $label)) {
                $result = $api->send_pagespeed($label);
            };

            if ($result == true && $label == 1) {
                set_transient('tenweb_send_pagespeed_1', 1, 2 * WEEK_IN_SECONDS);
            } else if ($result == true && $label == 2) {
                set_transient('tenweb_send_pagespeed_2', 1, 2 * WEEK_IN_SECONDS);
            } else if ($result == true && $label == 3) {
                set_transient('tenweb_send_pagespeed_3', 1, 2 * WEEK_IN_SECONDS);
            }
        }
    }
}
