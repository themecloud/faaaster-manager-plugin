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

require_once('plugin.php');
require_once('site-state.php');

$siteState = new SiteState();


function get_check()
{
    $data = array(
        "code" => "ok",
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

/**
 * at_rest_init
 */
function at_rest_init()
{
    // route url: domain.com/wp-json/$namespace/$route
    $namespace = 'hostmanager/v1';

    register_rest_route($namespace, '/site_state', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'get_site_state',
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

    register_rest_route('sso/v1', '/login', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'login',
        'args' => array(),
        'permission_callback' => '__return_true',
    ));
}

add_action('rest_api_init', 'at_rest_init');
