<?php

/**
 * Fonctions WordPress minimales, alimentées par un registre de fixtures.
 */
$GLOBALS['faaaster_test'] = array(
    'options' => array(),
    'site_options' => array(),
    'home_url' => 'https://example.com',
    'site_url' => 'https://example.com',
    'remote_posts' => array(),
    'remote_status' => 200,
    'cache_flushed' => 0,
    'user_id' => 0,
);

function faaaster_test_reset_wp()
{
    $GLOBALS['faaaster_test']['options'] = array();
    $GLOBALS['faaaster_test']['site_options'] = array();
    $GLOBALS['faaaster_test']['home_url'] = 'https://example.com';
    $GLOBALS['faaaster_test']['site_url'] = 'https://example.com';
    $GLOBALS['faaaster_test']['remote_posts'] = array();
    $GLOBALS['faaaster_test']['remote_status'] = 200;
    $GLOBALS['faaaster_test']['cache_flushed'] = 0;
    $GLOBALS['faaaster_test']['user_id'] = 0;
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['faaaster_test']['options']) ? $GLOBALS['faaaster_test']['options'][$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['faaaster_test']['options'][$name] = $value;
    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (array_key_exists($name, $GLOBALS['faaaster_test']['options'])) {
        return false;
    }
    $GLOBALS['faaaster_test']['options'][$name] = $value;
    return true;
}

function get_site_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['faaaster_test']['site_options']) ? $GLOBALS['faaaster_test']['site_options'][$name] : $default;
}

function home_url($path = '')
{
    return rtrim($GLOBALS['faaaster_test']['home_url'], '/') . '/' . ltrim($path, '/');
}

function site_url($path = '')
{
    return rtrim($GLOBALS['faaaster_test']['site_url'], '/') . '/' . ltrim($path, '/');
}

function get_current_user_id()
{
    return $GLOBALS['faaaster_test']['user_id'];
}

function wp_doing_cron()
{
    return false;
}

function wp_cache_flush()
{
    $GLOBALS['faaaster_test']['cache_flushed']++;
    return true;
}

class WP_Error
{
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function wp_remote_post($url, $args = array())
{
    $GLOBALS['faaaster_test']['remote_posts'][] = array('url' => $url, 'args' => $args);
    return array('response' => array('code' => $GLOBALS['faaaster_test']['remote_status']));
}

function wp_remote_retrieve_response_code($response)
{
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}

class WP_REST_Response
{
    public $data;
    public $status;

    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_data()
    {
        return $this->data;
    }
}
