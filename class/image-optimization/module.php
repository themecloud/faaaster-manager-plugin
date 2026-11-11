<?php

/** Platform-owned integration; declared OD backports are tracked in the vendor manifest. */
class FaaasterImageOptimization
{
    const OD_VERSION = '1.0.0-beta7';
    const IP_VERSION = '1.0.0-beta4';
    const RUNTIME_OPTION = 'faaaster_image_optimization_runtime';
    const PURGE_ERROR_OPTION = 'faaaster_image_optimization_purge_error';
    const WIDTHS = array(320, 480, 640, 768, 960, 1280, 1600, 1920, 2560);
    const REWRITER = 'faaaster/image-urls-v2';
    const DETECTION_FAILURES_OPTION = 'faaaster_image_detection_failures';
    const PARSER_METRIC_PROPERTY = 'faaasterParserRevision';
    const CSS_LCP_VERSION = 1;
    const FEATURES = array('css_lcp', 'inline_bg', 'lazy', 'srcset', 'style_bg');
    const CSS_LCP_REASONS = array('target_not_found', 'target_not_unique', 'source_not_in_style', 'csp');
    const STORE_ROUTE = '/optimization-detective/v1/url-metrics:store';
    // OD stores up to 1 MiB per URL Metric, 12 per URL. A real metric stays far below this bound,
    // which also caps what an anonymous request may make PHP decompress and parse.
    const MAX_METRIC_BYTES = 262144;
    // Anonymous submissions can reach any URL with public query vars: bound the posts they create.
    const MAX_URL_METRICS_POSTS = 2000;
    private static $booted = false;
    private static $status = 'disabled';
    private static $reason = 'disabled';
    private static $sources;
    private static $versions = array();
    /** Store-route refusals of this PHP request: spl_object_id => [WP_REST_Request, WP_Error]. */
    private static $refusals = array();

    public static function is_cli()
    {
        return defined('WP_CLI') && WP_CLI;
    }

    public static function enabled()
    {
        $value = isset($_SERVER['IMAGE_OPTIMIZATION_ENABLED']) ? $_SERVER['IMAGE_OPTIMIZATION_ENABLED'] : getenv('IMAGE_OPTIMIZATION_ENABLED');
        return $value === 'true';
    }

    /** The image-level switch stays authoritative; this list only cuts individual plugin mechanisms. */
    public static function parse_disabled_features($value)
    {
        $source = 'default'; $disabled = array(); $ignored = array();
        if ($value !== false) {
            if (!is_string($value) || strlen($value) > 200) {
                $source = 'invalid';
            } else {
                $value = strtolower(preg_replace('/\s+/', '', $value));
                if ($value !== '') {
                    if (preg_match('/[^a-z0-9_,]/', $value)) {
                        $source = 'invalid';
                    } else {
                        $source = 'configured';
                        foreach (array_unique(explode(',', $value)) as $name) {
                            if ($name === '') { continue; }
                            if (in_array($name, self::FEATURES, true)) { $disabled[] = $name; }
                            else { $ignored[] = $name; }
                        }
                    }
                }
            }
        }
        if (in_array('style_bg', $disabled, true)) { $disabled[] = 'css_lcp'; }
        $disabled = array_values(array_unique($disabled));
        sort($disabled); sort($ignored);
        return array('features' => array_values(array_diff(self::FEATURES, $disabled)), 'features_disabled' => $disabled,
            'features_ignored' => $ignored, 'features_source' => $source);
    }

