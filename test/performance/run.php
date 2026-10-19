<?php

/**
 * Tests du runtime performance, sans charger WordPress.
 * Exécution : php test/performance/run.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

// a WordPress tree of its own (wp-includes, plugins, themes, uploads) in a
// temporary directory, removed at the end
$perf_tmp = sys_get_temp_dir() . '/faaaster-perf-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
foreach (array('wp-includes', 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-content/themes', 'wp-content/uploads') as $perf_directory) {
    mkdir($perf_tmp . '/' . $perf_directory, 0777, true);
}
define('ABSPATH', $perf_tmp . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', $perf_tmp . '/wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
define('MINUTE_IN_SECONDS', 60);
ini_set('error_log', $perf_tmp . '/php-errors.log');

function perf_rmdir($directory)
{
    foreach ((array) scandir($directory) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0777);
            perf_rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}
register_shutdown_function(function () use ($perf_tmp) {
    if (is_dir($perf_tmp)) {
        perf_rmdir($perf_tmp);
    }
});
function perf_log() { return (string) @file_get_contents(ini_get('error_log')); }

$GLOBALS['perf_failures'] = 0;
$GLOBALS['perf_passes'] = 0;

function perf_check($label, $actual, $expected)
{
    if ($actual !== $expected) {
        $GLOBALS['perf_failures']++;
        fwrite(STDERR, "FAIL {$label}: got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . "\n");
        return;
    }
    $GLOBALS['perf_passes']++;
}

require_once __DIR__ . '/../cache/stubs/hooks.php';
require_once __DIR__ . '/../cache/stubs/wp.php';

$GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$GLOBALS['perf_styles'] = null;
$GLOBALS['perf_scripts'] = null;

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function is_admin() { return false; }
function is_home() { return !empty($GLOBALS['perf_context']['home']); }
function is_front_page() { return !empty($GLOBALS['perf_context']['front_page']); }
function is_singular($type = '')
{
    if (empty($GLOBALS['perf_context']['singular'])) {
        return false;
    }
    $object = $GLOBALS['perf_context']['object'] ?? null;
    // an object without post_type matches any type (the 0.13/0.14 fixtures)
    if ($type === '' || $type === array() || !is_object($object) || !isset($object->post_type)) {
        return true;
    }
    return in_array($object->post_type, (array) $type, true);
}
function is_archive() { return !empty($GLOBALS['perf_context']['archive']); }
function is_post_type_archive($types = '') { return in_array($GLOBALS['perf_context']['post_type_archive'] ?? null, (array) $types, true); }
function is_search() { return !empty($GLOBALS['perf_context']['search']); }
function is_404() { return !empty($GLOBALS['perf_context']['404']); }
/** The queried term among $term (slugs), any term when empty. */
function perf_term_matches($term)
{
    if ($term === '' || $term === array() || $term === null) {
        return true;
    }
    $object = $GLOBALS['perf_context']['object'] ?? null;
    return is_object($object) && isset($object->slug) && in_array($object->slug, (array) $term, true);
}
function is_tax($taxonomy = '', $term = '')
{
    $GLOBALS['perf_is_tax_calls'] = ($GLOBALS['perf_is_tax_calls'] ?? 0) + 1;
    $custom = array_diff($GLOBALS['perf_context']['taxonomies'] ?? array(), array('taxonomy:category', 'taxonomy:post_tag'));
    $hit = $taxonomy === '' ? (bool) $custom : in_array('taxonomy:' . $taxonomy, $custom, true);
    return $hit && perf_term_matches($term);
}
function is_category($term = '') { return in_array('taxonomy:category', $GLOBALS['perf_context']['taxonomies'] ?? array(), true) && perf_term_matches($term); }
function is_tag($term = '') { return in_array('taxonomy:post_tag', $GLOBALS['perf_context']['taxonomies'] ?? array(), true) && perf_term_matches($term); }
function is_page($page = null)
{
    $object = $GLOBALS['perf_context']['object'] ?? null;
    if (empty($GLOBALS['perf_context']['is_page']) || !is_object($object)) {
        return false;
    }
    if ($page === null || $page === '' || $page === array()) {
        return true;
    }
    foreach ((array) $page as $wanted) {
        if ((string) $wanted === (string) $object->ID || (isset($object->post_name) && $wanted === $object->post_name)) {
            return true;
        }
    }
    return false;
}
function is_user_logged_in()
{
    $GLOBALS['perf_logged_in_calls'] = ($GLOBALS['perf_logged_in_calls'] ?? 0) + 1;
    return !empty($GLOBALS['perf_context']['logged_in']);
}
function has_term($terms, $taxonomy, $post)
{
    $own = $GLOBALS['perf_post_terms'][is_object($post) ? (int) $post->ID : (int) $post][$taxonomy] ?? array();
    return (bool) array_intersect((array) $terms, $own);
}
function get_page_template_slug($post) { return $GLOBALS['perf_page_templates'][(int) $post->ID] ?? ''; }
function get_template() { return $GLOBALS['perf_template'] ?? 'astra-parent'; }
function get_post_type() { return null; }
function get_queried_object() { return $GLOBALS['perf_context']['object'] ?? null; }
function get_queried_object_id() { return isset($GLOBALS['perf_context']['object']->ID) ? (int) $GLOBALS['perf_context']['object']->ID : 0; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_styles() { return $GLOBALS['perf_styles']; }
function wp_scripts() { return $GLOBALS['perf_scripts']; }
function wp_dequeue_style($handle) { $GLOBALS['perf_dequeued']['style'][] = $handle; }
function wp_dequeue_script($handle) { $GLOBALS['perf_dequeued']['script'][] = $handle; }
function wp_script_add_data($handle, $key, $value) { $GLOBALS['perf_script_data'][$handle][$key] = $value; return true; }
function wp_enqueue_style($handle) { $GLOBALS['perf_enqueued_styles'][] = $handle; }
function esc_url($value) { return $value; }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$GLOBALS['fta']['transients'] = array();
function get_transient($key) { return $GLOBALS['fta']['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['fta']['transients'][$key] = $value; return true; }

function perf_canonical($value)
{
    if (!is_array($value)) return $value;
    $keys = array_keys($value);
    $is_list = $value === array() || $keys === range(0, count($value) - 1);
    if ($is_list) return array_map('perf_canonical', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = perf_canonical($item);
    return $value;
}

function perf_payload($rules, $revision = 1)
{
    $content = json_encode(
        perf_canonical(array('revision' => $revision, 'rules' => $rules)),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    return array(
        'schemaVersion' => 'faaaster-performance-runtime-v1',
        'revision' => $revision,
        'checksum' => hash('sha256', $content),
        'rules' => $rules,
    );
}

require_once __DIR__ . '/../../class/performance/settings.php';
require_once __DIR__ . '/../../class/performance/components.php';
require_once __DIR__ . '/../../class/performance/fonts.php';
require_once __DIR__ . '/../../class/performance/owners.php';
require_once __DIR__ . '/../../class/performance/conditionals.php';
require_once __DIR__ . '/../../class/performance/runtime.php';

$rules = array(
    array(
        'id' => 'script:defer-app',
        'type' => 'script_strategy',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('handles' => array('theme-app'), 'strategy' => 'defer'),
    ),
    array(
        'id' => 'unload:front',
        'type' => 'unload_unused_asset',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('assetType' => 'style', 'handles' => array('select2')),
    ),
    array(
        'id' => 'image:hero',
        'type' => 'image_delivery',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array(
            'attachmentIds' => array(42),
            'loading' => 'eager',
            'fetchPriority' => 'high',
        ),
    ),
);

$settings = new FaaasterPerformanceSettings();
$compiled = $settings->replace(perf_payload($rules));
perf_check('compiled revision', $compiled['revision'], 1);
perf_check('unload pre-indexed', count($compiled['compiled']['unload']['style']['select2']), 1);
perf_check('image pre-indexed', $compiled['compiled']['images']['42'][0]['fetchPriority'], 'high');
perf_check('script strategy pre-indexed', $compiled['compiled']['scriptStrategies']['theme-app'][0]['strategy'], 'defer');

$invalid = perf_payload($rules, 2);
$invalid['checksum'] = str_repeat('0', 64);
$rejected = false;
try {
    FaaasterPerformanceSettings::compile($invalid);
} catch (InvalidArgumentException $error) {
    $rejected = true;
}
perf_check('invalid checksum rejected', $rejected, true);

$dependency = (object) array('src' => '/select2.css', 'deps' => array());
$consumer = (object) array('src' => '/checkout.css', 'deps' => array('select2'));
$GLOBALS['perf_styles'] = (object) array(
    'queue' => array('select2', 'checkout'),
    'done' => array(),
    'registered' => array('select2' => $dependency, 'checkout' => $consumer),
);
$GLOBALS['perf_scripts'] = (object) array('queue' => array(), 'done' => array(), 'registered' => array());
$_SERVER['REQUEST_URI'] = '/';
$runtime = new FaaasterPerformanceRuntime($settings);
$runtime->unload_assets();
perf_check('dependency prevents unload', $GLOBALS['perf_dequeued']['style'], array());

$GLOBALS['perf_styles']->registered['checkout']->deps = array();
$runtime->unload_assets();
perf_check('matching asset unloaded', $GLOBALS['perf_dequeued']['style'], array('select2'));
$runtime->script_strategies();
perf_check('script strategy applied', $GLOBALS['perf_script_data']['theme-app']['strategy'], 'defer');

$attachment = (object) array('ID' => 42);
$attributes = $runtime->image_attributes(array('src' => '/hero.jpg', 'loading' => 'lazy'), $attachment, 'large');
perf_check('image loading overridden', $attributes['loading'], 'eager');
perf_check('image priority overridden', $attributes['fetchpriority'], 'high');

$_SERVER['REQUEST_URI'] = '/other/';
$attributes = $runtime->image_attributes(array('src' => '/hero.jpg', 'loading' => 'lazy'), $attachment, 'large');
perf_check('path mismatch untouched', $attributes['loading'], 'lazy');

// blog categories and tags: WordPress answers is_category()/is_tag(), not is_tax()
$taxonomy_rule = function ($taxonomy) {
    return array(
        'id' => 'unload:' . $taxonomy,
        'type' => 'unload_unused_asset',
        'contexts' => array('taxonomy:' . $taxonomy),
        'paths' => array(),
        'params' => array('assetType' => 'style', 'handles' => array('select2')),
    );
};
$GLOBALS['perf_styles'] = (object) array(
    'queue' => array('select2'),
    'done' => array(),
    'registered' => array('select2' => (object) array('src' => '/select2.css', 'deps' => array())),
);
$_SERVER['REQUEST_URI'] = '/category/vignerons/';
$taxonomy_revision = 9000;
foreach (array(
    array('category', array('taxonomy:category'), array('select2'), 'a rule on blog categories applies on a category archive'),
    array('post_tag', array('taxonomy:post_tag'), array('select2'), 'a rule on blog tags applies on a tag archive'),
    array('product_cat', array('taxonomy:product_cat'), array('select2'), 'a custom taxonomy still answers is_tax()'),
    array('category', array('taxonomy:product_cat'), array(), 'a category rule leaves a product category alone'),
) as $case) {
    list($taxonomy, $current, $expected, $label) = $case;
    $GLOBALS['perf_context'] = array('front_page' => false, 'singular' => false, 'archive' => true, 'taxonomies' => $current);
    $GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
    $taxonomy_settings = new FaaasterPerformanceSettings();
    $taxonomy_settings->replace(perf_payload(array($taxonomy_rule($taxonomy)), ++$taxonomy_revision));
    (new FaaasterPerformanceRuntime($taxonomy_settings))->unload_assets();
    perf_check($label, $GLOBALS['perf_dequeued']['style'], $expected);
}
$GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());

$many_rules = array();
$registered = array();
$queue = array();
for ($index = 0; $index < 200; $index++) {
    $handle = 'asset-' . $index;
    $many_rules[] = array(
        'id' => 'unload:rule-' . $index,
        'type' => 'unload_unused_asset',
        'contexts' => array('front_page'),
        'paths' => array('/not-this-page-' . $index),
        'params' => array('assetType' => 'style', 'handles' => array($handle)),
    );
    $registered[$handle] = (object) array('src' => '/' . $handle . '.css', 'deps' => array());
    $queue[] = $handle;
}
$memory_before = memory_get_usage();
$settings->replace(perf_payload($many_rules, 2));
$bench_runtime = new FaaasterPerformanceRuntime($settings);
$memory_delta = memory_get_usage() - $memory_before;
$GLOBALS['perf_styles'] = (object) array(
    'queue' => $queue,
    'done' => array(),
    'registered' => $registered,
);
$_SERVER['REQUEST_URI'] = '/';
$durations = array();
for ($index = 0; $index < 300; $index++) {
    $start = hrtime(true);
    $bench_runtime->unload_assets();
    $durations[] = (hrtime(true) - $start) / 1000000;
}
sort($durations);
$p95 = $durations[(int) floor(count($durations) * 0.95)];
perf_check('200 rules stay below 2 ms p95', $p95 < 2.0, true);
perf_check('200 rules stay below 1 MiB extra memory', $memory_delta < 1024 * 1024, true);
echo 'benchmark: p95=' . round($p95, 3) . 'ms, memory=' . $memory_delta . "B\n";

// ── Revisions only move forward ─────────────────────────────────────────────
$monotonic = new FaaasterPerformanceSettings();
$GLOBALS['faaaster_test']['options'] = array();
$monotonic->reset_cache();
$first = $monotonic->replace(perf_payload($rules, 5));
perf_check('first apply changes the pages', $first['changed'], true);
perf_check('changed flag never stored', array_key_exists('changed', get_option(FaaasterPerformanceSettings::OPTION)), false);
$replayed = $monotonic->replace(perf_payload($rules, 5));
perf_check('replaying the same payload is a no-op', $replayed['changed'], false);
$stale = null;
try {
    $monotonic->replace(perf_payload(array(), 4));
} catch (FaaasterPerformanceStaleRevision $error) {
    $stale = $error->current_revision;
}
perf_check('older revision refused', $stale, 5);
$stale = null;
try {
    $monotonic->replace(perf_payload(array_slice($rules, 0, 1), 5));
} catch (FaaasterPerformanceStaleRevision $error) {
    $stale = $error->current_revision;
}
perf_check('other content under the same revision refused', $stale, 5);
perf_check('refused payload left the live rules intact', count(get_option(FaaasterPerformanceSettings::OPTION)['rules']), 3);
$newer = $monotonic->replace(perf_payload(array_slice($rules, 0, 1), 6));
perf_check('newer revision applied', array($newer['revision'], $newer['changed']), array(6, true));

class Perf_Test_Request
{
    private $json;
    private $params;
    public function __construct($json = array(), $params = array())
    {
        $this->json = $json;
        $this->params = $params;
    }
    public function get_json_params() { return $this->json; }
    public function get_param($key) { return $this->params[$key] ?? null; }
}

require_once __DIR__ . '/../../class/performance/rest.php';
$rest = new FaaasterPerformanceRest($monotonic);
$refused = $rest->apply(new Perf_Test_Request(perf_payload($rules, 3)));
perf_check('REST apply refuses a stale revision', $refused instanceof WP_Error, true);
perf_check('REST stale refusal keeps the live rules', get_option(FaaasterPerformanceSettings::OPTION)['revision'], 6);

// ── Preview: a candidate set rendered for one request only, with receipts ──
$GLOBALS['fta']['transients'] = array();
$preview_token = str_repeat('ab', 24);
$preview_rules = array(
    array(
        'id' => 'unload:preview-only',
        'type' => 'unload_unused_asset',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('assetType' => 'style', 'handles' => array('preview-only')),
    ),
    array(
        'id' => 'script:defer-preview',
        'type' => 'script_strategy',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('handles' => array('theme-app'), 'strategy' => 'defer'),
    ),
);
$begun = $rest->begin_preview(new Perf_Test_Request(array(
    'token' => $preview_token,
    'paths' => array('/', '/'),
    'compiled' => perf_payload($preview_rules, 1),
)));
perf_check('preview session registered', is_array($begun) ? $begun['paths'] : $begun, array('/'));
perf_check(
    'preview with an invalid rule set refused',
    $rest->begin_preview(new Perf_Test_Request(array(
        'token' => $preview_token,
        'paths' => array('/'),
        'compiled' => array_merge(perf_payload($preview_rules, 1), array('checksum' => str_repeat('0', 64))),
    ))) instanceof WP_Error,
    true
);
perf_check(
    'preview path with a query refused',
    $rest->begin_preview(new Perf_Test_Request(array(
        'token' => $preview_token,
        'paths' => array('/?x=1'),
        'compiled' => perf_payload($preview_rules, 1),
    ))) instanceof WP_Error,
    true
);
perf_check('live rules untouched by a preview', get_option(FaaasterPerformanceSettings::OPTION)['revision'], 6);

faaaster_test_reset_hooks();
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$GLOBALS['perf_script_data'] = array();
$GLOBALS['perf_styles'] = (object) array(
    'queue' => array('preview-only', 'select2'),
    'done' => array(),
    'registered' => array(
        'preview-only' => (object) array('src' => '/preview.css', 'deps' => array()),
        'select2' => (object) array('src' => '/select2.css', 'deps' => array()),
    ),
);
$_SERVER['REQUEST_URI'] = '/?faaaster_perf_preview=' . $preview_token;
$_GET = array('faaaster_perf_preview' => $preview_token);
$live_with_select2 = new FaaasterPerformanceSettings();
$live_with_select2->replace(perf_payload($rules, 7));
$preview_runtime = new FaaasterPerformanceRuntime($live_with_select2);
$preview_runtime->register();
$preview_runtime->unload_assets();
$preview_runtime->script_strategies();
perf_check('preview set replaces the live set', $GLOBALS['perf_dequeued']['style'], array('preview-only'));
perf_check('preview strategy applied', $GLOBALS['perf_script_data']['theme-app']['strategy'], 'defer');
apply_filters('style_loader_tag', '<link rel="stylesheet" href="/select2.css">', 'select2', '/select2.css', 'all');
apply_filters(
    'script_loader_tag',
    '<script id="theme-app-js-before">var defer = 1;</script>' . "\n"
        . '<script src="/app.js" id="theme-app-js" defer data-wp-strategy="defer"></script>',
    'theme-app',
    '/app.js'
);
apply_filters('script_loader_tag', '<script src="/legacy.js" id="legacy-js" data-wp-strategy="defer"></script>', 'legacy', '/legacy.js');
do_action('shutdown');
$receipt = get_transient(FaaasterPerformanceRuntime::preview_receipt_key($preview_token, '/'));
perf_check('preview receipt recorded', is_array($receipt), true);
perf_check('receipt binds the preview set', $receipt['checksum'], perf_payload($preview_rules, 1)['checksum']);
perf_check('receipt lists the dequeued handle', $receipt['dequeued']['style'], array('preview-only'));
perf_check('receipt lists the removed source', $receipt['dequeuedSources'], array('https://example.com/preview.css'));
perf_check('receipt lists the printed styles', $receipt['printed']['style'], array('select2'));
perf_check('receipt reads defer on the external script only', $receipt['scriptLoading']['theme-app'], 'defer');
perf_check('data-wp-strategy alone is not a strategy', $receipt['scriptLoading']['legacy'], 'blocking');
$result = $rest->preview_result(new Perf_Test_Request(array(), array('token' => $preview_token)));
perf_check('preview result returns the receipts', count($result['receipts']), 1);

// random content: the same draws on every rendering of the page under test
$seed = FaaasterPerformanceRuntime::preview_seed('/');
mt_srand($seed);
$expected_draws = array(mt_rand(), mt_rand(), mt_rand());
faaaster_test_reset_hooks();
$seeded = new FaaasterPerformanceRuntime($live_with_select2);
$seeded->register();
perf_check('a preview seeds the PHP draws with its page', array(mt_rand(), mt_rand(), mt_rand()), $expected_draws);
perf_check(
    'a preview seeds ORDER BY RAND()',
    apply_filters('posts_orderby', 'wp_posts.menu_order ASC, RAND()'),
    'wp_posts.menu_order ASC, RAND(' . $seed . ')'
);
perf_check('an explicit seed is left alone', apply_filters('posts_orderby', 'RAND(7)'), 'RAND(7)');
perf_check('another page draws its own seed', FaaasterPerformanceRuntime::preview_seed('/shop/') !== $seed, true);
faaaster_test_reset_hooks();
$_SERVER['REQUEST_URI'] = '/';
$_GET = array();
$visitor = new FaaasterPerformanceRuntime($live_with_select2);
$visitor->register();
perf_check('a visitor request is never seeded', apply_filters('posts_orderby', 'RAND()'), 'RAND()');
$_SERVER['REQUEST_URI'] = '/?faaaster_perf_preview=' . $preview_token;
$_GET = array('faaaster_perf_preview' => $preview_token);

faaaster_test_reset_hooks();
$_SERVER['REQUEST_URI'] = '/other/?faaaster_perf_preview=' . $preview_token;
$undeclared = new FaaasterPerformanceRuntime($live_with_select2);
$undeclared->register();
do_action('shutdown');
perf_check('undeclared path writes no receipt', get_transient(FaaasterPerformanceRuntime::preview_receipt_key($preview_token, '/other/')), false);

faaaster_test_reset_hooks();
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$_SERVER['REQUEST_URI'] = '/?faaaster_perf_preview=' . str_repeat('cd', 24);
$_GET = array('faaaster_perf_preview' => str_repeat('cd', 24));
$expired = new FaaasterPerformanceRuntime($live_with_select2);
$expired->register();
$expired->unload_assets();
perf_check('unknown preview token renders the live rules', $GLOBALS['perf_dequeued']['style'], array('select2'));
$_GET = array();

// ── Late stylesheets: detection, promotion to the <head>, receipts ─────────
$promote_rules = array(
    array(
        'id' => 'promote:widgets',
        'type' => 'promote_late_style',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('handles' => array('widget-slider', 'widget-ghost')),
    ),
);
$promote_compiled = FaaasterPerformanceSettings::compile(perf_payload($promote_rules, 1));
perf_check('promote rule pre-indexed', count($promote_compiled['compiled']['promoteStyles']['widget-slider']), 1);
$empty_handles = false;
try {
    FaaasterPerformanceSettings::compile(perf_payload(array(array(
        'id' => 'promote:empty',
        'type' => 'promote_late_style',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('handles' => array()),
    )), 1));
} catch (InvalidArgumentException $error) {
    $empty_handles = true;
}
perf_check('promote rule without handle refused', $empty_handles, true);

faaaster_test_reset_hooks();
$GLOBALS['fta']['transients'] = array();
$GLOBALS['perf_enqueued_styles'] = array();
$promote_token = str_repeat('ef', 24);
$rest->begin_preview(new Perf_Test_Request(array(
    'token' => $promote_token,
    'paths' => array('/'),
    'compiled' => perf_payload($promote_rules, 1),
)));
$GLOBALS['perf_styles'] = (object) array(
    'queue' => array('theme'),
    'done' => array(),
    'registered' => array(
        'theme' => (object) array('src' => 'https://example.com/theme.css', 'deps' => array()),
        'widget-slider' => (object) array('src' => 'https://example.com/slider.css', 'deps' => array()),
        'widget-inline' => (object) array(
            'src' => 'https://example.com/inline.css',
            'deps' => array(),
            'extra' => array('after' => array('.x{color:red}')),
        ),
    ),
);
$_SERVER['REQUEST_URI'] = '/?faaaster_perf_preview=' . $promote_token;
$_GET = array('faaaster_perf_preview' => $promote_token);
$promoting = new FaaasterPerformanceRuntime($live_with_select2);
$promoting->register();
$promoting->promote_late_styles();
perf_check('only a registered late style is promoted', $GLOBALS['perf_enqueued_styles'], array('widget-slider'));
apply_filters('style_loader_tag', '<link rel="stylesheet" href="https://example.com/theme.css">', 'theme', '', 'all');
apply_filters('style_loader_tag', '<link rel="stylesheet" href="https://example.com/slider.css">', 'widget-slider', '', 'all');
do_action('wp_head');
apply_filters('style_loader_tag', '<link rel="stylesheet" href="https://example.com/inline.css">', 'widget-inline', '', 'all');
do_action('shutdown');
$promote_receipt = get_transient(FaaasterPerformanceRuntime::preview_receipt_key($promote_token, '/'));
perf_check('receipt lists the promoted handle', $promote_receipt['promoted']['style'], array('widget-slider'));
perf_check('receipt lists only the styles printed after the head', $promote_receipt['late']['style'], array('widget-inline'));

faaaster_test_reset_hooks();
$audit_token = str_repeat('12', 24);
$GLOBALS['fta']['transients'][FaaasterPerformanceRuntime::audit_transient_key($audit_token)] = array(
    'paths' => array('/'),
    'snapshots' => array(),
    'createdAt' => time(),
);
$GLOBALS['perf_styles']->queue = array();
$GLOBALS['perf_styles']->done = array('theme', 'widget-slider', 'widget-inline');
$GLOBALS['perf_enqueued_styles'] = array();
$_SERVER['REQUEST_URI'] = '/?faaaster_perf_audit=' . $audit_token;
$_GET = array('faaaster_perf_audit' => $audit_token, 'faaaster_perf_preview' => $promote_token);
$scripts_before_audit = $GLOBALS['perf_scripts'];
$GLOBALS['perf_scripts'] = (object) array(
    'queue' => array('slider', 'cart'),
    'done' => array('jquery-core', 'jquery-migrate', 'jquery', 'slider', 'cart'),
    'registered' => array(
        'jquery-core' => (object) array('src' => '/wp-includes/js/jquery/jquery.min.js', 'deps' => array(), 'extra' => array()),
        'jquery-migrate' => (object) array('src' => '/wp-includes/js/jquery/jquery-migrate.min.js', 'deps' => array(), 'extra' => array()),
        'jquery' => (object) array('src' => false, 'deps' => array('jquery-core', 'jquery-migrate'), 'extra' => array()),
        'slider' => (object) array('src' => '/slider.js', 'deps' => array('jquery'), 'extra' => array('strategy' => 'defer')),
        'cart' => (object) array('src' => '/cart.js', 'deps' => array('jquery'), 'extra' => array('after' => array('cart.init();'))),
    ),
);
$auditing = new FaaasterPerformanceRuntime($live_with_select2);
$auditing->register();
$auditing->promote_late_styles();
perf_check('audit request promotes nothing', $GLOBALS['perf_enqueued_styles'], array());
apply_filters('style_loader_tag', '<link rel="stylesheet" href="https://example.com/theme.css">', 'theme', '', 'all');
do_action('wp_head');
apply_filters('style_loader_tag', '<link rel="stylesheet" href="https://example.com/slider.css">', 'widget-slider', '', 'all');
apply_filters('style_loader_tag', '<link rel="stylesheet" href="https://example.com/inline.css">', 'widget-inline', '', 'all');
do_action('shutdown');
$audit_snapshot = get_transient(FaaasterPerformanceRuntime::audit_transient_key($audit_token))['snapshots']['/'];
$late_flags = array();
foreach ($audit_snapshot['assets'] as $asset) {
    if ($asset['type'] === 'style') {
        $late_flags[$asset['handle']] = array($asset['late'], $asset['inlineAfter']);
    }
}
perf_check('audit snapshot flags late and inline-extended styles', $late_flags, array(
    'theme' => array(false, false),
    'widget-slider' => array(true, false),
    'widget-inline' => array(true, true),
));
perf_check('audit snapshot records the script graph, aliases included', $audit_snapshot['scripts'], array(
    array('handle' => 'slider', 'deps' => array('jquery'), 'alias' => false, 'inlineAfter' => false, 'strategy' => 'defer'),
    array('handle' => 'cart', 'deps' => array('jquery'), 'alias' => false, 'inlineAfter' => true, 'strategy' => null),
    array('handle' => 'jquery-core', 'deps' => array(), 'alias' => false, 'inlineAfter' => false, 'strategy' => null),
    array('handle' => 'jquery-migrate', 'deps' => array(), 'alias' => false, 'inlineAfter' => false, 'strategy' => null),
    array('handle' => 'jquery', 'deps' => array('jquery-core', 'jquery-migrate'), 'alias' => true, 'inlineAfter' => false, 'strategy' => null),
));
$GLOBALS['perf_scripts'] = $scripts_before_audit;
$_GET = array();

// ── Google Fonts display=swap ──────────────────────────────────────────────
perf_check(
    'swap appended, repeated family parameters kept',
    FaaasterPerformanceRuntime::google_fonts_swap('https://fonts.googleapis.com/css2?family=Inter:wght@400&family=Lora'),
    'https://fonts.googleapis.com/css2?family=Inter:wght@400&family=Lora&display=swap'
);
perf_check(
    'existing display replaced',
    FaaasterPerformanceRuntime::google_fonts_swap('//fonts.googleapis.com/css?family=Open+Sans&display=auto&ver=6'),
    '//fonts.googleapis.com/css?family=Open+Sans&display=swap&ver=6'
);
perf_check(
    'other hosts untouched',
    FaaasterPerformanceRuntime::google_fonts_swap('https://example.com/fonts.css?family=x'),
    'https://example.com/fonts.css?family=x'
);

$font_rules = array(
    array(
        'id' => 'font:google',
        'type' => 'font_delivery',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array(
            'preloadUrls' => array(),
            'preconnectOrigins' => array('https://fonts.gstatic.com'),
            'metricOverrides' => array(),
            'displaySwapHandles' => array('theme-fonts'),
        ),
    ),
    array(
        'id' => 'preconnect:cdn',
        'type' => 'preconnect_origins',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('origins' => array(
            array('origin' => 'https://cdn.example.net', 'crossorigin' => false),
            array('origin' => 'https://use.typekit.net', 'crossorigin' => true),
        )),
    ),
);
$bad_preconnect = false;
try {
    FaaasterPerformanceSettings::compile(perf_payload(array(array(
        'id' => 'preconnect:bad',
        'type' => 'preconnect_origins',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('origins' => array(array('origin' => 'https://cdn.example.net/path', 'crossorigin' => false))),
    )), 1));
} catch (InvalidArgumentException $error) {
    $bad_preconnect = true;
}
perf_check('preconnect to a path refused', $bad_preconnect, true);
$loose_mode = false;
try {
    FaaasterPerformanceSettings::compile(perf_payload(array(array(
        'id' => 'preconnect:loose',
        'type' => 'preconnect_origins',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('origins' => array(array('origin' => 'https://cdn.example.net', 'crossorigin' => 'yes'))),
    )), 1));
} catch (InvalidArgumentException $error) {
    $loose_mode = true;
}
perf_check('non-boolean crossorigin refused', $loose_mode, true);

faaaster_test_reset_hooks();
$GLOBALS['fta']['transients'] = array();
$font_token = str_repeat('34', 24);
$rest->begin_preview(new Perf_Test_Request(array(
    'token' => $font_token,
    'paths' => array('/'),
    'compiled' => perf_payload($font_rules, 1),
)));
$_SERVER['REQUEST_URI'] = '/?faaaster_perf_preview=' . $font_token;
$_GET = array('faaaster_perf_preview' => $font_token);
$fonts_runtime = new FaaasterPerformanceRuntime($live_with_select2);
$fonts_runtime->register();
perf_check(
    'preview swaps the targeted Google Fonts handle',
    apply_filters('style_loader_src', 'https://fonts.googleapis.com/css2?family=Inter', 'theme-fonts'),
    'https://fonts.googleapis.com/css2?family=Inter&display=swap'
);
perf_check(
    'other handles keep their source',
    apply_filters('style_loader_src', 'https://fonts.googleapis.com/css2?family=Lora', 'plugin-fonts'),
    'https://fonts.googleapis.com/css2?family=Lora'
);
perf_check('preconnect hints with their CORS mode', apply_filters('wp_resource_hints', array(), 'preconnect'), array(
    array('href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'),
    array('href' => 'https://cdn.example.net'),
    array('href' => 'https://use.typekit.net', 'crossorigin' => 'anonymous'),
));
do_action('shutdown');
$font_receipt = get_transient(FaaasterPerformanceRuntime::preview_receipt_key($font_token, '/'));
perf_check('receipt lists the swapped handle', $font_receipt['fonts']['swapped'], array('theme-fonts'));
perf_check('receipt counts the preconnects', $font_receipt['preconnects'], 3);

// ── Content images (core/image, templates) through the real HTML API ───────
$html_api = getenv('WP_HTML_API_DIR') ?: (getenv('HOME') . '/Ressources/wordpress/wp-includes/html-api');
if (is_file($html_api . '/class-wp-html-tag-processor.php')) {
    if (!function_exists('__')) {
        function __($text) { return $text; }
    }
    if (!function_exists('_doing_it_wrong')) {
        function _doing_it_wrong() {}
    }
    foreach (array('class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-tag-processor') as $file) {
        require_once $html_api . '/' . $file . '.php';
    }
    $image_rules = array(array(
        'id' => 'image:lcp',
        'type' => 'image_delivery',
        'contexts' => array('front_page'),
        'paths' => array('/'),
        'params' => array('attachmentIds' => array(77), 'loading' => 'eager', 'fetchPriority' => 'high'),
    ));
    faaaster_test_reset_hooks();
    $image_token = str_repeat('56', 24);
    $rest->begin_preview(new Perf_Test_Request(array(
        'token' => $image_token,
        'paths' => array('/'),
        'compiled' => perf_payload($image_rules, 1),
    )));
    $_SERVER['REQUEST_URI'] = '/?faaaster_perf_preview=' . $image_token;
    $_GET = array('faaaster_perf_preview' => $image_token);
    $images_runtime = new FaaasterPerformanceRuntime($live_with_select2);
    $images_runtime->register();
    $lazy = '<img src="https://site.test/hero-1024x683.jpg" class="wp-image-77" loading="lazy" decoding="async">';
    $tag = apply_filters('wp_content_img_tag', $lazy, 'the_content', 77);
    perf_check('content LCP image loses lazy loading', strpos($tag, 'loading="eager"') !== false, true);
    perf_check('content LCP image gets high priority', strpos($tag, 'fetchpriority="high"') !== false, true);
    perf_check('other content images untouched', apply_filters('wp_content_img_tag', $lazy, 'the_content', 78), $lazy);
    do_action('shutdown');
    $image_receipt = get_transient(FaaasterPerformanceRuntime::preview_receipt_key($image_token, '/'));
    perf_check('receipt lists the prioritised attachment', $image_receipt['images'], array(77));

    faaaster_test_reset_hooks();
    $audit_images_token = str_repeat('78', 24);
    $GLOBALS['fta']['transients'][FaaasterPerformanceRuntime::audit_transient_key($audit_images_token)] = array(
        'paths' => array('/'),
        'snapshots' => array(),
        'createdAt' => time(),
    );
    $_SERVER['REQUEST_URI'] = '/?faaaster_perf_audit=' . $audit_images_token;
    $_GET = array('faaaster_perf_audit' => $audit_images_token);
    $GLOBALS['perf_styles']->done = array();
    $images_audit = new FaaasterPerformanceRuntime($live_with_select2);
    $images_audit->register();
    apply_filters(
        'wp_content_img_tag',
        '<img src="https://site.test/hero.jpg" srcset="https://site.test/hero.jpg 1600w, https://site.test/hero-1024x683.jpg 1024w" loading="lazy">',
        'the_content',
        77
    );
    do_action('shutdown');
    $audit_images = get_transient(FaaasterPerformanceRuntime::audit_transient_key($audit_images_token))['snapshots']['/']['images'];
    perf_check('audit records every URL of a content image', $audit_images, array(array(
        'attachmentId' => 77,
        'src' => 'https://site.test/hero.jpg',
        'urls' => array('https://site.test/hero.jpg', 'https://site.test/hero-1024x683.jpg'),
    )));
    $_GET = array();
} else {
    echo "SKIP content images: WordPress HTML API not found (set WP_HTML_API_DIR)\n";
}

// ── Inventory: templates with sample pages, active plugins ─────────────────
$GLOBALS['perf_posts'] = array(
    'post' => array(11 => '/blog/a/', 12 => '/blog/b/', 13 => '/blog/c/'),
    'page' => array(21 => '/contact/', 22 => '/panier/', 23 => '/accueil/', 24 => '/a-propos/', 25 => '/boutique/'),
    'product' => array(31 => '/produit/chaise/', 32 => '/produit/table/'),
    'event' => array(41 => '/evenements/salon/'),
);
function get_post_types($args, $output) { return array('post', 'page', 'attachment', 'event', 'product'); }
function wc_get_page_id($name)
{
    // the checkout page is the home page: never listed as a shop page
    $ids = array('shop' => 25, 'cart' => 22, 'checkout' => 23);
    return isset($ids[$name]) ? $ids[$name] : -1;
}
$GLOBALS['faaaster_test']['options']['page_on_front'] = 23;
function post_type_exists($type) { return isset($GLOBALS['perf_posts'][$type]); }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat'; }
function get_posts($args)
{
    $type = is_array($args['post_type']) ? $args['post_type'][0] : $args['post_type'];
    if (!empty($args['meta_key'])) {
        return array();
    }
    return array_slice(array_keys($GLOBALS['perf_posts'][$type] ?? array()), 0, $args['numberposts']);
}
function get_permalink($id)
{
    $id = is_object($id) ? (int) $id->ID : (int) $id;
    if (isset($GLOBALS['perf_scan_links'][$id])) {
        return $GLOBALS['perf_scan_links'][$id];
    }
    foreach ($GLOBALS['perf_posts'] as $posts) {
        if (isset($posts[$id])) {
            return 'https://example.com' . $posts[$id];
        }
    }
    return false;
}
function get_taxonomies($args, $output) { return array('category', 'post_format', 'product_cat'); }
function get_terms($args)
{
    $slugs = $args['taxonomy'] === 'category' ? array('vins') : array('chaises', 'tables');
    return array_map(function ($slug) use ($args) {
        return (object) array('slug' => $slug, 'taxonomy' => $args['taxonomy']);
    }, $slugs);
}
function get_term_link($term)
{
    return 'https://example.com/' . ($term->taxonomy === 'category' ? 'rubrique' : 'categorie') . '/' . $term->slug . '/';
}
function get_stylesheet() { return 'astra'; }
function wp_count_terms($args) { return $args['taxonomy'] === 'category' ? 1 : 2; }
function get_post_type_object($type)
{
    return (object) array('has_archive' => in_array($type, array('product', 'event'), true));
}
function get_post_type_archive_link($type)
{
    return $type === 'product' ? 'https://example.com/boutique/' : 'https://example.com/evenements/';
}
function get_post($id)
{
    $id = (int) $id;
    return (object) array('ID' => $id, 'post_type' => 'page', 'post_name' => 'p' . $id, 'post_parent' => 0, 'filter' => 'raw');
}
function get_bloginfo($what) { return $what === 'version' ? '6.8.3' : ''; }
class Perf_Test_Theme
{
    private $slug;
    private $version;
    private $parent;
    public function __construct($slug, $version, $parent = null)
    {
        $this->slug = $slug;
        $this->version = $version;
        $this->parent = $parent;
    }
    public function get($key) { return $key === 'Version' ? $this->version : ''; }
    public function get_stylesheet() { return $this->slug; }
    public function parent() { return $this->parent; }
}
function wp_get_theme() { return new Perf_Test_Theme('astra', '4.8.1', new Perf_Test_Theme('astra-parent', '4.8.0')); }
function get_plugins()
{
    return array(
        'woocommerce/woocommerce.php' => array('Version' => '9.3.1'),
        'complianz-gdpr/complianz-gpdr.php' => array('Version' => '7.1'),
        'hello.php' => array('Version' => '1.7.2'),
    );
}
$GLOBALS['faaaster_test']['options']['active_plugins'] = array('woocommerce/woocommerce.php', 'complianz-gdpr/complianz-gpdr.php', 'hello.php');
$inventory = $rest->inventory();
perf_check('inventory v2: capabilities announced', $inventory['capabilities'], array('inventory-v2'));
perf_check('inventory v2: terms with contents per public taxonomy', (array) $inventory['taxonomyCounts'], array('product_cat' => 2, 'category' => 1));
perf_check('inventory v2: archive pages of the types that have one', $inventory['archives'], array(
    array('postType' => 'product', 'path' => '/boutique/'),
    array('postType' => 'event', 'path' => '/evenements/'),
));
perf_check('inventory v2: versions of WordPress, the theme and each active extension', $inventory['versions'], array(
    'wordpress' => '6.8.3',
    'theme' => array('slug' => 'astra', 'version' => '4.8.1', 'parent' => 'astra-parent', 'parentVersion' => '4.8.0'),
    'plugins' => array('complianz-gdpr' => '7.1', 'hello' => '1.7.2', 'woocommerce' => '9.3.1'),
));
perf_check('inventory v2: without a database, no class counted', $inventory['classCounts'], array());
perf_check('inventory: every public content type is a template, shop pages apart', $inventory['templates'], array(
    array('kind' => 'home', 'context' => 'front_page', 'paths' => array('/')),
    array('kind' => 'article', 'context' => 'post_type:post', 'paths' => array('/blog/a/', '/blog/b/', '/blog/c/')),
    array('kind' => 'page', 'context' => 'post_type:page', 'paths' => array('/contact/', '/a-propos/'), 'special' => array('/boutique/', '/panier/')),
    array('kind' => 'product', 'context' => 'post_type:product', 'paths' => array('/produit/chaise/', '/produit/table/')),
    array('kind' => 'event', 'context' => 'post_type:event', 'paths' => array('/evenements/salon/')),
    array('kind' => 'product_category', 'context' => 'taxonomy:product_cat', 'paths' => array('/categorie/chaises/', '/categorie/tables/')),
    array('kind' => 'category', 'context' => 'taxonomy:category', 'paths' => array('/rubrique/vins/')),
));
perf_check('inventory lists active plugin slugs', $inventory['plugins'], array('woocommerce', 'complianz-gdpr', 'hello'));
perf_check('inventory: one measured page per template', array_column($inventory['representatives'], 'path'), array(
    '/', '/blog/a/', '/contact/', '/produit/chaise/', '/evenements/salon/', '/categorie/chaises/', '/rubrique/vins/',
));
perf_check('a session lifetime asked by Next stays within bounds', array(
    FaaasterPerformanceRuntime::session_ttl(null, 900),
    FaaasterPerformanceRuntime::session_ttl(300, 900),
    FaaasterPerformanceRuntime::session_ttl(3600, 900),
    FaaasterPerformanceRuntime::session_ttl(99999, 900),
), array(900, 900, 3600, 21600));

// ═══ 0.14.0: content conditions, group unloads, adapters, font variants ═══

function perf_rule($id, $type, $params, $unless = null, $contexts = array('singular'), $paths = array())
{
    $rule = array('id' => $id, 'type' => $type, 'contexts' => $contexts, 'paths' => $paths);
    if ($unless !== null) {
        $rule['unless'] = $unless;
    }
    $rule['params'] = $params;
    return $rule;
}

function perf_refused(array $rules)
{
    try {
        FaaasterPerformanceSettings::compile(perf_payload($rules, 1));
    } catch (InvalidArgumentException $error) {
        return true;
    }
    return false;
}

/** Live rules replaced for one runtime: revisions only move forward. */
function perf_revision()
{
    static $revision = 50000;
    return ++$revision;
}
function perf_live(array $rules)
{
    $settings = new FaaasterPerformanceSettings();
    $settings->replace(perf_payload($rules, perf_revision()));
    return $settings;
}

/** A preview session for $rules on $path, the request pointed at it. */
function perf_preview(array $rules, $path, $token)
{
    global $rest;
    faaaster_test_reset_hooks();
    $GLOBALS['fta']['transients'] = array();
    $rest->begin_preview(new Perf_Test_Request(array(
        'token' => $token,
        'paths' => array($path),
        'compiled' => perf_payload($rules, 1),
    )));
    $_SERVER['REQUEST_URI'] = $path . '?faaaster_perf_preview=' . $token;
    $_GET = array('faaaster_perf_preview' => $token);
}

function perf_receipt($path, $token)
{
    do_action('shutdown');
    return get_transient(FaaasterPerformanceRuntime::preview_receipt_key($token, $path));
}

function get_post_meta($id, $key, $single = false)
{
    $GLOBALS['perf_meta_reads'] = ($GLOBALS['perf_meta_reads'] ?? 0) + 1;
    return $GLOBALS['perf_meta'][$id][$key] ?? '';
}
function is_active_widget($callback = false, $widget_id = false, $id_base = false, $skip_inactive = true)
{
    $GLOBALS['perf_widget_reads'] = ($GLOBALS['perf_widget_reads'] ?? 0) + 1;
    return in_array($id_base, $GLOBALS['perf_active_widgets'] ?? array(), true) ? 'sidebar-1' : false;
}
function is_product() { return !empty($GLOBALS['perf_context']['product']); }
function wc_get_product($id) { return $GLOBALS['perf_products'][$id] ?? false; }
function parse_blocks($content) { return $GLOBALS['perf_parsed_blocks'][md5($content)] ?? array(); }

class Perf_Test_Product
{
    private $id;
    private $image;
    private $gallery;
    private $type;
    public function __construct($id, $image, $gallery = array(), $type = 'simple')
    {
        $this->id = $id;
        $this->image = $image;
        $this->gallery = $gallery;
        $this->type = $type;
    }
    public function get_id() { return $this->id; }
    public function get_image_id() { return $this->image; }
    public function get_gallery_image_ids() { return $this->gallery; }
    public function get_type() { return $this->type; }
}

// ── 11.1 `unless`: compile ─────────────────────────────────────────────────
$unload_pwf = array('assetType' => 'style', 'handles' => array('pwf-filters'));
$every_kind = array(
    array('kind' => 'has_block', 'value' => 'stackable/columns'),
    array('kind' => 'has_shortcode', 'value' => 'pwf_filter'),
    array('kind' => 'active_widget', 'value' => 'woocommerce_price_filter'),
    array('kind' => 'product_type', 'value' => 'grouped'),
    array('kind' => 'elementor_widget', 'value' => 'image-carousel'),
    array('kind' => 'post_ids', 'value' => array(12, 7, 12)),
);
$unless_compiled = FaaasterPerformanceSettings::compile(perf_payload(array(
    perf_rule('unload:pwf', 'unload_unused_asset', $unload_pwf, $every_kind),
    perf_rule('unload:plain', 'unload_unused_asset', array('assetType' => 'style', 'handles' => array('select2'))),
    perf_rule('unload:empty-unless', 'unload_unused_asset', array('assetType' => 'script', 'handles' => array('slick')), array()),
), 1));
perf_check('unless: every kind compiles', count($unless_compiled['rules'][0]['unless']), 6);
perf_check('unless: post ids deduplicated', $unless_compiled['rules'][0]['unless'][5]['value'], array(12, 7));
perf_check(
    'unless: the clean rule keeps it between paths and params',
    array_keys($unless_compiled['rules'][0]),
    array('id', 'type', 'contexts', 'paths', 'unless', 'params')
);
perf_check('unless: absent from a rule without conditions', array_keys($unless_compiled['rules'][1]), array('id', 'type', 'contexts', 'paths', 'params'));
perf_check('unless: an empty list is dropped', array_key_exists('unless', $unless_compiled['rules'][2]), false);
$pwf_target = $unless_compiled['compiled']['unload']['style']['pwf-filters'][0];
perf_check('unless: the compiled target keeps the rule id and its conditions', array($pwf_target['rule'], count($pwf_target['unless'])), array('unload:pwf', 6));
perf_check('unless: a target without conditions keeps its rule id only', $unless_compiled['compiled']['unload']['style']['select2'][0], array(
    'contexts' => array('singular'),
    'paths' => array(),
    'rule' => 'unload:plain',
));
foreach (array(
    'unknown kind' => array(array('kind' => 'has_class', 'value' => 'pwf')),
    'block without namespace' => array(array('kind' => 'has_block', 'value' => 'columns')),
    'shortcode with a space' => array(array('kind' => 'has_shortcode', 'value' => 'pwf filter')),
    'upper-case widget' => array(array('kind' => 'active_widget', 'value' => 'Price_Filter')),
    'product type too long' => array(array('kind' => 'product_type', 'value' => str_repeat('a', 41))),
    'elementor widget with a dot' => array(array('kind' => 'elementor_widget', 'value' => 'image.carousel')),
    'empty post ids' => array(array('kind' => 'post_ids', 'value' => array())),
    'post id zero' => array(array('kind' => 'post_ids', 'value' => array(0))),
    'negative post id' => array(array('kind' => 'post_ids', 'value' => array(-3))),
    'post id as text' => array(array('kind' => 'post_ids', 'value' => array('abc'))),
    'post ids not a list' => array(array('kind' => 'post_ids', 'value' => 'all')),
    '201 post ids' => array(array('kind' => 'post_ids', 'value' => range(1, 201))),
    '11 conditions' => array_fill(0, 11, array('kind' => 'has_shortcode', 'value' => 'pwf_filter')),
    'a condition not an object' => array('has_shortcode'),
) as $label => $unless) {
    perf_check('unless refused: ' . $label, perf_refused(array(perf_rule('unload:bad', 'unload_unused_asset', $unload_pwf, $unless))), true);
}
perf_check('unless refused: not a list', perf_refused(array(perf_rule('unload:bad', 'unload_unused_asset', $unload_pwf, 'has_shortcode'))), true);
perf_check('unless: 200 post ids accepted', perf_refused(array(perf_rule('unload:ids', 'unload_unused_asset', $unload_pwf, array(
    array('kind' => 'post_ids', 'value' => range(1, 200)),
)))), false);

// ── 11.1 `unless`: runtime ─────────────────────────────────────────────────
$GLOBALS['shortcode_tags'] = array('pwf_filter' => 'cb', 'gallery' => 'cb', 'woosg' => 'cb');
$filters_page = (object) array(
    'ID' => 12,
    'post_type' => 'page',
    'post_content' => "<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->[pwf_filter id=\"3\"]<!-- /wp:shortcode -->",
);
$builder_page = (object) array(
    'ID' => 15,
    'post_type' => 'page',
    'post_content' => '<!-- wp:stackable/columns {"a":1} --><div></div><!-- /wp:stackable/columns --> [unregistered_tag] [gallery ids="1,2"]',
);
$GLOBALS['perf_meta'] = array(15 => array('_elementor_data' => '[{"elType":"widget","widgetType":"image-carousel","settings":[]}]'));
$GLOBALS['perf_active_widgets'] = array('woocommerce_price_filter');
$GLOBALS['perf_products'] = array(31 => new Perf_Test_Product(31, 0, array(), 'grouped'), 32 => new Perf_Test_Product(32, 0));
$product_page = (object) array('ID' => 31, 'post_type' => 'product', 'post_content' => '');
$simple_page = (object) array('ID' => 32, 'post_type' => 'product', 'post_content' => '');

/** Whether the unload rule with these conditions applied on the page. */
function perf_unless_applies($unless, $object, array $context = array(), $query_ran = true)
{
    faaaster_test_reset_hooks();
    if ($query_ran) {
        do_action('wp');
    }
    $GLOBALS['perf_context'] = array_merge(
        array('front_page' => false, 'singular' => $object !== null, 'archive' => $object === null, 'object' => $object),
        $context
    );
    $GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
    $GLOBALS['perf_styles'] = (object) array(
        'queue' => array('pwf-filters'),
        'done' => array(),
        'registered' => array('pwf-filters' => (object) array('src' => '/pwf.css', 'deps' => array())),
    );
    $_SERVER['REQUEST_URI'] = '/filtres/';
    $_GET = array();
    $contexts = $object !== null ? array('singular') : array('archive');
    $settings = perf_live(array(perf_rule('unload:pwf', 'unload_unused_asset', array('assetType' => 'style', 'handles' => array('pwf-filters')), $unless, $contexts)));
    (new FaaasterPerformanceRuntime($settings))->unload_assets();
    return $GLOBALS['perf_dequeued']['style'] === array('pwf-filters');
}

$shortcode = array(array('kind' => 'has_shortcode', 'value' => 'pwf_filter'));
perf_check('has_shortcode: withheld on a page showing the filter', perf_unless_applies($shortcode, $filters_page), false);
perf_check('has_shortcode: applied on a page without it', perf_unless_applies($shortcode, $builder_page), true);
perf_check('has_shortcode: an unregistered tag is no shortcode', perf_unless_applies(array(array('kind' => 'has_shortcode', 'value' => 'unregistered_tag')), $builder_page), true);
perf_check('has_shortcode: never true off a singular page', perf_unless_applies($shortcode, null), true);
perf_check('has_block: withheld where the block is', perf_unless_applies(array(array('kind' => 'has_block', 'value' => 'stackable/columns')), $builder_page), false);
perf_check('has_block: applied where it is not', perf_unless_applies(array(array('kind' => 'has_block', 'value' => 'stackable/columns')), $filters_page), true);
perf_check('has_block: a core block serialized without namespace', perf_unless_applies(array(array('kind' => 'has_block', 'value' => 'core/paragraph')), $filters_page), false);
perf_check('elementor_widget: withheld where the widget is', perf_unless_applies(array(array('kind' => 'elementor_widget', 'value' => 'image-carousel')), $builder_page), false);
perf_check('elementor_widget: applied elsewhere', perf_unless_applies(array(array('kind' => 'elementor_widget', 'value' => 'image-carousel')), $filters_page), true);
perf_check('active_widget: withheld while the widget is placed', perf_unless_applies(array(array('kind' => 'active_widget', 'value' => 'woocommerce_price_filter')), null), false);
perf_check('active_widget: applied when it is not', perf_unless_applies(array(array('kind' => 'active_widget', 'value' => 'calendar')), null), true);
$grouped = array(array('kind' => 'product_type', 'value' => 'grouped'));
perf_check('product_type: withheld on a grouped product', perf_unless_applies($grouped, $product_page, array('product' => true)), false);
perf_check('product_type: applied on a simple product', perf_unless_applies($grouped, $simple_page, array('product' => true)), true);
perf_check('product_type: applied off a product page', perf_unless_applies($grouped, $filters_page), true);
perf_check('post_ids: withheld on a listed content', perf_unless_applies(array(array('kind' => 'post_ids', 'value' => array(3, 12))), $filters_page), false);
perf_check('post_ids: applied on another', perf_unless_applies(array(array('kind' => 'post_ids', 'value' => array(3, 12))), $builder_page), true);
perf_check('any holding condition withholds the rule', perf_unless_applies(array(
    array('kind' => 'post_ids', 'value' => array(99)),
    array('kind' => 'has_shortcode', 'value' => 'gallery'),
), $builder_page), false);
perf_check('before the main query a conditioned rule waits', perf_unless_applies($shortcode, $builder_page, array(), false), false);
perf_check('before the main query a rule without condition applies', perf_unless_applies(null, $builder_page, array(), false), true);

// conditions are evaluated once per request, whatever the number of rules
faaaster_test_reset_hooks();
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => true, 'archive' => false, 'object' => $builder_page);
$GLOBALS['perf_widget_reads'] = 0;
$GLOBALS['perf_meta_reads'] = 0;
$memo_rules = array();
$memo_queue = array();
$memo_registered = array();
for ($index = 0; $index < 20; $index++) {
    $memo_rules[] = perf_rule('unload:memo-' . $index, 'unload_unused_asset', array('assetType' => 'style', 'handles' => array('memo-' . $index)), array(
        array('kind' => 'active_widget', 'value' => 'calendar'),
        array('kind' => 'elementor_widget', 'value' => 'heading'),
        array('kind' => 'has_shortcode', 'value' => 'woosg'),
    ));
    $memo_queue[] = 'memo-' . $index;
    $memo_registered['memo-' . $index] = (object) array('src' => '/memo.css', 'deps' => array());
}
$GLOBALS['perf_styles'] = (object) array('queue' => $memo_queue, 'done' => array(), 'registered' => $memo_registered);
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$_SERVER['REQUEST_URI'] = '/page/';
(new FaaasterPerformanceRuntime(perf_live($memo_rules)))->unload_assets();
perf_check('conditions memoized: 20 rules, one widget lookup and one meta read', array($GLOBALS['perf_widget_reads'], $GLOBALS['perf_meta_reads']), array(1, 1));
perf_check('conditions memoized: every rule still applied', count($GLOBALS['perf_dequeued']['style']), 20);

// the preview receipt names every rule a condition withheld
$skip_token = str_repeat('a1', 24);
perf_preview(array(
    perf_rule('unload:pwf', 'unload_unused_asset', $unload_pwf, $shortcode),
    perf_rule('unload:pwf-page', 'unload_unused_asset', $unload_pwf, array(array('kind' => 'post_ids', 'value' => array(12)))),
    perf_rule('unload:select2', 'unload_unused_asset', array('assetType' => 'style', 'handles' => array('select2'))),
    perf_rule('unload:select2-unless', 'unload_unused_asset', array('assetType' => 'style', 'handles' => array('select2')), $shortcode),
), '/filtres/', $skip_token);
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => true, 'archive' => false, 'object' => $filters_page);
$GLOBALS['perf_styles'] = (object) array(
    'queue' => array('pwf-filters', 'select2'),
    'done' => array(),
    'registered' => array(
        'pwf-filters' => (object) array('src' => '/pwf.css', 'deps' => array()),
        'select2' => (object) array('src' => '/select2.css', 'deps' => array()),
    ),
);
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$skipping = new FaaasterPerformanceRuntime(perf_live(array()));
$skipping->register();
$skipping->unload_assets();
$skip_receipt = perf_receipt('/filtres/', $skip_token);
perf_check('receipt: rules withheld by a condition are skipped, even behind an applied rule', $skip_receipt['skipped'], array('unload:pwf', 'unload:pwf-page', 'unload:select2-unless'));
perf_check('receipt: the other rule applied', $skip_receipt['dequeued']['style'], array('select2'));

// ── 11.2 group unloads ─────────────────────────────────────────────────────
/** Dequeued handles of $type for one request: rules of $groups, a graph. */
function perf_group_unload(array $queue, array $graph, array $groups, $type = 'style')
{
    faaaster_test_reset_hooks();
    $GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
    $GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
    $registry = (object) array('queue' => $queue, 'done' => array(), 'registered' => array());
    foreach ($graph as $handle => $deps) {
        $registry->registered[$handle] = (object) array('src' => '/' . $handle, 'deps' => $deps);
    }
    $empty = (object) array('queue' => array(), 'done' => array(), 'registered' => array());
    $GLOBALS['perf_styles'] = $type === 'style' ? $registry : $empty;
    $GLOBALS['perf_scripts'] = $type === 'script' ? $registry : $empty;
    $_SERVER['REQUEST_URI'] = '/';
    $_GET = array();
    $rules = array();
    foreach ($groups as $index => $group) {
        $rules[] = perf_rule('unload:group-' . $index, 'unload_unused_asset', array('assetType' => $type, 'handles' => $group['handles']), $group['unless'] ?? null, array('front_page'));
    }
    do_action('wp');
    (new FaaasterPerformanceRuntime(perf_live($rules)))->unload_assets();
    return $GLOBALS['perf_dequeued'][$type];
}

perf_check('group: a chain leaves, dependents first', perf_group_unload(
    array('a', 'b', 'c'),
    array('a' => array(), 'b' => array('a'), 'c' => array('b')),
    array(array('handles' => array('a', 'b', 'c')))
), array('c', 'b', 'a'));
perf_check('group: a dependent outside the group keeps it', perf_group_unload(
    array('a', 'x'),
    array('a' => array(), 'x' => array('a')),
    array(array('handles' => array('a')))
), array());
perf_check('group: a dependent through a handle not enqueued keeps it', perf_group_unload(
    array('x'),
    array('a' => array(), 'm' => array('a'), 'x' => array('m')),
    array(array('handles' => array('a')))
), array());
perf_check('group: a dependent of a dependent outside keeps both', perf_group_unload(
    array('a', 'b', 'y'),
    array('a' => array(), 'b' => array('a'), 'y' => array('b')),
    array(array('handles' => array('a', 'b')))
), array());
perf_check('group: a dependent of a dependent inside, all leave', perf_group_unload(
    array('a', 'b', 'y'),
    array('a' => array(), 'b' => array('a'), 'y' => array('b')),
    array(array('handles' => array('a', 'b', 'y')))
), array('y', 'b', 'a'));
perf_check('group: handles of two rules of the request form one group', perf_group_unload(
    array('a', 'b'),
    array('a' => array(), 'b' => array('a')),
    array(array('handles' => array('a')), array('handles' => array('b')))
), array('b', 'a'));
perf_check('group: a dependent withheld by its condition keeps the dependency', perf_group_unload(
    array('a', 'b'),
    array('a' => array(), 'b' => array('a')),
    array(array('handles' => array('a')), array('handles' => array('b'), 'unless' => array(array('kind' => 'active_widget', 'value' => 'woocommerce_price_filter'))))
), array());
perf_check('group: jquery-migrate stays while a script needs the jquery alias', perf_group_unload(
    array('slider'),
    array('jquery-core' => array(), 'jquery-migrate' => array(), 'jquery' => array('jquery-core', 'jquery-migrate'), 'slider' => array('jquery')),
    array(array('handles' => array('jquery-migrate'))),
    'script'
), array());
perf_check('group: scripts and their dependency leave together', perf_group_unload(
    array('slider', 'cart'),
    array('jquery-core' => array(), 'jquery-migrate' => array(), 'jquery' => array('jquery-core', 'jquery-migrate'), 'slider' => array('jquery'), 'cart' => array('jquery')),
    array(array('handles' => array('slider', 'cart', 'jquery-migrate'))),
    'script'
), array('slider', 'cart', 'jquery-migrate'));

// ── 11.4 audit snapshot: style deps and the page's components ──────────────
$GLOBALS['perf_parsed_blocks'][md5($builder_page->post_content)] = array(
    array('blockName' => 'stackable/columns', 'innerBlocks' => array(
        array('blockName' => 'core/paragraph', 'innerBlocks' => array()),
        array('blockName' => 'stackable/column', 'innerBlocks' => array(
            array('blockName' => 'core/image', 'innerBlocks' => array()),
        )),
    )),
    array('blockName' => null, 'innerBlocks' => array()),
    array('blockName' => 'core/paragraph', 'innerBlocks' => array()),
);
/** The audit snapshot of a request showing $object (null: an archive). */
function perf_audit_snapshot($object)
{
    faaaster_test_reset_hooks();
    $token = str_repeat('9a', 24);
    $GLOBALS['fta']['transients'][FaaasterPerformanceRuntime::audit_transient_key($token)] = array('paths' => array('/composants/'), 'snapshots' => array(), 'createdAt' => time());
    $_SERVER['REQUEST_URI'] = '/composants/?faaaster_perf_audit=' . $token;
    $_GET = array('faaaster_perf_audit' => $token);
    $GLOBALS['perf_context'] = array('front_page' => false, 'singular' => $object !== null, 'archive' => $object === null, 'object' => $object);
    $GLOBALS['perf_styles'] = (object) array(
        'queue' => array(),
        'done' => array('parent', 'child'),
        'registered' => array(
            'parent' => (object) array('src' => '/parent.css', 'deps' => array()),
            'child' => (object) array('src' => '/child.css', 'deps' => array('parent')),
        ),
    );
    $GLOBALS['perf_scripts'] = (object) array('queue' => array(), 'done' => array(), 'registered' => array());
    $auditing = new FaaasterPerformanceRuntime(perf_live(array()));
    $auditing->register();
    do_action('wp');
    do_action('shutdown');
    $_GET = array();
    return get_transient(FaaasterPerformanceRuntime::audit_transient_key($token))['snapshots']['/composants/'];
}
$component_snapshot = perf_audit_snapshot($builder_page);
perf_check('snapshot: style deps for the group unloads', array_column($component_snapshot['assets'], 'deps', 'handle'), array(
    'parent' => array(),
    'child' => array('parent'),
));
perf_check('snapshot: components of the displayed content', $component_snapshot['components'], array(
    'blocks' => array('stackable/columns', 'core/paragraph', 'stackable/column', 'core/image'),
    'shortcodes' => array('gallery'),
    'elementorWidgets' => array('image-carousel'),
));
perf_check('snapshot: no components off a singular page', perf_audit_snapshot(null)['components'], array(
    'blocks' => array(),
    'shortcodes' => array(),
    'elementorWidgets' => array(),
));
$many_blocks = array();
for ($index = 0; $index < 150; $index++) {
    $many_blocks[] = array('blockName' => 'acme/block-' . $index, 'innerBlocks' => array());
}
$GLOBALS['perf_parsed_blocks'][md5('<!-- wp:acme/many -->')] = $many_blocks;
perf_check('snapshot: 100 block names at most', count(FaaasterPerformanceComponents::parsed_blocks('<!-- wp:acme/many -->', 100)), 100);

// ── 11.3 wc_loop_images ────────────────────────────────────────────────────
perf_check('wc_loop_images: params accepted', perf_refused(array(perf_rule('wc:loop', 'wc_loop_images', array('eager' => 4, 'highPriority' => 1), null, array('archive')))), false);
foreach (array(
    'eager 0' => array('eager' => 0, 'highPriority' => 0),
    'eager 13' => array('eager' => 13, 'highPriority' => 1),
    'highPriority 5' => array('eager' => 8, 'highPriority' => 5),
    'highPriority over eager' => array('eager' => 2, 'highPriority' => 3),
    'eager as text' => array('eager' => '4', 'highPriority' => 1),
    'missing highPriority' => array('eager' => 4),
) as $label => $params) {
    perf_check('wc_loop_images refused: ' . $label, perf_refused(array(perf_rule('wc:loop', 'wc_loop_images', $params, null, array('archive')))), true);
}

$loop_token = str_repeat('b2', 24);
perf_preview(array(perf_rule('wc:loop', 'wc_loop_images', array('eager' => 2, 'highPriority' => 1), null, array('archive', 'singular'))), '/boutique/', $loop_token);
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => false, 'archive' => true);
$loop_runtime = new FaaasterPerformanceRuntime(perf_live(array()));
$loop_runtime->register();
/** Attributes WordPress would print for the attachment, product $product in the loop. */
function perf_card_input($attachment_id)
{
    return array('src' => '/card-' . $attachment_id . '.jpg', 'loading' => 'lazy', 'fetchpriority' => 'high');
}
function perf_card($product, $attachment_id)
{
    $GLOBALS['product'] = $product;
    return apply_filters(
        'wp_get_attachment_image_attributes',
        perf_card_input($attachment_id),
        (object) array('ID' => $attachment_id),
        'woocommerce_thumbnail'
    );
}
$card_a = new Perf_Test_Product(101, 501);
$card_b = new Perf_Test_Product(102, 502);
$card_c = new Perf_Test_Product(103, 503);
$cards = array(perf_card($card_a, 501), perf_card($card_b, 502), perf_card($card_c, 503));
perf_check('wc_loop_images: the first card loads at once, with priority', array($cards[0]['loading'], $cards[0]['fetchpriority'] ?? null), array('eager', 'high'));
perf_check('wc_loop_images: the second at once, without priority', array($cards[1]['loading'], $cards[1]['fetchpriority'] ?? null), array('eager', null));
perf_check('wc_loop_images: the next ones deferred', array($cards[2]['loading'], $cards[2]['fetchpriority'] ?? null), array('lazy', null));
perf_check('wc_loop_images: a product keeps its rank', perf_card($card_a, 501)['loading'], 'eager');
perf_check('wc_loop_images: another image of the product untouched', perf_card($card_b, 999), perf_card_input(999));
do_action('woocommerce_widget_product_item_start');
perf_check('wc_loop_images: a widget product list untouched', perf_card(new Perf_Test_Product(104, 504), 504)['fetchpriority'] ?? null, 'high');
do_action('woocommerce_widget_product_item_end');
$loop_receipt = perf_receipt('/boutique/', $loop_token);
perf_check('receipt: product cards adjusted', $loop_receipt['adapters'], array('wcLoopImages' => 4, 'wcGallery' => 0, 'menuImagesLazy' => 0));
// the product page: its own image is no card, the related products are
$_SERVER['REQUEST_URI'] = '/produit/a/';
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => true, 'archive' => false, 'object' => (object) array('ID' => 101, 'post_content' => ''));
faaaster_test_reset_hooks();
do_action('wp');
$related_runtime = new FaaasterPerformanceRuntime(perf_live(array(perf_rule('wc:loop', 'wc_loop_images', array('eager' => 1, 'highPriority' => 1), null, array('singular')))));
$related_runtime->register();
perf_check('wc_loop_images: the product page image is no card', perf_card($card_a, 501), perf_card_input(501));
perf_check('wc_loop_images: the first related product is', perf_card($card_b, 502)['loading'], 'eager');
unset($GLOBALS['product']);

