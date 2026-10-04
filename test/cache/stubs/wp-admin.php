<?php

/**
 * Stubs wp-admin : traduction (fr_FR chargeable depuis languages/*.l10n.php),
 * échappement, nonces, transients, redirections. wp_safe_redirect et wp_die
 * lèvent une exception pour ne pas atteindre exit.
 */
class FaaasterTestRedirect extends Exception
{
    public $location;

    public function __construct($location)
    {
        parent::__construct('redirect');
        $this->location = $location;
    }
}

class FaaasterTestDie extends Exception
{
}

function faaaster_test_reset_admin()
{
    $GLOBALS['fta'] = array(
        'caps' => array('manage_options' => true),
        'nonce_ok' => true,
        'nonce_checked' => array(),
        'transients' => array(),
        'enqueued' => array(),
        'remote' => array(),
        'remote_calls' => array(),
        'post_types' => array(
            'post' => (object) array('name' => 'post', 'has_archive' => false, 'labels' => (object) array('name' => 'Posts', 'singular_name' => 'Post')),
            'page' => (object) array('name' => 'page', 'has_archive' => false, 'labels' => (object) array('name' => 'Pages', 'singular_name' => 'Page')),
            'attachment' => (object) array('name' => 'attachment', 'has_archive' => false, 'labels' => (object) array('name' => 'Media', 'singular_name' => 'Media')),
            'book' => (object) array('name' => 'book', 'has_archive' => true, 'labels' => (object) array('name' => 'Books', 'singular_name' => 'Book')),
        ),
        'l10n' => null,
        'ext_object_cache' => true,
    );
}
faaaster_test_reset_admin();

/** Charge la traduction compilée d'une locale (null = anglais source). */
function faaaster_test_locale($locale)
{
    $GLOBALS['fta']['l10n'] = $locale
        ? require __DIR__ . '/../../../languages/faaaster-manager-plugin-' . $locale . '.l10n.php'
        : null;
}

function __($text, $domain = 'default')
{
    $l10n = $GLOBALS['fta']['l10n'];
    return ($l10n && $domain === 'faaaster-manager-plugin' && isset($l10n['messages'][$text])) ? $l10n['messages'][$text] : $text;
}

function _n($single, $plural, $number, $domain = 'default')
{
    $l10n = $GLOBALS['fta']['l10n'];
    $key = $single . "\0" . $plural;
    if ($l10n && $domain === 'faaaster-manager-plugin' && isset($l10n['messages'][$key])) {
        $forms = explode("\0", $l10n['messages'][$key]);
        return $forms[$number > 1 ? 1 : 0];
    }
    return $number == 1 ? $single : $plural;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html__($text, $domain = 'default')
{
    return esc_html(__($text, $domain));
}

function esc_attr__($text, $domain = 'default')
{
    return esc_attr(__($text, $domain));
}

function esc_url($url)
{
    return preg_match('#^(https?:)?//#', (string) $url) ? esc_attr($url) : '';
}

function esc_url_raw($url)
{
    return preg_match('#^https?://#', (string) $url) ? (string) $url : '';
}

function admin_url($path = '')
{
    return 'https://example.com/wp-admin/' . ltrim($path, '/');
}

function plugins_url($path, $plugin)
{
    return 'https://example.com/wp-content/mu-plugins/faaaster-manager-plugin/' . $path;
}

function wp_nonce_field($action)
{
    echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr($action) . '">';
}

function wp_nonce_url($url, $action)
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . '_wpnonce=nonce-' . $action;
}

function add_query_arg($args, $url)
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}

function check_admin_referer($action)
{
    $GLOBALS['fta']['nonce_checked'][] = $action;
    if (!$GLOBALS['fta']['nonce_ok']) {
        throw new FaaasterTestDie('nonce');
    }
    return 1;
}

function current_user_can($cap)
{
    return !empty($GLOBALS['fta']['caps'][$cap]);
}

function wp_die($message = '', $code = 500)
{
    throw new FaaasterTestDie((string) $message, (int) $code);
}

function wp_safe_redirect($location)
{
    throw new FaaasterTestRedirect($location);
}

function wp_get_referer()
{
    return false;
}

function selected($a, $b, $echo = true)
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function wp_unslash($value)
{
    return $value;
}

function get_transient($key)
{
    return isset($GLOBALS['fta']['transients'][$key]) ? $GLOBALS['fta']['transients'][$key] : false;
}

function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['fta']['transients'][$key] = $value;
    return true;
}

function delete_transient($key)
{
    unset($GLOBALS['fta']['transients'][$key]);
    return true;
}

function get_post_types($args = array(), $output = 'names')
{
    return $GLOBALS['fta']['post_types'];
}

function get_taxonomies($args = array(), $output = 'names')
{
    $objects = array();
    foreach ($GLOBALS['ft']['viewable_taxonomies'] as $name) {
        $objects[$name] = (object) array('name' => $name, 'labels' => (object) array('name' => ucfirst($name)));
    }
    return $objects;
}

function human_time_diff($from, $to = 0)
{
    return '5 mins';
}

function wp_remote_get($url, $args = array())
{
    $GLOBALS['fta']['remote_calls'][] = array($url, $args);
    return $GLOBALS['fta']['remote'] ? array_shift($GLOBALS['fta']['remote']) : array('response' => array('code' => 412), 'headers' => array());
}

function wp_remote_retrieve_header($response, $name)
{
    return isset($response['headers'][$name]) ? $response['headers'][$name] : '';
}

function wp_using_ext_object_cache()
{
    return $GLOBALS['fta']['ext_object_cache'];
}

function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false)
{
    $GLOBALS['fta']['enqueued'][] = $handle;
}

function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $footer = false)
{
    $GLOBALS['fta']['enqueued'][] = $handle;
}

function add_options_page($title, $menu, $cap, $slug, $callback)
{
    $GLOBALS['fta']['options_page'] = array($cap, $slug);
    return 'settings_page_' . $slug;
}

function is_ssl()
{
    return true;
}

function set_url_scheme($url)
{
    return $url;
}
