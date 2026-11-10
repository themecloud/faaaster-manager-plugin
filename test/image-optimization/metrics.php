<?php

/** Actual WP REST request/schema validation and OD strict metric serialization, without a database. */
define('ABSPATH', rtrim(getenv('WP_TEST_CORE_DIR'), '/') . '/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);
require __DIR__ . '/../cache/stubs/hooks.php';
function __($text, $domain = null) { return $text; }
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/compat-utf8.php';
require ABSPATH . 'wp-includes/utf8.php';
require ABSPATH . 'wp-includes/load.php';
require ABSPATH . 'wp-includes/functions.php';
require ABSPATH . 'wp-includes/formatting.php';
require ABSPATH . 'wp-includes/kses.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
add_filter('pre_option_blog_charset', static function () { return 'UTF-8'; });
require ABSPATH . 'wp-includes/rest-api.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';
require ABSPATH . 'wp-includes/class-wp-token-map.php';
require ABSPATH . 'wp-includes/html-api/html5-named-character-references.php';
foreach (array('attribute-token', 'span', 'text-replacement', 'token', 'doctype-info', 'decoder', 'tag-processor', 'processor') as $name) {
    require ABSPATH . 'wp-includes/html-api/class-wp-html-' . $name . '.php';
}
$vendor = __DIR__ . '/../../vendor/image-optimization/optimization-detective/';
foreach (array('html-tag-processor', 'data-validation-exception', 'element', 'url-metric', 'strict-url-metric', 'url-metric-group', 'url-metric-group-collection') as $name) {
    require $vendor . 'class-od-' . $name . '.php';
}
require __DIR__ . '/../../class/image-optimization/module.php';
function od_get_minimum_viewport_aspect_ratio() { return 0.4; }
function od_get_maximum_viewport_aspect_ratio() { return 2.5; }
function check_metric($label, $actual, $expected = true) {
    if ($actual !== $expected) { throw new RuntimeException($label . ': ' . var_export($actual, true)); }
    $GLOBALS['checks'] = isset($GLOBALS['checks']) ? $GLOBALS['checks'] + 1 : 1;
}
add_filter('od_url_metric_schema_root_additional_properties', array('FaaasterImageOptimization', 'metric_schema'));
add_filter('rest_request_before_callbacks', array('FaaasterImageOptimization', 'stamp_metric_revision'), 10, 3);
$body = array('url' => 'https://client.test/page/', 'viewport' => array('width' => 1200, 'height' => 800), 'elements' => array());
function metric_request($revision, $body, $route = '/optimization-detective/v1/url-metrics:store', $method = 'POST') {
    $request = new WP_REST_Request($method, $route);
    $request->set_header('Content-Type', 'application/json');
    $request->set_query_params($revision === null ? array() : array('faaaster_parser_revision' => $revision));
    $request->set_body(wp_json_encode($body));
    apply_filters('rest_request_before_callbacks', null, array(), $request);
    return $request;
}
$request = metric_request('1', $body);
check_metric('server stamps the current parser revision', $request->get_json_params()['faaasterParserRevision'], 1);
check_metric('other metric fields survive stamping', $request->get_json_params()['viewport'], $body['viewport']);
$read_only = array('uuid' => 'd3ec007a-d23a-4d29-bfc7-1f370ead83db', 'timestamp' => microtime(true), 'etag' => str_repeat('a', 32));
$metric = new OD_Strict_URL_Metric(array_merge($request->get_json_params(), $read_only));
check_metric('strict OD schema accepts the marker', $metric->get('faaasterParserRevision'), 1);
$stored = json_decode(wp_json_encode($metric), true);
$reloaded = new OD_URL_Metric($stored);
check_metric('marker survives actual OD serialization and reload', $reloaded->get('faaasterParserRevision'), 1);
check_metric('new parser sample survives an unrelated ETag change', FaaasterImageOptimization::current_url_metrics(array($reloaded), str_repeat('b', 32)), array($reloaded));
$forged = $body + array('faaasterParserRevision' => 1);
foreach (array(null, '0', '2', array('1')) as $revision) {
    $request = metric_request($revision, $forged);
    check_metric('old/unknown page cannot supply its own marker', isset($request->get_json_params()['faaasterParserRevision']), false);
    $old = new OD_Strict_URL_Metric(array_merge($request->get_json_params(), $read_only));
    check_metric('unmarked sample is excluded even with matching ETag', FaaasterImageOptimization::current_url_metrics(array($old), str_repeat('a', 32)), array());
}
check_metric('other REST routes are unchanged', metric_request('1', $body, '/wp/v2/media')->get_json_params(), $body);
check_metric('other REST methods are unchanged', metric_request('1', $body, '/optimization-detective/v1/url-metrics:store', 'GET')->get_json_params(), $body);
$blocked = metric_request('1', $body, '/wp/v2/media');
$error = new WP_Error('blocked', 'Existing error');
check_metric('existing REST error remains unchanged', FaaasterImageOptimization::stamp_metric_revision($error, array(), $blocked), $error);
$bad = $stored;
$bad['faaasterParserRevision'] = 'invalid';
try { new OD_Strict_URL_Metric($bad); throw new RuntimeException('Invalid marker was accepted.'); }
catch (OD_Data_Validation_Exception $e) { check_metric('invalid marker rejected by real WP schema', true); }
$geometry_body = $body + array('faaasterCssLcp' => array('url' => 'https://client.test/_faaaster/images/v1/webp/q80/w0/m123-s456/wp-content/uploads/hero.png', 'width' => 390.5, 'height' => 844));
$geometry_metric = new OD_Strict_URL_Metric(array_merge(metric_request('1', $geometry_body)->get_json_params(), $read_only));
check_metric('CSS LCP geometry survives strict OD validation', $geometry_metric->get('faaasterCssLcp')['width'], 390.5);
$nullable_body = $body + array('faaasterCssLcp' => null);
$nullable_metric = new OD_Strict_URL_Metric(array_merge(metric_request('1', $nullable_body)->get_json_params(), $read_only));
check_metric('non-CSS LCP can clear its optional geometry', $nullable_metric->get('faaasterCssLcp'), null);
$geometry_body['faaasterCssLcp']['height'] = 0;
try { new OD_Strict_URL_Metric(array_merge(metric_request('1', $geometry_body)->get_json_params(), $read_only)); throw new RuntimeException('Invalid CSS geometry accepted.'); }
catch (OD_Data_Validation_Exception $e) { check_metric('invalid geometry rejected by real WP schema', true); }
echo 'OK metric revision: ' . $GLOBALS['checks'] . " checks with actual WP REST schema\n";
