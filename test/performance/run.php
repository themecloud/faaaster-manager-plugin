<?php

/**
 * Tests du runtime performance, sans charger WordPress.
 * Exécution : php test/performance/run.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

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
function is_home() { return false; }
function is_front_page() { return !empty($GLOBALS['perf_context']['front_page']); }
function is_singular($type = '') { return !empty($GLOBALS['perf_context']['singular']); }
function is_archive() { return !empty($GLOBALS['perf_context']['archive']); }
function is_search() { return false; }
function is_404() { return false; }
function is_tax($taxonomy = '') { return false; }
function is_page($id = null) { return false; }
function get_post_type() { return null; }
function get_queried_object() { return null; }
function get_queried_object_id() { return 0; }
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
    $late_flags[$asset['handle']] = array($asset['late'], $asset['inlineAfter']);
}
perf_check('audit snapshot flags late and inline-extended styles', $late_flags, array(
    'theme' => array(false, false),
    'widget-slider' => array(true, false),
    'widget-inline' => array(true, true),
));
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
    'page' => array(21 => '/contact/'),
    'product' => array(31 => '/produit/chaise/', 32 => '/produit/table/'),
);
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
    foreach ($GLOBALS['perf_posts'] as $posts) {
        if (isset($posts[$id])) {
            return 'https://example.com' . $posts[$id];
        }
    }
    return false;
}
function get_terms($args) { return array((object) array('slug' => 'chaises'), (object) array('slug' => 'tables')); }
function get_term_link($term) { return 'https://example.com/categorie/' . $term->slug . '/'; }
function get_stylesheet() { return 'astra'; }
$GLOBALS['faaaster_test']['options']['active_plugins'] = array('woocommerce/woocommerce.php', 'complianz-gdpr/complianz-gpdr.php', 'hello.php');
$inventory = $rest->inventory();
perf_check('inventory templates with sample pages', $inventory['templates'], array(
    array('kind' => 'home', 'context' => 'front_page', 'paths' => array('/')),
    array('kind' => 'article', 'context' => 'post_type:post', 'paths' => array('/blog/a/', '/blog/b/', '/blog/c/')),
    array('kind' => 'product', 'context' => 'post_type:product', 'paths' => array('/produit/chaise/', '/produit/table/')),
    array('kind' => 'product_category', 'context' => 'taxonomy:product_cat', 'paths' => array('/categorie/chaises/', '/categorie/tables/')),
));
perf_check('inventory lists active plugin slugs', $inventory['plugins'], array('woocommerce', 'complianz-gdpr', 'hello'));
perf_check('inventory keeps one representative per kind', array_column($inventory['representatives'], 'path'), array(
    '/', '/blog/a/', '/contact/', '/produit/chaise/', '/categorie/chaises/',
));

echo "{$GLOBALS['perf_passes']} OK, {$GLOBALS['perf_failures']} FAIL\n";
exit($GLOBALS['perf_failures'] ? 1 : 0);
