<?php

/**
 * Plugin Name: Faaaster Manager
 * Plugin URI: https://faaaster.io
 * Description: This plugin is ideal to effortlessly manage your website.
 * Version: 0.1.0
 * Author: Faaaster
 * Author URI: https://faaaster.io
 * License: GPLv2 or later
 */
if (!file_exists('/app/.include/manager.php')) {
    exit;
}
require_once('/app/.include/manager.php');

global $active_plugins;
global $cfcache_enabled;
global $private;

$app_id = defined('APP_ID') ? APP_ID : false;
$branch = defined('BRANCH') ? BRANCH : false;
$wp_api_key = defined('WP_API_KEY') ? WP_API_KEY : false;
$cfcache_enabled = defined('CFCACHE_ENABLED') ? CFCACHE_ENABLED : "false";
$private = defined('PRIVATE_MODE') ? PRIVATE_MODE : "false";
$app_env = ['APP_ID' => $app_id, 'BRANCH' => $branch, 'WP_API_KEY' => $wp_api_key, 'CFCACHE_ENABLED' => $cfcache_enabled];
$active_plugins = (array) get_option('active_plugins', []);
$faaaster_api_base = defined('CUSTOM_FAAASTER_API_BASE') ? CUSTOM_FAAASTER_API_BASE : 'https://app.faaaster.io';
define('FAAASTER_API_BASE', $faaaster_api_base);


// Initialize error handling as early as possible
require_once(__DIR__ . '/class/error-handler.php');

if (defined('APP_ID') && defined('BRANCH') && defined('WP_API_KEY')) {
    $errorHandler = new FaaasterErrorHandler(APP_ID, BRANCH, WP_API_KEY);
    $errorHandler->init();
}


if (strpos($_SERVER['REQUEST_URI'], 'hostmanager') !== false) {
    require_once ABSPATH . 'wp-load.php';
    add_filter('option_active_plugins', 'skipplugins_plugins_filter');
    function skipplugins_plugins_filter($plugins)
    {
        foreach ($plugins as $i => $plugin) {
            if ($plugin != "simply-static/simply-static.php" && $plugin != "advanced-custom-fields/acf.php" && $plugin != "advanced-custom-fields-pro/acf.php") {
                unset($plugins[$i]);
            }
        }
        return $plugins;
    }
}

function faaaster_disable_filters_for_manager_plugin($response)
{
    $request_url = $_SERVER['REQUEST_URI'];
    // Check if the URL contains "manager-plugin"
    if (strpos($request_url, 'hostmanager') !== false or strpos($request_url, 'sso') !== false) {
        // Remove all filters on the "rest_not_logged_in" hook
        remove_all_filters('rest_not_logged_in');
        remove_all_filters('rest_authentication_errors');
    }

    return $response;
}
add_action('rest_api_init', 'faaaster_disable_filters_for_manager_plugin');

require_once(__DIR__ . '/class/plugin-manager.php');
require_once(__DIR__ . '/class/theme-manager.php');
require_once(__DIR__ . '/class/core-manager.php');
require_once(__DIR__ . '/class/site-state.php');
require_once(__DIR__ . '/class/mu-plugin-manager.php');
require_once(__DIR__ . '/class/loginSSO.php');
require_once(__DIR__ . '/class/cloudflare-manager.php');
require_once(__DIR__ . '/class/static-manager.php');
require_once(__DIR__ . '/class/event-manager.php');


$siteState = new SiteState();
$muManager = new MUPluginManager();
$cloudflare = new FaaasterCloudflare($app_id, $branch, $wp_api_key, $cfcache_enabled, $faaaster_api_base);
$staticManager = new FaaasterStaticManager();
$pluginUpgrader = new PluginUpgrade();
$themeUpgrader = new ThemeUpgrade();
$coreUpgrader = new CoreUpgrade();
$eventManager = new FaaasterEventManager($app_id, $branch, $wp_api_key, $faaaster_api_base);

$cloudflare->init();
$eventManager->init();


function faaaster_get_check()
{
    $data = array(
        "code" => "ok",
    );

    return new WP_REST_Response($data, 200);
}

function faaaster_manager_do_remote_get(string $url, array $args = array())
{
    $headers = array(
        "X-Purge-Cache:true",
        "Host:" . wp_parse_url(home_url())['host'],
    );

    $ch = curl_init();

    //this will set the minimum time to wait before proceed to the next line to 100 milliseconds
    curl_setopt($ch, CURLOPT_URL, "$url");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    curl_setopt($ch, CURLOPT_COOKIE, '"trial_bypass":"true"');
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_exec($ch);

    //this line will be executed after 100 milliseconds

    curl_close($ch);
}

function faaaster_manager_clear_all_cache()
{
    global $cfcache_enabled, $cloudflare;

    // OP Cache
    opcache_reset();

    // New Method fcgi
    $_url_purge = "http://localhost/purge-all";
    faaaster_manager_do_remote_get($_url_purge);

    // Pagespeed
    touch('/tmp/pagespeed/cache.flush');

    // Cloudflare
    if (APP_ID && WP_API_KEY && BRANCH && $cfcache_enabled == "true") {
        $cloudflare->purgeAll();
    }

    // Cache objet WordPress
    wp_cache_flush();
}

function faaaster_toggle_mu_plugin($request)
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

