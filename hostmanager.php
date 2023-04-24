<?php

/**
 * Plugin Name: Manager
 * Plugin URI: https://themecloud.io
 * Description: This plugin is ideal to effortlessly manage your website.
 * Version: 0.1.0
 * Author: Themecloud
 * Author URI: https://themecloud.io
 * License: GPLv2 or later
 */


if (strpos($_SERVER['REQUEST_URI'], 'hostmanager') !== false) {
    require_once ABSPATH . 'wp-load.php';
    add_filter('option_active_plugins', 'skipplugins_plugins_filter');
    function skipplugins_plugins_filter($plugins)
    {
        foreach ($plugins as $i => $plugin) {
            if ($plugin != "simply-static/simply-static.php") {
                unset($plugins[$i]);
            }
        }
        return $plugins;
    }
}

require_once('plugin.php');
require_once('site-state.php');
require_once('mu-plugin-manager.php');
require_once('loginSSO.php');

$siteState = new SiteState();
$muManager = new MUPluginManager();

// add_action('admin_enqueue_scripts', 'hostmanager_assets');
// add_action('admin_menu', 'manager_setup_menu');

// [WIP] Hook after static push
add_action('ss_after_cleanup', function () {
    $webhook_url = 'https://domains.themecloud.io/api/statichook';
    wp_remote_get($webhook_url, array());
});


function hostmanager_assets($hook)
{

    // copy files into wp_content_dir because mu-plugins isn't accessible

    $plugin_data = get_plugin_data(__FILE__);
    $plugin_domain = "/" . $plugin_data['TextDomain'];

    $pluginDir = plugin_dir_path(__FILE__);

    if (!file_exists(WP_CONTENT_DIR . $plugin_domain . "/js")) {
        mkdir(WP_CONTENT_DIR . $plugin_domain . "/js", 0755, true);
    }

    if (!file_exists(WP_CONTENT_DIR . $plugin_domain . "/js/script.js")) {
        copy($pluginDir . "/js/script.js", WP_CONTENT_DIR . $plugin_domain . "/js/script.js");
    }

    // only enqueue script on our own page
    if ('toplevel_page_hostmanager' != $hook) {
        return;
    }

    // Charger notre script
    wp_enqueue_script('hostmanager',  WP_CONTENT_URL . $plugin_domain . "/js/script.js", array('jquery'), '1.0', true);

    // Envoyer une variable de PHP à JS proprement
    wp_localize_script('hostmanager', 'hostmanager', ['url' => get_site_url(), 'nonce' => wp_create_nonce('wp_rest'), 'tc_token' => $_COOKIE['tc_token']]);
}

function manager_setup_menu()
{

    add_submenu_page(null, 'HostManger Plugin', 'HostManger Plugin', 'manage_options', 'hostmanager', 'manager_init');
}

function manager_init()
{

    global $muManager;

    $html = '
    <p>Toggle Benchmark plugin</p>
        <input type="submit" id="togglePlugin" name="togglePlugin"
                class="button" value="Toggle plugin" />

    ';

    echo $html;
}

function get_check()
{
    $data = array(
        "code" => "ok",
    );

    return new WP_REST_Response($data, 200);
}

function toggle_mu_plugin($request)
{
    global $muManager;

    // verify if user nonce is valid and can do something
    if (!current_user_can('manage_options')) {
        // if not check X-TC_TOKEN that has been set to the tc-token cookie
        if (!$request->get_header('X-TC-TOKEN')) {
            $data = array(
                "code" => "no_tc_token",
                "data" => "Need to set X-TC-Token header"
            );

            return new WP_REST_Response($data, 403);
        }

        $ssoClass = new LoginSSO();

        $verified = $ssoClass->verifyTCToken($request->get_header('X-TC-TOKEN'));

        // invalid tc token
        if (!$verified) {
            $data = array(
                "code" => "invalid_tc_token",
                "data" => "Invalid TC Token"
            );

            return new WP_REST_Response($data, 403);
        }
    }

    $data = array(
        "code" => "ok",
        "data" => $muManager->togglePlugin()
    );

    return new WP_REST_Response($data, 200);
}

function get_site_state()
{
    global $siteState;
    $data = array(
        "code" => "ok",
        "data" => $siteState->get_site_full_state()
    );

    return new WP_REST_Response($data, 200);
}

function plugin_upgrade($request)
{
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->plugin_upgrade($request);
}

function plugin_install($request)
{
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->restInstall($request);
}

function plugin_toggle($request)
{
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->restToggle($request);
}

function plugin_list($request)
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();

    $data = array(
        "code" => "ok",
        "data" =>  array("json" => json_encode($all_plugins))
    );

    return new WP_REST_Response($data, 200);
}

function get_db_prefix()
{
    require_once ABSPATH . "wp-blog-header.php";

    $data = array(
        "code" => "ok",
        "data" => $wpdb->base_prefix
    );

    return new WP_REST_Response($data, 200);
}

function login()
{
    include('request/index.php');
}

function ssp_run_static_export()
{
    // Full static export
    $simply_static = Simply_Static\Plugin::instance();
    $simply_static->run_static_export();
}

/**
 * at_rest_init
 */
function at_rest_init()
{
    // route url: domain.com/wp-json/$namespace/$route
    $namespace = 'hostmanager/v1';

    $namespacePublic = 'public-hostmanager/v1';

    register_rest_route($namespace, '/site_state', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'get_site_state',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/staticpush', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'ssp_run_static_export',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/get_check', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'get_check',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/db_prefix', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'get_db_prefix',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_upgrade', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'plugin_upgrade',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_install', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'plugin_install',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_toggle', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'plugin_toggle',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_list', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'plugin_list',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespacePublic, '/toggle_mu_plugin', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'toggle_mu_plugin',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route('sso/v1', '/login', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'login',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));
}

add_action('rest_api_init', 'at_rest_init');


// On désactive les indices de connexion WP
function no_wordpress_errors()
{
    return 'Something is wrong!';
}
add_filter('login_errors', 'no_wordpress_errors');

// On cache la version de WP

function remove_wordpress_version()
{
    return '';
}
add_filter('the_generator', 'remove_wordpress_version');

// Pick out the version number from scripts and styles
function remove_version_from_style_js($src)
{
    if (strpos($src, 'ver=' . get_bloginfo('version')))
        $src = remove_query_arg('ver', $src);
    return $src;
}
add_filter('style_loader_src', 'remove_version_from_style_js');
add_filter('script_loader_src', 'remove_version_from_style_js');