// ── 11.3 wc_gallery_images ─────────────────────────────────────────────────
perf_check('wc_gallery_images: params accepted', perf_refused(array(perf_rule('wc:gallery', 'wc_gallery_images', array('mainHighPriority' => true, 'thumbnailsLazy' => false)))), false);
perf_check('wc_gallery_images refused: nothing to do', perf_refused(array(perf_rule('wc:gallery', 'wc_gallery_images', array('mainHighPriority' => false, 'thumbnailsLazy' => false)))), true);
perf_check('wc_gallery_images refused: not booleans', perf_refused(array(perf_rule('wc:gallery', 'wc_gallery_images', array('mainHighPriority' => 'yes', 'thumbnailsLazy' => true)))), true);
$gallery_token = str_repeat('c3', 24);
perf_preview(array(
    perf_rule('wc:gallery', 'wc_gallery_images', array('mainHighPriority' => true, 'thumbnailsLazy' => true), null, array('post_type:product')),
    perf_rule('image:explicit', 'image_delivery', array('attachmentIds' => array(602), 'loading' => 'eager', 'fetchPriority' => 'auto'), null, array('post_type:product')),
), '/produit/chaise/', $gallery_token);
do_action('wp');
$GLOBALS['perf_products'][201] = new Perf_Test_Product(201, 600, array(601, 602));
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => true, 'archive' => false, 'product' => true, 'object' => (object) array('ID' => 201, 'post_content' => ''));
$gallery_runtime = new FaaasterPerformanceRuntime(perf_live(array()));
$gallery_runtime->register();
$main_image = perf_card(null, 600);
$gallery_image = perf_card(null, 601);
perf_check('wc_gallery_images: the main image first', array($main_image['loading'], $main_image['fetchpriority'] ?? null), array('eager', 'high'));
perf_check('wc_gallery_images: gallery images deferred', array($gallery_image['loading'], $gallery_image['fetchpriority'] ?? null), array('lazy', null));
perf_check('wc_gallery_images: a rule on the attachment has the last word', perf_card(null, 602)['loading'], 'eager');
perf_check('wc_gallery_images: other images untouched', perf_card(null, 999), perf_card_input(999));
$gallery_receipt = perf_receipt('/produit/chaise/', $gallery_token);
perf_check('receipt: gallery images adjusted', $gallery_receipt['adapters']['wcGallery'], 2);
perf_check('receipt: the explicit rule listed apart', $gallery_receipt['images'], array(602));
faaaster_test_reset_hooks();
do_action('wp');
$thumbnails_only = new FaaasterPerformanceRuntime(perf_live(array(perf_rule('wc:gallery', 'wc_gallery_images', array('mainHighPriority' => false, 'thumbnailsLazy' => true), null, array('singular')))));
$thumbnails_only->register();
perf_check('wc_gallery_images: main image left alone when not asked', perf_card(null, 600)['fetchpriority'] ?? null, 'high');
perf_check('wc_gallery_images: off a product page nothing changes', (function () {
    $GLOBALS['perf_context']['product'] = false;
    $runtime = new FaaasterPerformanceRuntime(perf_live(array(perf_rule('wc:gallery', 'wc_gallery_images', array('mainHighPriority' => true, 'thumbnailsLazy' => true), null, array('singular')))));
    faaaster_test_reset_hooks();
    do_action('wp');
    $runtime->register();
    return perf_card(null, 601);
})(), perf_card_input(601));
unset($GLOBALS['product']);

