<?php

/** Real shared-file counter and independent stale WP option caches; no database/network. */
require __DIR__ . '/../../class/image-optimization/module.php';
require __DIR__ . '/../../class/image-optimization/purges.php';
$worker = isset($argv[1]);
$directory = $worker ? $argv[1] : sys_get_temp_dir() . '/faaaster-counter-' . getmypid();
if (!$worker) { mkdir($directory . '/cache/faaaster-image-optimization', 0777, true); }
define('WP_CONTENT_DIR', $directory);
$GLOBALS['option_cache'] = array();
$GLOBALS['notoptions'] = array();
function get_current_blog_id() { return isset($GLOBALS['test_blog']) ? $GLOBALS['test_blog'] : 1; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_json_encode($data) { return json_encode($data); }
function db_path() { return WP_CONTENT_DIR . '/db-' . get_current_blog_id() . '.json'; }
function wp_cache_delete($key, $group) {
    if ($key === 'notoptions') { $GLOBALS['notoptions'] = array(); }
    else { unset($GLOBALS['option_cache'][$key]); }
}
function get_option($key, $default = false) {
    if ($key !== FaaasterImageOptimization::DETECTION_FAILURES_OPTION) { return $default; }
    if (isset($GLOBALS['notoptions'][$key])) { return $default; }
    if (isset($GLOBALS['option_cache'][$key])) { return $GLOBALS['option_cache'][$key]; }
    $path = db_path();
    clearstatcache(true, $path);
    if (!is_file($path)) { $GLOBALS['notoptions'][$key] = true; return $default; }
    return $GLOBALS['option_cache'][$key] = json_decode(file_get_contents($path), true);
}
function update_option($key, $value, $autoload = null) {
    if ($autoload !== false) { throw new RuntimeException('The counter must not be autoloaded.'); }
    file_put_contents(WP_CONTENT_DIR . '/db-writes', "1\n", FILE_APPEND | LOCK_EX);
    if (!empty($GLOBALS['fail_sql'])) { return false; }
    $path = db_path();
    file_put_contents($path . '.' . getmypid(), json_encode($value));
    rename($path . '.' . getmypid(), $path);
    $GLOBALS['option_cache'][$key] = $value;
    unset($GLOBALS['notoptions'][$key]);
    return true;
}
class FaaasterCache {
    public static function log_exception($operation, $exception) { $GLOBALS['counter_errors'][] = $operation; }
}
function barrier($path) {
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $path);
        if (is_file($path)) { return; }
        if (microtime(true) > $deadline) { throw new RuntimeException('Counter barrier timeout.'); }
        usleep(10000);
    } while (true);
}
function check_counter($label, $actual, $expected = true) {
    if ($actual !== $expected) { throw new RuntimeException($label . ': ' . var_export($actual, true)); }
    $GLOBALS['checks'] = isset($GLOBALS['checks']) ? $GLOBALS['checks'] + 1 : 1;
}
function sql_writes() { return count(file(WP_CONTENT_DIR . '/db-writes', FILE_IGNORE_NEW_LINES)); }
function expire_checkpoint() {
    $data = FaaasterImageOptimization::read_detection_counter();
    $data['next_flush'] = time() - 1;
    file_put_contents(FaaasterImageOptimization::detection_counter_path(), json_encode($data));
}
if ($worker) {
    $token = $argv[2];
    if ($token === 'read') { echo FaaasterImageOptimization::state()['detection_injection_failures']; exit(0); }
    get_option(FaaasterImageOptimization::DETECTION_FAILURES_OPTION, 0);
    file_put_contents($directory . '/ready-' . $token, '1');
    barrier($directory . '/go');
    for ($i = 0; $i < 25; ++$i) { FaaasterImagePurges::record_detection_failure(); FaaasterImagePurges::record_css_lcp_unmatched(array('csp' => 1)); }
    if (!empty($GLOBALS['counter_errors'])) { exit(1); }
    exit(0);
}
$processes = array();
for ($i = 0; $i < 3; ++$i) {
    $processes[] = proc_open(array(PHP_BINARY, __FILE__, $directory, (string) $i), array(
        0 => array('pipe', 'r'), 1 => array('file', $directory . '/stdout-' . $i, 'w'),
        2 => array('file', $directory . '/stderr-' . $i, 'w'),
    ), $pipes);
    fclose($pipes[0]);
    barrier($directory . '/ready-' . $i);
}
file_put_contents($directory . '/go', '1');
foreach ($processes as $index => $process) {
    check_counter('counter worker exits cleanly: ' . file_get_contents($directory . '/stderr-' . $index), proc_close($process), 0);
}
check_counter('all concurrent increments retained', FaaasterImageOptimization::read_detection_counter()['count'], 75);
check_counter('concurrent CSS increments share the durable snapshot', FaaasterImageOptimization::state()['css_lcp_unmatched']['csp'], 75);
check_counter('only one SQL checkpoint in the first minute', sql_writes(), 1);
check_counter('SQL contains the first checkpoint', json_decode(file_get_contents(db_path()), true)['count'], 1);
check_counter('site state includes the uncheckpointed tail', FaaasterImageOptimization::state()['detection_injection_failures'], 75);
check_counter('healthy counter has no state error', FaaasterImageOptimization::state()['detection_counter_error'], null);
$process = proc_open(array(PHP_BINARY, __FILE__, $directory, 'read'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $directory . '/stderr-read', 'w')), $pipes);
fclose($pipes[0]);
$restarted_count = stream_get_contents($pipes[1]);
fclose($pipes[1]);
check_counter('new PHP process reads the durable total', $restarted_count, '75');
check_counter('read process exits cleanly', proc_close($process), 0);
expire_checkpoint();
FaaasterImagePurges::record_detection_failure();
check_counter('next minute checkpoints the full cumulative total', json_decode(file_get_contents(db_path()), true)['count'], 76);
check_counter('one additional checkpoint when due', sql_writes(), 2);
expire_checkpoint();
$GLOBALS['fail_sql'] = true;
FaaasterImagePurges::record_detection_failure();
check_counter('SQL failure retains the durable increment', FaaasterImageOptimization::state()['detection_injection_failures'], 77);
check_counter('SQL failure is logged', count($GLOBALS['counter_errors']), 1);
FaaasterImagePurges::record_detection_failure();
check_counter('SQL failure does not cause a per-view retry loop', sql_writes(), 3);
check_counter('increments continue after SQL failure', FaaasterImageOptimization::read_detection_counter()['count'], 78);
$GLOBALS['fail_sql'] = false;
expire_checkpoint();
FaaasterImagePurges::record_detection_failure();
check_counter('recovery checkpoints all retained increments', json_decode(file_get_contents(db_path()), true)['count'], 79);
check_counter('recovery consumes one checkpoint attempt', sql_writes(), 4);
FaaasterImagePurges::record_css_lcp_unmatched(array('target_not_found' => 2, 'target_not_unique' => 3, 'source_not_in_style' => 4));
check_counter('mixed CSS reasons do not consume another SQL checkpoint', sql_writes(), 4);
check_counter('CSS tail is visible without SQL checkpoint', FaaasterImageOptimization::state()['css_lcp_unmatched'], array('target_not_found' => 2, 'target_not_unique' => 3, 'source_not_in_style' => 4, 'csp' => 75));
expire_checkpoint();
FaaasterImagePurges::record_css_lcp_unmatched(array('csp' => 1));
check_counter('CSS checkpoint retains injection total', json_decode(file_get_contents(db_path()), true)['count'], 79);
check_counter('CSS checkpoint stores every reason', json_decode(file_get_contents(db_path()), true)['css_lcp_unmatched']['csp'], 76);
check_counter('one shared checkpoint after next minute', sql_writes(), 5);
$snapshot = file_get_contents(FaaasterImageOptimization::detection_counter_path());
file_put_contents(FaaasterImageOptimization::detection_counter_path(), '{corrupt');
FaaasterImagePurges::record_detection_failure();
check_counter('corrupt state is reported as unknown, not zero', FaaasterImageOptimization::state()['detection_injection_failures'], null);
check_counter('corrupt state exposes the reason', FaaasterImageOptimization::state()['detection_counter_error'], 'detection_counter_unavailable');
check_counter('corrupt state does not reset the counter or write SQL', sql_writes(), 5);
check_counter('corrupt state remains available for diagnosis', file_get_contents(FaaasterImageOptimization::detection_counter_path()), '{corrupt');
file_put_contents(FaaasterImageOptimization::detection_counter_path(), $snapshot);
$GLOBALS['test_blog'] = 2;
$GLOBALS['option_cache'] = $GLOBALS['notoptions'] = array();
FaaasterImagePurges::record_detection_failure();
check_counter('separate blog has its own counter', FaaasterImageOptimization::state()['detection_injection_failures'], 1);
$GLOBALS['test_blog'] = 1;
check_counter('other blog total remains intact', FaaasterImageOptimization::state()['detection_injection_failures'], 79);
$GLOBALS['test_blog'] = 3;
$GLOBALS['option_cache'] = $GLOBALS['notoptions'] = array();
file_put_contents(db_path(), json_encode('9'));
check_counter('legacy SQL numeric string remains readable', FaaasterImageOptimization::state()['detection_injection_failures'], 9);
FaaasterImagePurges::record_css_lcp_unmatched(array('target_not_found' => 1));
check_counter('legacy SQL total survives first CSS event', FaaasterImageOptimization::state()['detection_injection_failures'], 9);
check_counter('legacy SQL gains reason totals', FaaasterImageOptimization::state()['css_lcp_unmatched']['target_not_found'], 1);
$GLOBALS['test_blog'] = 4;
file_put_contents(FaaasterImageOptimization::detection_counter_path(), json_encode(array('count' => 5, 'next_flush' => 0)));
FaaasterImagePurges::record_css_lcp_unmatched(array('csp' => 2));
check_counter('legacy snapshot retains injection total', FaaasterImageOptimization::state()['detection_injection_failures'], 5);
check_counter('legacy snapshot gains coverage totals', FaaasterImageOptimization::state()['css_lcp_unmatched']['csp'], 2);
$GLOBALS['test_blog'] = 1;
define('WP_CLI', true);
$writes = sql_writes();
FaaasterImagePurges::record_detection_failure();
FaaasterImagePurges::record_css_lcp_unmatched(array('csp' => 1));
check_counter('CLI does not modify the durable counter', file_get_contents(FaaasterImageOptimization::detection_counter_path()), $snapshot);
check_counter('CLI does not checkpoint to SQL', sql_writes(), $writes);
echo 'OK detection counter: ' . $GLOBALS['checks'] . " checks, concurrent increments, bounded SQL, restart/recovery, CLI\n";
