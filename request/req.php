<?php

require_once('../loginSSO.php');

function hostmanager_search_wp_config($dir, $fileSearch)
{
    try {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = @realpath($dir.DIRECTORY_SEPARATOR.$value);

            if (!is_dir($path)) {
                if ($fileSearch == $value) {
                    return $path;
                    break;
                }
            } elseif ($value != "." && $value != "..") {
                hostmanager_search_wp_config($path, $fileSearch);
            }
        }
    } catch (\Exception $e) {
        return __DIR__ . '/../../../../wp-config.php';
    }
}


function hostmanager_response($data)
{
    header('Cache-Control: no-cache');
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;

if (!$method) {
    hostmanager_response([
        "code" => "not_authorized"
    ]);
    return;
}


function hostmanager_get_parameters($method = 'POST')
{
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data === null) {
            return $_POST;
        }
    } elseif ($method === 'GET') {
        return $_GET;
    }
}

function hostmanager_get_headers()
{
    $function = 'getallheaders';
    $all_headers = array();

    if (function_exists($function)) {
        $all_headers = $function();
    } else {
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5)=='HTTP_') {
                $name=substr($name, 5);
                $name=str_replace('_', ' ', $name);
                $name=strtolower($name);
                $name=ucwords($name);
                $name=str_replace(' ', '-', $name);

                $all_headers[$name] = $value;
            } elseif ($function === 'apache_request_headers') {
                $all_headers[$name] = $value;
            }
        }
    }
    return $all_headers;
}

$actionManager = null;

$headers = hostmanager_get_headers();

if ($method === 'GET' && isset($_GET['x-action']) && ($_GET['x-action'] === '/v1/login' || $_GET['x-action'] === '/v1/authorize')) {
    if (!isset($_GET['x-action'])) {
        hostmanager_response([
            "code" => "not_authorized"
        ]);
        return;
    }
    $actionManager = $_GET['x-action'];
} else {
    if (!isset($headers['X-Action'])) {
        if (!isset($_POST['X-Action'])) {
            hostmanager_response([
                "code" => "not_authorized"
            ]);
        }

        $actionManager = $_POST['X-Action'];
    } else {
        $actionManager = $headers['X-Action'];
    }
}

if (!defined('ABSPATH')) {
    $fileWpConfig = hostmanager_search_wp_config(__DIR__ . '/../../../../', 'wp-config.php');
    require_once $fileWpConfig;
}

if (!function_exists('is_plugin_active') && defined('ABSPATH')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (!is_plugin_active('hostmanager-reformed/hostmanager.php')) {
    hostmanager_response([
        "code" => "not_authorized"
    ]);
    return;
}

try {
    switch ($actionManager) {
        case '/v1/login':
            $loginClass = new LoginSSO();
            $response = $loginClass->sso();

            return hostmanager_response($response);
        case '/v1/authorize':
            $data = hostmanager_get_parameters("GET");

            $loginClass = new LoginSSO();
            $response = $loginClass->authorize($data);

            return hostmanager_response($response);
    }
} catch (\Exception $e) {
    hostmanager_response([
        "code" => "unknown"
    ]);
    return;
}
