<?php

/** Real WP 6.9 HTML API + unmodified OD/IP; no database, site, or HTTP requests. */
error_reporting(E_ALL);
$core = getenv('WP_TEST_CORE_DIR');
if (!$core || !is_file($core . '/wp-includes/html-api/class-wp-html-tag-processor.php')) {
    fwrite(STDERR, "Set WP_TEST_CORE_DIR to an unpacked WordPress 6.9 distribution.\n");
    exit(1);
}
if (!isset($argv[1])) {
    $failed = 0;
    foreach (array('disabled', 'disable-after-active', 'isolated', 'old-wp', 'private', 'active', 'incompatible', 'rest-unavailable', 'manifest-invalid', 'cli-disabled', 'cli-enabled', 'purges') as $case) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case), $code);
        $failed += $code !== 0;
    }
    exit($failed ? 1 : 0);
}
$case = $argv[1];
if ($case === 'purges') {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/purges.php'), $code);
    exit($code);
}
$root = sys_get_temp_dir() . '/faaaster-image-test-' . getmypid();
mkdir($root . '/uploads/2026/11', 0777, true);
$root = realpath($root);
define('ABSPATH', rtrim($core, '/') . '/');
define('WP_CONTENT_DIR', $root);
define('WP_PLUGIN_DIR', $root . '/plugins');
define('WP_DEBUG', false);
define('SCRIPT_DEBUG', false);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MINUTE_IN_SECONDS', 60);
define('MONTH_IN_SECONDS', 2592000);
define('MB_IN_BYTES', 1048576);
$_SERVER['IMAGE_OPTIMIZATION_ENABLED'] = in_array($case, array('disabled', 'disable-after-active'), true) ? 'false' : 'true';
$_SERVER['REQUEST_URI'] = $case === 'isolated' ? '/wp-json/hostmanager/v1/site_state' : '/page/';
if (in_array($case, array('cli-disabled', 'cli-enabled'), true)) {
    define('WP_CLI', true);
    unset($_SERVER['IMAGE_OPTIMIZATION_ENABLED']);
    putenv('IMAGE_OPTIMIZATION_ENABLED=' . ($case === 'cli-enabled' ? 'true' : 'false'));
}
if ($case === 'private') {
    define('PRIVATE_MODE', 'true');
}
$wp_version = $case === 'old-wp' ? '6.8' : '6.9';
require __DIR__ . '/../cache/stubs/hooks.php';
function check($label, $actual, $expected = true) {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export($actual, true) . ' != ' . var_export($expected, true));
    }
    $GLOBALS['checks'] = isset($GLOBALS['checks']) ? $GLOBALS['checks'] + 1 : 1;
}
function __( $value, $domain = null ) { return $value; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($v) { return esc_html($v); }
function esc_url_raw($v) { return $v; }
function esc_url($v) { return esc_attr($v); }
function wp_kses_uri_attributes() { return array('src', 'href', 'poster'); }
function plugins_url($path, $file) { return home_url('/wp-content/mu-plugins/faaaster-manager-plugin/vendor/image-optimization/image-prioritizer/' . $path); }
function add_query_arg($key, $value, $url = '') { return is_array($key) ? $value . '?' . http_build_query($key) : $url . '?' . rawurlencode($key) . '=' . rawurlencode($value); }
function wp_get_inline_script_tag($script, $attributes = array()) { return '<script type="module">' . $script . '</script>'; }
function _doing_it_wrong($name, $message, $version) { throw new RuntimeException($name . ': ' . $message); }
function wp_parse_url($v, $component = -1) { return parse_url($v, $component); }
function home_url($path = '') { return 'https://client.test' . $path; }
function site_url($path = '') { return home_url($path); }
function wp_upload_dir($time = null, $create = true) { return array('baseurl' => home_url('/wp-content/uploads'), 'basedir' => WP_CONTENT_DIR . '/uploads'); }
function wp_get_registered_image_subsizes() {
    return array('thumbnail' => array('width' => 150), 'custom' => array('width' => 777),
        'large' => array('width' => 1024), 'large2' => array('width' => 1536),
        'duplicate' => array('width' => 777), 'standard' => array('width' => 640),
        'too-large' => array('width' => 2561), 'zero' => array('width' => 0),
        'negative' => array('width' => -1), 'fraction' => array('width' => 666.5));
}
function wp_getimagesize($p) { return @getimagesize($p); }
function wp_mkdir_p($p) { return is_dir($p) || mkdir($p, 0777, true); }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function get_option($k, $default = false) { return isset($GLOBALS['options'][$k]) ? $GLOBALS['options'][$k] : $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function get_post_meta($id, $k, $single = false) { return isset($GLOBALS['meta'][$id][$k]) ? $GLOBALS['meta'][$id][$k] : ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function wp_cache_delete($id, $group) { return true; }
function get_current_blog_id() { return 1; }
function wp_next_scheduled($hook, $args = array()) { return isset($GLOBALS['schedule'][$hook][json_encode($args)]) ? $GLOBALS['schedule'][$hook][json_encode($args)] : false; }
function wp_clear_scheduled_hook($hook, $args) { unset($GLOBALS['schedule'][$hook][json_encode($args)]); return true; }
function wp_schedule_single_event($time, $hook, $args) { $GLOBALS['schedule'][$hook][json_encode($args)] = $time; return true; }
function is_admin() { return false; }
function wp_generate_uuid4() { static $id = 0; return 'test-' . ++$id; }
// WordPress loads these native compatibility functions before plugins, including on PHP 7.4.
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/compat-utf8.php';
require ABSPATH . 'wp-includes/utf8.php';
function wp_check_invalid_utf8($value, $strip = false) { return wp_is_valid_utf8($value) ? $value : ''; }
class ImageTestQueue {
    public $urls = array();
    public $all = 0;
    public function enqueue_url($url, $source) { $this->urls[] = $url; return true; }
    public function enqueue_all($source) { $this->all++; }
    public function flush($forced = false) { return array('confirmed' => true); }
}
class ImageTestCache {
    public $pending;
    public function __construct() { $this->pending = new ImageTestQueue(); }
    public function queue() { return $this->pending; }
}
$GLOBALS['image_cache'] = new ImageTestCache();
function faaaster_cache() { return $GLOBALS['image_cache']; }
function faaaster_is_hostmanager_request() { return strpos($_SERVER['REQUEST_URI'], 'hostmanager') !== false; }
require __DIR__ . '/../../class/cache/url.php';
require __DIR__ . '/../../class/image-optimization/bootstrap.php';
if (in_array($case, array('cli-disabled', 'cli-enabled'), true)) {
    $observed = array('status' => 'active', 'reason' => null, 'observed_at' => 123);
    $GLOBALS['options'][FaaasterImageOptimization::RUNTIME_OPTION] = $observed;
    faaaster_image_optimization_boot();
    FaaasterImageOptimization::initialize();
    check('CLI never changes web observation', get_option(FaaasterImageOptimization::RUNTIME_OPTION), $observed);
    check('CLI never purges', faaaster_cache()->queue()->all, 0);
    check('CLI never loads OD/IP', isset($GLOBALS['optimization_detective_pending_plugin']), false);
    check('CLI does not claim container env is hot config', faaaster_image_optimization_state()['configured'], null);
    check('CLI identifies isolation', faaaster_image_optimization_state()['reason'], 'cli_request');
    check('CLI retains dated web observation', faaaster_image_optimization_state()['last_observed'], $observed);
    require __DIR__ . '/../../class/image-optimization/purges.php';
    FaaasterImagePurges::stored(null);
    FaaasterImagePurges::flush(42);
    check('even direct CLI purge calls do nothing', faaaster_cache()->queue()->urls, array());
    echo "OK {$case}\n";
    exit(0);
}
if ($case === 'rest-unavailable') {
    $GLOBALS['options']['od_rest_api_unavailable'] = '1';
}
if ($case === 'disable-after-active') {
    $GLOBALS['options'][FaaasterImageOptimization::RUNTIME_OPTION] = array('status' => 'active', 'observed_at' => time() - 100);
    faaaster_image_optimization_boot();
    check('disable clears HTML cache once', faaaster_cache()->queue()->all, 1);
    check('disabled observation', get_option(FaaasterImageOptimization::RUNTIME_OPTION)['status'], 'disabled');
    check('disable does not load vendor', isset($GLOBALS['optimization_detective_pending_plugin']), false);
    echo "OK disable-after-active\n";
    exit(0);
}
if (in_array($case, array('disabled', 'isolated', 'old-wp', 'private'), true)) {
    faaaster_image_optimization_boot();
    check('no vendor bootstrap', isset($GLOBALS['optimization_detective_pending_plugin']), false);
    check('no HTML hooks', has_action('od_register_tag_visitors'), false);
    $reason = array('disabled' => 'disabled', 'isolated' => 'isolated_request', 'old-wp' => 'wordpress_version', 'private' => 'private_or_trial');
    check('explicit reason', faaaster_image_optimization_state()['reason'], $reason[$case]);
    echo "OK {$case}\n";
    exit(0);
}
// Load the actual minimum-supported WordPress parser (not a parser mock).
require ABSPATH . 'wp-includes/class-wp-token-map.php';
require ABSPATH . 'wp-includes/html-api/html5-named-character-references.php';
foreach (array('attribute-token', 'span', 'text-replacement', 'token', 'doctype-info', 'decoder', 'tag-processor', 'processor') as $name) {
    require ABSPATH . 'wp-includes/html-api/class-wp-html-' . $name . '.php';
}
faaaster_image_optimization_boot();
if ($case === 'incompatible') {
    // A regular installed plugin replaces the pending embedded copy, even at a LOWER version.
    $from = __DIR__ . '/../../vendor/image-optimization/optimization-detective';
    $to = WP_PLUGIN_DIR . '/optimization-detective';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $dest = $to . substr($file->getPathname(), strlen($from));
        if ($file->isDir()) { wp_mkdir_p($dest); } else { wp_mkdir_p(dirname($dest)); copy($file->getPathname(), $dest); }
    }
    file_put_contents($to . '/load.php', str_replace('1.0.0-beta7', '1.0.0-beta6', file_get_contents($to . '/load.php')));
    require $to . '/load.php';
    check('installed older copy actually wins bootstrap', $GLOBALS['optimization_detective_pending_plugin']['version'], '1.0.0-beta6');
}
foreach (array('optimization_detective_pending_plugin', 'image_prioritizer_pending_plugin') as $global) {
    $pending = $GLOBALS[$global];
    $pending['load']($pending['version']);
}
check('initialization waits for effective versions and late image sizes', has_action('wp_loaded', array('FaaasterImageOptimization', 'initialize')), 10);
do_action('od_init', OPTIMIZATION_DETECTIVE_VERSION);
if ($case === 'manifest-invalid') {
    wp_mkdir_p(dirname(FaaasterImageOptimization::manifest_path()));
    file_put_contents(FaaasterImageOptimization::manifest_path(), '{bad json');
}
do_action('wp_loaded');
if ($case === 'manifest-invalid') {
    check('invalid manifest suspends connector', faaaster_image_optimization_state()['reason'], 'manifest_unwritable_or_invalid');
    check('invalid manifest does not enable rewriting', has_action('od_register_tag_visitors', array('FaaasterImageOptimization', 'register_rewriter')), false);
    check('invalid manifest is not overwritten', file_get_contents(FaaasterImageOptimization::manifest_path()), '{bad json');
    echo "OK manifest-invalid\n";
    exit(0);
}
if ($case === 'incompatible') {
    check('runtime conflict suspended', faaaster_image_optimization_state()['reason'], 'plugin_versions');
    check('no rewriter on conflict', has_action('od_register_tag_visitors', array('FaaasterImageOptimization', 'register_rewriter')), false);
    echo "OK incompatible\n";
    exit(0);
}
if ($case === 'rest-unavailable') {
    check('REST failure suspends connector', faaaster_image_optimization_state()['reason'], 'od_rest_api_unavailable');
    check('REST failure exposed', faaaster_image_optimization_state()['od_rest_api_unavailable'], true);
    check('REST failure retains our pending purge callback', has_action('od_trigger_page_cache_invalidation', array('FaaasterImagePurges', 'flush')), 10);
    check('REST failure does not install rewriter', has_action('od_register_tag_visitors', array('FaaasterImageOptimization', 'register_rewriter')), false);
    echo "OK rest-unavailable\n";
    exit(0);
}
check('active', faaaster_image_optimization_state()['status'], 'active');
$allowed_widths = array(0, 150, 320, 480, 640, 768, 777, 960, 1024, 1280, 1536, 1600, 1920, 2560);
check('registered and standard widths exposed', faaaster_image_optimization_state()['allowed_widths'], $allowed_widths);
$manifest_path = FaaasterImageOptimization::manifest_path();
$manifest_body = file_get_contents($manifest_path);
$manifest_inode = fileinode($manifest_path);
$manifest = json_decode($manifest_body, true);
check('manifest contract', $manifest['contract'], 'faaaster-images-v1');
check('manifest widths match URL validation', $manifest['widths'], array_slice($allowed_widths, 1));
check('manifest format and quality', array($manifest['format'], $manifest['quality']), array('webp', 80));
check('manifest source contract', array($manifest['source_prefix'], $manifest['width_zero']), array('/wp-content/uploads/', 'no-resize'));
check('manifest re-publication succeeds', FaaasterImageOptimization::publish_manifest());
check('unchanged manifest retained', file_get_contents($manifest_path), $manifest_body);
clearstatcache(true, $manifest_path);
check('unchanged manifest not rewritten', fileinode($manifest_path), $manifest_inode);
$manifest['widths'][] = 750;
file_put_contents($manifest_path, json_encode($manifest));
check('previously declared width retained for cached URLs', FaaasterImageOptimization::publish_manifest());
check('cached 750 width retained', in_array(750, json_decode(file_get_contents($manifest_path), true)['widths'], true));
$manifest['widths'] = range(1, 256);
$oversized_manifest = json_encode($manifest);
file_put_contents($manifest_path, $oversized_manifest);
check('too many published widths refused', FaaasterImageOptimization::publish_manifest(), false);
check('oversized manifest not silently changed', file_get_contents($manifest_path), $oversized_manifest);
file_put_contents($manifest_path, str_repeat('x', 65537));
check('manifest read bounded to 64 KiB', FaaasterImageOptimization::publish_manifest(), false);
file_put_contents($manifest_path, $manifest_body);
check('REST status unknown until upstream checks it', faaaster_image_optimization_state()['od_rest_api_unavailable'], null);
check('unconditional purge removed', has_action('od_trigger_page_cache_invalidation', 'od_trigger_post_update_actions'), false);
check('PHP floor', FaaasterImageOptimization::eligibility('6.9', '7.3'), 'php_version');

// Valid PNG pixels generated locally; no image library dependency in the tests.
function image_png($w, $h, $name) {
    $chunk = function ($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); };
    $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x20\x40\x60", $w), $h))) . $chunk('IEND', '');
    file_put_contents(WP_CONTENT_DIR . '/uploads/2026/11/' . $name, $bytes);
    return home_url('/wp-content/uploads/2026/11/' . rawurlencode($name));
}
$large = image_png(1600, 800, 'large.png');
$small = image_png(400, 200, 'small.png');
$crop = image_png(300, 300, 'crop.png');
$unicode = image_png(40, 20, 'été photo.png');
$wide = image_png(3000, 1500, 'wide.png');
$resolver = new FaaasterImageSources(wp_upload_dir());
$source = $resolver->resolve($large);
check('non-string source rejected', $resolver->resolve(array()), null);
check('local source dimensions', $source['width'], 1600);
check('URI contract', (bool) preg_match('~^/_faaaster/images/v1/webp/q80/w960/m[0-9]+-s[0-9]+/wp-content/uploads/2026/11/large\.png$~', $resolver->url($source, 960)));
check('no upscale', $resolver->url($source, 1920), null);
check('no arbitrary width', $resolver->url($source, 666), null);
check('registered custom width emitted', strpos($resolver->url($source, 777), '/w777/') !== false);
check('custom width never upscaled', $resolver->url($resolver->resolve($small), 777), null);
check('out of contract width refused', $resolver->url($source, 2561), null);
check('encoded unicode', strpos($resolver->url($resolver->resolve($unicode)), '%C3%A9t%C3%A9%20photo.png') !== false);
$nested = 'dossier été+1';
mkdir(WP_CONTENT_DIR . '/uploads/2026/11/' . $nested);
copy(WP_CONTENT_DIR . '/uploads/2026/11/large.png', WP_CONTENT_DIR . '/uploads/2026/11/' . $nested . '/photo été +1.png');
$nested_url = home_url('/wp-content/uploads/2026/11/' . rawurlencode($nested) . '/' . rawurlencode('photo été +1.png'));
$encoded = $resolver->url($resolver->resolve($nested_url), 960);
check('each segment uses RFC3986 path encoding', strpos($encoded, '/dossier%20%C3%A9t%C3%A9%2B1/photo%20%C3%A9t%C3%A9%20%2B1.png') !== false);
check('no form-encoded plus in generated paths', strpos($encoded, '+'), false);
foreach (array('https://evil.test/wp-content/uploads/2026/11/large.png', 'https://client.test:999/wp-content/uploads/2026/11/large.png', '/wp-content/uploads/../secret.png', '/wp-content/uploads/%2e%2e/secret.png', '/wp-content/uploads/%252e%252e/secret.png', '/wp-content/uploads/2026%2f11/large.png', '/wp-content/uploads/2026/11/large.png?token=secret', '/wp-content/uploads/2026/11/large.svg') as $url) {
    check('unsafe source rejected: ' . $url, $resolver->resolve($url), null);
}
file_put_contents(WP_CONTENT_DIR . '/outside.png', file_get_contents(WP_CONTENT_DIR . '/uploads/2026/11/large.png'));
symlink(WP_CONTENT_DIR . '/outside.png', WP_CONTENT_DIR . '/uploads/escape.png');
check('symlink escape rejected', $resolver->resolve('/wp-content/uploads/escape.png'), null);
$candidate = $resolver->srcset($resolver->resolve($small), $resolver->candidates($small . ' 400w, ' . $large . ' 1600w'));
check('small and large candidates', strpos($candidate, '/w320/') !== false && strpos($candidate, '/w1280/') !== false);
check('unknown srcset preserved', $resolver->candidates('data:image/png;base64,abc 1x'), null);
check('large candidates bounded', strpos($resolver->srcset($resolver->resolve($wide), $resolver->candidates($wide . ' 3000w')), '3000w'), false);
$mixed = $resolver->srcset($resolver->resolve($small), $resolver->candidates($crop . ' 300w, ' . $large . ' 1600w'));
check('mixed-ratio crop set not interpolated', strpos($mixed, '/w320/'), false);
$custom_crop = image_png(777, 333, 'custom-crop.png');
$custom_set = $resolver->srcset($source, $resolver->candidates($custom_crop . ' 777w, ' . $large . ' 1600w'));
check('registered cropped candidate retains its own source', strpos($custom_set, 'custom-crop.png 777w') !== false);
check('registered crop converted without resizing', strpos($custom_set, '/w777/'), false);

$percent = image_png(40, 20, 'literal% photo.png');
check('literal percent source not signed by px2 stays original', $resolver->resolve($percent), null);
$long_segment = str_repeat('é', 80);
wp_mkdir_p(WP_CONTENT_DIR . '/uploads/' . $long_segment . '/' . $long_segment);
copy(WP_CONTENT_DIR . '/uploads/2026/11/large.png', WP_CONTENT_DIR . '/uploads/' . $long_segment . '/' . $long_segment . '/large.png');
$long_url = home_url('/wp-content/uploads/' . rawurlencode($long_segment) . '/' . rawurlencode($long_segment) . '/large.png');
check('overlong encoded source remains original', $resolver->resolve($long_url), null);
$boundary = $source;
$boundary['width'] = 3000;
$fixed_length = strlen(FaaasterImageSources::PREFIX . 'w2560/' . $source['version'] . '/wp-content/uploads/a.png');
$boundary['path'] = '/wp-content/uploads/' . str_repeat('x', 1024 - $fixed_length) . 'a.png';
check('1024 byte public path allowed', strlen($resolver->url($boundary, 2560)), 1024);
$boundary['path'] .= 'x';
check('1025 byte public path rejected', $resolver->url($boundary, 2560), null);

class ImageTestMetric extends OD_URL_Metric {
    public function __construct(array $data) { $this->data = $data; }
}
function collection_for($html, $lcp_id = 'hero', $ratio = 1.0) {
    $processor = new OD_HTML_Tag_Processor($html);
    $elements = array();
    while ($processor->next_tag(array('tag_closers' => 'skip'))) {
        $id = $processor->get_attribute('id');
        if (!$id) { continue; }
        $top = $id === 'below' ? 1200.0 : 0.0;
        $rect = array('width' => 400.0, 'height' => 200.0, 'x' => 0.0, 'y' => $top, 'top' => $top, 'right' => 400.0, 'bottom' => $top + 200);
        $elements[] = array('xpath' => $processor->get_xpath(), 'isLCP' => $id === $lcp_id, 'isLCPCandidate' => $id === $lcp_id,
            'intersectionRatio' => $id === 'below' ? 0.0 : $ratio, 'boundingClientRect' => $rect, 'intersectionRect' => $rect);
    }
    $samples = array();
    foreach (array(400, 1200) as $width) {
        $samples[] = new ImageTestMetric(array('uuid' => 'sample-' . $width, 'timestamp' => microtime(true), 'etag' => '0123456789abcdef0123456789abcdef', 'url' => home_url('/page/'),
            'viewport' => array('width' => $width, 'height' => 800), 'elements' => $elements));
    }
    return new OD_URL_Metric_Group_Collection($samples, '0123456789abcdef0123456789abcdef', array(480), 1, 604800);
}
function transform_html($html, $collection = null) {
    $processor = new OD_HTML_Tag_Processor($html);
    $groups = $collection ?: collection_for($html);
    $links = new OD_Link_Collection();
    $state = new OD_Visited_Tag_State();
    $registry = new OD_Tag_Visitor_Registry();
    do_action('od_register_tag_visitors', $registry);
    check('rewriter ordered before native IP', array_keys(iterator_to_array($registry))[0], 'faaaster/image-urls');
    check('video optimization excluded', $registry->is_registered('image-prioritizer/video'), false);
    $context = new OD_Tag_Visitor_Context($processor, $groups, $links, $state, 10);
    while ($processor->next_tag(array('tag_closers' => 'skip'))) {
        $processor->set_bookmark('test-current');
        foreach ($registry as $visitor) {
            $visitor($context);
            $processor->seek('test-current');
        }
        $processor->release_bookmark('test-current');
        $state->reset();
    }
    return array($processor->get_updated_html(), $links->get_html());
}
$html = '<html><head></head><body><div><img id="hero" src="' . $large . '" width="1600" height="800" loading="lazy"><img id="below" width="400" src="' . $large . '"></div></body></html>';
list($output, $preloads) = transform_html($html);
check('hero made eager by native IP', strpos($output, 'fetchpriority="high"') !== false);
check('below lazy and auto by native IP', strpos($output, 'sizes="auto, (max-width: 400px) 100vw, 400px"') !== false && strpos($output, 'loading="lazy"') !== false);
check('preload is transformed', strpos($preloads, FaaasterImageSources::PREFIX) !== false);
check('preload does not contain original URL', strpos($preloads, $large), false);
check('generated srcset covers small sizes', strpos($output, '/w320/') !== false);
list($twice) = transform_html($output);
check('no double rewriting', substr_count($twice, '/_faaaster/images/'), substr_count($output, '/_faaaster/images/'));
$unknown = '<html><head></head><body><img id="hero" src="' . $large . '"></body></html>';
list($unknown_output, $unknown_preloads) = transform_html($unknown);
check('unknown rendered width only converts src', strpos($unknown_output, '/w0/') !== false);
check('unknown rendered width does not add srcset', strpos($unknown_output, 'srcset='), false);
check('unknown rendered width does not invent sizes', strpos($unknown_output, 'sizes='), false);
check('unknown width preload has no imagesrcset', strpos($unknown_preloads, 'imagesrcset='), false);
$known = str_replace('<img ', '<img width="400" ', $unknown);
list($known_output, $known_preloads) = transform_html($known);
check('registered custom size adds responsive candidate', strpos($known_output, '/w777/') !== false);
check('registered custom size carried into preload', strpos($known_preloads, '/w777/') !== false);
check('HTML width attribute retained', strpos($known_output, 'width="400"') !== false);
check('known HTML width adds responsive candidates', strpos($known_output, '/w320/') !== false);
check('known HTML width adds bounded sizes', strpos($known_output, 'sizes="(max-width: 400px) 100vw, 400px"') !== false);
check('preload uses same bounded sizes', strpos($known_preloads, 'imagesizes="(max-width: 400px) 100vw, 400px"') !== false);
list($percent_output) = transform_html(str_replace('width="400"', 'width="100%"', $known));
check('percentage width is not a known pixel width', strpos($percent_output, 'srcset='), false);
$authored_sizes = '(max-width: 600px) 90vw, 600px';
list($sized_output) = transform_html(str_replace('<img ', '<img sizes="' . $authored_sizes . '" ', $unknown));
check('authored sizes allow candidate generation', strpos($sized_output, '/w320/') !== false);
check('authored sizes preserved', strpos($sized_output, 'sizes="' . $authored_sizes . '"') !== false);
list($auto_output) = transform_html(str_replace('<img ', '<img sizes="auto" ', $unknown));
check('auto alone does not invent an eager srcset', strpos($auto_output, 'srcset='), false);
$authored_set = str_replace('<img ', '<img srcset="' . $crop . ' 300w, ' . $large . ' 1600w" ', $unknown);
list($authored_output) = transform_html($authored_set);
check('unknown size authored srcset not expanded', strpos($authored_output, '/w320/'), false);
check('authored crop source converted at original dimensions', strpos($authored_output, '/w0/') !== false && strpos($authored_output, 'crop.png 300w') !== false);
list($unsafe_output) = transform_html(str_replace($large, $percent, $unknown));
check('unsigned percent path not rewritten in HTML', strpos($unsafe_output, FaaasterImageSources::PREFIX), false);
list($long_output) = transform_html(str_replace($large, $long_url, $unknown));
check('unsigned long path not rewritten in HTML', strpos($long_output, FaaasterImageSources::PREFIX), false);

$js = '<html><head></head><body><img src="' . $small . '" data-src="' . $large . '"></body></html>';
list($output) = transform_html($js);
check('JS loader untouched', $output, $js);
$picture = '<html><head></head><body><picture><source media="(max-width: 500px)" type="image/png" srcset="' . $crop . ' 300w"><source type="image/png" srcset="' . $large . ' 1600w"><img id="hero" src="' . $large . '"></picture></body></html>';
list($output) = transform_html($picture);
check('picture sources changed to webp', substr_count($output, 'type="image/webp"'), 2);
check('art direction retained', strpos($output, 'media="(max-width: 500px)"') !== false);
check('crop source retained', strpos($output, 'crop.png') !== false);
$unsupported_picture = str_replace('type="image/png"', 'type="image/avif"', $picture);
list($output) = transform_html($unsupported_picture);
check('unsupported picture not partially converted', strpos($output, FaaasterImageSources::PREFIX), false);
$js_picture = str_replace('<picture>', '<picture data-src="custom-loader">', $picture);
list($output) = transform_html($js_picture);
check('JS picture not partially converted', strpos($output, FaaasterImageSources::PREFIX), false);
check('JS picture loader not reprioritized', strpos($output, 'fetchpriority='), false);
$background = '<html><head></head><body><div style=\'background-image:url("' . $large . '"); color:red\'></div></body></html>';
list($output) = transform_html($background, new OD_URL_Metric_Group_Collection(array(), '0123456789abcdef0123456789abcdef', array(480), 1, 604800));
check('background converted without resize', strpos($output, '/w0/') !== false);
check('background other CSS preserved', strpos($output, 'color:red') !== false);
$below_background = '<html><head></head><body><div><img id="hero" src="' . $large . '"><div id="below" style=\'background-image:url("' . $large . '")\'></div></div></body></html>';
list($output) = transform_html($below_background);
check('native background lazy script injected', strpos($output, 'IntersectionObserver') !== false);
check('native background lazy class', strpos($output, 'od-lazy-bg-image') !== false);

$wp = (object) array('public_query_vars' => array('p', 'page', 'paged', 'custom_state'));
check('tracking-only page purge normalized', FaaasterImageOptimization::purge_url(home_url('/page/?utm_source=test')), home_url('/page/'));
check('functional query not stripped', FaaasterImageOptimization::purge_url(home_url('/page/?custom_state=done')), home_url('/page/?custom_state=done'));

$a = collection_for($html, 'hero', 0.5);
$b = collection_for($html, 'hero', 0.9);
$c = collection_for($html, 'below', 0.9);
check('geometry noise does not purge', FaaasterImagePurges::fingerprint($a), FaaasterImagePurges::fingerprint($b));
check('changed LCP does purge', FaaasterImagePurges::fingerprint($a) !== FaaasterImagePurges::fingerprint($c));
$request = new class { public function get_param($name) { return 42; } };
$context = (object) array('url_metrics_id' => 10, 'url_metric_group_collection' => $a, 'url_metric' => $a->get_flattened_url_metrics()[0], 'request' => $request);
FaaasterImagePurges::stored($context);
FaaasterImagePurges::flush(42);
check('first change purged once', count(faaaster_cache()->queue()->urls), 1);
FaaasterImagePurges::stored($context);
FaaasterImagePurges::flush(42);
check('same decision does not purge again', count(faaaster_cache()->queue()->urls), 1);
$context->url_metric_group_collection = $c;
FaaasterImagePurges::stored($context);
FaaasterImagePurges::flush(42);
check('cooldown respected', count(faaaster_cache()->queue()->urls), 1);
check('cooldown rescheduled', wp_next_scheduled('od_trigger_page_cache_invalidation', array(42)) !== false);
$GLOBALS['meta'][10][FaaasterImagePurges::LAST] = time() - 601;
FaaasterImagePurges::flush(42);
check('pending change eventually purged', count(faaaster_cache()->queue()->urls), 2);


$vendor = __DIR__ . '/../../vendor/image-optimization/';
$manifest = json_decode(file_get_contents($vendor . 'manifest.json'), true);
foreach ($manifest as $slug => $package) {
    foreach ($package['files'] as $file => $hash) {
        check('vendor integrity ' . $slug . '/' . $file, hash_file('sha256', $vendor . $slug . '/' . $file), $hash);
    }
}
echo 'OK active: ' . $GLOBALS['checks'] . " checks\n";
