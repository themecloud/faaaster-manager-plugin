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
require_once('login.php');


$siteState = new SiteState();

function get_check() {
    $data = array(
        "code" => "ok",
    );

    return new WP_REST_Response($data, 200);
}

function get_site_state() {
    global $siteState;
    $data = array(
        "code" => "ok",
        "data" => $siteState->get_site_full_state()
    );

    return new WP_REST_Response($data, 200);
}

function plugin_upgrade($request) {
    $pluginUpgrader = new PluginUpgrade();
    return $pluginUpgrader->plugin_upgrade($request);
}


function sso_login() {
    $loginClass = new Login();
    return $loginClass->sso();
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
    ));


    register_rest_route($namespace, '/get_check', array(
        'methods'   => WP_REST_Server::READABLE,
        'callback'  => 'get_check',
        'args' => array(),
    ));

    register_rest_route($namespace, '/plugin_upgrade', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'plugin_upgrade',
        'args' => array(),
    ));

    register_rest_route($namespace, '/login', array(
        'methods'   => WP_REST_Server::CREATABLE,
        'callback'  => 'sso_login',
        'args' => array(),
    ));
    
}

add_action('rest_api_init', 'at_rest_init');

?>