    public static function feature_configuration()
    {
        $value = array_key_exists('IMAGE_OPTIMIZATION_DISABLED_FEATURES', $_SERVER)
            ? ($_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'] === false ? null : $_SERVER['IMAGE_OPTIMIZATION_DISABLED_FEATURES'])
            : getenv('IMAGE_OPTIMIZATION_DISABLED_FEATURES');
        $configuration = self::parse_disabled_features($value);
        if (!self::enabled() || self::$status === 'suspended') {
            $configuration['features'] = array();
        } elseif (self::$status === 'active' && !self::parser_revision()) {
            // Preserve the existing client-copy mode: only our IMG/picture and lazy srcsets remain.
            $configuration['features'] = array_values(array_intersect($configuration['features'], array('lazy', 'srcset')));
        }
        $configuration['features_disabled'] = array_values(array_diff(self::FEATURES, $configuration['features']));
        return $configuration;
    }

    public static function feature_enabled($name)
    {
        return !self::is_cli() && self::$status === 'active'
            && in_array($name, self::feature_configuration()['features'], true);
    }

    public static function can_optimize_response($can_optimize)
    {
        return $can_optimize && (bool) self::feature_configuration()['features'];
    }

    public static function eligibility($wp_version, $php_version)
    {
        if (version_compare($php_version, '7.4', '<')) {
            return 'php_version';
        }
        if (version_compare($wp_version, '6.9', '<')) {
            return 'wordpress_version';
        }
        if ((defined('PRIVATE_MODE') && in_array(PRIVATE_MODE, array(true, 'true'), true))
            || (isset($_SERVER['PRIVATE']) && $_SERVER['PRIVATE'] === 'true')
            || (isset($_SERVER['TRIAL']) && $_SERVER['TRIAL'] === 'true')) {
            return 'private_or_trial';
        }
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (is_string($host) && preg_match('/(?:^|\.)faaaster\.dev$/i', $host)) {
            return 'development_site';
        }
        return null;
    }

    public static function boot()
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        // WP-CLI sees the container environment, not FPM's hot configuration.
        if (self::is_cli()) {
            self::$status = 'pending';
            self::$reason = 'cli_request';
            // `wp cron event run` would otherwise consume a scheduled image purge without a handler.
            add_action('od_trigger_page_cache_invalidation', array(__CLASS__, 'defer_cli_invalidation'), 1);
            return;
        }
        if (!self::enabled()) {
            if (get_option(self::RUNTIME_OPTION, null) !== null) {
                self::observe();
            }
            return;
        }
        self::$status = 'pending';
        // These endpoints deliberately do not load client plugins or their public hooks.
        if (faaaster_is_hostmanager_request()) {
            self::$reason = 'isolated_request';
            return;
        }
        global $wp_version;
        $reason = self::eligibility(isset($wp_version) ? $wp_version : '0', PHP_VERSION);
        if ($reason !== null) {
            self::suspend($reason);
            return;
        }
        $vendor = dirname(__DIR__, 2) . '/vendor/image-optimization/';
        foreach (array('optimization-detective/load.php', 'image-prioritizer/load.php', 'optimization-detective/build/web-vitals.asset.php') as $file) {
            if (!is_readable($vendor . $file)) {
                self::suspend('vendor_incomplete');
                return;
            }
        }
        require_once $vendor . 'optimization-detective/load.php';
        require_once $vendor . 'image-prioritizer/load.php';
        // Upstream selects the effective copies at init:9, after regular plugins are loaded.
        // wp_loaded also waits for themes/builders which register image sizes late in init.
        add_action('wp_loaded', array(__CLASS__, 'initialize'));
    }

    public static function versions_compatible($od, $ip)
    {
        return $od === self::OD_VERSION && $ip === self::IP_VERSION;
    }