// ── 11.3 preload_image ─────────────────────────────────────────────────────
$hero = 'https://example.com/wp-content/uploads/hero-bg.jpg';
perf_check('preload_image: url, srcset and sizes accepted', perf_refused(array(perf_rule('preload:hero', 'preload_image', array(
    'url' => $hero,
    'srcset' => $hero . ' 1600w, https://example.com/wp-content/uploads/hero-bg-800.jpg 800w',
    'sizes' => '100vw',
)))), false);
foreach (array(
    'http' => array('url' => 'http://example.com/hero.jpg'),
    'no url' => array('srcset' => $hero . ' 1x'),
    'empty srcset' => array('url' => $hero, 'srcset' => ''),
    'srcset over 2000' => array('url' => $hero, 'srcset' => str_repeat('a', 2001)),
    'sizes over 200' => array('url' => $hero, 'sizes' => str_repeat('1', 201)),
    'url over 500' => array('url' => 'https://example.com/' . str_repeat('a', 490) . '.jpg'),
) as $label => $params) {
    perf_check('preload_image refused: ' . $label, perf_refused(array(perf_rule('preload:hero', 'preload_image', $params))), true);
}
$preload_token = str_repeat('d4', 24);
perf_preview(array(
    perf_rule('preload:hero', 'preload_image', array('url' => $hero, 'srcset' => $hero . ' 1600w', 'sizes' => '100vw'), null, array('front_page')),
    perf_rule('preload:hero-again', 'preload_image', array('url' => $hero), null, array('all')),
    perf_rule('preload:elsewhere', 'preload_image', array('url' => 'https://example.com/other.jpg'), null, array('archive')),
), '/', $preload_token);
$GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
$preload_runtime = new FaaasterPerformanceRuntime(perf_live(array()));
$preload_runtime->register();
ob_start();
do_action('wp_head');
$preload_head = ob_get_clean();
perf_check(
    'preload_image: one preload per image, at the top of the head',
    $preload_head,
    '<link rel="preload" as="image" href="' . $hero . '" imagesrcset="' . $hero . ' 1600w" imagesizes="100vw" fetchpriority="high">' . "\n"
);
perf_check('receipt: preloaded images', perf_receipt('/', $preload_token)['preloadedImages'], array($hero));

