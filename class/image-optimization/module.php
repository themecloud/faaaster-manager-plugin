<?php

/** Platform-owned integration; third-party distributions remain unmodified. */
class FaaasterImageOptimization
{
    const OD_VERSION = '1.0.0-beta7';
    const IP_VERSION = '1.0.0-beta4';
    const RUNTIME_OPTION = 'faaaster_image_optimization_runtime';
    const PURGE_ERROR_OPTION = 'faaaster_image_optimization_purge_error';
    const WIDTHS = array(320, 480, 640, 768, 960, 1280, 1600, 1920, 2560);
    private static $booted = false;
    private static $status = 'disabled';
    private static $reason = 'disabled';
    private static $sources;
    private static $versions = array();

    public static function is_cli()
    {
        return defined('WP_CLI') && WP_CLI;
    }

    public static function enabled()
    {
        $value = isset($_SERVER['IMAGE_OPTIMIZATION_ENABLED']) ? $_SERVER['IMAGE_OPTIMIZATION_ENABLED'] : getenv('IMAGE_OPTIMIZATION_ENABLED');
        return $value === 'true';
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
            self::suspend('plugin_versions');
            return;
        }
        if (!faaaster_cache()) {
            self::suspend('cache_module_unavailable');
            return;
        }
        require_once __DIR__ . '/sources.php';
        require_once __DIR__ . '/visitor.php';
        require_once __DIR__ . '/purges.php';
        self::$sources = new FaaasterImageSources(wp_upload_dir(null, false));
        if (!self::$sources->available()) {
            self::suspend('uploads_location');
            return;
        }
        // Keep ownership of already scheduled purges even when new REST samples are unavailable.
        add_action('od_url_metric_stored', array('FaaasterImagePurges', 'stored'));
        remove_action('od_trigger_page_cache_invalidation', 'od_trigger_post_update_actions');
        add_action('od_trigger_page_cache_invalidation', array('FaaasterImagePurges', 'flush'));
        if (1 === (int) get_option('od_rest_api_unavailable', '0')) {
            self::suspend('od_rest_api_unavailable');
            return;
        }
        self::$status = 'active';
        self::$reason = null;
        add_action('od_register_tag_visitors', array(__CLASS__, 'register_rewriter'), 5);
        add_action('od_register_tag_visitors', array(__CLASS__, 'limit_visitors'), 20);
        self::observe();
    }

    public static function register_rewriter($registry)
    {
        if (self::$status === 'active') {
            $registry->register('faaaster/image-urls', new FaaasterImageVisitor(self::$sources));
        }
    }

    public static function limit_visitors($registry)
    {
        $registry->unregister('image-prioritizer/video');
        $rewriter = $registry->get_registered('faaaster/image-urls');
        foreach (array('image-prioritizer/img', 'image-prioritizer/background-image') as $id) {
            $native = $registry->get_registered($id);
            if (!$native) {
                continue;
            }
            $registry->register($id, function ($context) use ($native, $rewriter) {
                if ($rewriter && $rewriter->skips($context->processor)) {
                    return false;
                }
                return $native($context);
            });
        }
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

    private static function observe()
    {
        if (self::is_cli()) {
            return;
        }
        $state = array('status' => self::$status, 'reason' => self::$reason, 'versions' => self::$versions);
        $previous = get_option(self::RUNTIME_OPTION, array());
        $previous = is_array($previous) ? $previous : array();
        $comparable = $previous;
        unset($comparable['observed_at']);
        if ($comparable !== $state) {
            $state['observed_at'] = time();
            update_option(self::RUNTIME_OPTION, $state, false);
            // A version installed after activation must invalidate rewritten pages as well.
            if (faaaster_cache()) {
                faaaster_cache()->queue()->enqueue_all('image-optimization:' . self::$status);
            }
        }
    }

    public static function state()
    {
        $rest_unavailable = get_option('od_rest_api_unavailable', null);
        return array(
            'configured' => self::is_cli() ? null : self::enabled(), 'status' => self::$status, 'reason' => self::$reason,
            'versions' => self::$versions,
            // Internal requests skip plugin loading: expose a dated observation, never invent "active".
            'last_observed' => (self::is_cli() || self::enabled()) ? get_option(self::RUNTIME_OPTION, null) : null,
            'allowed_widths' => array_merge(array(0), self::WIDTHS), 'url_contract' => 'faaaster-images-v1',
            'od_rest_api_unavailable' => $rest_unavailable === null ? null : 1 === (int) $rest_unavailable,
            'purge_error' => get_option(self::PURGE_ERROR_OPTION, null),
        );
    }
}
