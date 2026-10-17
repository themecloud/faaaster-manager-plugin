<?php

if (!defined('ABSPATH')) {
    exit;
}

class FaaasterPerformanceRuntime
{
    const AUDIT_PARAM = 'faaaster_perf_audit';
    /**
     * A preview request renders the page with a compiled rule set that the
     * control plane registered for a few minutes, instead of the live rules.
     * The parameter is unknown to the FastCGI cache normalisation, so the
     * request always reaches PHP and its response is never stored: a candidate
     * rule is validated without ever reaching a visitor.
     */
    const PREVIEW_PARAM = 'faaaster_perf_preview';
    const PREVIEW_TTL = 900;
    /** An audit spans several control-plane ticks (one page per tick). */
    const AUDIT_TTL = 1800;
    const RECEIPT_LIST_MAX = 300;

    private $settings;
    private $compiled;
    private $audit_token = null;
    private $audit_images = array();
    private $preview = null;
    private $head_printed = false;
    private $receipt = array(
        'dequeued' => array('style' => array(), 'script' => array()),
        'dequeuedSources' => array(),
        'strategies' => array(),
        'printed' => array('style' => array(), 'script' => array()),
        'late' => array(),
        'inlineAfter' => array(),
        'promoted' => array(),
        'scriptLoading' => array(),
        'images' => array(),
        'swapped' => array(),
        'preloads' => 0,
        'preconnects' => 0,
        'metrics' => 0,
    );

    public function __construct(FaaasterPerformanceSettings $settings)
    {
        $this->settings = $settings;
        $all = $settings->all();
        $this->compiled = isset($all['compiled']) && is_array($all['compiled'])
            ? $all['compiled']
            : FaaasterPerformanceSettings::defaults()['compiled'];
    }

    public function register()
    {
        add_action('wp_enqueue_scripts', array($this, 'unload_assets'), 9999);
        add_action('wp_enqueue_scripts', array($this, 'script_strategies'), 9998);
        add_action('wp_enqueue_scripts', array($this, 'promote_late_styles'), 10000);
        add_filter('wp_get_attachment_image_attributes', array($this, 'image_attributes'), 9999, 3);
        add_filter('wp_content_img_tag', array($this, 'content_image_tag'), 9999, 3);
        add_filter('style_loader_src', array($this, 'style_source'), 9999, 2);
        add_filter('wp_resource_hints', array($this, 'resource_hints'), 9999, 2);
        add_action('wp_head', array($this, 'font_head'), 2);
        add_action('shutdown', array($this, 'capture_audit_snapshot'), PHP_INT_MAX);
        $this->audit_token = $this->requested_audit_token();
        if ($this->audit_token) {
            $this->start_recording();
        } else {
            $this->start_preview();
        }
    }

    /**
     * Audit and preview requests record each printed stylesheet and whether it
     * came after the <head>: WordPress prints the styles enqueued during the
     * rendering at wp_footer, once the content is already laid out.
     */
    private function start_recording()
    {
        add_filter('style_loader_tag', array($this, 'record_style_tag'), PHP_INT_MAX, 2);
        add_action('wp_head', array($this, 'mark_head_printed'), PHP_INT_MAX);
    }

    public function mark_head_printed()
    {
        $this->head_printed = true;
    }