// ── 11.3 menu_images_lazy ──────────────────────────────────────────────────
// Next hashes `params: {}`; WordPress decodes it to an empty PHP array
$menu_rules = array(array('id' => 'menu:lazy', 'type' => 'menu_images_lazy', 'contexts' => array('all'), 'paths' => array(), 'params' => new stdClass()));
$menu_payload = json_decode(json_encode(perf_payload($menu_rules, 1)), true);
$menu_compiled = FaaasterPerformanceSettings::compile($menu_payload);
perf_check('menu_images_lazy: an empty params object hashes like the control plane', count($menu_compiled['compiled']['menuImagesLazy']), 1);
perf_check('menu_images_lazy: older rules keep their checksum', FaaasterPerformanceSettings::compile(perf_payload($rules, 1))['checksum'], perf_payload($rules, 1)['checksum']);
if (class_exists('WP_HTML_Tag_Processor')) {
    $menu_token = str_repeat('e5', 24);
    perf_preview($menu_rules, '/', $menu_token);
    $GLOBALS['fta']['transients'][FaaasterPerformanceRuntime::preview_transient_key($menu_token)]['compiled'] = $menu_compiled['compiled'];
    $GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
    $menu_runtime = new FaaasterPerformanceRuntime(perf_live(array()));
    $menu_runtime->register();
    $menu = apply_filters(
        'wp_nav_menu',
        '<ul><li><a href="/a/"><img src="a.jpg"></a></li><li><img src="b.jpg" loading="eager"></li><li><img src="c.jpg" decoding="sync"></li></ul>',
        (object) array()
    );
    perf_check(
        'menu_images_lazy: menu images without a loading mode deferred',
        $menu,
        '<ul><li><a href="/a/"><img decoding="async" loading="lazy" src="a.jpg"></a></li><li><img src="b.jpg" loading="eager"></li><li><img loading="lazy" src="c.jpg" decoding="sync"></li></ul>'
    );
    perf_check(
        'menu_images_lazy: navigation block items too',
        apply_filters('render_block', '<li><img src="d.jpg"></li>', array('blockName' => 'core/navigation-link')),
        '<li><img decoding="async" loading="lazy" src="d.jpg"></li>'
    );
    perf_check(
        'menu_images_lazy: other blocks untouched',
        apply_filters('render_block', '<figure><img src="e.jpg"></figure>', array('blockName' => 'core/image')),
        '<figure><img src="e.jpg"></figure>'
    );
    perf_check('receipt: menu images deferred', perf_receipt('/', $menu_token)['adapters']['menuImagesLazy'], 3);
    faaaster_test_reset_hooks();
    $_GET = array();
    $GLOBALS['perf_context'] = array('front_page' => false, 'singular' => false, 'archive' => true);
    $menu_elsewhere = new FaaasterPerformanceRuntime(perf_live(array(perf_rule('menu:home', 'menu_images_lazy', new stdClass(), null, array('front_page')))));
    $menu_elsewhere->register();
    perf_check('menu_images_lazy: untouched outside the target', apply_filters('wp_nav_menu', '<img src="a.jpg">', null), '<img src="a.jpg">');
} else {
    echo "SKIP menu images: WordPress HTML API not found (set WP_HTML_API_DIR)\n";
}
perf_check('menu_images_lazy: no hook without a rule', (function () {
    faaaster_test_reset_hooks();
    $_GET = array();
    (new FaaasterPerformanceRuntime(perf_live(array())))->register();
    return array(has_filter('render_block'), has_filter('wp_nav_menu'), has_action('wp_print_styles'));
})(), array(false, false, false));

// ── 11.3 font_variants: compile ────────────────────────────────────────────
$keep = array(
    array('family' => '"Ubuntu"', 'weight' => '400', 'style' => 'normal'),
    array('family' => 'Inter', 'weight' => '400'),
);
$font_rule_src = 'https://example.com/wp-content/themes/t/css/fonts.css';
$font_params = array('handle' => 'theme-fonts', 'src' => $font_rule_src, 'keep' => $keep);
$fonts_compiled = FaaasterPerformanceSettings::compile(perf_payload(array(
    perf_rule('font:ubuntu', 'font_variants', $font_params, null, array('all')),
), 1));
perf_check('font_variants: params cleaned, src kept', $fonts_compiled['rules'][0]['params'], array(
    'handle' => 'theme-fonts',
    'src' => $font_rule_src,
    'keep' => array(
        array('family' => 'Ubuntu', 'weight' => '400', 'style' => 'normal'),
        array('family' => 'Inter', 'weight' => '400', 'style' => 'normal'),
    ),
));
perf_check('font_variants: compiled per handle with its src, weights as ranges, no copy yet', $fonts_compiled['compiled']['fontVariants']['theme-fonts'][0], array(
    'target' => array('contexts' => array('all'), 'paths' => array(), 'rule' => 'font:ubuntu'),
    'src' => $font_rule_src,
    'keep' => array(
        array('family' => 'ubuntu', 'weight' => array(400, 400), 'style' => 'normal'),
        array('family' => 'inter', 'weight' => array(400, 400), 'style' => 'normal'),
    ),
));
foreach (array(
    'weight as a keyword' => array('keep' => array(array('family' => 'Ubuntu', 'weight' => 'bold'))),
    'three weights' => array('keep' => array(array('family' => 'Ubuntu', 'weight' => '400 700 900'))),
    'oblique' => array('keep' => array(array('family' => 'Ubuntu', 'weight' => '400', 'style' => 'oblique'))),
    'family with a semicolon' => array('keep' => array(array('family' => 'Ubuntu;', 'weight' => '400'))),
    'no face kept' => array('keep' => array()),
    '41 faces' => array('keep' => array_fill(0, 41, array('family' => 'Ubuntu', 'weight' => '400'))),
    'handle with a space' => array('handle' => 'theme fonts'),
    'no src' => array('src' => null),
    'http src' => array('src' => 'http://example.com/wp-content/themes/t/css/fonts.css'),
    'src with a query' => array('src' => $font_rule_src . '?ver=1.2'),
    'src with a fragment' => array('src' => $font_rule_src . '#x'),
    'src over 500' => array('src' => 'https://example.com/' . str_repeat('a', 490) . '.css'),
) as $label => $override) {
    $params = array_merge($font_params, $override);
    if (array_key_exists('src', $override) && $override['src'] === null) {
        unset($params['src']);
    }
    perf_check('font_variants refused: ' . $label, perf_refused(array(perf_rule('font:bad', 'font_variants', $params, null, array('all')))), true);
}

// ── font_variants: the @font-face filter ───────────────────────────────────
$compiled_keep = $fonts_compiled['compiled']['fontVariants']['theme-fonts'][0]['keep'];
$font_css = "/* @font-face { font-family: Commented; } */\n"
    . "@font-face{font-family:\"Ubuntu\";font-style:normal;font-weight:400;src:url(../fonts/ubuntu-400.woff2) format(\"woff2\")}\n"
    . "@font-face {\n  font-family: 'Ubuntu';\n  font-style: normal;\n  font-weight: 700;\n  src: url(\"../fonts/ubuntu-700.woff2\") format(\"woff2\");\n}\n"
    . "@font-face{font-family:Ubuntu;font-style:italic;font-weight:400;src:url(../fonts/ubuntu-400i.woff2)}\n"
    . "@font-face{font-family:\"Ubuntu Condensed\";font-weight:normal;src:url(/wp-content/themes/t/fonts/uc.woff2)}\n"
    . "@font-face{font-family:\"Inter\";font-weight:100 900;src:url(data:font/woff2;base64,AAAA) format(\"woff2\")}\n"
    . "@media (min-width: 600px) { @font-face{font-family:\"Ubuntu\";font-weight:bold;src:url('fonts/ubuntu-700b.woff2?v=1#iefix')} }\n"
    . "@font-face{src:url(nofamily.woff2)}\n"
    . "body{font-family:Ubuntu,sans-serif;background:url(\"../img/bg.png\")}\n"
    . "@import \"print.css\";\n";
$filtered = FaaasterPerformanceFontFaces::filter($font_css, $compiled_keep);
perf_check('font filter: faces not kept removed', $filtered['removed'], 4);
$present = array();
foreach (array('ubuntu-400.woff2', 'ubuntu-700.woff2', 'ubuntu-400i', 'uc.woff2', 'base64,AAAA', 'ubuntu-700b', 'nofamily.woff2', 'body{font-family:Ubuntu', '/* @font-face { font-family: Commented; } */', '@media (min-width: 600px) {  }') as $needle) {
    $present[$needle] = strpos($filtered['css'], $needle) !== false;
}
perf_check('font filter: kept faces, ranges, unreadable faces, comments and rules stay', $present, array(
    'ubuntu-400.woff2' => true,
    'ubuntu-700.woff2' => false,
    'ubuntu-400i' => false,
    'uc.woff2' => false,
    'base64,AAAA' => true,
    'ubuntu-700b' => false,
    'nofamily.woff2' => true,
    'body{font-family:Ubuntu' => true,
    '/* @font-face { font-family: Commented; } */' => true,
    '@media (min-width: 600px) {  }' => true,
));
perf_check('font filter: braces stay balanced', substr_count($filtered['css'], '{'), substr_count($filtered['css'], '}'));
perf_check('font filter: an unbalanced stylesheet is refused', FaaasterPerformanceFontFaces::filter('@font-face{font-family:X;src:url(a.woff2)', $compiled_keep), null);
perf_check('font filter: a kept range keeps the faces inside', FaaasterPerformanceFontFaces::filter(
    '@font-face{font-family:Ubuntu;font-weight:500}',
    array(array('family' => 'ubuntu', 'weight' => array(300, 700), 'style' => 'normal'))
)['removed'], 0);
perf_check('font filter: a stylesheet without faces untouched', FaaasterPerformanceFontFaces::filter('body{color:red}', $compiled_keep), array('css' => 'body{color:red}', 'removed' => 0));

perf_check('font copy: relative urls made absolute', FaaasterPerformanceFontFaces::absolute_urls(
    'a{src:url(../fonts/a.woff2)}b{src:url("fonts/b.woff2?v=1#iefix")}c{src:url(\'/wp-content/x.woff2\')}'
        . 'd{src:url(data:font/woff2;base64,AAAA)}e{src:url(https://cdn.example.net/f.woff2)}f{src:url(//cdn.example.net/g.woff2)}'
        . 'g{src:url(#svg)}h{src:url(./c.woff2)}i{src:url(../../../../../../x.woff2)}@import "print.css";',
    'https://example.com/wp-content/themes/t/css/fonts.css'
), 'a{src:url(https://example.com/wp-content/themes/t/fonts/a.woff2)}'
    . 'b{src:url("https://example.com/wp-content/themes/t/css/fonts/b.woff2?v=1#iefix")}'
    . 'c{src:url(\'https://example.com/wp-content/x.woff2\')}'
    . 'd{src:url(data:font/woff2;base64,AAAA)}e{src:url(https://cdn.example.net/f.woff2)}f{src:url(https://cdn.example.net/g.woff2)}'
    . 'g{src:url(#svg)}h{src:url(https://example.com/wp-content/themes/t/css/c.woff2)}i{src:url(https://example.com/x.woff2)}'
    . '@import "https://example.com/wp-content/themes/t/css/print.css";');

// ── font_variants: copies written by the REST handlers only ───────────────
mkdir(WP_CONTENT_DIR . '/themes/t/css', 0777, true);
function content_url($path = '') { return 'https://example.com/wp-content' . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
function wp_upload_dir($time = null, $create_dir = true, $refresh = false)
{
    return array(
        'basedir' => WP_CONTENT_DIR . '/uploads',
        'baseurl' => $GLOBALS['perf_uploads_baseurl'] ?? 'https://example.com/wp-content/uploads',
        'error' => $GLOBALS['perf_uploads_error'] ?? false,
    );
}
function wp_mkdir_p($directory) { return is_dir($directory) || @mkdir($directory, 0777, true); }
/** Every file under the uploads, to prove a page request writes none. */
function perf_uploads_files()
{
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_CONTENT_DIR . '/uploads', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $files[] = $file->getPathname() . '@' . $file->getMTime();
    }
    sort($files);
    return $files;
}
$fonts_dir = WP_CONTENT_DIR . '/uploads/faaaster-performance/fonts';
$font_file = WP_CONTENT_DIR . '/themes/t/css/fonts.css';
file_put_contents($font_file, $font_css);
touch($font_file, time() - 3600);
clearstatcache();
$font_src = $font_rule_src . '?ver=1.2';
$alias_src = 'https://example.com/wp-content/themes/t/css/alias.css';
$font_rules = array(
    perf_rule('font:ubuntu', 'font_variants', $font_params, null, array('all')),
    perf_rule('font:alias', 'font_variants', array('handle' => 'alias-fonts', 'src' => $alias_src, 'keep' => $keep), null, array('all')),
);
$copy_name = sha1($font_rule_src . '|' . filemtime($font_file) . '|' . json_encode($compiled_keep)) . '.css';
$copy_url = 'https://example.com/wp-content/uploads/faaaster-performance/fonts/' . $copy_name;
$font_rest = new FaaasterPerformanceRest(new FaaasterPerformanceSettings());
$font_revision = perf_revision();

$applied = $font_rest->apply(new Perf_Test_Request(perf_payload($font_rules, $font_revision)));
$live_fonts = get_option(FaaasterPerformanceSettings::OPTION)['compiled']['fontVariants'];
perf_check('font_variants: apply writes the copy and records it', $live_fonts['theme-fonts'][0], array(
    'target' => array('contexts' => array('all'), 'paths' => array(), 'rule' => 'font:ubuntu'),
    'src' => $font_rule_src,
    'keep' => $compiled_keep,
    'copy' => $copy_url,
    'file' => realpath($font_file),
    'mtime' => filemtime($font_file),
));
perf_check('font_variants: a stylesheet that is not a file of the site gets no copy', array_key_exists('copy', $live_fonts['alias-fonts'][0]), false);
perf_check('font_variants: the missing stylesheet is logged at apply', strpos(perf_log(), 'font_variants: not a stylesheet of the site ' . $alias_src) !== false, true);
perf_check('font_variants: apply changes the pages', $applied['changed'], true);
$copy = (string) @file_get_contents($fonts_dir . '/' . $copy_name);
perf_check('font_variants: the copy keeps the kept faces only, urls absolute', array(
    strpos($copy, 'url(https://example.com/wp-content/themes/t/fonts/ubuntu-400.woff2)') !== false,
    strpos($copy, 'ubuntu-700.woff2') !== false,
    strpos($copy, 'url("https://example.com/wp-content/themes/t/img/bg.png")') !== false,
    strpos($copy, '@import "https://example.com/wp-content/themes/t/css/print.css"') !== false,
), array(true, false, true, true));
perf_check('font_variants: replaying the same rules changes nothing', $font_rest->apply(new Perf_Test_Request(perf_payload($font_rules, $font_revision)))['changed'], false);

/** A visitor's page: the stylesheet's source and the files the request wrote. */
function perf_visitor_fonts($src, array $query = array())
{
    faaaster_test_reset_hooks();
    $_GET = $query;
    $_SERVER['REQUEST_URI'] = '/' . ($query ? '?' . http_build_query($query) : '');
    $GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
    $inline = '@font-face{font-family:Ubuntu;font-weight:400;src:url(/u4.woff2)}@font-face{font-family:Ubuntu;font-weight:700;src:url(/u7.woff2)}';
    $GLOBALS['perf_styles'] = (object) array(
        'queue' => array('theme-fonts', 'alias-fonts'),
        'done' => array(),
        'registered' => array(
            'theme-fonts' => (object) array('src' => $src, 'deps' => array(), 'extra' => array('after' => array($inline))),
            'alias-fonts' => (object) array('src' => false, 'deps' => array(), 'extra' => array('after' => array($inline))),
        ),
    );
    $before = perf_uploads_files();
    $runtime = new FaaasterPerformanceRuntime(new FaaasterPerformanceSettings());
    $runtime->register();
    $served = apply_filters('style_loader_src', $src, 'theme-fonts');
    do_action('wp_print_styles');
    return array(
        'served' => $served,
        'inline' => $GLOBALS['perf_styles']->registered['theme-fonts']->extra['after'][0],
        'alias' => $GLOBALS['perf_styles']->registered['alias-fonts']->extra['after'][0],
        'written' => array_values(array_diff(perf_uploads_files(), $before)),
    );
}
$kept_inline = '@font-face{font-family:Ubuntu;font-weight:400;src:url(/u4.woff2)}';
// an older mtime: a page request touching the copy would show
touch($fonts_dir . '/' . $copy_name, time() - 100);
clearstatcache();
perf_check('font_variants: a visitor gets the copy, inline faces filtered, nothing written', perf_visitor_fonts($font_src), array(
    'served' => $copy_url,
    'inline' => $kept_inline,
    'alias' => $kept_inline,
    'written' => array(),
));
perf_check('font_variants: another stylesheet on the handle keeps its source', perf_visitor_fonts('https://example.com/wp-content/plugins/x/fonts.css?ver=2')['served'], 'https://example.com/wp-content/plugins/x/fonts.css?ver=2');
perf_check('font_variants: the same stylesheet in http or protocol-relative still matches', array(
    perf_visitor_fonts('//example.com/wp-content/themes/t/css/fonts.css?ver=3')['served'],
    perf_visitor_fonts('http://example.com/wp-content/themes/t/css/fonts.css')['served'],
), array($copy_url, $copy_url));

// a theme update changes the source: the original is served until an apply
touch($font_file, time() - 60);
clearstatcache();
$log_before = substr_count(perf_log(), 'is stale');
$stale = perf_visitor_fonts($font_src);
apply_filters('style_loader_src', $font_src, 'theme-fonts');
perf_check('font_variants: a stale copy is never served', array($stale['served'], $stale['inline'], $stale['written']), array($font_src, $kept_inline, array()));
perf_check('font_variants: a stale copy is logged once per request', substr_count(perf_log(), 'is stale') - $log_before, 1);
$refreshed = $font_rest->apply(new Perf_Test_Request(perf_payload($font_rules, $font_revision)));
$fresh_name = sha1($font_rule_src . '|' . filemtime($font_file) . '|' . json_encode($compiled_keep)) . '.css';
perf_check('font_variants: a replay writes the copy again and changes the pages', array($refreshed['changed'], is_file($fonts_dir . '/' . $fresh_name)), array(true, true));
perf_check('font_variants: the fresh copy is served', perf_visitor_fonts($font_src)['served'], 'https://example.com/wp-content/uploads/faaaster-performance/fonts/' . $fresh_name);

// a preview writes its own copies when it begins, its pages only read them
$preview_keep = array(array('family' => 'Ubuntu', 'weight' => '400', 'style' => 'normal'));
$variants_token = str_repeat('f6', 24);
$GLOBALS['fta']['transients'] = array();
$font_rest->begin_preview(new Perf_Test_Request(array(
    'token' => $variants_token,
    'paths' => array('/'),
    'compiled' => perf_payload(array(perf_rule('font:preview', 'font_variants', array('handle' => 'theme-fonts', 'src' => $font_rule_src, 'keep' => $preview_keep), null, array('all'))), 1),
)));
$preview_name = sha1($font_rule_src . '|' . filemtime($font_file) . '|' . json_encode(array(array('family' => 'ubuntu', 'weight' => array(400, 400), 'style' => 'normal')))) . '.css';
perf_check('font_variants: the preview begins with its copy written', is_file($fonts_dir . '/' . $preview_name), true);
$preview_page = perf_visitor_fonts($font_src, array('faaaster_perf_preview' => $variants_token));
perf_check('font_variants: the preview page reads its copy, writes nothing', array($preview_page['served'], $preview_page['written']), array(
    'https://example.com/wp-content/uploads/faaaster-performance/fonts/' . $preview_name,
    array(),
));
perf_check('receipt: filtered font handles', perf_receipt('/', $variants_token)['fontsFiltered'], array('theme-fonts'));
$_GET = array();

// copies the live set does not reference, untouched for a day, are removed
$old = time() - 2 * 86400;
foreach (array('orphan.css', 'orphan.css.123.tmp', 'orphan.css.skip', 'readme.txt') as $name) {
    file_put_contents($fonts_dir . '/' . $name, '/* */');
    touch($fonts_dir . '/' . $name, $old);
}
touch($fonts_dir . '/' . $copy_name, $old);
file_put_contents($fonts_dir . '/young.css', '/* */');
// a preview beginning again reuses its old copy: the copy is in use again
touch($fonts_dir . '/' . $preview_name, $old);
$font_rest->begin_preview(new Perf_Test_Request(array(
    'token' => $variants_token,
    'paths' => array('/'),
    'compiled' => perf_payload(array(perf_rule('font:preview', 'font_variants', array('handle' => 'theme-fonts', 'src' => $font_rule_src, 'keep' => $preview_keep), null, array('all'))), 1),
)));
$font_rest->apply(new Perf_Test_Request(perf_payload($font_rules, $font_revision)));
clearstatcache();
$left = array_values(array_diff(scandir($fonts_dir), array('.', '..')));
sort($left);
$expected_left = array($fresh_name, $preview_name, 'readme.txt', 'young.css');
sort($expected_left);
perf_check('font_variants: apply removes old unreferenced copies, keeps referenced, young, reused and foreign files', $left, $expected_left);
touch($fonts_dir . '/' . $fresh_name, $old);
clearstatcache();
perf_check('font_variants: a referenced copy is never removed, however old', array(
    FaaasterPerformanceFontFaces::cleanup(get_option(FaaasterPerformanceSettings::OPTION)['compiled']),
    is_file($fonts_dir . '/' . $fresh_name),
), array(0, true));
perf_check('font_variants: without a set, an old copy goes', FaaasterPerformanceFontFaces::cleanup(array(), time() + 86400 * 3) >= 1 && !is_file($fonts_dir . '/' . $fresh_name), true);

