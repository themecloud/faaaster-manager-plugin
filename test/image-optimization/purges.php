<?php

/** Actual purge queue + inter-process locking; file-backed WP metadata stand-in, no DB/network. */
error_reporting(E_ALL);
ini_set('error_log', '/dev/null');
require __DIR__ . '/../cache/stubs/hooks.php';
require __DIR__ . '/../cache/stubs/wp.php';
foreach (array('url', 'transport', 'settings', 'events', 'purge-queue', 'cache') as $file) {
    require __DIR__ . '/../../class/cache/' . $file . '.php';
}
require __DIR__ . '/../cache/stubs/fakes.php';
require __DIR__ . '/../../class/cloudflare-manager.php';
require __DIR__ . '/../../class/image-optimization/module.php';
require __DIR__ . '/../../class/image-optimization/backgrounds.php';
require __DIR__ . '/../../class/image-optimization/purges.php';

$worker = isset($argv[2]) && $argv[2] === 'worker';
$directory = $worker ? $argv[3] : sys_get_temp_dir() . '/faaaster-purge-test-' . getmypid();
if (!$worker) { mkdir($directory, 0777, true); }
define('WP_CONTENT_DIR', $directory);
$lock_directory = $directory . '/cache/faaaster-image-optimization';
$GLOBALS['meta_directory'] = $directory;
$GLOBALS['meta_cache'] = array();
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value) { return json_encode($value); }
function get_current_blog_id() { return 1; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function delete_option($key) { unset($GLOBALS['faaaster_test']['options'][$key]); return true; }
function wp_cache_delete($id, $group) { unset($GLOBALS['meta_cache'][$id]); return true; }
function meta_path($id, $key) { return $GLOBALS['meta_directory'] . '/meta-' . $id . '-' . md5($key) . '.json'; }
function get_post_meta($id, $key, $single = false) {
    if (!isset($GLOBALS['meta_cache'][$id]) || !array_key_exists($key, $GLOBALS['meta_cache'][$id])) {
        $path = meta_path($id, $key);
        $GLOBALS['meta_cache'][$id][$key] = is_file($path) ? json_decode(file_get_contents($path), true) : '';
    }
    return $GLOBALS['meta_cache'][$id][$key];
}
function update_post_meta($id, $key, $value) {
    // An individual metadata write is atomic, as in MySQL; the map RMW is not.
    if ($key === FaaasterImagePurges::PENDING && !empty($GLOBALS['reject_pending_write'])) { return false; }
    if ($key === FaaasterImagePurges::PENDING && isset($GLOBALS['before_pending_write'])) {
        call_user_func($GLOBALS['before_pending_write']);
    }
    $path = meta_path($id, $key);
    $tmp = $path . '.' . getmypid();
    file_put_contents($tmp, json_encode($value));
    rename($tmp, $path);
    wp_cache_delete($id, 'post_meta');
    return true;
}
function wp_next_scheduled($hook, $args = array()) {
    $path = $GLOBALS['meta_directory'] . '/cron-' . md5($hook . json_encode($args));
    return is_file($path) ? (int) file_get_contents($path) : false;
}
function wp_clear_scheduled_hook($hook, $args) {
    $path = $GLOBALS['meta_directory'] . '/cron-' . md5($hook . json_encode($args));
    if (is_file($path)) { unlink($path); }
    return true;
}
function wp_schedule_single_event($time, $hook, $args) {
    file_put_contents($GLOBALS['meta_directory'] . '/cron-' . md5($hook . json_encode($args)), $time);
    return true;
}
function check_purge($label, $actual, $expected = true) {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export($actual, true) . ' != ' . var_export($expected, true));
    }
    $GLOBALS['purge_checks'] = isset($GLOBALS['purge_checks']) ? $GLOBALS['purge_checks'] + 1 : 1;
}
class ImagePurgeTestCache {
    private $queue;
    public function __construct($queue) { $this->queue = $queue; }
    public function queue() { return $this->queue; }
}
function faaaster_cache() { return isset($GLOBALS['purge_cache']) ? $GLOBALS['purge_cache'] : null; }
class ImagePurgeTransport extends FaaasterTestTransport {
    public $during;
    public function purge_path($host, $path) {
        if ($this->during) { call_user_func($this->during); }
        file_put_contents($GLOBALS['meta_directory'] . '/http-calls', $path . "\n", FILE_APPEND | LOCK_EX);
        return parent::purge_path($host, $path);
    }
}
function purge_queue($enabled = true, $all = false) {
    $transport = new ImagePurgeTransport();
    // Exercise the real CF client and real queue report, including batching/status parsing.
    $cf = new FaaasterCloudflare('app', 'main', 'test-key', $enabled ? 'true' : 'false', 'https://control.test');
    $queue = new FaaasterCachePurgeQueue($transport, $cf, $enabled, new FaaasterCacheEvents(), new FaaasterCacheSettings(), function () {
        return array('home_url' => home_url('/'), 'hosts' => array('example.com'));
    });
    if ($all) { $queue->enqueue_all('existing-change'); }
    $GLOBALS['purge_cache'] = new ImagePurgeTestCache($queue);
    return $transport;
}
class ImagePurgeGroup implements Countable {
    public function count(): int { return 1; }
    public function get_lcp_element() { return null; }
    public function get_xpath_elements_map() { return array(); }
    public function get_minimum_viewport_width() { return 0; }
    public function get_maximum_viewport_width() { return null; }
}
class ImagePurgeCollection extends ArrayIterator {
    public $decision;
    public function __construct($decision) { parent::__construct(array(new ImagePurgeGroup())); $this->decision = $decision; }
    public function get_all_element_max_intersection_ratios() { return array($this->decision => 1); }
    public function get_all_elements_positioned_in_any_initial_viewport() { return array(); }
    public function get_first_group() { return $this[0]; }
    public function get_last_group() { return $this[0]; }
}
function purge_context($id, $post_id, $decision = 'hero') {
    return (object) array(
        'url_metrics_id' => $id, 'url_metric_group_collection' => new ImagePurgeCollection($decision),
        'url_metric' => new class($id) {
            private $id;
            public function __construct($id) { $this->id = $id; }
            public function get_url() { return home_url('/page-' . $this->id . '/'); }
        },
        'request' => new class($post_id) {
            private $id;
            public function __construct($id) { $this->id = $id; }
            public function get_param($name) { return $this->id; }
        },
    );
}
function fresh_meta($id, $key) { wp_cache_delete($id, 'post_meta'); return get_post_meta($id, $key, true); }
function due_retry($post_id) {
    $pending = fresh_meta($post_id, FaaasterImagePurges::PENDING);
    foreach ($pending as &$entry) { $entry['next_attempt'] = time() - 1; }
    unset($entry);
    update_post_meta($post_id, FaaasterImagePurges::PENDING, $pending);
    wp_clear_scheduled_hook('od_trigger_page_cache_invalidation', array($post_id));
}
function wait_file($path) {
    $deadline = microtime(true) + 10;
    while (!is_file($path)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout: ' . basename($path)); }
        usleep(10000);
        clearstatcache(true, $path);
    }
}
function start_worker($action, $post_id, $metric_id, $token) {
    $dir = $GLOBALS['meta_directory'];
    $process = proc_open(array(PHP_BINARY, __FILE__, 'purges', 'worker', $dir, $action, $post_id, $metric_id, $token), array(
        0 => array('pipe', 'r'), 1 => array('file', $dir . '/stdout-' . $token, 'w'), 2 => array('file', $dir . '/stderr-' . $token, 'w'),
    ), $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start purge worker.'); }
    fclose($pipes[0]);
    wait_file($dir . '/ready-' . $token);
    return array($process, $token);
}
function finish_worker($worker) {
    list($process, $token) = $worker;
    $dir = $GLOBALS['meta_directory'];
    wait_file($dir . '/done-' . $token);
    check_purge('worker exits cleanly: ' . $token . ' ' . file_get_contents($dir . '/stderr-' . $token), proc_close($process), 0);
}

if ($worker) {
    list($action, $post_id, $metric_id, $token) = array_slice($argv, 4);
    // Prime the request-local WP metadata cache BEFORE the other process writes.
    get_post_meta($post_id, FaaasterImagePurges::PENDING, true);
    get_post_meta($metric_id, FaaasterImagePurges::META, true);
    get_post_meta($metric_id, FaaasterImagePurges::LAST, true);
    file_put_contents($directory . '/ready-' . $token, '1');
    wait_file($directory . '/go-' . $token);
    $transport = purge_queue(false);
    if ($action === 'flush-hold') {
        $transport->during = function () use ($directory, $token) {
            file_put_contents($directory . '/in-http-' . $token, '1');
            wait_file($directory . '/release-' . $token);
        };
    }
    if ($action === 'store-hold') {
        $GLOBALS['before_pending_write'] = function () use ($directory, $token) {
            file_put_contents($directory . '/in-write-' . $token, '1');
            wait_file($directory . '/release-' . $token);
        };
    }
    if (in_array($action, array('store', 'store-hold'), true)) { FaaasterImagePurges::stored(purge_context((int) $metric_id, (int) $post_id, $token)); }
    else { FaaasterImagePurges::flush((int) $post_id); }
    file_put_contents($directory . '/done-' . $token, '1');
    exit(0);
}

// Enqueue is not confirmation. Keep pending data and cooldown unchanged during HTTP.
$t = purge_queue();
FaaasterImagePurges::stored(purge_context(10, 42));
$t->during = function () {
    check_purge('pending still durable during HTTP', isset(get_post_meta(42, FaaasterImagePurges::PENDING, true)[10]));
    check_purge('no cooldown before confirmation', get_post_meta(10, FaaasterImagePurges::LAST, true), '');
};
$t->path_status = 0;
// WP-Cron removes a single event before invoking its callback.
unlink($directory . '/cron-' . md5('od_trigger_page_cache_invalidation' . json_encode(array(42))));
FaaasterImagePurges::flush(42);
check_purge('nginx transport failure remains pending', isset(fresh_meta(42, FaaasterImagePurges::PENDING)[10]));
check_purge('failed purge does not advance cooldown', fresh_meta(10, FaaasterImagePurges::LAST), '');
check_purge('failure has scheduled retry', wp_next_scheduled('od_trigger_page_cache_invalidation', array(42)) !== false);
$attempt_calls = $t->count('path');
FaaasterImagePurges::flush(42);
check_purge('failed purge is not retried before ten minutes', $t->count('path'), $attempt_calls);
check_purge('early callback does not consume attempt budget', fresh_meta(42, FaaasterImagePurges::PENDING)[10]['attempts'], 1);
$t->path_status = 403;
due_retry(42);
FaaasterImagePurges::flush(42);
check_purge('nginx rejection remains pending', isset(fresh_meta(42, FaaasterImagePurges::PENDING)[10]));
$t->path_status = 200;
$GLOBALS['faaaster_test']['remote_status'] = 503;
due_retry(42);
FaaasterImagePurges::flush(42);
check_purge('CF failure remains pending despite nginx success', isset(fresh_meta(42, FaaasterImagePurges::PENDING)[10]));
check_purge('CF failure does not advance cooldown', fresh_meta(10, FaaasterImagePurges::LAST), '');
$GLOBALS['faaaster_test']['remote_status'] = 200;
due_retry(42);
FaaasterImagePurges::flush(42);
check_purge('confirmed retry clears pending', fresh_meta(42, FaaasterImagePurges::PENDING), array());
check_purge('confirmed retry starts cooldown', fresh_meta(10, FaaasterImagePurges::LAST) > time() - 5);
$n = $t->count('path');
FaaasterImagePurges::stored(purge_context(10, 42));
FaaasterImagePurges::flush(42);
check_purge('unchanged decisions do not retry acknowledged work', $t->count('path'), $n);
FaaasterImagePurges::stored(purge_context(10, 42, 'changed'));
FaaasterImagePurges::flush(42);
check_purge('new decision respects successful cooldown', $t->count('path'), $n);
check_purge('new decision stays pending during cooldown', isset(fresh_meta(42, FaaasterImagePurges::PENDING)[10]));

// A total purge may supersede the URL batch; its CF status must also be confirmed.
purge_queue(true, true);
FaaasterImagePurges::stored(purge_context(11, 43));
$GLOBALS['faaaster_test']['remote_status'] = 503;
FaaasterImagePurges::flush(43);
check_purge('failed total CF purge remains pending', isset(fresh_meta(43, FaaasterImagePurges::PENDING)[11]));
$GLOBALS['faaaster_test']['remote_status'] = 200;
due_retry(43);
FaaasterImagePurges::flush(43);
check_purge('retry after failed total purge is acknowledged', fresh_meta(43, FaaasterImagePurges::PENDING), array());
check_purge('existing cache journal records HTTP outcomes', count(get_option(FaaasterCacheEvents::OPTION)) >= 6);

// Retry is durable even if the transport throws, or the cache module is unavailable.
$t = purge_queue(false);
FaaasterImagePurges::stored(purge_context(12, 44));
$t->during = function () { throw new RuntimeException('transport aborted'); };
FaaasterImagePurges::flush(44);
check_purge('transport exception caught and exposed', FaaasterImageOptimization::state()['purge_error']['reason'], 'purge_exception');
check_purge('interrupted transport remains pending', isset(fresh_meta(44, FaaasterImagePurges::PENDING)[12]));
check_purge('interrupted transport has retry', wp_next_scheduled('od_trigger_page_cache_invalidation', array(44)) !== false);
$GLOBALS['purge_cache'] = null;
due_retry(44);
FaaasterImagePurges::flush(44);
check_purge('missing cache module preserves pending', isset(fresh_meta(44, FaaasterImagePurges::PENDING)[12]));
purge_queue(false);
due_retry(44);
FaaasterImagePurges::flush(44);
check_purge('lock released after exception and nginx-only retry confirmed', fresh_meta(44, FaaasterImagePurges::PENDING), array());

// Two writers start with the SAME stale empty metadata snapshot. Serialize their RMWs.
$w1 = start_worker('store-hold', 50, 20, 'writer-a');
$w2 = start_worker('store', 50, 21, 'writer-b');
file_put_contents($directory . '/go-writer-a', '1');
wait_file($directory . '/in-write-writer-a');
file_put_contents($directory . '/go-writer-b', '1');
$probe = fopen($lock_directory . '/.purges-1-50.lock', 'c');
check_purge('store lock held across pending read-modify-write', flock($probe, LOCK_EX | LOCK_NB), false);
fclose($probe);
file_put_contents($directory . '/release-writer-a', '1');
finish_worker($w1);
finish_worker($w2);
$pending = fresh_meta(50, FaaasterImagePurges::PENDING);
check_purge('concurrent stale writers preserve both URLs', count($pending), 2);
check_purge('both metric IDs retained', isset($pending[20], $pending[21]));

// HTTP runs outside the post lock: a store request (anonymous REST) completes while a purge
// is in flight, and its new decision survives the acknowledgement of the older one.
FaaasterImagePurges::stored(purge_context(30, 60));
$flush = start_worker('flush-hold', 60, 30, 'flush-a');
file_put_contents($directory . '/go-flush-a', '1');
wait_file($directory . '/in-http-flush-a');
// A nonblocking probe proves the other PHP process does not hold the lock during transport.
$probe = fopen($lock_directory . '/.purges-1-60.lock', 'c');
check_purge('purge lock released during transport', flock($probe, LOCK_EX | LOCK_NB), true);
flock($probe, LOCK_UN);
fclose($probe);
$writer = start_worker('store', 60, 30, 'new-decision');
file_put_contents($directory . '/go-new-decision', '1');
finish_worker($writer);
check_purge('store completes while the purge is in flight', isset(fresh_meta(60, FaaasterImagePurges::PENDING)[30]));
file_put_contents($directory . '/release-flush-a', '1');
finish_worker($flush);
check_purge('decision arriving during HTTP survives acknowledgement', isset(fresh_meta(60, FaaasterImagePurges::PENDING)[30]));
check_purge('new decision fingerprint survives', fresh_meta(30, FaaasterImagePurges::META), FaaasterImagePurges::fingerprint(new ImagePurgeCollection('new-decision')));
$t = purge_queue(false);
FaaasterImagePurges::flush(60);
check_purge('new decision obeys confirmed cooldown', $t->count('path'), 0);
update_post_meta(30, FaaasterImagePurges::LAST, time() - 601);
FaaasterImagePurges::flush(60);
check_purge('new decision eventually purged', fresh_meta(60, FaaasterImagePurges::PENDING), array());

// Two cron workers cannot purge the same snapshot twice, even with stale LAST/PENDING caches.
FaaasterImagePurges::stored(purge_context(40, 70));
$first = start_worker('flush-hold', 70, 40, 'cron-a');
$second = start_worker('flush', 70, 40, 'cron-b');
$before = file_get_contents($directory . '/http-calls');
file_put_contents($directory . '/go-cron-a', '1');
wait_file($directory . '/in-http-cron-a');
file_put_contents($directory . '/go-cron-b', '1');
file_put_contents($directory . '/release-cron-a', '1');
finish_worker($first);
finish_worker($second);
$after = file_get_contents($directory . '/http-calls');
check_purge('concurrent cron workers issue one HTTP purge', substr_count(substr($after, strlen($before)), '/page-40/'), 1);
check_purge('concurrent cron workers leave confirmed empty queue', fresh_meta(70, FaaasterImagePurges::PENDING), array());
// Five failed attempts exhaust the budget; unchanged samples cannot start a new loop.
$t = purge_queue(false);
$t->path_status = 503;
FaaasterImagePurges::stored(purge_context(50, 80));
for ($i = 0; $i < FaaasterImagePurges::MAX_ATTEMPTS; $i++) {
    due_retry(80);
    FaaasterImagePurges::flush(80);
}
check_purge('exactly five HTTP attempts', $t->count('path'), 5);
check_purge('exhausted work is still durable', fresh_meta(80, FaaasterImagePurges::PENDING)[50]['attempts'], 5);
check_purge('exhausted retry no longer scheduled', wp_next_scheduled('od_trigger_page_cache_invalidation', array(80)), false);
check_purge('retry exhaustion exposed in site state', FaaasterImageOptimization::state()['purge_error']['reason'], 'purge_retry_exhausted');
FaaasterImagePurges::stored(purge_context(50, 80));
FaaasterImagePurges::flush(80);
check_purge('unchanged measurement cannot reset failed budget', $t->count('path'), 5);
check_purge('unchanged measurement does not rearm exhausted cron', wp_next_scheduled('od_trigger_page_cache_invalidation', array(80)), false);
FaaasterImagePurges::stored(purge_context(50, 80, 'new-layout'));
check_purge('new decision resets attempt budget', fresh_meta(80, FaaasterImagePurges::PENDING)[50]['attempts'], 0);
check_purge('new decision schedules work', wp_next_scheduled('od_trigger_page_cache_invalidation', array(80)) !== false);
$t->path_status = 200;
FaaasterImagePurges::flush(80);
check_purge('new confirmed decision clears old exhausted work', fresh_meta(80, FaaasterImagePurges::PENDING), array());
check_purge('recovery clears corresponding site error', FaaasterImageOptimization::state()['purge_error'], null);

// A lock that cannot be opened must not crash cron or discard pending work.
$locked_pending = array(52 => array('url' => home_url('/page-52/'), 'attempts' => 0, 'next_attempt' => 0));
update_post_meta(82, FaaasterImagePurges::PENDING, $locked_pending);
mkdir($lock_directory . '/.purges-1-82.lock');
FaaasterImagePurges::flush(82);
check_purge('lock exception caught and exposed', FaaasterImageOptimization::state()['purge_error']['reason'], 'purge_lock_unavailable');
check_purge('lock failure preserves pending data', fresh_meta(82, FaaasterImagePurges::PENDING), $locked_pending);
check_purge('lock failure does not create an infinite retry loop', wp_next_scheduled('od_trigger_page_cache_invalidation', array(82)), false);
check_purge('purge storage never creates widths manifest', is_file($lock_directory . '/widths.json'), false);
$t = purge_queue(false);
FaaasterImagePurges::stored(purge_context(53, 83));
$GLOBALS['reject_pending_write'] = true;
FaaasterImagePurges::flush(83);
$GLOBALS['reject_pending_write'] = false;
check_purge('no HTTP without a persisted attempt budget', $t->count('path'), 0);
check_purge('metadata write failure retains pending work', isset(fresh_meta(83, FaaasterImagePurges::PENDING)[53]));
check_purge('metadata write failure exposed', FaaasterImageOptimization::state()['purge_error']['reason'], 'purge_exception');


echo 'OK purges: ' . $GLOBALS['purge_checks'] . " checks (including separate PHP processes)\n";