// Get site info
function faaaster_get_site_state()
{
    global $active_plugins;
    global $siteState;
    $data = array(
        "code" => "ok",
        "data" => $siteState->get_site_full_state($active_plugins)
    );

    return new WP_REST_Response($data, 200);
}




// Check integruty of core and plugins
function faaaster_integrity_check($request)
{
    exec('wp core verify-checksums --skip-plugins --skip-themes', $output, $return_var);
    if ($return_var !== 0) {
        // Handle error
        echo "Error executing command: " . implode("\n", $output);
        $core_integrity = false;
    } else {
        // Command executed successfully
        echo "Command executed successfully: " . implode("\n", $output);
        $core_integrity = true;
    }
    $plugins_integrity = shell_exec('wp plugin verify-checksums --skip-plugins --skip-themes --all --format=json');

    $data = array(
        "code" => "ok",
        "data" =>   array(
            "core" => $core_integrity,
            "plugins" => json_decode($plugins_integrity)
        )
    );

    return new WP_REST_Response($data, 200);
}

// Update core
function faaaster_update_core($request)
{
    $params = $request->get_query_params();
    $coreUpgrader = new CoreUpgrade();
    return $params ? $coreUpgrader->core_update($params) : $coreUpgrader->core_update();
}





// Clear Cache
function faaaster_clear_cache($request)
{
    $clear_cache = faaaster_manager_clear_all_cache();

    $data = array(
        "code" => "ok",
    );

    return new WP_REST_Response($data, 200);
}

// Disable or enable emails
function faaaster_handle_email_control($request)
{
    $enable = $request->get_param('enable');

    if ($enable === 'yes') {
        update_option('disable_emails', 'no');
        return new WP_REST_Response('Emails enabled', 200);
    } else {
        update_option('disable_emails', 'yes');
        return new WP_REST_Response('Emails disabled', 200);
    }
}

// Intercept emails
function faaaster_intercept_emails($args)
{
    global $private;
    if (get_option('disable_emails') === 'yes' && $private == "true") {
        return []; // Returning an empty array to cancel email sending
    }
    return $args;
}
add_filter('wp_mail', 'faaaster_intercept_emails');


// Get DB prefix
function faaaster_get_db_prefix()
{
    global $wpdb;
    $data = array(
        "code" => "ok",
        "data" => $wpdb->base_prefix
    );

    return new WP_REST_Response($data, 200);
}

// Login
function faaaster_login()
{
    include('request/index.php');
}

/**
 * at_rest_init
 */
function faaaster_at_rest_init()
{
    global $pluginUpgrader, $themeUpgrader, $coreUpgrader, $staticManager;

    // route url: domain.com/wp-json/$namespace/$route
    $namespace = 'hostmanager/v1';

    $namespacePublic = 'public-hostmanager/v1';

    register_rest_route($namespace, '/site_state', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_get_site_state',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));


    register_rest_route($namespace, '/get_check', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_get_check',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/db_prefix', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_get_db_prefix',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_upgrade', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$pluginUpgrader, 'plugin_upgrade'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_install', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$pluginUpgrader, 'restInstall'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_toggle', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$pluginUpgrader, 'restToggle'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/theme_upgrade', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$themeUpgrader, 'theme_upgrade'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/theme_install', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$themeUpgrader, 'restInstall'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/theme_toggle', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$themeUpgrader, 'restToggle'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/plugin_list', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => [$pluginUpgrader, 'plugin_list'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/update_core', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$coreUpgrader, 'core_update'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/reinstall_core', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$coreUpgrader, 'reinstall_core'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/reinstall_plugins', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$pluginUpgrader, 'reinstall_plugins'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/integrity_check', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_integrity_check',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/clear_cache', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'faaaster_clear_cache',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/toggle_email', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_handle_email_control',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/static_enable', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$staticManager, 'enableStatic'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespace, '/static_push', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => [$staticManager, 'runStaticExport'],
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route($namespacePublic, '/toggle_mu_plugin', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_toggle_mu_plugin',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));

    register_rest_route('sso/v1', '/login', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'faaaster_login',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));
}

add_action('rest_api_init', 'faaaster_at_rest_init');

// Hide WordPress errors
if (defined('HIDE_WP_ERRORS') == false) {
    define('HIDE_WP_ERRORS', true);
}

// Disable WordPress login errors
function faaaster_no_wordpress_errors()
{
    return 'Something is wrong!';
}
if (HIDE_WP_ERRORS != false) {
    add_filter('login_errors', 'faaaster_no_wordpress_errors');
}

// Hide WordPress version
function faaaster_remove_wordpress_version()
{
    return '';
}
add_filter('the_generator', 'faaaster_remove_wordpress_version');

// Add custom header for logged in admins
add_action('send_headers', function () {
    if (is_user_logged_in() && current_user_can('manage_options')) {
        header('X-WP-Admin: true');
    }
});

// Pick out the version number from scripts and styles
function faaaster_remove_version_from_style_js($src)
{
    if (strpos($src, 'ver=' . get_bloginfo('version')))
        $src = remove_query_arg('ver', $src);
    return $src;
}
add_filter('style_loader_src', 'faaaster_remove_version_from_style_js');
add_filter('script_loader_src', 'faaaster_remove_version_from_style_js');