// generation: every failure leaves the entry without copy
/** The compiled entry generate() returns for a stylesheet. */
function perf_generated($src, array $keep)
{
    $compiled = array('fontVariants' => array('h' => array(array('target' => array('contexts' => array('all'), 'paths' => array()), 'src' => $src, 'keep' => $keep))));
    return FaaasterPerformanceFontFaces::generate($compiled)['fontVariants']['h'][0];
}
file_put_contents($perf_tmp . '/outside.css', $font_css);
file_put_contents(WP_CONTENT_DIR . '/themes/t/css/broken.css', '@font-face{font-family:X;src:url(a.woff2)');
file_put_contents(WP_CONTENT_DIR . '/themes/t/css/kept.css', '@font-face{font-family:Ubuntu;font-weight:400;src:url(u.woff2)}');
file_put_contents(WP_CONTENT_DIR . '/themes/t/css/fonts.php', $font_css);
$failures = array();
foreach (array(
    'another host' => 'https://cdn.example.net/wp-content/themes/t/css/fonts.css',
    'outside wp-content' => 'https://example.com/wp-content/../outside.css',
    'outside wp-content, encoded' => 'https://example.com/wp-content/%2e%2e/outside.css',
    'not a stylesheet' => 'https://example.com/wp-content/themes/t/css/fonts.php',
    'wp-includes' => 'https://example.com/wp-includes/css/dashicons.css',
    'missing file' => 'https://example.com/wp-content/themes/t/css/missing.css',
    'unbalanced stylesheet' => 'https://example.com/wp-content/themes/t/css/broken.css',
    'nothing to remove' => 'https://example.com/wp-content/themes/t/css/kept.css',
) as $label => $src) {
    $failures[$label] = array_key_exists('copy', perf_generated($src, $compiled_keep));
}
perf_check('font_variants: no copy for a stylesheet that cannot or need not be filtered', array_filter($failures), array());
perf_check('font_variants: an unreadable stylesheet is logged', strpos(perf_log(), 'unreadable stylesheet https://example.com/wp-content/themes/t/css/broken.css') !== false, true);
$GLOBALS['perf_uploads_baseurl'] = 'http://example.com/wp-content/uploads';
perf_check('font_variants: an https stylesheet never gets an http copy', strpos((string) (perf_generated($font_rule_src, $compiled_keep)['copy'] ?? ''), 'https://example.com/wp-content/uploads/') === 0, true);
unset($GLOBALS['perf_uploads_baseurl']);
$inter_only = array(array('family' => 'inter', 'weight' => array(400, 400), 'style' => 'normal'));
$GLOBALS['perf_uploads_error'] = 'Unable to create directory';
perf_check('font_variants: no uploads directory, no copy', array_key_exists('copy', perf_generated($font_rule_src, $inter_only)), false);
unset($GLOBALS['perf_uploads_error']);
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    chmod($fonts_dir, 0555);
    perf_check('font_variants: a copy that cannot be written is not recorded', array_key_exists('copy', perf_generated($font_rule_src, $inter_only)), false);
    perf_check('font_variants: the write failure is logged', strpos(perf_log(), 'font_variants: cannot write') !== false, true);
    chmod($fonts_dir, 0777);
}
perf_check('font_variants: no temporary file left behind', glob($fonts_dir . '/*.tmp'), array());
$_GET = array();

// ── 11.4 inventory: registry and components of the published content ──────
class WP_Block_Type_Registry
{
    public static function get_instance() { return new self(); }
    public function get_all_registered() { return $GLOBALS['perf_block_types'] ?? array(); }
}
function wp_get_sidebars_widgets()
{
    return array(
        'wp_inactive_widgets' => array('archives-2'),
        'sidebar-1' => array('text-2', 'woocommerce_price_filter-3', 'block-4', 'block-5'),
        'orphaned_widgets_1' => array('calendar-2'),
        'array_version' => 3,
    );
}
class Perf_Test_Wpdb
{
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $terms = 'wp_terms';
    public $rows = array();
    public $meta = array();
    /** id => true: Elementor's builder mode on */
    public $edit_mode = array();
    public $class_queries = 0;
    /** id => [[taxonomy, slug]] */
    public $term_rows = array();
    /** id => _wp_page_template */
    public $templates = array();
    public $post_queries = 0;
    public $index_queries = 0;
    public function prepare($query, $args) { return array('sql' => $query, 'args' => (array) $args); }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    private function published($types, $below)
    {
        $out = array();
        foreach ($this->rows as $id => $row) {
            if ($id < $below && in_array($row['post_type'], $types, true)) {
                $out[$id] = $row;
            }
        }
        krsort($out);
        return $out;
    }
    public function get_results($prepared)
    {
        $args = $prepared['args'];
        if (strpos($prepared['sql'], 'GROUP BY p.post_type') !== false) {
            // classCounts: one row per type × raw template × builder
            $this->class_queries++;
            array_pop($args);
            $groups = array();
            foreach ($this->rows as $id => $row) {
                if (!in_array($row['post_type'], $args, true)) {
                    continue;
                }
                $template = $this->templates[$id] ?? null;
                $el = strpos($prepared['sql'], '_elementor_edit_mode') !== false && isset($this->edit_mode[$id]) ? '1' : '0';
                $key = $row['post_type'] . '|' . (string) $template . '|' . $el;
                if (!isset($groups[$key])) {
                    $groups[$key] = (object) array('type' => $row['post_type'], 'template' => $template, 'el' => $el, 'n' => '0', 'example' => '0');
                }
                $groups[$key]->n = (string) ((int) $groups[$key]->n + 1);
                $groups[$key]->example = (string) max((int) $groups[$key]->example, $id);
            }
            return array_values($groups);
        }
        if (strpos($prepared['sql'], "meta_key = '_elementor_edit_mode'") !== false) {
            $out = array();
            foreach ($args as $id) {
                if (isset($this->edit_mode[$id])) {
                    $out[] = (object) array('post_id' => (string) $id);
                }
            }
            return $out;
        }
        if (strpos($prepared['sql'], 'FROM wp_term_relationships') !== false) {
            $this->index_queries++;
            $taxonomies = array_filter($args, 'is_string');
            $out = array();
            foreach (array_filter($args, 'is_int') as $id) {
                foreach ($this->term_rows[$id] ?? array() as $term) {
                    if (in_array($term[0], $taxonomies, true)) {
                        $out[] = (object) array('object_id' => (string) $id, 'taxonomy' => $term[0], 'slug' => $term[1]);
                    }
                }
            }
            return $out;
        }
        if (strpos($prepared['sql'], "meta_key = '_wp_page_template'") !== false) {
            $this->index_queries++;
            $out = array();
            foreach ($args as $id) {
                if (isset($this->templates[$id])) {
                    $out[] = (object) array('post_id' => (string) $id, 'meta_value' => $this->templates[$id]);
                }
            }
            return $out;
        }
        if (strpos($prepared['sql'], 'FROM wp_postmeta') !== false) {
            $out = array();
            foreach ($args as $id) {
                if (isset($this->meta[$id])) {
                    $out[] = (object) array('post_id' => (string) $id, 'meta_value' => $this->meta[$id]);
                }
            }
            return $out;
        }
        $this->post_queries++;
        $limit = array_pop($args);
        $below = array_pop($args);
        $out = array();
        foreach (array_slice($this->published($args, $below), 0, $limit, true) as $id => $row) {
            $out[] = (object) array_merge(array('ID' => (string) $id, 'post_name' => 'p' . $id, 'post_parent' => '0', 'post_date' => '2026-10-19 00:00:00', 'post_status' => 'publish'), $row);
        }
        return $out;
    }
    public function get_var($prepared)
    {
        $args = $prepared['args'];
        $below = array_pop($args);
        $rows = $this->published($args, $below);
        return $rows ? (string) key($rows) : null;
    }
}
// the extensions declaring what the registry lists, as files of their own
function perf_php($path, $code)
{
    $file = $GLOBALS['perf_tmp'] . '/' . $path;
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, "<?php\n" . $code);
    return $file;
}
function plugins_url() { return 'https://example.com/wp-content/plugins'; }
function get_theme_root() { return WP_CONTENT_DIR . '/themes'; }
function get_theme_root_uri() { return 'https://example.com/wp-content/themes'; }
$acme_closure = require perf_php('wp-content/plugins/acme-filters/acme-filters.php', <<<'PHP'
function acme_filter_shortcode() { return ''; }
class Acme_Filters
{
    public static function render() { return ''; }
    public function shortcode() { return ''; }
    public function __invoke() { return ''; }
}
class Acme_Widget { public $id_base = 'acme_widget'; }
return function () { return ''; };
PHP
);
require perf_php('wp-content/plugins/hello.php', "function hello_shortcode() { return ''; }");
require perf_php('wp-content/mu-plugins/site-tools.php', "function site_tools_shortcode() { return ''; }");
require perf_php('wp-content/themes/blocksy/inc/shortcodes.php', "function blocksy_shortcode() { return ''; }\nclass Blocksy_Widget { public \$id_base = 'blocksy_posts'; }");
require perf_php('wp-content/themes/loose.php', "function loose_theme_file_shortcode() { return ''; }");
require perf_php('wp-includes/perf-core.php', <<<'PHP'
function perf_core_caption() { return ''; }
class Perf_Core_Widget_Text { public $id_base = 'text'; }
function perf_block_from_metadata($template_path)
{
    return static function () use ($template_path) { return $template_path; };
}
PHP
);
$acme_template = perf_php('wp-content/plugins/acme-blocks/render.php', "echo 'block';");
require perf_php('wp-content/plugins/elementor-pro/modules/carousel.php', "namespace ElementorPro;\nclass Carousel {}");
require perf_php('wp-content/plugins/elementor/includes/plugin.php', <<<'PHP'
namespace Elementor;
class Plugin
{
    public static $instance;
    public $widgets_manager;
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->widgets_manager = new Widgets_Manager();
        }
        return self::$instance;
    }
}
class Widgets_Manager
{
    public function get_widget_types()
    {
        if (!empty($GLOBALS['perf_elementor_broken'])) {
            throw new \RuntimeException('widgets not ready');
        }
        return array(
            'heading' => new Widget_Heading(),
            'image-carousel' => new \ElementorPro\Carousel(),
            'Bad Name' => new Widget_Heading(),
            'internal' => new \ArrayObject(),
        );
    }
}
class Widget_Heading {}
PHP
);
$GLOBALS['wp_widget_factory'] = (object) array('widgets' => array(
    'Perf_Core_Widget_Text' => new Perf_Core_Widget_Text(),
    'Acme_Widget' => new Acme_Widget(),
    'Blocksy_Widget' => new Blocksy_Widget(),
    'WP_Widget_Block' => (object) array('id_base' => 'block'),
));
$GLOBALS['shortcode_tags'] = array(
    'pwf_filter' => 'acme_filter_shortcode',
    'woosg' => array(new Acme_Filters(), 'shortcode'),
    'acme_static' => 'Acme_Filters::render',
    'acme_class_array' => array('Acme_Filters', 'render'),
    'acme_closure' => $acme_closure,
    'acme_invokable' => new Acme_Filters(),
    'hello' => 'hello_shortcode',
    'site_tools' => 'site_tools_shortcode',
    'blocksy_cta' => 'blocksy_shortcode',
    'caption' => 'perf_core_caption',
    'loose' => 'loose_theme_file_shortcode',
    'gallery' => 'strtolower',
    'missing' => 'no_such_function',
    'broken_method' => array('Acme_Filters', 'nope'),
    'bad tag' => 'acme_filter_shortcode',
);
$GLOBALS['perf_block_types'] = array(
    'core/paragraph' => (object) array('render_callback' => null),
    'stackable/columns' => (object) array('render_callback' => null, 'editor_script_handles' => array('ugb-editor'), 'style_handles' => array()),
    'acme/slider' => (object) array('render_callback' => array('Acme_Filters', 'render')),
    'acme/rendered' => (object) array('render_callback' => perf_block_from_metadata($acme_template)),
    'blocksy/hero' => (object) array('render_callback' => null, 'style_handles' => array('blocksy-hero')),
    'legacy/block' => (object) array('render_callback' => null, 'editor_script' => 'legacy-js'),
    'tools/block' => (object) array('render_callback' => null, 'script_handles' => array('tools-js')),
    'odd/wp-callback' => (object) array('render_callback' => 'perf_core_caption'),
    'ghost/block' => (object) array('render_callback' => null),
);
$GLOBALS['perf_scripts'] = (object) array('queue' => array(), 'done' => array(), 'registered' => array(
    'ugb-editor' => (object) array('src' => 'https://example.com/wp-content/plugins/stackable-ultimate-gutenberg-blocks/dist/editor.js', 'deps' => array()),
    'legacy-js' => (object) array('src' => '/wp-content/plugins/legacy-plugin/block.js', 'deps' => array()),
    'tools-js' => (object) array('src' => '/wp-content/mu-plugins/tools/block.js', 'deps' => array()),
));
$GLOBALS['perf_styles'] = (object) array('queue' => array(), 'done' => array(), 'registered' => array(
    'blocksy-hero' => (object) array('src' => '/wp-content/themes/blocksy/static/hero.css', 'deps' => array()),
));
$scan_db = new Perf_Test_Wpdb();
for ($id = 1000; $id < 1650; $id++) {
    $scan_db->rows[$id] = array('post_type' => 'post', 'post_content' => '');
}
$scan_db->rows[1649]['post_content'] = '<!-- wp:stackable/columns {"x":1} --><!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!-- /wp:stackable/columns -->[pwf_filter id=1][unknown]';
$scan_db->rows[1500] = array('post_type' => 'page', 'post_content' => '[pwf_filter][woosg ids="1"]');
$scan_db->rows[1400] = array('post_type' => 'product', 'post_content' => '[pwf_filter]');
$scan_db->rows[1300] = array('post_type' => 'page', 'post_content' => '[pwf_filter]');
$scan_db->rows[1200] = array('post_type' => 'post', 'post_content' => '<!-- wp:acme/slider /-->');
$scan_db->rows[1100] = array('post_type' => 'attachment', 'post_content' => '[gallery]');
$scan_db->rows[1050] = array('post_type' => 'page', 'post_content' => '');
$scan_db->rows[1002]['post_content'] = implode('', array_map(function ($index) {
    return '<!-- wp:block-' . $index . ' /-->';
}, range(1, 120)));
$scan_db->rows[1001] = array('post_type' => 'post', 'post_content' => '<!-- wp:core/quote --><!-- wp:image --><figure></figure><!-- /wp:image --><!-- /wp:core/quote -->');
$scan_db->meta[1050] = '[{"elType":"widget","widgetType":"image-carousel"},{"elType":"widget","widgetType":"heading"}]';
$scan_db->meta[1400] = '';
$GLOBALS['perf_scan_links'] = array(
    1649 => 'https://example.com/blog/filtres/',
    1500 => 'https://example.com/filtres-2/',
    1400 => 'https://example.com/produit/a/',
    1300 => 'https://example.com/filtres-3/',
    1200 => 'https://example.com/?p=1200',
    1050 => 'https://example.com/accueil-elementor/',
);
$GLOBALS['wpdb'] = $scan_db;
$GLOBALS['faaaster_test']['options']['active_plugins'][] = 'elementor/elementor.php';
$scanned = $rest->inventory();
$scan_queries = $scan_db->post_queries;
$registry = $scanned['registry'];
unset($registry['owners']);
perf_check('inventory registry: names a condition may use', $registry, array(
    'shortcodes' => array('pwf_filter', 'woosg', 'acme_static', 'acme_class_array', 'acme_closure', 'acme_invokable', 'hello', 'site_tools', 'blocksy_cta', 'caption', 'loose', 'gallery', 'missing', 'broken_method'),
    'blocks' => array('core/paragraph', 'stackable/columns', 'acme/slider', 'acme/rendered', 'blocksy/hero', 'legacy/block', 'tools/block', 'odd/wp-callback', 'ghost/block'),
    'widgets' => array('text', 'acme_widget', 'blocksy_posts', 'block'),
    'activeWidgets' => array('text', 'woocommerce_price_filter', 'block'),
));
$acme = 'plugin:acme-filters';
perf_check('registry owners: shortcodes by the file declaring each callback form', $scanned['registry']['owners']['shortcodes'], array(
    'pwf_filter' => $acme,
    'woosg' => $acme,
    'acme_static' => $acme,
    'acme_class_array' => $acme,
    'acme_closure' => $acme,
    'acme_invokable' => $acme,
    'hello' => 'plugin:hello',
    'site_tools' => 'mu-plugin:site-tools.php',
    'blocksy_cta' => 'theme:blocksy',
    'caption' => 'wordpress',
));
perf_check('registry owners: blocks by render callback, block.json template or assets', $scanned['registry']['owners']['blocks'], array(
    'core/paragraph' => 'wordpress',
    'stackable/columns' => 'plugin:stackable-ultimate-gutenberg-blocks',
    'acme/slider' => $acme,
    'acme/rendered' => 'plugin:acme-blocks',
    'blocksy/hero' => 'theme:blocksy',
    'legacy/block' => 'plugin:legacy-plugin',
    'tools/block' => 'mu-plugin:tools',
));
perf_check('registry owners: widgets by their class', $scanned['registry']['owners']['widgets'], array(
    'text' => 'wordpress',
    'acme_widget' => $acme,
    'blocksy_posts' => 'theme:blocksy',
));
perf_check('registry owners: Elementor widgets by their class', $scanned['registry']['owners']['elementorWidgets'], array(
    'heading' => 'plugin:elementor',
    'image-carousel' => 'plugin:elementor-pro',
));
$GLOBALS['perf_elementor_broken'] = true;
$broken_owners = $rest->inventory()['registry']['owners'];
perf_check('registry owners: Elementor failing leaves its map empty, never fatal', array(json_encode($broken_owners['elementorWidgets']), count($broken_owners['shortcodes'])), array('{}', 10));
perf_check('registry owners: the Elementor failure is logged', strpos(perf_log(), 'registry: Elementor widgets unreadable: widgets not ready') !== false, true);
unset($GLOBALS['perf_elementor_broken']);
perf_check('inventory components: registered shortcodes, up to 3 paths', $scanned['components']['shortcodes'], array(
    'pwf_filter' => array('/blog/filtres/', '/filtres-2/', '/produit/a/'),
    'woosg' => array('/filtres-2/'),
));
perf_check('inventory components: blocks outside core/, plain permalinks without path', $scanned['components']['blocks'], array(
    'stackable/columns' => array('/blog/filtres/'),
    'acme/slider' => array(),
));
perf_check('inventory components: Elementor widgets', $scanned['components']['elementorWidgets'], array(
    'image-carousel' => array('/accueil-elementor/'),
    'heading' => array('/accueil-elementor/'),
));
perf_check('inventory components: public contents only, read by chunks of 300', array($scanned['componentsScan'], $scan_queries), array(
    array('contents' => 649, 'complete' => true),
    3,
));
$post_blocks = $scanned['componentsByType']['post']['blocks'];
perf_check('components by type: each type with its contents and components, core blocks included', array(
    array_keys($scanned['componentsByType']),
    $scanned['componentsByType']['post']['contents'],
    array_slice($post_blocks, 0, 4),
    $scanned['componentsByType']['post']['shortcodes'],
    $scanned['componentsByType']['page'],
    $scanned['componentsByType']['product'],
), array(
    array('post', 'page', 'product'),
    645,
    array('stackable/columns', 'core/paragraph', 'acme/slider', 'core/block-1'),
    array('pwf_filter'),
    array('contents' => 3, 'blocks' => array(), 'shortcodes' => array('pwf_filter', 'woosg'), 'elementorWidgets' => array('image-carousel', 'heading')),
    array('contents' => 1, 'blocks' => array(), 'shortcodes' => array('pwf_filter'), 'elementorWidgets' => array()),
));
perf_check('components by type: 100 blocks at most per type', array(count($post_blocks), in_array('core/quote', $post_blocks, true)), array(100, false));
$bounded_db = new Perf_Test_Wpdb();
for ($id = 1; $id <= 3100; $id++) {
    $bounded_db->rows[$id] = array('post_type' => 'post', 'post_content' => '');
}
$bounded_db->rows[50]['post_content'] = '[woosg]';
$GLOBALS['wpdb'] = $bounded_db;
$bounded = $rest->inventory();
perf_check('inventory components: 3 000 contents at most, the scan says it is partial', array($bounded['componentsScan'], $bounded_db->post_queries), array(
    array('contents' => 3000, 'complete' => false),
    10,
));
perf_check('components by type: counts the contents read, the scan says it is partial', $bounded['componentsByType'], array(
    'post' => array('contents' => 3000, 'blocks' => array(), 'shortcodes' => array(), 'elementorWidgets' => array()),
));
perf_check('inventory components: empty maps stay JSON objects', json_encode($bounded['components']), '{"shortcodes":{},"blocks":{},"elementorWidgets":{}}');
$exact_db = new Perf_Test_Wpdb();
for ($id = 1; $id <= 3000; $id++) {
    $exact_db->rows[$id] = array('post_type' => 'page', 'post_content' => '');
}
$GLOBALS['wpdb'] = $exact_db;
perf_check('inventory components: exactly 3 000 contents is a complete scan', $rest->inventory()['componentsScan'], array('contents' => 3000, 'complete' => true));
$GLOBALS['wpdb'] = new Perf_Test_Wpdb();
perf_check('components by type: no content, an empty JSON object', json_encode($rest->inventory()['componentsByType']), '{}');
unset($GLOBALS['wpdb']);

// ── Benchmark: content conditions stay far below the 2 ms p95 objective ────
$bench_content = '';
while (strlen($bench_content) < 30000) {
    $bench_content .= "<!-- wp:paragraph --><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p><!-- /wp:paragraph -->\n"
        . "<!-- wp:acme/card {\"id\":3} --><div>[gallery ids=\"1,2,3\"]</div><!-- /wp:acme/card -->\n";
}
$bench_page = (object) array('ID' => 4242, 'post_type' => 'page', 'post_content' => $bench_content);
$GLOBALS['perf_meta'][4242] = array('_elementor_data' => str_repeat('{"elType":"widget","widgetType":"text-editor","settings":{"editor":"Lorem ipsum"}},', 200));
$GLOBALS['shortcode_tags'] = array('gallery' => 'cb');
$pool = array();
for ($index = 0; $index < 10; $index++) {
    $pool[] = array('kind' => 'has_block', 'value' => 'acme/block-' . $index);
    $pool[] = array('kind' => 'has_shortcode', 'value' => 'tag_' . $index);
    $GLOBALS['shortcode_tags']['tag_' . $index] = 'cb';
}
for ($index = 0; $index < 5; $index++) {
    $pool[] = array('kind' => 'elementor_widget', 'value' => 'widget-' . $index);
}
for ($index = 0; $index < 3; $index++) {
    $pool[] = array('kind' => 'active_widget', 'value' => 'widget_' . $index);
}
$pool[] = array('kind' => 'post_ids', 'value' => range(1, 200));
$pool[] = array('kind' => 'product_type', 'value' => 'grouped');
$bench_rules = array();
$bench_queue = array();
$bench_registered = array();
for ($index = 0; $index < 200; $index++) {
    $handle = 'cond-' . $index;
    $bench_rules[] = perf_rule('unload:cond-' . $index, 'unload_unused_asset', array('assetType' => 'style', 'handles' => array($handle)), array(
        $pool[$index % 30],
        $pool[($index + 7) % 30],
        $pool[($index + 13) % 30],
    ), array('singular'));
    $bench_queue[] = $handle;
    $bench_registered[$handle] = (object) array('src' => '/' . $handle . '.css', 'deps' => array());
}
$bench_settings = perf_live($bench_rules);
faaaster_test_reset_hooks();
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => true, 'archive' => false, 'object' => $bench_page);
$GLOBALS['perf_styles'] = (object) array('queue' => $bench_queue, 'done' => array(), 'registered' => $bench_registered);
$_SERVER['REQUEST_URI'] = '/bench/';
$_GET = array();
$durations = array();
for ($index = 0; $index < 300; $index++) {
    $GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
    // a fresh runtime per request: every condition evaluated cold
    $cold = new FaaasterPerformanceRuntime($bench_settings);
    $start = hrtime(true);
    $cold->unload_assets();
    $durations[] = (hrtime(true) - $start) / 1000000;
}
sort($durations);
$conditions_p95 = $durations[(int) floor(count($durations) * 0.95)];
perf_check('benchmark: 200 rules with 600 conditions (30 distinct), every rule applied', count($GLOBALS['perf_dequeued']['style']), 200);
perf_check('benchmark: conditions stay below 1 ms p95 (objective 2 ms)', $conditions_p95 < 1.0, true);
echo 'benchmark conditions: p95=' . round($conditions_p95, 3) . "ms (200 rules, 30 KB page)\n";

// ═══ 0.15.0: composable conditions (`when`), schema v2, rendering rules ═══