    /** Swap the live tables for the preview set and record what really prints. */
    private function start_preview()
    {
        $token = isset($_GET[self::PREVIEW_PARAM]) ? (string) $_GET[self::PREVIEW_PARAM] : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return;
        }
        $session = get_transient(self::preview_transient_key($token));
        if (!is_array($session) || !isset($session['compiled']) || !is_array($session['compiled'])) {
            return;
        }
        $this->compiled = $session['compiled'];
        $this->preview = array(
            'token' => $token,
            'checksum' => (string) ($session['checksum'] ?? ''),
            'paths' => isset($session['paths']) && is_array($session['paths']) ? $session['paths'] : array(),
        );
        $this->start_recording();
        add_filter('script_loader_tag', array($this, 'record_script_tag'), PHP_INT_MAX, 2);
        add_action('shutdown', array($this, 'capture_preview_receipt'), PHP_INT_MAX);
    }

    public function unload_assets()
    {
        if ($this->audit_token) {
            return;
        }
        foreach (array('style', 'script') as $type) {
            $rules = isset($this->compiled['unload'][$type]) && is_array($this->compiled['unload'][$type])
                ? $this->compiled['unload'][$type]
                : array();
            foreach ($rules as $handle => $targets) {
                if (!$this->targets_match($targets) || $this->has_enqueued_dependents($type, $handle)) {
                    continue;
                }
                if ($type === 'style') {
                    wp_dequeue_style($handle);
                } else {
                    wp_dequeue_script($handle);
                }
                if ($this->preview) {
                    $this->receipt['dequeued'][$type][(string) $handle] = true;
                    $registry = $type === 'style' ? wp_styles() : wp_scripts();
                    $src = is_object($registry) && isset($registry->registered[$handle])
                        ? (string) $registry->registered[$handle]->src
                        : '';
                    if ($src !== '') {
                        $this->receipt['dequeuedSources'][self::absolute_src($src)] = true;
                    }
                }
            }
        }
    }

    public function script_strategies()
    {
        if ($this->audit_token) {
            return;
        }
        foreach ((array) ($this->compiled['scriptStrategies'] ?? array()) as $handle => $entries) {
            foreach ((array) $entries as $entry) {
                if (!$this->target_matches($entry['target'] ?? array())) {
                    continue;
                }
                wp_script_add_data($handle, 'strategy', $entry['strategy']);
                if ($this->preview) {
                    $this->receipt['strategies'][(string) $handle] = $entry['strategy'];
                }
                break;
            }
        }
    }

    /**
     * A late stylesheet enqueued again from wp_enqueue_scripts prints in the
     * <head>, with its dependencies, instead of at wp_footer. Only registered
     * handles: enqueuing an unknown one would be skipped by WordPress anyway.
     */
    public function promote_late_styles()
    {
        if ($this->audit_token) {
            return;
        }
        $registry = wp_styles();
        foreach ((array) ($this->compiled['promoteStyles'] ?? array()) as $handle => $targets) {
            if (!$this->targets_match($targets) || !is_object($registry) || !isset($registry->registered[$handle])) {
                continue;
            }
            wp_enqueue_style($handle);
            if ($this->preview) {
                $this->receipt['promoted'][(string) $handle] = true;
            }
        }
    }

    public function record_style_tag($tag, $handle)
    {
        $handle = (string) $handle;
        $this->receipt['printed']['style'][$handle] = true;
        if ($this->head_printed) {
            $this->receipt['late'][$handle] = true;
        }
        // Inline CSS attached to the handle may be added during the rendering:
        // printed in the <head>, it would lose what comes after.
        $registry = wp_styles();
        if (is_object($registry) && !empty($registry->registered[$handle]->extra['after'])) {
            $this->receipt['inlineAfter'][$handle] = true;
        }
        return $tag;
    }

    /**
     * The filtered markup can hold the inline before/after scripts too: the
     * loading mode is read on the external <script src> element only, as
     * WordPress finally printed it (it may downgrade a strategy for a
     * dependency chain).
     */
    public function record_script_tag($tag, $handle)
    {
        $handle = (string) $handle;
        $this->receipt['printed']['script'][$handle] = true;
        $loading = 'blocking';
        if (preg_match_all('/<script\b([^>]*)>/i', (string) $tag, $matches)) {
            foreach ($matches[1] as $attributes) {
                if (!preg_match('/\ssrc\s*=/i', $attributes)) {
                    continue;
                }
                if (preg_match('/\s(defer|async)(?=[\s=\/]|$)/i', $attributes, $mode)) {
                    $loading = strtolower($mode[1]);
                }
                break;
            }
        }
        $this->receipt['scriptLoading'][$handle] = $loading;
        return $tag;
    }

    /** Images printed by wp_get_attachment_image() (featured images, widgets). */
    public function image_attributes($attr, $attachment, $size)
    {
        $attachment_id = is_object($attachment) && isset($attachment->ID)
            ? (int) $attachment->ID
            : 0;
        if ($attachment_id <= 0 || !is_array($attr)) {
            return $attr;
        }
        if ($this->audit_token) {
            $urls = array();
            if (!empty($attr['src'])) {
                $urls[] = (string) $attr['src'];
            }
            $this->record_audit_image($attachment_id, array_merge($urls, self::srcset_urls($attr['srcset'] ?? '')));
            return $attr;
        }
        $config = $this->image_config($attachment_id);
        if (!$config) {
            return $attr;
        }
        $attr['loading'] = $config['loading'];
        if ($config['fetchPriority'] === 'auto') {
            unset($attr['fetchpriority']);
        } else {
            $attr['fetchpriority'] = $config['fetchPriority'];
        }
        if ($this->preview) {
            $this->receipt['images'][$attachment_id] = true;
        }
        return $attr;
    }

    /**
     * Images of the post content and of block templates (core/image, cover…):
     * WordPress filters each <img> tag after adding its own loading attributes,
     * so the rule has the last word. Edited with the HTML tag processor, never
     * with a regular expression.
     */
    public function content_image_tag($image, $context, $attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || !is_string($image) || !class_exists('WP_HTML_Tag_Processor')) {
            return $image;
        }
        $processor = new WP_HTML_Tag_Processor($image);
        if (!$processor->next_tag('img')) {
            return $image;
        }
        if ($this->audit_token) {
            $src = $processor->get_attribute('src');
            $srcset = $processor->get_attribute('srcset');
            $this->record_audit_image($attachment_id, array_merge(
                is_string($src) && $src !== '' ? array($src) : array(),
                self::srcset_urls(is_string($srcset) ? $srcset : '')
            ));
            return $image;
        }
        $config = $this->image_config($attachment_id);
        if (!$config) {
            return $image;
        }
        $processor->set_attribute('loading', $config['loading']);
        if ($config['fetchPriority'] === 'auto') {
            $processor->remove_attribute('fetchpriority');
        } else {
            $processor->set_attribute('fetchpriority', $config['fetchPriority']);
        }
        if ($this->preview) {
            $this->receipt['images'][$attachment_id] = true;
        }
        return $processor->get_updated_html();
    }

    private function image_config($attachment_id)
    {
        $configs = isset($this->compiled['images'][(string) $attachment_id])
            ? (array) $this->compiled['images'][(string) $attachment_id]
            : array();
        foreach ($configs as $candidate) {
            if ($this->target_matches($candidate['target'] ?? array())) {
                return $candidate;
            }
        }
        return null;
    }

    /** Every URL an attachment may be fetched from on this page: src and srcset. */
    private function record_audit_image($attachment_id, array $urls)
    {
        $known = isset($this->audit_images[$attachment_id]) ? $this->audit_images[$attachment_id] : array();
        foreach ($urls as $url) {
            $known[(string) $url] = true;
        }
        $this->audit_images[$attachment_id] = array_slice($known, 0, 16, true);
    }

    private static function srcset_urls($srcset)
    {
        $urls = array();
        foreach (explode(',', (string) $srcset) as $candidate) {
            $url = strtok(trim($candidate), " \t");
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * Google Fonts stylesheets without `display=swap` hide the text until the
     * font arrives. The parameter is set by hand: add_query_arg() would merge
     * the repeated `family=` parameters of the CSS2 API.
     */
    public function style_source($src, $handle)
    {
        if ($this->audit_token || !is_string($src)) {
            return $src;
        }
        foreach ($this->matching_fonts() as $font) {
            if (!in_array((string) $handle, (array) ($font['params']['displaySwapHandles'] ?? array()), true)) {
                continue;
            }
            $swapped = self::google_fonts_swap($src);
            if ($swapped !== $src && $this->preview) {
                $this->receipt['swapped'][(string) $handle] = true;
            }
            return $swapped;
        }
        return $src;
    }

    public static function google_fonts_swap($src)
    {
        $host = wp_parse_url(strpos($src, '//') === 0 ? 'https:' . $src : $src, PHP_URL_HOST);
        if ($host !== 'fonts.googleapis.com') {
            return $src;
        }
        if (preg_match('/([?&])display=[^&#]*/', $src)) {
            return preg_replace('/([?&])display=[^&#]*/', '$1display=swap', $src, 1);
        }
        $fragment = '';
        $hash = strpos($src, '#');
        if ($hash !== false) {
            $fragment = substr($src, $hash);
            $src = substr($src, 0, $hash);
        }
        return $src . (strpos($src, '?') === false ? '?' : '&') . 'display=swap' . $fragment;
    }

    public function resource_hints($urls, $relation_type)
    {
        if ($this->audit_token || $relation_type !== 'preconnect') {
            return $urls;
        }
        foreach ($this->matching_fonts() as $font) {
            foreach ($font['params']['preconnectOrigins'] as $origin) {
                $urls[] = array('href' => $origin, 'crossorigin' => 'anonymous');
                if ($this->preview) {
                    $this->receipt['preconnects']++;
                }
            }
        }
        // A CORS fetch (fonts) cannot reuse an anonymous-less connection and
        // conversely: each origin carries the mode of its critical requests.
        foreach ((array) ($this->compiled['preconnects'] ?? array()) as $entry) {
            if (!$this->target_matches($entry['target'] ?? array())) {
                continue;
            }
            foreach ((array) ($entry['origins'] ?? array()) as $origin) {
                $hint = array('href' => $origin['origin']);
                if (!empty($origin['crossorigin'])) {
                    $hint['crossorigin'] = 'anonymous';
                }
                $urls[] = $hint;
                if ($this->preview) {
                    $this->receipt['preconnects']++;
                }
            }
        }
        return $urls;
    }

    public function font_head()
    {
        if ($this->audit_token) {
            return;
        }
        $preloads = array();
        $metrics = array();
        foreach ($this->matching_fonts() as $font) {
            foreach ($font['params']['preloadUrls'] as $url) {
                $preloads[$url] = true;
            }
            foreach ($font['params']['metricOverrides'] as $metric) {
                $metrics[] = $metric;
            }
        }
        foreach (array_keys($preloads) as $url) {
            echo '<link rel="preload" href="' . esc_url($url) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
        }
        if ($this->preview) {
            $this->receipt['preloads'] += count($preloads);
            $this->receipt['metrics'] += count($metrics);
        }
        if ($metrics) {
            echo '<style id="faaaster-font-metrics">';
            foreach ($metrics as $metric) {
                echo '@font-face{font-family:"' . esc_attr($metric['family']) . '";src:local("' . esc_attr($metric['fallback']) . '");size-adjust:' . (float) $metric['sizeAdjust'] . '%;ascent-override:' . (float) $metric['ascentOverride'] . '%;descent-override:' . (float) $metric['descentOverride'] . '%;line-gap-override:' . (float) $metric['lineGapOverride'] . '%;}';
            }
            echo '</style>' . "\n";
        }
    }

    public function capture_audit_snapshot()
    {
        if (!$this->audit_token || is_admin()) {
            return;
        }
        $key = self::audit_transient_key($this->audit_token);
        $session = get_transient($key);
        if (!is_array($session)) {
            return;
        }
        $assets = array_merge(
            $this->asset_snapshot('style', wp_styles()),
            $this->asset_snapshot('script', wp_scripts())
        );
        $images = array();
        foreach (array_slice($this->audit_images, 0, 100, true) as $attachment_id => $urls) {
            $urls = array_keys($urls);
            $images[] = array('attachmentId' => (int) $attachment_id, 'src' => $urls[0], 'urls' => $urls);
        }
        $snapshot = array(
            'path' => $this->request_path(),
            'contexts' => $this->current_contexts(),
            'assets' => array_slice($assets, 0, 200),
            'images' => $images,
            'capturedAt' => time(),
        );
        $snapshots = isset($session['snapshots']) && is_array($session['snapshots'])
            ? $session['snapshots']
            : array();
        $snapshots[$snapshot['path']] = $snapshot;
        $session['snapshots'] = array_slice($snapshots, -12, 12, true);
        set_transient($key, $session, self::AUDIT_TTL);
    }

    /**
     * One receipt per declared path (its own transient): the screenshots, the
     * HTTP probe and the functional flows hit different paths concurrently, so
     * a shared read-modify-write record would lose some of them.
     */
    public function capture_preview_receipt()
    {
        if (!$this->preview || is_admin()) {
            return;
        }
        $path = $this->request_path();
        if (!in_array($path, $this->preview['paths'], true)) {
            return;
        }
        $receipt = array(
            'path' => $path,
            'checksum' => $this->preview['checksum'],
            'contexts' => $this->current_contexts(),
            'dequeued' => array(
                'style' => self::bounded_keys($this->receipt['dequeued']['style']),
                'script' => self::bounded_keys($this->receipt['dequeued']['script']),
            ),
            'dequeuedSources' => self::bounded_keys($this->receipt['dequeuedSources']),
            'strategies' => array_slice($this->receipt['strategies'], 0, self::RECEIPT_LIST_MAX, true),
            'printed' => array(
                'style' => self::bounded_keys($this->receipt['printed']['style']),
                'script' => self::bounded_keys($this->receipt['printed']['script']),
            ),
            'late' => array('style' => self::bounded_keys($this->receipt['late'])),
            'promoted' => array('style' => self::bounded_keys($this->receipt['promoted'])),
            'scriptLoading' => array_slice($this->receipt['scriptLoading'], 0, self::RECEIPT_LIST_MAX, true),
            'images' => array_map('intval', self::bounded_keys($this->receipt['images'])),
            'fonts' => array(
                'preloads' => $this->receipt['preloads'],
                'metrics' => $this->receipt['metrics'],
                'swapped' => self::bounded_keys($this->receipt['swapped']),
            ),
            'preconnects' => $this->receipt['preconnects'],
            'capturedAt' => time(),
        );
        set_transient(self::preview_receipt_key($this->preview['token'], $path), $receipt, self::PREVIEW_TTL);
    }

    private static function bounded_keys(array $set)
    {
        return array_slice(array_map('strval', array_keys($set)), 0, self::RECEIPT_LIST_MAX);
    }

    public static function preview_transient_key($token)
    {
        return 'faaaster_perf_preview_' . substr(hash('sha256', (string) $token), 0, 40);
    }

    public static function preview_receipt_key($token, $path)
    {
        return self::preview_transient_key($token) . '_' . substr(hash('sha256', (string) $path), 0, 16);
    }

    public static function audit_transient_key($token)
    {
        return 'faaaster_perf_audit_' . substr(hash('sha256', (string) $token), 0, 40);
    }

    private function requested_audit_token()
    {
        $token = isset($_GET[self::AUDIT_PARAM]) ? (string) $_GET[self::AUDIT_PARAM] : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return is_array(get_transient(self::audit_transient_key($token))) ? $token : null;
    }

    private function asset_snapshot($type, $registry)
    {
        if (!is_object($registry) || !isset($registry->registered)) {
            return array();
        }
        $handles = array_unique(array_merge((array) $registry->queue, (array) $registry->done));
        $out = array();
        foreach ($handles as $handle) {
            if (!isset($registry->registered[$handle])) {
                continue;
            }
            $src = (string) $registry->registered[$handle]->src;
            if ($src === '') {
                continue;
            }
            $src = self::absolute_src($src);
            $asset = array('handle' => (string) $handle, 'type' => $type, 'src' => $src);
            if ($type === 'style') {
                $asset['late'] = isset($this->receipt['late'][(string) $handle]);
                $asset['inlineAfter'] = isset($this->receipt['inlineAfter'][(string) $handle]);
            }
            $out[] = $asset;
        }
        return $out;
    }

    private static function absolute_src($src)
    {
        if (strpos($src, '//') === 0) {
            return 'https:' . $src;
        }
        if (!preg_match('#^https?://#i', $src)) {
            return site_url('/' . ltrim($src, '/'));
        }
        return $src;
    }

    private function has_enqueued_dependents($type, $handle)
    {
        $registry = $type === 'style' ? wp_styles() : wp_scripts();
        if (!is_object($registry) || !isset($registry->registered)) {
            return true;
        }
        foreach ((array) $registry->queue as $queued) {
            if ($queued === $handle || !isset($registry->registered[$queued])) {
                continue;
            }
            if (in_array($handle, (array) $registry->registered[$queued]->deps, true)) {
                return true;
            }
        }
        return false;
    }

    private function targets_match($targets)
    {
        foreach ((array) $targets as $target) {
            if ($this->target_matches($target)) {
                return true;
            }
        }
        return false;
    }

    private function target_matches($target)
    {
        $paths = isset($target['paths']) ? (array) $target['paths'] : array();
        $contexts = isset($target['contexts']) ? (array) $target['contexts'] : array();
        if ($paths && !in_array($this->request_path(), $paths, true)) {
            return false;
        }
        if (!$contexts) {
            return true;
        }
        foreach ($contexts as $context) {
            if ($this->context_matches($context)) {
                return true;
            }
        }
        return false;
    }

    private function context_matches($context)
    {
        if ($context === 'all') return true;
        if ($context === 'home') return is_home();
        if ($context === 'front_page') return is_front_page();
        if ($context === 'singular') return is_singular();
        if ($context === 'archive') return is_archive();
        if ($context === 'search') return is_search();
        if ($context === '404') return is_404();
        if (strpos($context, 'post_type:') === 0) return is_singular(substr($context, 10));
        if (strpos($context, 'taxonomy:') === 0) return is_tax(substr($context, 9));
        if (strpos($context, 'page:') === 0) return is_page((int) substr($context, 5));
        return false;
    }

    private function current_contexts()
    {
        $contexts = array();
        foreach (array('home', 'front_page', 'singular', 'archive', 'search', '404') as $context) {
            if ($this->context_matches($context)) {
                $contexts[] = $context;
            }
        }
        $post_type = get_post_type();
        if ($post_type && is_singular($post_type)) {
            $contexts[] = 'post_type:' . sanitize_key($post_type);
        }
        $object = get_queried_object();
        if (is_object($object) && !empty($object->taxonomy)) {
            $contexts[] = 'taxonomy:' . sanitize_key($object->taxonomy);
        }
        if (is_page()) {
            $contexts[] = 'page:' . (int) get_queried_object_id();
        }
        return array_values(array_unique($contexts));
    }

    private function request_path()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function matching_fonts()
    {
        $out = array();
        foreach ((array) ($this->compiled['fonts'] ?? array()) as $font) {
            if ($this->target_matches($font['target'] ?? array())) {
                $out[] = $font;
            }
        }
        return $out;
    }
}