    public static function initialize()
    {
        if (self::is_cli()) {
            return;
        }
        self::$versions = array(
            'optimization_detective' => defined('OPTIMIZATION_DETECTIVE_VERSION') ? OPTIMIZATION_DETECTIVE_VERSION : null,
            'image_prioritizer' => defined('IMAGE_PRIORITIZER_VERSION') ? IMAGE_PRIORITIZER_VERSION : null,
        );
        if (!self::versions_compatible(self::$versions['optimization_detective'], self::$versions['image_prioritizer'])
            || !class_exists('OD_Tag_Visitor_Registry') || !class_exists('WP_HTML_Tag_Processor')) {
            // The client's own copies won the upstream selection: they keep their native behavior.
            self::suspend('plugin_versions');
            return;
        }
        // From here the embedded copies are the effective ones: a suspension must silence them
        // (see halt()), and their anonymous store route is guarded whatever the status.
        add_filter('rest_pre_dispatch', array(__CLASS__, 'pre_dispatch_metric_store'), 5, 3);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'reassert_metric_refusal'), PHP_INT_MAX, 3);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'guard_metric_store'), 5, 3);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'reassert_metric_refusal'), PHP_INT_MAX, 3);
        add_filter('od_maximum_url_metric_size', array(__CLASS__, 'maximum_metric_size'));
        // OD replays save_post/clean_post_cache 10 minutes after each sample: never on Faaaster.
        remove_action('od_trigger_page_cache_invalidation', 'od_trigger_post_update_actions');
        if (!faaaster_cache()) {
            self::halt('cache_module_unavailable');
            return;
        }
        require_once __DIR__ . '/sources.php';
        require_once __DIR__ . '/backgrounds.php';
        require_once __DIR__ . '/visitor.php';
        require_once __DIR__ . '/purges.php';
        // Keep ownership of already scheduled purges even when new REST samples are unavailable.
        add_action('od_url_metric_stored', array('FaaasterImagePurges', 'stored'));
        add_action('od_trigger_page_cache_invalidation', array('FaaasterImagePurges', 'flush'));
        self::$sources = new FaaasterImageSources(wp_upload_dir(null, false));
        if (!self::$sources->available()) {
            self::halt('uploads_location');
            return;
        }
        if (1 === (int) get_option('od_rest_api_unavailable', '0')) {
            self::halt('od_rest_api_unavailable');
            return;
        }
        if (!self::publish_manifest()) {
            self::halt('manifest_unwritable_or_invalid');
            return;
        }
        self::$status = 'active';
        self::$reason = self::parser_revision() ? null : 'od_parser_unpatched';
        if (self::$reason === null && self::feature_configuration()['features_source'] === 'invalid') { self::$reason = 'features_invalid'; }
        add_action('od_register_tag_visitors', array(__CLASS__, 'register_rewriter'), 5);
        add_action('od_register_tag_visitors', array(__CLASS__, 'limit_visitors'), 20);
        if (self::parser_revision()) {
            add_filter('od_current_url_metrics_etag_data', array(__CLASS__, 'etag_data'));
            add_filter('od_template_url_metrics', array(__CLASS__, 'current_url_metrics'), 10, 2);
            add_filter('rest_url', array(__CLASS__, 'detect_rest_url'), 10, 2);
            add_filter('od_url_metric_schema_root_additional_properties', array(__CLASS__, 'metric_schema'));
            if (self::feature_enabled('css_lcp')) { add_filter('od_extension_module_urls', array(__CLASS__, 'detection_modules')); }
            else { remove_filter('od_extension_module_urls', array(__CLASS__, 'detection_modules')); }
            add_filter('od_can_optimize_response', array(__CLASS__, 'can_optimize_response'));
            add_filter('rest_request_before_callbacks', array(__CLASS__, 'stamp_metric_revision'), 10, 3);
            add_action('od_detection_script_injection', array(__CLASS__, 'detection_result'), 10, 2);
        }
        self::observe();
    }

    public static function parser_revision()
    {
        return defined('OD_HTML_Tag_Processor::FAAASTER_PARSER_FIX_REVISION')
            ? OD_HTML_Tag_Processor::FAAASTER_PARSER_FIX_REVISION : 0;
    }

    public static function etag_data($data)
    {
        $data['faaaster_parser_revision'] = self::parser_revision();
        $data['faaaster_css_lcp'] = self::CSS_LCP_VERSION;
        $data['faaaster_features'] = self::feature_configuration()['features'];
        return $data;
    }

    public static function current_url_metrics($metrics, $etag)
    {
        return array_values(array_filter($metrics, function ($metric) {
            return $metric->get(self::PARSER_METRIC_PROPERTY) === self::parser_revision();
        }));
    }

    public static function detect_rest_url($url, $path)
    {
        if (ltrim($path, '/') === 'optimization-detective/v1/url-metrics:store') {
            return add_query_arg('faaaster_parser_revision', self::parser_revision(), $url);
        }
        return $url;
    }

    public static function detection_modules($urls)
    {
        if (!self::feature_enabled('css_lcp')) { return $urls; }
        $urls[] = add_query_arg('ver', self::CSS_LCP_VERSION, plugins_url('assets/image-css-lcp.js', dirname(__DIR__, 2) . '/faaaster-manager-plugin.php'));
        return $urls;
    }

    public static function metric_schema($properties)
    {
        $properties[self::PARSER_METRIC_PROPERTY] = array('type' => 'integer', 'minimum' => 1, 'readonly' => true);
        $properties['faaasterCssLcp'] = array('type' => array('object', 'null'), 'properties' => array(
            'url' => array('type' => 'string', 'format' => 'uri', 'maxLength' => 500, 'required' => true),
            'width' => array('type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true, 'required' => true),
            'height' => array('type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true, 'required' => true),
        ));
        return $properties;
    }

    /** Stamp only OD submissions from HTML produced with the effective parser revision. */
    public static function stamp_metric_revision($response, $handler, $request)
    {
        if ($response !== null || !self::is_store_request($request)) {
            return $response;
        }
        if (!is_array($request->get_json_params())) { return $response; }
        $query = $request->get_query_params();
        $revision = isset($query['faaaster_parser_revision']) ? $query['faaaster_parser_revision'] : null;
        // Edit the validated and sanitized JSON parameters in place: re-encoding the body would make
        // WordPress parse it again and hand OD the raw, unsanitized values.
        if ((is_string($revision) || is_int($revision)) && (string) $revision === (string) self::parser_revision()) {
            $request->set_param(self::PARSER_METRIC_PROPERTY, self::parser_revision());
        } else {
            // Do not trust a marker carried in an old HTML page's JSON payload.
            unset($request[self::PARSER_METRIC_PROPERTY]);
        }
        return $response;
    }

    public static function is_store_request($request)
    {
        return $request->get_method() === 'POST' && $request->get_route() === self::STORE_ROUTE;
    }

    public static function maximum_metric_size($size)
    {
        return min((int) $size, self::MAX_METRIC_BYTES);
    }

    /**
     * Before WordPress parses or validates anything (and before OD's own unbounded gzdecode):
     * refuse a submission while suspended or IP-locked, and bound the decompressed body. An empty
     * body (OD's REST availability check) keeps the native validation answer.
     *
     * A later rest_pre_dispatch callback may return null instead of the value it received and
     * cancel the refusal: ACF Pro's ACF_Rest_Api::initialize does (www.faaaster.io, 11/11/2026).
     * A refused request is therefore emptied for every later callback (nothing left to decompress
     * or parse), and reassert_metric_refusal() returns the refusal again at the end of the chain.
     */
    public static function pre_dispatch_metric_store($result, $server, $request)
    {
        if ($result !== null || !self::is_store_request($request)) {
            return $result;
        }
        $body = $request->get_body();
        if (!is_string($body) || $body === '') {
            return $result;
        }
        if (self::$status !== 'active') {
            return self::refuse($request, self::store_refused(), true);
        }
        if (class_exists('OD_Storage_Lock') && OD_Storage_Lock::is_locked()) {
            return self::refuse($request, new WP_Error('url_metric_storage_locked', 'URL Metric storage is presently locked for the current IP.', array('status' => 423)), true);
        }
        if ('gzip' === strtolower((string) $request->get_header('Content-Encoding'))) {
            // Same compressed bound as OD (navigator.sendBeacon limit).
            if (strlen($body) > 64 * 1024 || !function_exists('gzdecode')) {
                return self::refuse($request, self::store_too_large(), true);
            }
            $decoded = @gzdecode($body, self::MAX_METRIC_BYTES + 1);
            if (!is_string($decoded) || strlen($decoded) > self::MAX_METRIC_BYTES) {
                return self::refuse($request, self::store_too_large(), true);
            }
            // OD's decompression filter (priority 10) sees no Content-Encoding any more.
            $request->set_body($decoded);
            $request->remove_header('Content-Encoding');
        } elseif (strlen($body) > self::MAX_METRIC_BYTES) {
            return self::refuse($request, self::store_too_large(), true);
        }
        return $result;
    }

    /**
     * After validation, before OD's callback. A validation error (OD's REST availability check
     * expects rest_missing_callback_param) passes through untouched. A refusal is reasserted at the
     * end of the chain, like the pre-dispatch one.
     */
    public static function guard_metric_store($response, $handler, $request)
    {
        if ($response !== null || !self::is_store_request($request)) {
            return $response;
        }
        if (self::$status !== 'active') {
            return self::refuse($request, self::store_refused(), false);
        }
        $slug = $request->get_param('slug');
        if (class_exists('OD_URL_Metrics_Post_Type') && is_string($slug) && !OD_URL_Metrics_Post_Type::get_post($slug)) {
            $total = 0;
            foreach ((array) wp_count_posts(OD_URL_Metrics_Post_Type::SLUG) as $count) {
                $total += (int) $count;
            }
            if ($total >= (int) apply_filters('faaaster_image_optimization_max_url_metrics_posts', self::MAX_URL_METRICS_POSTS)) {
                return self::refuse($request, new WP_Error('faaaster_url_metrics_quota', 'URL Metrics storage quota reached for this site.', array('status' => 507)), false);
            }
        }
        return $response;
    }

    /**
     * Last callback of rest_pre_dispatch and rest_request_before_callbacks: a refusal decided for
     * this very request wins over whatever the callbacks in between returned.
     */
    public static function reassert_metric_refusal($result, $context, $request)
    {
        if (!is_object($request)) {
            return $result;
        }
        $id = spl_object_id($request);
        if (isset(self::$refusals[$id]) && self::$refusals[$id][0] === $request) {
            return self::$refusals[$id][1];
        }
        return $result;
    }

    private static function refuse($request, $error, $empty_body)
    {
        if ($empty_body) {
            $request->set_body('');
            $request->remove_header('Content-Encoding');
        }
        self::$refusals[spl_object_id($request)] = array($request, $error);
        return $error;
    }

    private static function store_refused()
    {
        return new WP_Error('faaaster_image_optimization_inactive', 'Image optimization is not active on this site.', array('status' => 503));
    }

    private static function store_too_large()
    {
        return new WP_Error('rest_content_too_large', sprintf('URL Metric payloads are limited to %d bytes.', self::MAX_METRIC_BYTES), array('status' => 413));
    }

    /** A decision-only view; never remove stored samples during parser migration. */
    public static function decision_metrics($collection)
    {
        return new OD_URL_Metric_Group_Collection(
            self::current_url_metrics($collection->get_flattened_url_metrics(), $collection->get_current_etag()),
            $collection->get_current_etag(), $collection->get_breakpoints(),
            $collection->get_sample_size(), $collection->get_freshness_ttl()
        );
    }

    public static function detection_result($needed, $injected)
    {
        if ($needed && !$injected && self::$status === 'active' && self::parser_revision() && !self::is_cli()) {
            FaaasterImagePurges::record_detection_failure();
        }
    }

    public static function register_rewriter($registry)
    {
        if (self::$status === 'active') {
            $visitor = new FaaasterImageVisitor(self::$sources, (bool) self::parser_revision());
            $registry->register(self::REWRITER, $visitor);
            if (self::parser_revision()) {
                add_action('od_finish_template_optimization', array($visitor, 'finish'));
            }
        }
    }

    public static function resize_widths()
    {
        return self::$sources ? self::$sources->widths() : array();
    }

    public static function limit_visitors($registry)
    {
        if (!self::parser_revision()) { return; } // Preserve the client extension's own behavior.
        $registry->unregister('image-prioritizer/video');
        $rewriter = $registry->get_registered(self::REWRITER);
        foreach (array('image-prioritizer/img', 'image-prioritizer/background-image') as $id) {
            $native = $registry->get_registered($id);
            if (!$native) {
                continue;
            }
            $registry->register($id, function ($context) use ($native, $rewriter, $id) {
                if ($id === 'image-prioritizer/img' && !self::feature_enabled('srcset')) { return false; }
                if ($id === 'image-prioritizer/background-image') {
                    if (!self::feature_enabled('inline_bg') && !self::feature_enabled('style_bg')) { return false; }
                    $style = $context->processor->get_attribute('style');
                    if (!self::feature_enabled('inline_bg') && is_string($style)
                        && preg_match('/background(?:-image)?\s*:[^;]*?url\(/', $style)) { return false; }
                }
                if ($id === 'image-prioritizer/background-image' && $rewriter && $rewriter->handles_background($context->processor)) {
                    return $rewriter->prioritize_background($context, $native);
                }
                if ($rewriter && $rewriter->skips($context->processor)) {
                    return false;
                }
                // Density candidates do not need the auto sizes that IP adds to lazy images.
                $density_without_sizes = $context->processor->get_tag() === 'IMG'
                    && $context->processor->get_attribute('sizes') === null
                    && preg_match('/\s[0-9.]+x\s*$/', (string) $context->processor->get_attribute('srcset'));
                if ($id === 'image-prioritizer/background-image' && $rewriter) { $context = $rewriter->css_native_context($context); }
                $tracked = $native($context);
                if ($density_without_sizes) {
                    $context->processor->remove_attribute('sizes');
                }
                return $tracked;
            });
        }
    }

    public static function manifest_path()
    {
        return WP_CONTENT_DIR . '/cache/faaaster-image-optimization/widths.json';
    }

    public static function publish_manifest()
    {
        if (self::is_cli() || !self::$sources) {
            return false;
        }
        $path = self::manifest_path();
        // Every PHP request lands here: a manifest that already lists the current widths needs
        // neither the lock nor a write (one small read, as the Nginx reader does).
        $current = is_file($path) ? @file_get_contents($path, false, null, 0, 65537) : false;
        if (is_string($current) && strlen($current) <= 65536) {
            $published = self::manifest_widths(json_decode($current, true));
            if ($published !== null && !array_diff(self::$sources->widths(), $published)) {
                return true;
            }
        }
        $directory = dirname($path);
        if ((!is_dir($directory) && !wp_mkdir_p($directory)) || !is_writable($directory)) {
            return false;
        }
        $lock = @fopen($directory . '/.widths.lock', 'c');
        if (!$lock) {
            return false;
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                return false;
            }
            return self::write_manifest($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Widths of a decoded manifest, or null if it does not follow the contract. */
    private static function manifest_widths($decoded)
    {
        if (!is_array($decoded) || !isset($decoded['contract'], $decoded['widths'], $decoded['format'], $decoded['quality'])
            || $decoded['contract'] !== 'faaaster-images-v1' || $decoded['format'] !== 'webp'
            || $decoded['quality'] !== 80 || !isset($decoded['source_prefix'], $decoded['width_zero'])
            || $decoded['source_prefix'] !== '/wp-content/uploads/' || $decoded['width_zero'] !== 'no-resize'
            || !is_array($decoded['widths']) || array_values($decoded['widths']) !== $decoded['widths']) {
            return null;
        }
        foreach ($decoded['widths'] as $width) {
            if (!is_int($width) || $width < 1 || $width > 2560) {
                return null;
            }
        }
        return $decoded['widths'];
    }

    private static function write_manifest($path)
    {
        if (is_file($path) && !is_readable($path)) {
            return false;
        }
        $widths = self::$sources->widths();
        $old = is_file($path) ? @file_get_contents($path, false, null, 0, 65537) : null;
        if ($old !== null && (!is_string($old) || strlen($old) > 65536)) {
            return false;
        }
        if ($old !== null) {
            $published = self::manifest_widths(json_decode($old, true));
            if ($published === null) {
                return false;
            }
            $widths = array_merge($widths, $published);
        }
        $widths = array_values(array_unique($widths));
        sort($widths, SORT_NUMERIC);
        // Same bound as the Nginx reader. Previously published widths keep cached URLs valid.
        if (count($widths) > 256) {
            return false;
        }
        $json = wp_json_encode(array(
            'contract' => 'faaaster-images-v1', 'format' => 'webp', 'quality' => 80,
            'source_prefix' => '/wp-content/uploads/', 'widths' => $widths, 'width_zero' => 'no-resize',
        ), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        if ($old === $json) {
            return true;
        }
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.widths-');
        if (!$temporary) {
            return false;
        }
        $ok = @file_put_contents($temporary, $json, LOCK_EX) === strlen($json)
            && @chmod($temporary, 0644) && @rename($temporary, $path);
        if (is_file($temporary)) {
            unlink($temporary);
        }
        return $ok;
    }

    public static function local_page($url)
    {
        $parsed = FaaasterCacheUrl::parse($url);
        if (!$parsed || FaaasterCacheUrl::is_static($parsed['path'])) {
            return false;
        }
        $allowed = array(wp_parse_url(home_url('/'), PHP_URL_HOST), wp_parse_url(site_url('/'), PHP_URL_HOST));
        return FaaasterCacheUrl::host_allowed($parsed['host'], $allowed);
    }

    public static function purge_url($url)
    {
        // OD identifies pages by public WP query vars; tracking-only URLs share the same HTML cache.
        global $wp;
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && isset($wp->public_query_vars) && is_array($wp->public_query_vars)) {
            parse_str($query, $args);
            if (!array_intersect(array_keys($args), $wp->public_query_vars)) {
                return substr($url, 0, strpos($url, '?'));
            }
        }
        return $url;
    }

    private static function suspend($reason)
    {
        self::$status = 'suspended';
        self::$reason = $reason;
        self::observe();
    }

    /**
     * Suspension once the embedded OD/IP are loaded: they would otherwise keep buffering and
     * rewriting pages (preloads, fetchpriority, lazy loading) on a site reported as suspended.
     * Their store route answers 503 (guard_metric_store) and OD's save_post replay is removed.
     */
    private static function halt($reason)
    {
        add_filter('od_can_optimize_response', array(__CLASS__, 'never_optimize'), PHP_INT_MAX);
        self::suspend($reason);
    }

    public static function never_optimize()
    {
        return false;
    }

    /** CLI cron: reschedule a pending image purge for the next HTTP cron run instead of dropping it. */
    public static function defer_cli_invalidation($post_id)
    {
        $post_id = (int) $post_id;
        $pending = $post_id > 0 ? get_post_meta($post_id, '_faaaster_image_pending', true) : null;
        if (!is_array($pending) || !$pending) {
            return;
        }
        $args = array($post_id);
        if (!wp_next_scheduled('od_trigger_page_cache_invalidation', $args)) {
            wp_schedule_single_event(time() + 600, 'od_trigger_page_cache_invalidation', $args);
        }
    }

    private static function observe()
    {
        if (self::is_cli()) {
            return;
        }
        $state = array('status' => self::$status, 'reason' => self::$reason, 'versions' => self::$versions,
            'features' => self::feature_configuration()['features']);
        $previous = get_option(self::RUNTIME_OPTION, array());
        $previous = is_array($previous) ? $previous : array();
        $comparable = $previous;
        unset($comparable['observed_at']);
        // Older observations predate the cuts and represent the previous all-on behavior.
        // Migrate metadata without a purge when the effective HTML behavior is unchanged.
        $missing_features = !array_key_exists('features', $comparable);
        if ($missing_features && $comparable) {
            $comparable['features'] = ($comparable['status'] ?? null) === 'active'
                ? (($comparable['reason'] ?? null) === 'od_parser_unpatched' ? array('lazy', 'srcset') : self::FEATURES) : array();
        }
        $changed = $comparable !== $state;
        if ($changed || $missing_features) {
            // A version installed after activation must invalidate rewritten pages as well.
            if ($changed && faaaster_cache()) {
                $queue = faaaster_cache()->queue();
                $queue->enqueue_all('image-optimization:' . self::$status);
                if (!$queue->all_pending()) {
                    // Throttled by the purge-all storm guard: keep the previous observation so a
                    // later request purges (activation and deactivation alike).
                    return;
                }
            }
            $state['observed_at'] = time();
            update_option(self::RUNTIME_OPTION, $state, false);
        }
    }

    public static function detection_counter_path()
    {
        return dirname(self::manifest_path()) . '/.detection-' . get_current_blog_id() . '.json';
    }

    /** Accept the previous integer option and snapshots without CSS counters during upgrade. */
    public static function counter_totals($data)
    {
        if (is_int($data) || (is_string($data) && ctype_digit($data))) { $data = array('count' => (int) $data); }
        if (!is_array($data) || !isset($data['count']) || !is_int($data['count']) || $data['count'] < 0) {
            throw new RuntimeException('Invalid image detection totals.');
        }
        $reasons = array_fill_keys(self::CSS_LCP_REASONS, 0);
        if (array_key_exists('css_lcp_unmatched', $data)) {
            if (!is_array($data['css_lcp_unmatched'])) { throw new RuntimeException('Invalid image CSS coverage totals.'); }
            foreach ($data['css_lcp_unmatched'] as $reason => $count) {
                if (!array_key_exists($reason, $reasons) || !is_int($count) || $count < 0) {
                    throw new RuntimeException('Invalid image CSS coverage total.');
                }
                $reasons[$reason] = $count;
            }
        }
        return array('count' => $data['count'], 'css_lcp_unmatched' => $reasons);
    }

    /** Atomic snapshots can be read by isolated site-state requests without loading OD/IP. */
    public static function read_detection_counter()
    {
        $path = self::detection_counter_path();
        if (!file_exists($path)) { return null; }
        $raw = @file_get_contents($path, false, null, 0, 2049);
        $data = is_string($raw) && strlen($raw) <= 2048 ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['count'], $data['next_flush'])
            || !is_int($data['count']) || $data['count'] < 0
            || !is_int($data['next_flush']) || $data['next_flush'] < 0) {
            throw new RuntimeException('Invalid or unreadable image detection counter.');
        }
        return self::counter_totals($data) + array('next_flush' => $data['next_flush']);
    }

    public static function state()
    {
        $rest_unavailable = get_option('od_rest_api_unavailable', null);
        $counter_error = null;
        try {
            $counter = self::read_detection_counter();
            $totals = $counter !== null ? $counter : self::counter_totals(get_option(self::DETECTION_FAILURES_OPTION, 0));
            $failures = $totals['count'];
            $unmatched = $totals['css_lcp_unmatched'];
        } catch (\Throwable $e) {
            $failures = null;
            $unmatched = null;
            $counter_error = 'detection_counter_unavailable';
        }
        $features = self::feature_configuration();
        if (self::is_cli()) { $features = array('features' => null, 'features_disabled' => null, 'features_ignored' => array(), 'features_source' => null); }
        return array_merge($features, array(
            'features_error' => $features['features_source'] === 'invalid' ? 'features_invalid' : null,
            'configured' => self::is_cli() ? null : self::enabled(), 'status' => self::$status, 'reason' => self::$reason,
            'versions' => self::$versions,
            'parser_fix_revision' => self::parser_revision(),
            'mode' => self::$status === 'active' ? (self::parser_revision() ? 'full' : 'srcset_only') : null,
            'detection_injection_failures' => $failures,
            'css_lcp_unmatched' => $unmatched,
            'detection_counter_error' => $counter_error,
            // Internal requests skip plugin loading: expose a dated observation, never invent "active".
            'last_observed' => (self::is_cli() || self::enabled()) ? get_option(self::RUNTIME_OPTION, null) : null,
            'allowed_widths' => self::$sources ? array_merge(array(0), self::$sources->widths()) : null,
            'manifest_path' => self::manifest_path(), 'url_contract' => 'faaaster-images-v1',
            'od_rest_api_unavailable' => $rest_unavailable === null ? null : 1 === (int) $rest_unavailable,
            'purge_error' => get_option(self::PURGE_ERROR_OPTION, null),
        ));
    }
}