function perf_payload_v2($rules, $revision = 1)
{
    $payload = perf_payload($rules, $revision);
    $payload['schemaVersion'] = 'faaaster-performance-runtime-v2';
    return $payload;
}
function perf_refused_v2(array $rules)
{
    try {
        FaaasterPerformanceSettings::compile(perf_payload_v2($rules, 1));
    } catch (InvalidArgumentException $error) {
        return true;
    }
    return false;
}
function perf_live_v2(array $rules)
{
    $settings = new FaaasterPerformanceSettings();
    $settings->replace(perf_payload_v2($rules, perf_revision()));
    return $settings;
}
function perf_preview_v2(array $rules, $path, $token)
{
    global $rest;
    faaaster_test_reset_hooks();
    $GLOBALS['fta']['transients'] = array();
    $begun = $rest->begin_preview(new Perf_Test_Request(array(
        'token' => $token,
        'paths' => array($path),
        'compiled' => perf_payload_v2($rules, 1),
    )));
    if (!is_array($begun)) {
        fwrite(STDERR, 'preview refused: ' . $begun->get_error_message() . "\n");
    }
    $_SERVER['REQUEST_URI'] = $path . '?faaaster_perf_preview=' . $token;
    $_GET = array('faaaster_perf_preview' => $token);
}
/** A rule with a `when` condition (`unless` optional), on every context by default. */
function perf_when_rule($id, $type, $params, $when, $contexts = array('all'), $unless = null, $paths = array())
{
    $rule = perf_rule($id, $type, $params, $unless, $contexts, $paths);
    if ($when !== null) {
        $rule['when'] = $when;
    }
    return $rule;
}
function perf_unload_params($handle = 'cond-asset')
{
    return array('assetType' => 'style', 'handles' => array($handle));
}
/** A rule refused with this `when` (v2 payload). */
function perf_when_refused($when)
{
    return perf_refused_v2(array(perf_when_rule('unload:when', 'unload_unused_asset', perf_unload_params(), $when)));
}
/** Whether an unload rule with this `when` (and `unless`) applied on a request. */
function perf_when_applies($when, array $context = array(), $path = '/page/', $unless = null, $query_ran = true)
{
    faaaster_test_reset_hooks();
    if ($query_ran) {
        do_action('wp');
    }
    // `+`, not array_merge: the '404' key is numeric
    $GLOBALS['perf_context'] = $context + array('front_page' => false, 'singular' => false, 'archive' => false);
    $GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
    $GLOBALS['perf_styles'] = (object) array(
        'queue' => array('cond-asset'),
        'done' => array(),
        'registered' => array('cond-asset' => (object) array('src' => '/cond.css', 'deps' => array())),
    );
    $_SERVER['REQUEST_URI'] = $path;
    $_GET = array();
    $settings = perf_live_v2(array(perf_when_rule('unload:when', 'unload_unused_asset', perf_unload_params(), $when, array('all'), $unless)));
    (new FaaasterPerformanceRuntime($settings))->unload_assets();
    return $GLOBALS['perf_dequeued']['style'] === array('cond-asset');
}

// ── Conditional tags of the site: plugins and themes declaring is_* ───────
require perf_php('wp-content/plugins/acme-filters/conditionals.php', <<<'PHP'
function is_acme_shop() { $GLOBALS['perf_acme_calls'] = ($GLOBALS['perf_acme_calls'] ?? 0) + 1; return !empty($GLOBALS['perf_acme_shop']); }
function is_acme_optional($context = 'x') { return true; }
function is_acme_with_arg($context) { return true; }
function is_acme_throws() { throw new RuntimeException('not ready'); }
function is_acme_noisy() { echo 'noise'; return true; }
function acme_not_a_conditional() { return true; }
function is_acme_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb() { return true; }
PHP
);
require perf_php('wp-content/plugins/inactive-plugin/inactive.php', "function is_inactive_thing() { return true; }");
require perf_php('wp-content/mu-plugins/mu-tools.php', "function is_mu_thing() { return true; }");
require perf_php('wp-content/themes/astra/functions.php', "function is_astra_landing() { return true; }");
require perf_php('wp-content/themes/astra-parent/functions.php', "function is_parent_layout() { return false; }");
require perf_php('wp-content/themes/blocksy/inc/conditionals.php', "function is_blocksy_thing() { return true; }");
$GLOBALS['faaaster_test']['options']['active_plugins'] = array('woocommerce/woocommerce.php', 'acme-filters/acme-filters.php', 'hello.php');
unset($GLOBALS['faaaster_test']['options'][FaaasterPerformanceConditionals::OPTION]);
FaaasterPerformanceConditionals::reset();
$discovered = array(
    array('name' => 'is_acme_noisy', 'origin' => 'plugin:acme-filters'),
    array('name' => 'is_acme_optional', 'origin' => 'plugin:acme-filters'),
    array('name' => 'is_acme_shop', 'origin' => 'plugin:acme-filters'),
    array('name' => 'is_acme_throws', 'origin' => 'plugin:acme-filters'),
    array('name' => 'is_astra_landing', 'origin' => 'theme:astra'),
    array('name' => 'is_parent_layout', 'origin' => 'theme:astra-parent'),
);
perf_check('conditionals: active plugins and themes only, no required parameter, sorted', FaaasterPerformanceConditionals::discover(), $discovered);
perf_check('conditionals: discovered on first use when never stored', array(
    array_keys(FaaasterPerformanceConditionals::allowed()),
    get_option(FaaasterPerformanceConditionals::OPTION),
), array(array_column($discovered, 'name'), $discovered));
$GLOBALS['faaaster_test']['options'][FaaasterPerformanceConditionals::OPTION] = array(array('name' => 'is_acme_shop', 'origin' => 'plugin:acme-filters'));
FaaasterPerformanceConditionals::reset();
perf_check('conditionals: the stored list is what the runtime revalidates against', array_keys(FaaasterPerformanceConditionals::allowed()), array('is_acme_shop'));
perf_check('conditionals: refreshed (inventory, apply, preview)', FaaasterPerformanceConditionals::refresh(), $discovered);
$bulk = '';
for ($index = 0; $index < 70; $index++) {
    $bulk .= 'function is_bulk_' . sprintf('%02d', $index) . "() { return true; }\n";
}
require perf_php('wp-content/plugins/bulk-conditionals/bulk.php', $bulk);
$GLOBALS['faaaster_test']['options']['active_plugins'][] = 'bulk-conditionals/bulk.php';
perf_check('conditionals: 60 at most', count(FaaasterPerformanceConditionals::discover()), 60);
array_pop($GLOBALS['faaaster_test']['options']['active_plugins']);
FaaasterPerformanceConditionals::refresh();

// ── Schema v2 and the `when` grammar ───────────────────────────────────────
$every_atom = array(
    array('kind' => 'front_page'),
    array('kind' => 'home'),
    array('kind' => 'search'),
    array('kind' => 'not_found'),
    array('kind' => 'logged_in'),
    array('kind' => 'singular', 'postTypes' => array('product', 'page', 'product')),
    array('kind' => 'archive', 'postTypes' => array()),
    array('kind' => 'taxonomy', 'taxonomy' => 'product_cat', 'terms' => array('chaises', 'caf%c3%a9')),
    array('kind' => 'page', 'ids' => array(12, '13'), 'slugs' => array('panier', 'compte/commandes')),
    array('kind' => 'conditional', 'name' => 'is_acme_shop'),
    array('kind' => 'has_block', 'name' => 'stackable/columns'),
    array('kind' => 'has_shortcode', 'tag' => 'pwf_filter'),
    array('kind' => 'elementor_widget', 'name' => 'image-carousel'),
    array('kind' => 'widget', 'idBase' => 'woocommerce_price_filter'),
    array('kind' => 'has_term', 'taxonomy' => 'product_type', 'terms' => array('variable')),
    array('kind' => 'product_type', 'types' => array('variable', 'grouped')),
    array('kind' => 'template', 'slugs' => array('default', 'templates/full-width.php')),
    array('kind' => 'path', 'prefix' => '/boutique/'),
    array('kind' => 'path', 'exact' => array('/panier/', '/commande/')),
);
$when_rules = array(
    perf_when_rule('unload:when-a', 'unload_unused_asset', perf_unload_params(), array('all' => array_slice($every_atom, 0, 10)), array('singular'), array(array('kind' => 'post_ids', 'value' => array(4)))),
    perf_when_rule('unload:when-b', 'unload_unused_asset', perf_unload_params(), array('any' => array_slice($every_atom, 10))),
    perf_rule('unload:plain-v2', 'unload_unused_asset', perf_unload_params('plain')),
);
$v2_compiled = FaaasterPerformanceSettings::compile(perf_payload_v2($when_rules, 3));
perf_check('v2: every atom of the table compiles, the checksum unchanged', array($v2_compiled['schemaVersion'], $v2_compiled['checksum']), array('faaaster-performance-runtime-v2', perf_payload_v2($when_rules, 3)['checksum']));
perf_check('v2: the clean rule keeps when after unless', array_keys($v2_compiled['rules'][0]), array('id', 'type', 'contexts', 'paths', 'unless', 'when', 'params'));
perf_check('v2: a rule without when has none', array_keys($v2_compiled['rules'][2]), array('id', 'type', 'contexts', 'paths', 'params'));
perf_check('v2: atoms normalized (lists deduplicated, empty dropped, ids as integers)', array_slice($v2_compiled['rules'][0]['when']['all'], 5, 4), array(
    array('kind' => 'singular', 'postTypes' => array('product', 'page')),
    array('kind' => 'archive'),
    array('kind' => 'taxonomy', 'taxonomy' => 'product_cat', 'terms' => array('chaises', 'caf%c3%a9')),
    array('kind' => 'page', 'ids' => array(12, 13), 'slugs' => array('panier', 'compte/commandes')),
));
$when_target = $v2_compiled['compiled']['unload']['style']['cond-asset'][0];
perf_check('v2: the compiled target keeps the rule id, unless and when', array($when_target['rule'], count($when_target['unless']), count($when_target['when']['all'])), array('unload:when-a', 1, 10));
perf_check('v2: each compiled atom carries its memo key', $when_target['when']['all'][9], array(
    'kind' => 'conditional',
    'name' => 'is_acme_shop',
    'key' => '{"kind":"conditional","name":"is_acme_shop"}',
));
perf_check('v2: the key is canonical whatever the order sent', FaaasterPerformanceSettings::compile(perf_payload_v2(array(
    perf_when_rule('unload:when-order', 'unload_unused_asset', perf_unload_params(), array('taxonomy' => 'product_cat', 'kind' => 'taxonomy')),
), 1))['compiled']['unload']['style']['cond-asset'][0]['when']['key'], '{"kind":"taxonomy","taxonomy":"product_cat"}');
perf_check('v2: a set without when or new type is accepted', perf_refused_v2(array(perf_rule('unload:v2-plain', 'unload_unused_asset', perf_unload_params()))), false);
perf_check('v1: a rule with when is refused', perf_refused(array(perf_when_rule('unload:when', 'unload_unused_asset', perf_unload_params(), array('kind' => 'front_page')))), true);
perf_check('v1: even a null when is refused', perf_refused(array(perf_when_rule('unload:when', 'unload_unused_asset', perf_unload_params(), null) + array('when' => null))), true);
foreach (array(
    'early_layout_rules' => array('rules' => array(array('selector' => '.wp-container-core-group-is-layout-1', 'declarations' => 'display:flex'))),
    'drop_preloads' => array('crossOrigin' => true, 'withoutHighPriority' => false),
    'image_priority' => array('signature' => array('wp-post-image'), 'rank' => 0, 'eager' => 1),
    'defer_backgrounds' => array('items' => array(array('selector' => '.hero', 'url' => 'https://example.com/bg.jpg'))),
) as $type => $params) {
    perf_check('v1: a ' . $type . ' rule is refused', perf_refused(array(perf_rule('v2:' . $type, $type, $params, null, array('all')))), true);
    perf_check('v2: a ' . $type . ' rule is accepted', perf_refused_v2(array(perf_rule('v2:' . $type, $type, $params, null, array('all')))), false);
}
perf_check('an unknown schema is refused', (function () {
    $payload = perf_payload(array());
    $payload['schemaVersion'] = 'faaaster-performance-runtime-v3';
    try {
        FaaasterPerformanceSettings::compile($payload);
    } catch (InvalidArgumentException $error) {
        return true;
    }
    return false;
})(), true);

$atom = array('kind' => 'front_page');
$sixteen = array_fill(0, 16, $atom);
foreach (array(
    'depth 4 (root included)' => array(array('all' => array(array('any' => array(array('not' => $atom))))), false),
    'depth 5' => array(array('all' => array(array('any' => array(array('not' => array('not' => $atom)))))), true),
    'four nested not' => array(array('not' => array('not' => array('not' => $atom))), false),
    'five nested not' => array(array('not' => array('not' => array('not' => array('not' => $atom)))), true),
    '16 atoms' => array(array('all' => $sixteen), false),
    '17 atoms across lists' => array(array('all' => array(array('any' => $sixteen), $atom)), true),
    '17 nodes in a list' => array(array('any' => array_fill(0, 17, $atom)), true),
    'empty list' => array(array('all' => array()), true),
    'list as root' => array(array($atom), true),
    'text as root' => array('front_page', true),
    'empty object as root' => array(array(), true),
    'operator over an object' => array(array('all' => $atom), true),
    'two operators' => array(array('all' => array($atom), 'any' => array($atom)), true),
    'operator and kind' => array(array('not' => $atom, 'kind' => 'home'), true),
    'not over a list' => array(array('not' => array($atom)), true),
    'unknown kind' => array(array('kind' => 'is_mobile'), true),
    'cookie kind' => array(array('kind' => 'cookie', 'name' => 'wp_lang'), true),
    'kind not a string' => array(array('kind' => array('front_page')), true),
    'unknown parameter' => array(array('kind' => 'front_page', 'ids' => array(1)), true),
    'logged_in with a parameter' => array(array('kind' => 'logged_in', 'role' => 'admin'), true),
    '11 post types' => array(array('kind' => 'singular', 'postTypes' => array_map('strval', range(1, 11))), true),
    'upper-case post type' => array(array('kind' => 'singular', 'postTypes' => array('Product')), true),
    'post types not a list' => array(array('kind' => 'archive', 'postTypes' => 'product'), true),
    'taxonomy without taxonomy' => array(array('kind' => 'taxonomy', 'terms' => array('a')), true),
    'a term slug as stored (case, dot)' => array(array('kind' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array('Vins', 'v1.2')), false),
    'a term slug with a space' => array(array('kind' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array('vins rouges')), true),
    'a term slug with a slash' => array(array('kind' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array('vins/rouges')), true),
    'a term slug over 200' => array(array('kind' => 'has_term', 'taxonomy' => 'category', 'terms' => array(str_repeat('a', 201))), true),
    '51 terms' => array(array('kind' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array_map('strval', range(1, 51))), true),
    'page without ids or slugs' => array(array('kind' => 'page'), true),
    'page with empty lists' => array(array('kind' => 'page', 'ids' => array(), 'slugs' => array()), true),
    'page id zero' => array(array('kind' => 'page', 'ids' => array(0)), true),
    'page slug with a space' => array(array('kind' => 'page', 'slugs' => array('mon panier')), true),
    'page slug ending with a slash' => array(array('kind' => 'page', 'slugs' => array('compte/')), true),
    'page slug with an empty segment' => array(array('kind' => 'page', 'slugs' => array('compte//commandes')), true),
    'conditional not is_' => array(array('kind' => 'conditional', 'name' => 'has_cart'), true),
    'conditional upper-case' => array(array('kind' => 'conditional', 'name' => 'is_Shop'), true),
    'conditional too long' => array(array('kind' => 'conditional', 'name' => 'is_' . str_repeat('a', 61)), true),
    'conditional with arguments' => array(array('kind' => 'conditional', 'name' => 'is_shop', 'args' => array(1)), true),
    'block without namespace' => array(array('kind' => 'has_block', 'name' => 'columns'), true),
    'shortcode under value' => array(array('kind' => 'has_shortcode', 'value' => 'pwf_filter'), true),
    'shortcode with a space' => array(array('kind' => 'has_shortcode', 'tag' => 'pwf filter'), true),
    'upper-case Elementor widget' => array(array('kind' => 'elementor_widget', 'name' => 'Image'), true),
    'upper-case widget' => array(array('kind' => 'widget', 'idBase' => 'Price'), true),
    'has_term without terms' => array(array('kind' => 'has_term', 'taxonomy' => 'product_cat'), true),
    'has_term with no term' => array(array('kind' => 'has_term', 'taxonomy' => 'product_cat', 'terms' => array()), true),
    'product types empty' => array(array('kind' => 'product_type', 'types' => array()), true),
    '11 product types' => array(array('kind' => 'product_type', 'types' => array_map('strval', range(1, 11))), true),
    'template without slugs' => array(array('kind' => 'template'), true),
    '21 templates' => array(array('kind' => 'template', 'slugs' => array_map('strval', range(1, 21))), true),
    'template with a space' => array(array('kind' => 'template', 'slugs' => array('full width')), true),
    'path without prefix or exact' => array(array('kind' => 'path'), true),
    'path with both' => array(array('kind' => 'path', 'prefix' => '/a/', 'exact' => array('/b/')), true),
    'path prefix without slash' => array(array('kind' => 'path', 'prefix' => 'boutique/'), true),
    'path prefix over 200' => array(array('kind' => 'path', 'prefix' => '/' . str_repeat('a', 200)), true),
    'path exact empty' => array(array('kind' => 'path', 'exact' => array()), true),
    'path exact with a query' => array(array('kind' => 'path', 'exact' => array('/panier/?x=1')), true),
    '51 exact paths' => array(array('kind' => 'path', 'exact' => array_map(function ($i) { return '/p' . $i . '/'; }, range(1, 51))), true),
) as $label => $case) {
    perf_check('when ' . ($case[1] ? 'refused' : 'accepted') . ': ' . $label, perf_when_refused($case[0]), $case[1]);
}

// ── `when`: evaluation of each atom ─────────────────────────────────────────
$product_object = (object) array('ID' => 31, 'post_type' => 'product', 'post_name' => 'chaise', 'post_content' => '');
$cart_page = (object) array('ID' => 22, 'post_type' => 'page', 'post_name' => 'panier', 'post_content' => '');
$chairs_term = (object) array('term_id' => 5, 'slug' => 'chaises', 'taxonomy' => 'product_cat', 'description' => 'Nos chaises [gallery ids="1"]');
$wines_term = (object) array('term_id' => 6, 'slug' => 'vins', 'taxonomy' => 'category', 'description' => '');
$GLOBALS['perf_post_terms'] = array(31 => array('product_cat' => array('chaises', 'bois')));
$GLOBALS['perf_page_templates'] = array(22 => 'templates/full-width.php');
$GLOBALS['perf_active_widgets'] = array('woocommerce_price_filter');
$singular_product = array('singular' => true, 'product' => true, 'object' => $product_object);
$cart = array('singular' => true, 'is_page' => true, 'object' => $cart_page);
$builder = array('singular' => true, 'object' => $builder_page);
$chairs = array('archive' => true, 'taxonomies' => array('taxonomy:product_cat'), 'object' => $chairs_term);
$wines = array('archive' => true, 'taxonomies' => array('taxonomy:category'), 'object' => $wines_term);
foreach (array(
    'front_page on the front page' => array(array('kind' => 'front_page'), array('front_page' => true), true),
    'front_page elsewhere' => array(array('kind' => 'front_page'), $cart, false),
    'home on the posts page' => array(array('kind' => 'home'), array('home' => true), true),
    'search' => array(array('kind' => 'search'), array('search' => true), true),
    'not_found' => array(array('kind' => 'not_found'), array('404' => true), true),
    'not_found on a page' => array(array('kind' => 'not_found'), $cart, false),
    'logged_in for a visitor' => array(array('kind' => 'logged_in'), $cart, false),
    'logged_in for a member' => array(array('kind' => 'logged_in'), array('logged_in' => true) + $cart, true),
    'singular' => array(array('kind' => 'singular'), $cart, true),
    'singular of a listed type' => array(array('kind' => 'singular', 'postTypes' => array('product')), $singular_product, true),
    'singular of another type' => array(array('kind' => 'singular', 'postTypes' => array('product')), $cart, false),
    'archive' => array(array('kind' => 'archive'), $chairs, true),
    'archive of a listed type' => array(array('kind' => 'archive', 'postTypes' => array('product')), array('archive' => true, 'post_type_archive' => 'product'), true),
    'archive of another type' => array(array('kind' => 'archive', 'postTypes' => array('event')), array('archive' => true, 'post_type_archive' => 'product'), false),
    'taxonomy archive' => array(array('kind' => 'taxonomy', 'taxonomy' => 'product_cat'), $chairs, true),
    'taxonomy archive of a listed term' => array(array('kind' => 'taxonomy', 'taxonomy' => 'product_cat', 'terms' => array('tables', 'chaises')), $chairs, true),
    'taxonomy archive of another term' => array(array('kind' => 'taxonomy', 'taxonomy' => 'product_cat', 'terms' => array('tables')), $chairs, false),
    'category through is_category' => array(array('kind' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array('vins')), $wines, true),
    'category atom on a product category' => array(array('kind' => 'taxonomy', 'taxonomy' => 'category'), $chairs, false),
    'post_tag through is_tag' => array(array('kind' => 'taxonomy', 'taxonomy' => 'post_tag'), array('archive' => true, 'taxonomies' => array('taxonomy:post_tag')), true),
    'page by id' => array(array('kind' => 'page', 'ids' => array(3, 22)), $cart, true),
    'page by slug' => array(array('kind' => 'page', 'slugs' => array('panier')), $cart, true),
    'another page' => array(array('kind' => 'page', 'ids' => array(3), 'slugs' => array('commande')), $cart, false),
    'has_block' => array(array('kind' => 'has_block', 'name' => 'stackable/columns'), $builder, true),
    'has_block absent' => array(array('kind' => 'has_block', 'name' => 'acme/slider'), $builder, false),
    'has_shortcode in the content' => array(array('kind' => 'has_shortcode', 'tag' => 'gallery'), $builder, true),
    'has_shortcode in the term description' => array(array('kind' => 'has_shortcode', 'tag' => 'gallery'), $chairs, true),
    'has_shortcode absent from the term description' => array(array('kind' => 'has_shortcode', 'tag' => 'gallery'), $wines, false),
    'elementor_widget' => array(array('kind' => 'elementor_widget', 'name' => 'image-carousel'), $builder, true),
    'widget placed' => array(array('kind' => 'widget', 'idBase' => 'woocommerce_price_filter'), $cart, true),
    'widget not placed' => array(array('kind' => 'widget', 'idBase' => 'calendar'), $cart, false),
    'has_term' => array(array('kind' => 'has_term', 'taxonomy' => 'product_cat', 'terms' => array('bois')), $singular_product, true),
    'has_term absent' => array(array('kind' => 'has_term', 'taxonomy' => 'product_cat', 'terms' => array('metal')), $singular_product, false),
    'has_term off a singular page' => array(array('kind' => 'has_term', 'taxonomy' => 'product_cat', 'terms' => array('chaises')), $chairs, false),
    'product_type listed' => array(array('kind' => 'product_type', 'types' => array('variable', 'grouped')), $singular_product, true),
    'product_type not listed' => array(array('kind' => 'product_type', 'types' => array('variable')), $singular_product, false),
    'product_type off a product page' => array(array('kind' => 'product_type', 'types' => array('grouped')), $cart, false),
    'template set' => array(array('kind' => 'template', 'slugs' => array('templates/full-width.php')), $cart, true),
    'template default' => array(array('kind' => 'template', 'slugs' => array('default')), $singular_product, true),
    'template other' => array(array('kind' => 'template', 'slugs' => array('default')), $cart, false),
    'template off a singular page' => array(array('kind' => 'template', 'slugs' => array('default')), $chairs, false),
    'path prefix' => array(array('kind' => 'path', 'prefix' => '/boutique/'), $cart, true, '/boutique/chaises/'),
    'path prefix elsewhere' => array(array('kind' => 'path', 'prefix' => '/boutique/'), $cart, false, '/blog/boutique/'),
    'path exact, query ignored' => array(array('kind' => 'path', 'exact' => array('/panier/')), $cart, true, '/panier/?add-to-cart=12'),
    'path exact elsewhere' => array(array('kind' => 'path', 'exact' => array('/panier/')), $cart, false, '/panier/etape-2/'),
    'conditional true' => array(array('kind' => 'conditional', 'name' => 'is_acme_optional'), $cart, true),
    'conditional false' => array(array('kind' => 'conditional', 'name' => 'is_parent_layout'), $cart, false),
) as $label => $case) {
    perf_check('when atom: ' . $label, perf_when_applies($case[0], $case[1], $case[3] ?? '/page/'), $case[2]);
}

// ── `when`: the tree, unless, readiness ─────────────────────────────────────
$front = array('kind' => 'front_page');
$member = array('kind' => 'logged_in');
foreach (array(
    'all, every atom true' => array(array('all' => array(array('kind' => 'singular'), array('kind' => 'page', 'ids' => array(22)))), true),
    'all, one atom false' => array(array('all' => array(array('kind' => 'singular'), $front)), false),
    'any, one atom true' => array(array('any' => array($front, array('kind' => 'page', 'slugs' => array('panier')))), true),
    'any, every atom false' => array(array('any' => array($front, $member)), false),
    'not' => array(array('not' => $front), true),
    'not over a true atom' => array(array('not' => array('kind' => 'singular')), false),
    'nested: product or cart page, not for members' => array(array('all' => array(
        array('any' => array(array('kind' => 'product_type', 'types' => array('variable')), array('kind' => 'page', 'slugs' => array('panier')))),
        array('not' => $member),
    )), true),
) as $label => $case) {
    perf_check('when tree: ' . $label, perf_when_applies($case[0], $cart), $case[1]);
}
$has_stackable = array(array('kind' => 'has_block', 'value' => 'stackable/columns'));
perf_check('when and unless: a holding unless withholds a true when', perf_when_applies(array('kind' => 'singular'), $builder, '/page/', $has_stackable), false);
perf_check('when and unless: a false when withholds a rule unless spares', perf_when_applies($front, $cart, '/page/', $has_stackable), false);
perf_check('when and unless: both satisfied', perf_when_applies(array('kind' => 'singular'), $cart, '/page/', $has_stackable), true);
perf_check('when: before the main query the rule waits', perf_when_applies(array('kind' => 'singular'), $cart, '/page/', null, false), false);
perf_check('when: even a path condition waits for the main query', perf_when_applies(array('kind' => 'path', 'prefix' => '/'), $cart, '/page/', null, false), false);

// atoms evaluated once per request, the tree short-circuits
faaaster_test_reset_hooks();
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => false, 'archive' => true, 'logged_in' => true, 'taxonomies' => array('taxonomy:product_cat'), 'object' => $chairs_term);
$GLOBALS['perf_logged_in_calls'] = 0;
$GLOBALS['perf_is_tax_calls'] = 0;
$GLOBALS['perf_acme_calls'] = 0;
$GLOBALS['perf_acme_shop'] = true;
$memo_rules = array();
$memo_queue = array();
$memo_registered = array();
for ($index = 0; $index < 20; $index++) {
    $memo_rules[] = perf_when_rule('unload:when-memo-' . $index, 'unload_unused_asset', perf_unload_params('memo-' . $index), array('all' => array(
        $member,
        // the same atom, its parameters sent in another order
        $index % 2 ? array('kind' => 'taxonomy', 'taxonomy' => 'product_cat') : array('taxonomy' => 'product_cat', 'kind' => 'taxonomy'),
        array('kind' => 'conditional', 'name' => 'is_acme_shop'),
        array('any' => array(array('kind' => 'archive'), $member)),
    )));
    $memo_queue[] = 'memo-' . $index;
    $memo_registered['memo-' . $index] = (object) array('src' => '/memo.css', 'deps' => array());
}
$memo_rules[] = perf_when_rule('unload:when-short', 'unload_unused_asset', perf_unload_params('memo-short'), array('all' => array($front, array('kind' => 'search'))));
$memo_queue[] = 'memo-short';
$memo_registered['memo-short'] = (object) array('src' => '/memo.css', 'deps' => array());
$GLOBALS['perf_styles'] = (object) array('queue' => $memo_queue, 'done' => array(), 'registered' => $memo_registered);
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$_SERVER['REQUEST_URI'] = '/categorie/chaises/';
$_GET = array();
$memo_runtime = new FaaasterPerformanceRuntime(perf_live_v2($memo_rules));
$memo_runtime->unload_assets();
$memo_runtime->unload_assets();
perf_check('when memoized: 21 rules evaluated twice, each atom once', array($GLOBALS['perf_logged_in_calls'], $GLOBALS['perf_is_tax_calls'], $GLOBALS['perf_acme_calls']), array(1, 1, 1));
perf_check('when memoized: the 20 rules applied, the false one withheld', count($GLOBALS['perf_dequeued']['style']), 40);
$GLOBALS['perf_logged_in_calls'] = 0;
perf_check('when short-circuits: all stops at the first false atom', array(
    perf_when_applies(array('all' => array($front, $member)), $cart),
    $GLOBALS['perf_logged_in_calls'],
), array(false, 0));
perf_check('when short-circuits: any stops at the first true atom', array(
    perf_when_applies(array('any' => array(array('kind' => 'singular'), $member)), $cart),
    $GLOBALS['perf_logged_in_calls'],
), array(true, 0));

// ── `conditional`: fail-safe ────────────────────────────────────────────────
$GLOBALS['perf_acme_shop'] = false;
perf_check('conditional: a discovered tag answering false withholds', perf_when_applies(array('kind' => 'conditional', 'name' => 'is_acme_shop'), $cart), false);
$GLOBALS['perf_acme_shop'] = true;
perf_check('conditional: a discovered tag answering true applies', perf_when_applies(array('kind' => 'conditional', 'name' => 'is_acme_shop'), $cart), true);
$log_before = perf_log();
$throwing = array('kind' => 'conditional', 'name' => 'is_acme_throws');
perf_check('conditional: a tag that throws withholds the rule', perf_when_applies($throwing, $cart), false);
perf_check('conditional: under not too, never a negated error', perf_when_applies(array('not' => $throwing), $cart), false);
perf_check('conditional: in any after a true atom, never called', perf_when_applies(array('any' => array(array('kind' => 'singular'), $throwing)), $cart), true);
perf_check('conditional: an undiscovered function is never called', perf_when_applies(array('kind' => 'conditional', 'name' => 'is_inactive_thing'), $cart), false);
perf_check('conditional: the errors are logged', array(
    strpos(substr(perf_log(), strlen($log_before)), 'conditional is_acme_throws: threw RuntimeException: not ready, the rules using it do not apply') !== false,
    strpos(substr(perf_log(), strlen($log_before)), 'conditional is_inactive_thing: not a discovered conditional') !== false,
), array(true, true));
$GLOBALS['faaaster_test']['options'][FaaasterPerformanceConditionals::OPTION][] = array('name' => 'is_ghost_function', 'origin' => 'plugin:acme-filters');
FaaasterPerformanceConditionals::reset();
perf_check('conditional: a listed function gone since withholds', perf_when_applies(array('kind' => 'conditional', 'name' => 'is_ghost_function'), $cart), false);
perf_check('conditional: the missing function is logged', strpos(perf_log(), 'conditional is_ghost_function: undefined function') !== false, true);
FaaasterPerformanceConditionals::refresh();
ob_start();
$noisy = perf_when_applies(array('kind' => 'conditional', 'name' => 'is_acme_noisy'), $cart);
perf_check('conditional: what a tag prints is discarded', array($noisy, ob_get_clean()), array(true, ''));
// three rules on the same failing tag: one log line for the request
faaaster_test_reset_hooks();
do_action('wp');
$GLOBALS['perf_context'] = $cart;
$GLOBALS['perf_styles'] = (object) array('queue' => array('a', 'b', 'c'), 'done' => array(), 'registered' => array(
    'a' => (object) array('src' => '/a.css', 'deps' => array()),
    'b' => (object) array('src' => '/b.css', 'deps' => array()),
    'c' => (object) array('src' => '/c.css', 'deps' => array()),
));
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$log_before = substr_count(perf_log(), 'conditional is_acme_throws');
(new FaaasterPerformanceRuntime(perf_live_v2(array(
    perf_when_rule('unload:throw-a', 'unload_unused_asset', perf_unload_params('a'), $throwing),
    perf_when_rule('unload:throw-b', 'unload_unused_asset', perf_unload_params('b'), array('not' => $throwing)),
    perf_when_rule('unload:throw-c', 'unload_unused_asset', perf_unload_params('c'), array('all' => array(array('kind' => 'singular'), $throwing))),
))))->unload_assets();
perf_check('conditional: one log line per request and name, every rule withheld', array(substr_count(perf_log(), 'conditional is_acme_throws') - $log_before, $GLOBALS['perf_dequeued']['style']), array(1, array()));

// ── Receipt: verdicts, errors, skipped ──────────────────────────────────────
$verdict_token = str_repeat('a7', 24);
perf_preview_v2(array(
    perf_rule('unload:r-plain', 'unload_unused_asset', perf_unload_params('r-plain'), null, array('all')),
    perf_when_rule('unload:r-when-false', 'unload_unused_asset', perf_unload_params('r-when-false'), array('kind' => 'home')),
    perf_rule('unload:r-elsewhere', 'unload_unused_asset', perf_unload_params('r-elsewhere'), null, array('archive')),
    perf_rule('unload:r-unless', 'unload_unused_asset', perf_unload_params('r-unless'), array(array('kind' => 'has_shortcode', 'value' => 'gallery')), array('all')),
    perf_when_rule('unload:r-error', 'unload_unused_asset', perf_unload_params('r-error'), $throwing),
    perf_rule('image:r-never-reached', 'image_delivery', array('attachmentIds' => array(999), 'loading' => 'eager', 'fetchPriority' => 'high'), null, array('all')),
    perf_when_rule('unload:r-when-true', 'unload_unused_asset', perf_unload_params('r-when-true'), array('kind' => 'singular')),
), '/composants/', $verdict_token);
do_action('wp');
$GLOBALS['perf_context'] = $builder;
$GLOBALS['perf_styles'] = (object) array('queue' => array('r-plain', 'r-when-false', 'r-elsewhere', 'r-unless', 'r-error', 'r-when-true'), 'done' => array(), 'registered' => array());
foreach ($GLOBALS['perf_styles']->queue as $handle) {
    $GLOBALS['perf_styles']->registered[$handle] = (object) array('src' => '/' . $handle . '.css', 'deps' => array());
}
$GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
$verdict_runtime = new FaaasterPerformanceRuntime(perf_live(array()));
$verdict_runtime->register();
$verdict_runtime->unload_assets();
$verdict_receipt = perf_receipt('/composants/', $verdict_token);
perf_check('receipt conditions: the verdict of every rule whose scope matches, hooked or not', $verdict_receipt['conditions'], array(
    'image:r-never-reached' => true,
    'unload:r-error' => false,
    'unload:r-plain' => true,
    'unload:r-unless' => false,
    'unload:r-when-false' => false,
    'unload:r-when-true' => true,
));
perf_check('receipt conditionErrors: the failing tags', $verdict_receipt['conditionErrors'], array('is_acme_throws'));
perf_check('receipt skipped: withheld by unless or when', $verdict_receipt['skipped'], array('unload:r-when-false', 'unload:r-unless', 'unload:r-error'));
perf_check('receipt: the applied rules', $verdict_receipt['dequeued']['style'], array('r-plain', 'r-when-true'));
perf_check('receipt: the 0.15 fields, empty maps as JSON objects', json_encode(array_intersect_key(
    perf_receipt('/composants/', $verdict_token),
    array_flip(array('earlyLayout', 'droppedPreloads', 'imagePriority', 'deferredBackgrounds'))
)), '{"earlyLayout":0,"droppedPreloads":[],"imagePriority":{},"deferredBackgrounds":[]}');
$empty_token = str_repeat('a8', 24);
perf_preview_v2(array(perf_rule('unload:r-elsewhere', 'unload_unused_asset', perf_unload_params('r-elsewhere'), null, array('archive'))), '/composants/', $empty_token);
do_action('wp');
(new FaaasterPerformanceRuntime(perf_live(array())))->register();
perf_check('receipt conditions: no rule in scope, an empty JSON object', json_encode(perf_receipt('/composants/', $empty_token)['conditions']), '{}');
$_GET = array();

// ── early_layout_rules ──────────────────────────────────────────────────────
function perf_layout($rules)
{
    return array('rules' => $rules);
}
$grid = '.wp-container-core-group-is-layout-3';
$layout_compiled = FaaasterPerformanceSettings::compile(perf_payload_v2(array(perf_rule('layout:grid', 'early_layout_rules', perf_layout(array(
    array('selector' => $grid, 'declarations' => ' display : grid ;grid-template-columns:repeat(auto-fill, minmax(min(12rem, 100%), 1fr));gap:var(--wp--preset--spacing--40);'),
    array('selector' => $grid, 'declarations' => 'display:block'),
    array('selector' => '.wp-container-core-columns-is-layout-1', 'declarations' => 'flex-wrap:nowrap;column-gap:calc(2rem + 1%)'),
)), null, array('all'))), 1));
perf_check('early_layout_rules: declarations normalized, selectors deduplicated (first kept)', $layout_compiled['rules'][0]['params'], perf_layout(array(
    array('selector' => $grid, 'declarations' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(min(12rem, 100%), 1fr));gap:var(--wp--preset--spacing--40)'),
    array('selector' => '.wp-container-core-columns-is-layout-1', 'declarations' => 'flex-wrap:nowrap;column-gap:calc(2rem + 1%)'),
)));
perf_check('early_layout_rules: compiled with its target', array_keys($layout_compiled['compiled']['earlyLayout'][0]), array('target', 'rules'));
foreach (array(
    'no rule' => array(),
    '41 rules' => array_map(function ($i) { return array('selector' => '.wp-container-x-' . $i, 'declarations' => 'display:flex'); }, range(1, 41)),
    'another class' => array(array('selector' => '.is-layout-flex', 'declarations' => 'display:flex')),
    'a compound selector' => array(array('selector' => '.wp-container-1 > *', 'declarations' => 'display:flex')),
    'an upper-case selector' => array(array('selector' => '.wp-container-Core-1', 'declarations' => 'display:flex')),
    'a property off the list' => array(array('selector' => '.wp-container-1', 'declarations' => 'display:flex;position:fixed')),
    'a colour' => array(array('selector' => '.wp-container-1', 'declarations' => 'color:red')),
    'important' => array(array('selector' => '.wp-container-1', 'declarations' => 'display:flex !important')),
    'url()' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:url(x)')),
    'an empty declaration' => array(array('selector' => '.wp-container-1', 'declarations' => 'display:flex;;gap:0')),
    'an empty string' => array(array('selector' => '.wp-container-1', 'declarations' => '')),
    'a value of spaces' => array(array('selector' => '.wp-container-1', 'declarations' => 'display: ')),
    'a brace' => array(array('selector' => '.wp-container-1', 'declarations' => 'display:flex}body{display:none')),
    'a tag' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:1px</style>')),
    'an at-rule' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:1px@import')),
    'a backslash' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:\\31 px')),
    'a quote' => array(array('selector' => '.wp-container-1', 'declarations' => 'grid-template-columns:"a"')),
    'a comment' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:1px /* x */')),
    'an unbalanced parenthesis' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:calc(1px')),
    'a closing parenthesis first' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:1px)(')),
    'over 600 bytes' => array(array('selector' => '.wp-container-1', 'declarations' => 'width:' . str_repeat('1', 600))),
    'declarations not a string' => array(array('selector' => '.wp-container-1', 'declarations' => array('display' => 'flex'))),
) as $label => $rules) {
    perf_check('early_layout_rules refused: ' . $label, perf_refused_v2(array(perf_rule('layout:bad', 'early_layout_rules', perf_layout($rules), null, array('all')))), true);
}
$layout_token = str_repeat('b8', 24);
perf_preview_v2(array(
    perf_rule('layout:grid', 'early_layout_rules', perf_layout(array(
        array('selector' => $grid, 'declarations' => 'display:grid;gap:2rem'),
    )), null, array('all')),
    perf_when_rule('layout:shop', 'early_layout_rules', perf_layout(array(
        array('selector' => $grid, 'declarations' => 'display:block'),
        array('selector' => '.wp-container-core-columns-is-layout-1', 'declarations' => 'flex-wrap:nowrap'),
    )), array('kind' => 'singular')),
    perf_when_rule('layout:home', 'early_layout_rules', perf_layout(array(
        array('selector' => '.wp-container-core-group-is-layout-9', 'declarations' => 'max-width:40rem'),
    )), array('kind' => 'front_page')),
), '/page/', $layout_token);
do_action('wp');
$GLOBALS['perf_context'] = $cart;
(new FaaasterPerformanceRuntime(perf_live(array())))->register();
ob_start();
do_action('wp_head');
perf_check('early_layout_rules: one style in the head, each selector once, rules withheld by when left out', ob_get_clean(),
    '<style id="faaaster-early-layout">' . $grid . '{display:grid;gap:2rem}.wp-container-core-columns-is-layout-1{flex-wrap:nowrap}</style>' . "\n");
perf_check('receipt earlyLayout: rules printed', perf_receipt('/page/', $layout_token)['earlyLayout'], 2);

// ── drop_preloads ───────────────────────────────────────────────────────────
foreach (array(
    'nothing to drop' => array('crossOrigin' => false, 'withoutHighPriority' => false),
    'a missing flag' => array('crossOrigin' => true),
    'a flag as text' => array('crossOrigin' => 'true', 'withoutHighPriority' => false),
) as $label => $params) {
    perf_check('drop_preloads refused: ' . $label, perf_refused_v2(array(perf_rule('drop:bad', 'drop_preloads', $params, null, array('all')))), true);
}
if (class_exists('WP_HTML_Tag_Processor')) {
    $theme_head = function () {
        echo '<link rel="preload" as="image" href="https://cdn.example.net/theme-hero.jpg" fetchpriority="high">' . "\n"
            . '<link rel="preload" as="image" href="/wp-content/uploads/slide.jpg">' . "\n"
            . "<link rel='preload' as='image' href='https://example.com/lcp.jpg' fetchpriority='high'>\n"
            . '<link rel="Preload" as="IMAGE" imagesrcset="https://example.com/a-400.jpg 400w, https://example.com/a-800.jpg 800w">' . "\n"
            . '<link rel="preload" as="font" href="https://fonts.gstatic.com/x.woff2" crossorigin>' . "\n"
            . '<link rel="stylesheet" href="https://cdn.example.net/x.css">' . "\n"
            . '<script>var tpl = \'<link rel="preload" as="image" href="https://cdn.example.net/js.jpg">\';</script>' . "\n"
            . '<!-- <link rel="preload" as="image" href="https://cdn.example.net/comment.jpg"> -->' . "\n";
    };
    $drop_token = str_repeat('c9', 24);
    perf_preview_v2(array(
        perf_rule('drop:theme', 'drop_preloads', array('crossOrigin' => true, 'withoutHighPriority' => true), null, array('all')),
        perf_rule('preload:own', 'preload_image', array('url' => 'https://cdn.example.net/own.jpg'), null, array('all')),
    ), '/page/', $drop_token);
    do_action('wp');
    (new FaaasterPerformanceRuntime(perf_live(array())))->register();
    add_action('wp_head', $theme_head, 5);
    ob_start();
    do_action('wp_head');
    $dropped_head = ob_get_clean();
    perf_check('drop_preloads: cross-origin and non-prioritised image preloads removed, the rest untouched', $dropped_head,
        '<link rel="preload" as="image" href="https://cdn.example.net/own.jpg" fetchpriority="high">' . "\n"
        . "<link rel='preload' as='image' href='https://example.com/lcp.jpg' fetchpriority='high'>\n"
        . '<link rel="preload" as="font" href="https://fonts.gstatic.com/x.woff2" crossorigin>' . "\n"
        . '<link rel="stylesheet" href="https://cdn.example.net/x.css">' . "\n"
        . '<script>var tpl = \'<link rel="preload" as="image" href="https://cdn.example.net/js.jpg">\';</script>' . "\n"
        . '<!-- <link rel="preload" as="image" href="https://cdn.example.net/comment.jpg"> -->' . "\n");
    perf_check('receipt droppedPreloads: the removed addresses', perf_receipt('/page/', $drop_token)['droppedPreloads'], array(
        'https://cdn.example.net/theme-hero.jpg',
        '/wp-content/uploads/slide.jpg',
        'https://example.com/a-400.jpg 400w, https://example.com/a-800.jpg 800w',
    ));
    // crossOrigin only: a same-host preload without priority stays
    faaaster_test_reset_hooks();
    do_action('wp');
    $_GET = array();
    (new FaaasterPerformanceRuntime(perf_live_v2(array(perf_rule('drop:cdn', 'drop_preloads', array('crossOrigin' => true, 'withoutHighPriority' => false), null, array('all'))))))->register();
    add_action('wp_head', $theme_head, 5);
    ob_start();
    do_action('wp_head');
    $cdn_head = ob_get_clean();
    perf_check('drop_preloads: crossOrigin alone keeps the same-host preloads', array(
        strpos($cdn_head, 'theme-hero.jpg') !== false,
        strpos($cdn_head, 'slide.jpg') !== false,
        strpos($cdn_head, 'a-400.jpg') !== false,
    ), array(false, true, true));
    // a buffer left open by another callback: the head is left as printed
    faaaster_test_reset_hooks();
    do_action('wp');
    (new FaaasterPerformanceRuntime(perf_live_v2(array(perf_rule('drop:all', 'drop_preloads', array('crossOrigin' => true, 'withoutHighPriority' => true), null, array('all'))))))->register();
    add_action('wp_head', $theme_head, 5);
    add_action('wp_head', function () { ob_start(); }, 6);
    $level = ob_get_level();
    ob_start();
    do_action('wp_head');
    while (ob_get_level() > $level + 1) {
        ob_end_flush();
    }
    $untouched = ob_get_clean();
    perf_check('drop_preloads: a buffering change leaves the head untouched and is logged', array(
        strpos($untouched, 'theme-hero.jpg') !== false,
        strpos(perf_log(), 'drop_preloads: output buffering changed during wp_head, nothing dropped') !== false,
    ), array(true, true));
    // no matching rule: no buffer at all
    faaaster_test_reset_hooks();
    do_action('wp');
    $GLOBALS['perf_context'] = $cart;
    (new FaaasterPerformanceRuntime(perf_live_v2(array(perf_when_rule('drop:home', 'drop_preloads', array('crossOrigin' => true, 'withoutHighPriority' => true), array('kind' => 'front_page'))))))->register();
    $levels = array();
    add_action('wp_head', function () use (&$levels) { $levels[] = ob_get_level(); }, 5);
    $level = ob_get_level();
    do_action('wp_head');
    perf_check('drop_preloads: no buffer where the rule does not apply', $levels, array($level));
} else {
    echo "SKIP drop_preloads: WordPress HTML API not found (set WP_HTML_API_DIR)\n";
}

// ── image_priority ──────────────────────────────────────────────────────────
$card_signature = array('attachment-woocommerce_thumbnail', 'size-woocommerce_thumbnail');
$card_sizes = '(max-width: 600px) 50vw, 300px';
perf_check('image_priority: params accepted', FaaasterPerformanceSettings::compile(perf_payload_v2(array(
    perf_rule('img:cards', 'image_priority', array('signature' => array('a', 'b', 'a'), 'rank' => 1, 'eager' => 2, 'sizes' => $card_sizes), null, array('all')),
), 1))['rules'][0]['params'], array('signature' => array('a', 'b'), 'rank' => 1, 'eager' => 2, 'sizes' => $card_sizes));
foreach (array(
    'no signature' => array('signature' => array(), 'rank' => 0, 'eager' => 1),
    '7 classes' => array('signature' => array('a', 'b', 'c', 'd', 'e', 'f', 'g'), 'rank' => 0, 'eager' => 1),
    'a class with a dot' => array('signature' => array('wp.image'), 'rank' => 0, 'eager' => 1),
    'signature as text' => array('signature' => 'wp-post-image', 'rank' => 0, 'eager' => 1),
    'rank 51' => array('signature' => array('a'), 'rank' => 51, 'eager' => 12),
    'eager 0' => array('signature' => array('a'), 'rank' => 0, 'eager' => 0),
    'eager 13' => array('signature' => array('a'), 'rank' => 0, 'eager' => 13),
    'rank equal to eager' => array('signature' => array('a'), 'rank' => 2, 'eager' => 2),
    'rank as text' => array('signature' => array('a'), 'rank' => '0', 'eager' => 1),
    'upper-case sizes' => array('signature' => array('a'), 'rank' => 0, 'eager' => 1, 'sizes' => '100VW'),
    'empty sizes' => array('signature' => array('a'), 'rank' => 0, 'eager' => 1, 'sizes' => ''),
    'sizes with a quote' => array('signature' => array('a'), 'rank' => 0, 'eager' => 1, 'sizes' => '100vw"'),
) as $label => $params) {
    perf_check('image_priority refused: ' . $label, perf_refused_v2(array(perf_rule('img:bad', 'image_priority', $params, null, array('all')))), true);
}
/** Attributes WordPress would print for a product card image. */
function perf_priority_input($attachment_id, $class = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail')
{
    return array('src' => 'https://example.com/card-' . $attachment_id . '.jpg', 'class' => $class, 'loading' => 'lazy', 'sizes' => 'auto, (max-width: 300px) 100vw, 300px');
}
function perf_priority_card($attachment_id, $class = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail')
{
    return apply_filters('wp_get_attachment_image_attributes', perf_priority_input($attachment_id, $class), (object) array('ID' => $attachment_id), 'woocommerce_thumbnail');
}
$priority_token = str_repeat('d1', 24);
perf_preview_v2(array(
    perf_rule('img:cards', 'image_priority', array('signature' => $card_signature, 'rank' => 1, 'eager' => 2, 'sizes' => $card_sizes), null, array('all')),
    perf_rule('img:explicit', 'image_delivery', array('attachmentIds' => array(704), 'loading' => 'lazy', 'fetchPriority' => 'low'), null, array('all')),
), '/boutique/', $priority_token);
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => false, 'archive' => true);
(new FaaasterPerformanceRuntime(perf_live(array())))->register();
$priority_cards = array(perf_priority_card(701), perf_priority_card(702), perf_priority_card(703));
perf_check('image_priority: before the rank, at once, sizes without auto', $priority_cards[0], array(
    'src' => 'https://example.com/card-701.jpg', 'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail', 'loading' => 'eager', 'sizes' => $card_sizes,
));
perf_check('image_priority: the rank, at once with high priority', array($priority_cards[1]['loading'], $priority_cards[1]['fetchpriority'] ?? null, $priority_cards[1]['sizes']), array('eager', 'high', $card_sizes));
perf_check('image_priority: after eager, still lazy, sizes behind auto', array($priority_cards[2]['loading'], $priority_cards[2]['fetchpriority'] ?? null, $priority_cards[2]['sizes']), array('lazy', null, 'auto, ' . $card_sizes));
perf_check('image_priority: an image already met keeps its decision', perf_priority_card(701)['loading'], 'eager');
perf_check('image_priority: an image without the whole signature untouched', perf_priority_card(705, 'attachment-woocommerce_thumbnail'), perf_priority_input(705, 'attachment-woocommerce_thumbnail'));
$explicit = perf_priority_card(704);
perf_check('image_priority: a rule on the attachment has the last word', array($explicit['loading'], $explicit['fetchpriority']), array('lazy', 'low'));
if (class_exists('WP_HTML_Tag_Processor')) {
    // the block template filters the card printed above: met again, never counted twice
    perf_check('image_priority: the same image in the template filter keeps its decision', apply_filters(
        'wp_content_img_tag',
        '<img src="https://example.com/card-702.jpg" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" loading="eager" fetchpriority="high" sizes="' . $card_sizes . '">',
        'template',
        702
    ), '<img src="https://example.com/card-702.jpg" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" loading="eager" fetchpriority="high" sizes="' . $card_sizes . '">');
    // a block grid image without attachment id: the 5th card (the explicit one counted too)
    perf_check('image_priority: content images count too, attachment or not', apply_filters(
        'wp_content_img_tag',
        '<img src="https://example.com/grid-6.jpg" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" loading="lazy" sizes="auto, 100vw">',
        'template',
        0
    ), '<img src="https://example.com/grid-6.jpg" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" loading="lazy" sizes="auto, ' . $card_sizes . '">');
}
perf_check('receipt imagePriority: images set per rule', perf_receipt('/boutique/', $priority_token)['imagePriority'], array('img:cards' => class_exists('WP_HTML_Tag_Processor') ? 4 : 3));
if (class_exists('WP_HTML_Tag_Processor')) {
    // the content filter: rank 0 on the first block image, WordPress's `auto` dropped once eager
    faaaster_test_reset_hooks();
    do_action('wp');
    $_GET = array();
    (new FaaasterPerformanceRuntime(perf_live_v2(array(
        perf_rule('img:grid', 'image_priority', array('signature' => array('wc-block-components-product-image'), 'rank' => 0, 'eager' => 1), null, array('all')),
    ))))->register();
    $block_image = function ($n) {
        return apply_filters('wp_content_img_tag', '<img src="https://example.com/p-' . $n . '.jpg" class="wc-block-components-product-image wp-image-' . $n . '" loading="lazy" decoding="async" sizes="auto, (max-width: 300px) 100vw, 300px">', 'the_content', $n);
    };
    perf_check('image_priority: the first block image first, auto removed from sizes', $block_image(801), '<img fetchpriority="high" src="https://example.com/p-801.jpg" class="wc-block-components-product-image wp-image-801" loading="eager" decoding="async" sizes="(max-width: 300px) 100vw, 300px">');
    perf_check('image_priority: without sizes, a lazy image keeps its own', $block_image(802), '<img src="https://example.com/p-802.jpg" class="wc-block-components-product-image wp-image-802" loading="lazy" decoding="async" sizes="auto, (max-width: 300px) 100vw, 300px">');
}
// first matching rule wins, every rule still counts its images; adapters keep their precedence
faaaster_test_reset_hooks();
do_action('wp');
$_GET = array();
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => false, 'archive' => true);
(new FaaasterPerformanceRuntime(perf_live_v2(array(
    perf_rule('img:card', 'image_priority', array('signature' => array('card'), 'rank' => 0, 'eager' => 1), null, array('all')),
    perf_rule('img:featured', 'image_priority', array('signature' => array('card', 'featured'), 'rank' => 1, 'eager' => 2), null, array('all')),
    perf_rule('img:only-featured', 'image_priority', array('signature' => array('featured'), 'rank' => 2, 'eager' => 3), null, array('all')),
    perf_when_rule('img:never', 'image_priority', array('signature' => array('card'), 'rank' => 0, 'eager' => 1), array('kind' => 'front_page')),
    perf_rule('wc:loop', 'wc_loop_images', array('eager' => 1, 'highPriority' => 0), null, array('all')),
))))->register();
$first = perf_priority_card(901, 'card featured');
$second = perf_priority_card(902, 'card featured');
$GLOBALS['product'] = new Perf_Test_Product(950, 903);
$loop_card = perf_priority_card(903, 'featured');
unset($GLOBALS['product']);
$third = perf_priority_card(904, 'featured');
perf_check('image_priority: the first matching rule decides', array($first['loading'], $first['fetchpriority'] ?? null), array('eager', 'high'));
perf_check('image_priority: the second rule counted it but does not decide', array($second['loading'], $second['fetchpriority'] ?? null), array('lazy', null));
perf_check('image_priority: the product card adapter keeps its precedence', array($loop_card['loading'], $loop_card['fetchpriority'] ?? null), array('eager', null));
perf_check('image_priority: the adapter\'s image still counted, the next one is past eager', array($third['loading'], $third['fetchpriority'] ?? null), array('lazy', null));

// ── defer_backgrounds ───────────────────────────────────────────────────────
$backgrounds = array(
    array('selector' => '.stk-block-background', 'url' => 'https://example.com/bg-1.jpg'),
    array('selector' => 'section#about .wp-block-cover.is-light div', 'url' => 'https://example.com/bg-2.jpg'),
    array('selector' => '.stk-block-background', 'url' => 'https://example.com/bg-3.jpg'),
);
perf_check('defer_backgrounds: items accepted, selectors deduplicated', FaaasterPerformanceSettings::compile(perf_payload_v2(array(
    perf_rule('bg:home', 'defer_backgrounds', array('items' => $backgrounds), null, array('all')),
), 1))['rules'][0]['params']['items'], array_slice($backgrounds, 0, 2));
foreach (array(
    'no item' => array(),
    '21 items' => array_map(function ($i) { return array('selector' => '.bg-' . $i, 'url' => 'https://example.com/' . $i . '.jpg'); }, range(1, 21)),
    'a child combinator' => array(array('selector' => '.a > .b', 'url' => 'https://example.com/a.jpg')),
    'two spaces' => array(array('selector' => '.a  .b', 'url' => 'https://example.com/a.jpg')),
    '5 compounds' => array(array('selector' => '.a .b .c .d .e', 'url' => 'https://example.com/a.jpg')),
    'an attribute selector' => array(array('selector' => 'div[data-bg]', 'url' => 'https://example.com/a.jpg')),
    'a pseudo-class' => array(array('selector' => '.a:hover', 'url' => 'https://example.com/a.jpg')),
    'a list' => array(array('selector' => '.a, .b', 'url' => 'https://example.com/a.jpg')),
    'a class starting with a digit' => array(array('selector' => '.1col', 'url' => 'https://example.com/a.jpg')),
    'over 200 characters' => array(array('selector' => '.' . str_repeat('a', 200), 'url' => 'https://example.com/a.jpg')),
    'an http url' => array(array('selector' => '.a', 'url' => 'http://example.com/a.jpg')),
    'no url' => array(array('selector' => '.a')),
) as $label => $items) {
    perf_check('defer_backgrounds refused: ' . $label, perf_refused_v2(array(perf_rule('bg:bad', 'defer_backgrounds', array('items' => $items), null, array('all')))), true);
}
$background_token = str_repeat('e2', 24);
perf_preview_v2(array(
    perf_rule('bg:home', 'defer_backgrounds', array('items' => array_slice($backgrounds, 0, 2)), null, array('all')),
    perf_rule('bg:more', 'defer_backgrounds', array('items' => array(
        array('selector' => '.stk-block-background', 'url' => 'https://example.com/bg-1.jpg'),
        array('selector' => '.footer-bg', 'url' => 'https://example.com/bg-4.jpg'),
    )), null, array('all')),
    perf_when_rule('bg:never', 'defer_backgrounds', array('items' => array(array('selector' => '.hero', 'url' => 'https://example.com/hero.jpg'))), array('kind' => 'search')),
), '/', $background_token);
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
(new FaaasterPerformanceRuntime(perf_live(array())))->register();
ob_start();
do_action('wp_head');
$background_head = ob_get_clean();
perf_check('defer_backgrounds: the js class and the hiding style at the top of the head', $background_head,
    '<script id="faaaster-bg-js">document.documentElement.classList.add(\'fstr-js\')</script>' . "\n"
    . '<style id="faaaster-defer-backgrounds">'
    . '.fstr-js .stk-block-background:not(.fstr-bg-in){background-image:none!important}'
    . '.fstr-js section#about .wp-block-cover.is-light div:not(.fstr-bg-in){background-image:none!important}'
    . '.fstr-js .footer-bg:not(.fstr-bg-in){background-image:none!important}'
    . '</style>' . "\n");
ob_start();
do_action('wp_footer');
$background_footer = ob_get_clean();
perf_check('defer_backgrounds: one observer script with the same selectors, 300px ahead, everything shown without the observer', array(
    substr_count($background_footer, '<script'),
    strpos($background_footer, 's=[".stk-block-background","section#about .wp-block-cover.is-light div",".footer-bg"]') !== false,
    strpos($background_footer, 'rootMargin:"300px 0px"') !== false,
    strpos($background_footer, '"IntersectionObserver" in w') !== false,
    strpos($background_footer, 'show(n[j])') !== false,
    strpos($background_footer, 'fstr-bg-in') !== false,
), array(1, true, true, true, true, true));
perf_check('receipt deferredBackgrounds: the selectors', perf_receipt('/', $background_token)['deferredBackgrounds'], array('.stk-block-background', 'section#about .wp-block-cover.is-light div', '.footer-bg'));
faaaster_test_reset_hooks();
do_action('wp');
$_GET = array();
$GLOBALS['perf_context'] = $cart;
(new FaaasterPerformanceRuntime(perf_live_v2(array(perf_when_rule('bg:home-only', 'defer_backgrounds', array('items' => $backgrounds), array('kind' => 'front_page'))))))->register();
ob_start();
do_action('wp_head');
do_action('wp_footer');
perf_check('defer_backgrounds: nothing printed where the rule does not apply', ob_get_clean(), '');
$background_audit = str_repeat('e3', 24);
$GLOBALS['fta']['transients'][FaaasterPerformanceRuntime::audit_transient_key($background_audit)] = array('paths' => array('/'), 'snapshots' => array(), 'createdAt' => time());
faaaster_test_reset_hooks();
$_GET = array('faaaster_perf_audit' => $background_audit);
$GLOBALS['perf_context'] = array('front_page' => true, 'singular' => false, 'archive' => false);
(new FaaasterPerformanceRuntime(perf_live_v2(array(
    perf_rule('bg:all', 'defer_backgrounds', array('items' => $backgrounds), null, array('all')),
    perf_rule('layout:all', 'early_layout_rules', perf_layout(array(array('selector' => $grid, 'declarations' => 'display:grid'))), null, array('all')),
))))->register();
do_action('wp');
ob_start();
do_action('wp_head');
do_action('wp_footer');
perf_check('rendering rules: an audit request renders without them', ob_get_clean(), '');
$_GET = array();

// ── Audit snapshot: each discovered conditional tag on the page ─────────────
$GLOBALS['perf_acme_shop'] = true;
ob_start();
$conditional_snapshot = perf_audit_snapshot($builder_page);
perf_check('snapshot conditionals: the value of each discovered tag, errors left out, nothing printed', array($conditional_snapshot['conditionals'], ob_get_clean()), array(array(
    'is_acme_noisy' => true,
    'is_acme_optional' => true,
    'is_acme_shop' => true,
    'is_astra_landing' => true,
    'is_parent_layout' => false,
), ''));
$GLOBALS['faaaster_test']['options'][FaaasterPerformanceConditionals::OPTION] = array();
FaaasterPerformanceConditionals::reset();
perf_check('snapshot conditionals: none discovered, an empty JSON object', json_encode(perf_audit_snapshot($builder_page)['conditionals']), '{}');
FaaasterPerformanceConditionals::refresh();

// ── Benchmark: `when` trees stay far below the 2 ms p95 objective ──────────
$GLOBALS['shortcode_tags'] = array('gallery' => 'cb');
$when_pool = array();
for ($index = 0; $index < 10; $index++) {
    $when_pool[] = array('kind' => 'has_block', 'name' => 'acme/block-' . $index);
    $when_pool[] = array('kind' => 'has_shortcode', 'tag' => 'tag_' . $index);
    $GLOBALS['shortcode_tags']['tag_' . $index] = 'cb';
}
for ($index = 0; $index < 5; $index++) {
    $when_pool[] = array('kind' => 'elementor_widget', 'name' => 'widget-' . $index);
}
for ($index = 0; $index < 3; $index++) {
    $when_pool[] = array('kind' => 'widget', 'idBase' => 'widget_' . $index);
}
$when_pool[] = array('kind' => 'page', 'ids' => range(1, 200));
$when_pool[] = array('kind' => 'product_type', 'types' => array('grouped', 'variable'));
$when_bench_rules = array();
for ($index = 0; $index < 200; $index++) {
    $when_bench_rules[] = perf_when_rule('unload:when-bench-' . $index, 'unload_unused_asset', perf_unload_params('cond-' . $index), array('all' => array(
        array('not' => $when_pool[$index % 30]),
        array('not' => $when_pool[($index + 7) % 30]),
        array('any' => array($when_pool[($index + 13) % 30], array('kind' => 'singular'))),
    )), array('singular'));
}
$when_bench_settings = perf_live_v2($when_bench_rules);
faaaster_test_reset_hooks();
do_action('wp');
$GLOBALS['perf_context'] = array('front_page' => false, 'singular' => true, 'archive' => false, 'object' => $bench_page);
$GLOBALS['perf_styles'] = (object) array('queue' => $bench_queue, 'done' => array(), 'registered' => $bench_registered);
$_SERVER['REQUEST_URI'] = '/bench/';
$_GET = array();
$durations = array();
for ($index = 0; $index < 300; $index++) {
    $GLOBALS['perf_dequeued'] = array('style' => array(), 'script' => array());
    $cold = new FaaasterPerformanceRuntime($when_bench_settings);
    $start = hrtime(true);
    $cold->unload_assets();
    $durations[] = (hrtime(true) - $start) / 1000000;
}
sort($durations);
$when_p95 = $durations[(int) floor(count($durations) * 0.95)];
perf_check('benchmark when: 200 rules with 800 atoms (31 distinct), every rule applied', count($GLOBALS['perf_dequeued']['style']), 200);
perf_check('benchmark when: below 1 ms p95 (objective 2 ms)', $when_p95 < 1.0, true);
echo 'benchmark when: p95=' . round($when_p95, 3) . "ms (200 rules, 30 KB page)\n";

// ── Inventory: contentIndex, site, conditionals ─────────────────────────────
$GLOBALS['shortcode_tags'] = array('pwf_filter' => 'cb', 'gallery' => 'cb');
$index_db = new Perf_Test_Wpdb();
$index_db->rows = array(
    2001 => array('post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->[pwf_filter id="2"]'),
    2002 => array('post_type' => 'product', 'post_content' => ''),
    2003 => array('post_type' => 'post', 'post_content' => '<!-- wp:acme/slider /-->'),
    2004 => array('post_type' => 'page', 'post_content' => '<!-- wp:group --><div></div><!-- /wp:group -->'),
    2005 => array('post_type' => 'page', 'post_content' => ''),
    2006 => array('post_type' => 'page', 'post_content' => ''),
);
$index_db->meta = array(2004 => '[{"elType":"widget","widgetType":"heading"}]');
$index_db->term_rows = array(2002 => array(array('product_cat', 'chaises'), array('product_type', 'variable'), array('pa_color', 'red')));
$index_db->templates = array(2004 => 'templates/full-width.php', 2005 => 'default', 2006 => 'mon gabarit');
$GLOBALS['perf_scan_links'] = array(
    2001 => 'https://example.com/filtres/',
    2002 => 'https://example.com/produit/chaise/',
    2003 => 'https://example.com/?p=2003',
    2004 => 'https://example.com/pleine-largeur/',
    2005 => 'https://example.com/defaut/',
    2006 => 'https://example.com/gabarit-bizarre/',
);
$GLOBALS['wpdb'] = $index_db;
$GLOBALS['faaaster_test']['options']['active_plugins'][] = 'elementor/elementor.php';
$GLOBALS['faaaster_test']['options']['show_on_front'] = 'page';
$GLOBALS['faaaster_test']['options']['page_for_posts'] = 24;
$indexed = $rest->inventory();
perf_check('contentIndex: one name list, prefixed', $indexed['contentIndex']['names'], array(
    'p:default',
    'p:templates/full-width.php',
    'e:heading',
    'b:core/group',
    'b:acme/slider',
    't:product_cat:chaises',
    't:product_type:variable',
    's:pwf_filter',
    'b:core/paragraph',
));
perf_check('contentIndex: one item per content read, names by index, no path for a query permalink', $indexed['contentIndex']['items'], array(
    array('id' => 2006, 'type' => 'page', 'slug' => 'p2006', 'path' => '/gabarit-bizarre/', 'c' => array(0)),
    array('id' => 2005, 'type' => 'page', 'slug' => 'p2005', 'path' => '/defaut/', 'c' => array(0)),
    array('id' => 2004, 'type' => 'page', 'slug' => 'p2004', 'path' => '/pleine-largeur/', 'c' => array(1, 2, 3)),
    array('id' => 2003, 'type' => 'post', 'slug' => 'p2003', 'path' => '', 'c' => array(0, 4)),
    array('id' => 2002, 'type' => 'product', 'slug' => 'p2002', 'path' => '/produit/chaise/', 'c' => array(0, 5, 6)),
    array('id' => 2001, 'type' => 'page', 'slug' => 'p2001', 'path' => '/filtres/', 'c' => array(0, 7, 8)),
));
perf_check('contentIndex: terms and templates read with one query each per chunk', $index_db->index_queries, 2);
perf_check('contentIndex: the components scan unchanged', array($indexed['components']['shortcodes'], $indexed['components']['blocks'], $indexed['componentsScan']), array(
    array('pwf_filter' => array('/filtres/')),
    array('acme/slider' => array()),
    array('contents' => 6, 'complete' => true),
));
$index_db->edit_mode = array(2004 => true);
$classed = $rest->inventory();
perf_check('inventory v2: every content counted by type, template and builder, one example path each', $classed['classCounts'], array(
    array('type' => 'page', 'template' => 'default', 'builder' => null, 'count' => 3, 'example' => '/filtres/'),
    array('type' => 'product', 'template' => 'default', 'builder' => null, 'count' => 1, 'example' => '/produit/chaise/'),
    array('type' => 'post', 'template' => 'default', 'builder' => null, 'count' => 1, 'example' => ''),
    array('type' => 'page', 'template' => 'templates/full-width.php', 'builder' => 'elementor', 'count' => 1, 'example' => '/pleine-largeur/'),
));
perf_check('inventory v2: a content in Elementor\'s builder mode is marked in the index', array_values(array_filter(array_map(function ($item) {
    return isset($item['el']) ? $item['id'] : null;
}, $classed['contentIndex']['items']))), array(2004));
perf_check('inventory v2: one grouped query for the counts', $index_db->class_queries, 2);
$index_db->edit_mode = array();
perf_check('inventory site: the static front and posts pages, the shop pages with their role', $indexed['site'], array(
    'frontPageId' => 23,
    'postsPageId' => 24,
    'postsPagePath' => '/a-propos/',
    'special' => array(
        array('role' => 'shop', 'id' => 25, 'path' => '/boutique/'),
        array('role' => 'cart', 'id' => 22, 'path' => '/panier/'),
    ),
));
perf_check('inventory conditionals: the discovered tags', $indexed['conditionals'], $discovered);
$GLOBALS['faaaster_test']['options']['page_for_posts'] = 2003;
$drafted = $rest->inventory()['site'];
perf_check('inventory site: a posts page without an address of its own (draft) is no posts page', array($drafted['postsPageId'], $drafted['postsPagePath']), array(0, ''));
$GLOBALS['faaaster_test']['options']['page_for_posts'] = 24;
$GLOBALS['faaaster_test']['options']['show_on_front'] = 'posts';
perf_check('inventory site: 0 without a static front page', $rest->inventory()['site'], array(
    'frontPageId' => 0,
    'postsPageId' => 0,
    'postsPagePath' => '',
    'special' => array(
        array('role' => 'shop', 'id' => 25, 'path' => '/boutique/'),
        array('role' => 'cart', 'id' => 22, 'path' => '/panier/'),
    ),
));
$GLOBALS['wpdb'] = new Perf_Test_Wpdb();
perf_check('contentIndex: empty, the same shape', json_encode($rest->inventory()['contentIndex']), '{"names":[],"items":[]}');
$wide_db = new Perf_Test_Wpdb();
$wide_db->rows[4000] = array('post_type' => 'post', 'post_content' => implode('', array_map(function ($j) {
    return '<!-- wp:acme/wide-' . $j . ' /-->';
}, range(1, 120))));
for ($id = 3001; $id <= 3100; $id++) {
    $wide_db->rows[$id] = array('post_type' => 'post', 'post_content' => implode('', array_map(function ($j) use ($id) {
        return '<!-- wp:acme/b-' . $id . '-' . $j . ' /-->';
    }, range(1, 70))));
}
$GLOBALS['wpdb'] = $wide_db;
$wide = $rest->inventory()['contentIndex'];
perf_check('contentIndex: 80 names per content, 6 000 names at most', array(
    count($wide['items']),
    max(array_map(function ($item) { return count($item['c']); }, $wide['items'])),
    count($wide['names']),
    count($wide['items'][100]['c']),
), array(101, 80, 6000, 1));
unset($GLOBALS['wpdb']);
array_pop($GLOBALS['faaaster_test']['options']['active_plugins']);

echo "{$GLOBALS['perf_passes']} OK, {$GLOBALS['perf_failures']} FAIL\n";
exit($GLOBALS['perf_failures'] ? 1 : 0);
