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
    /** Next validates a rule or a set over several requests, by batches of
     *  pages then lab runs: it asks for the lifetime its pages need, within
     *  this bound (a set over 60 pages, each measured, takes hours) */
    const MAX_SESSION_TTL = 21600;
    /** pages a preview or an audit covers: one per template of the site */
    const MAX_SESSION_PATHS = 60;
    /** An audit spans several control-plane ticks (one page per tick). */
    const AUDIT_TTL = 1800;
    const RECEIPT_LIST_MAX = 300;
    const RECEIPT_DROPPED_MAX = 20;
    /** set by the HTML API on the <head> preloads to remove, then removed with the tag */
    const DROP_MARKER = 'data-faaaster-drop-preload';

    private $settings;
    private $compiled;
    private $audit_token = null;
    private $audit_images = array();
    private $preview = null;
    private $head_printed = false;
    /** content conditions already evaluated in this request: kind:value => bool */
    private $predicates = array();
    /** `when` atoms evaluated in this request: canonical JSON => bool or error */
    private $atoms = array();
    /** conditional tags called in this request: name => bool or error */
    private $conditionals = array();
    /** image_priority: images counted per rule (index of the entry) and decisions by source */
    private $priority_counts = array();
    private $priority_seen = array();
    private $priority_applied = array();
    /** preload_image URLs printed by the plugin: drop_preloads never removes them */
    private $own_preloads = array();
    /** drop_preloads: the <head> buffer open (level, what to drop), or null */
    private $head_buffer = null;
    /** defer_backgrounds: selectors of the request, decided once for head and footer */
    private $deferred = null;
    /** the request's singular content (`post`) and its component sets, read once */
    private $content = array();
    /** product id => rank of its card in the request's product loops */
    private $loop_ranks = array();
    private $in_widget_product = false;
    private $gallery = null;
    /** font_variants handles whose stale copy was already logged in this request */
    private $font_warnings = array();
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
        'skipped' => array(),
        'adapters' => array('wcLoopImages' => 0, 'wcGallery' => 0, 'menuImagesLazy' => 0),
        'preloadedImages' => array(),
        'fontsFiltered' => array(),
        'conditions' => array(),
        'conditionErrors' => array(),
        'earlyLayout' => 0,
        'droppedPreloads' => array(),
        'imagePriority' => array(),
        'deferredBackgrounds' => array(),
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
            $this->register_adapters();
        }
    }

    /** Component adapters hook in only when a rule of theirs is compiled. */
    private function register_adapters()
    {
        if (!empty($this->compiled['preloadImages'])) {
            add_action('wp_head', array($this, 'preload_images'), 1);
        }
        if (!empty($this->compiled['menuImagesLazy'])) {
            add_filter('wp_nav_menu', array($this, 'menu_images'), 9999, 2);
            add_filter('render_block', array($this, 'navigation_block_images'), 9999, 2);
        }
        if (!empty($this->compiled['wcLoopImages'])) {
            add_action('woocommerce_widget_product_item_start', array($this, 'widget_product_start'));
            add_action('woocommerce_widget_product_item_end', array($this, 'widget_product_end'));
        }
        if (!empty($this->compiled['fontVariants'])) {
            // head styles, then the late ones printed at wp_footer
            add_action('wp_print_styles', array($this, 'filter_inline_fonts'), PHP_INT_MAX);
            add_action('wp_print_footer_scripts', array($this, 'filter_inline_fonts'), 1);
        }
        if (!empty($this->compiled['earlyLayout'])) {
            add_action('wp_head', array($this, 'early_layout'), 100);
        }
        if (!empty($this->compiled['dropPreloads'])) {
            add_action('wp_head', array($this, 'begin_head_buffer'), PHP_INT_MIN);
            add_action('wp_head', array($this, 'end_head_buffer'), PHP_INT_MAX);
        }
        if (!empty($this->compiled['deferBackgrounds'])) {
            add_action('wp_head', array($this, 'defer_backgrounds_head'), 1);
            add_action('wp_footer', array($this, 'defer_backgrounds_footer'), 100);
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
            'ttl' => self::session_ttl($session['ttl'] ?? null, self::PREVIEW_TTL),
        );
        $this->start_recording();
        add_filter('script_loader_tag', array($this, 'record_script_tag'), PHP_INT_MAX, 2);
        add_action('shutdown', array($this, 'capture_preview_receipt'), PHP_INT_MAX);
        $this->seed_randomness();
    }

    /**
     * Every rendering of a page under test draws the same « random » content:
     * the related products WooCommerce shuffles in PHP, the `orderby=rand`
     * queries. The page without the rule, the page with it and the page again
     * then differ only by the rule (18/10: a title of two lines among random
     * related products moved product pages by 23 px and refused rules that
     * changed nothing). The seed is the page's path, not the token: the
     * reference and the candidate get the same draws. Only preview requests
     * are seeded; wp_rand(), nonces and passwords use the system's generator.
     */
    private function seed_randomness()
    {
        mt_srand(self::preview_seed($this->request_path()));
        add_filter('posts_orderby', array($this, 'seeded_random_order'), PHP_INT_MAX);
    }

    public static function preview_seed($path)
    {
        return (int) sprintf('%u', crc32((string) $path)) & 0x7fffffff;
    }

    /** `ORDER BY RAND()` of a preview query, drawn with the page's seed. */
    public function seeded_random_order($orderby)
    {
        if (!is_string($orderby) || stripos($orderby, 'RAND(') === false) {
            return $orderby;
        }
        return preg_replace(
            '/\bRAND\(\s*\)/i',
            'RAND(' . self::preview_seed($this->request_path()) . ')',
            $orderby
        );
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
            $candidates = array();
            foreach ($rules as $handle => $targets) {
                if ($this->targets_match($targets)) {
                    $candidates[(string) $handle] = true;
                }
            }
            if (!$candidates) {
                continue;
            }
            $registry = $type === 'style' ? wp_styles() : wp_scripts();
            foreach ($this->removable_handles($registry, $candidates) as $handle) {
                if ($type === 'style') {
                    wp_dequeue_style($handle);
                } else {
                    wp_dequeue_script($handle);
                }
                if ($this->preview) {
                    $this->receipt['dequeued'][$type][$handle] = true;
                    $src = isset($registry->registered[$handle]) ? (string) $registry->registered[$handle]->src : '';
                    if ($src !== '') {
                        $this->receipt['dequeuedSources'][self::absolute_src($src)] = true;
                    }
                }
            }
        }
    }

    /**
     * The handles of the request's unloads that can go. A handle leaves once
     * every enqueued handle needing it, directly or through other
     * dependencies, leaves too: dependents first, to a fixpoint. An enqueued
     * dependent outside the unloads keeps it, WordPress would print it again.
     */
    private function removable_handles($registry, array $candidates)
    {
        if (!is_object($registry) || !isset($registry->registered)) {
            return array();
        }
        // candidate => the enqueued handles needing it
        $dependents = array();
        foreach ((array) $registry->queue as $queued) {
            $queued = (string) $queued;
            foreach (self::dependency_closure($registry->registered, $queued) as $dependency => $unused) {
                $dependency = (string) $dependency;
                if ($dependency !== $queued && isset($candidates[$dependency])) {
                    $dependents[$dependency][] = $queued;
                }
            }
        }
        $removed = array();
        do {
            $changed = false;
            foreach (array_map('strval', array_keys($candidates)) as $handle) {
                if (isset($removed[$handle])) {
                    continue;
                }
                foreach ($dependents[$handle] ?? array() as $queued) {
                    if (!isset($removed[$queued])) {
                        continue 2;
                    }
                }
                $removed[$handle] = true;
                $changed = true;
            }
        } while ($changed);
        return array_map('strval', array_keys($removed));
    }

    /** Every handle a handle needs, transitively: handle => true. */
    private static function dependency_closure(array $registered, $handle)
    {
        $closure = array();
        $stack = array($handle);
        while ($stack) {
            $current = array_pop($stack);
            if (!isset($registered[$current])) {
                continue;
            }
            foreach ((array) $registered[$current]->deps as $dependency) {
                $dependency = (string) $dependency;
                if (!isset($closure[$dependency])) {
                    $closure[$dependency] = true;
                    $stack[] = $dependency;
                }
            }
        }
        return $closure;
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
        // counted first: the rank of an image never depends on the rule that sets it
        $priority = $this->priority_decision($attr['class'] ?? '', $attr['src'] ?? '');
        $config = $this->image_config($attachment_id);
        if (!$config) {
            // a rule on the attachment itself has the last word over adapters
            $adapted = $this->gallery_image_attributes($attr, $attachment_id);
            if ($adapted === null) {
                $adapted = $this->loop_image_attributes($attr, $attachment_id);
            }
            if ($adapted !== null) {
                return $adapted;
            }
            return $priority ? $this->priority_image_attributes($attr, $priority) : $attr;
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
     * Product cards: the featured image of the product a loop prints, ranked
     * by first appearance in the request. The product page's own image and
     * the product lists of widgets are no cards.
     */
    private function loop_image_attributes(array $attr, $attachment_id)
    {
        if (empty($this->compiled['wcLoopImages']) || $this->in_widget_product) {
            return null;
        }
        $product = isset($GLOBALS['product']) ? $GLOBALS['product'] : null;
        if (!is_object($product) || !method_exists($product, 'get_image_id') || !method_exists($product, 'get_id')) {
            return null;
        }
        $product_id = (int) $product->get_id();
        if ((int) $product->get_image_id() !== $attachment_id || (is_singular() && $product_id === (int) get_queried_object_id())) {
            return null;
        }
        $config = $this->first_matching($this->compiled['wcLoopImages']);
        if (!$config) {
            return null;
        }
        if (!isset($this->loop_ranks[$product_id])) {
            $this->loop_ranks[$product_id] = count($this->loop_ranks);
        }
        $rank = $this->loop_ranks[$product_id];
        $attr['loading'] = $rank < (int) $config['eager'] ? 'eager' : 'lazy';
        if ($rank < (int) $config['highPriority']) {
            $attr['fetchpriority'] = 'high';
        } elseif (($attr['fetchpriority'] ?? '') === 'high') {
            unset($attr['fetchpriority']);
        }
        if ($this->preview) {
            $this->receipt['adapters']['wcLoopImages']++;
        }
        return $attr;
    }

    /** Product page: its main image first, its gallery images deferred. */
    private function gallery_image_attributes(array $attr, $attachment_id)
    {
        if (empty($this->compiled['wcGallery'])) {
            return null;
        }
        $gallery = $this->product_gallery();
        if (!$gallery || ($attachment_id !== $gallery['main'] && !isset($gallery['images'][$attachment_id]))) {
            return null;
        }
        $config = $this->first_matching($this->compiled['wcGallery']);
        if (!$config) {
            return null;
        }
        if ($attachment_id === $gallery['main']) {
            if (empty($config['mainHighPriority'])) {
                return null;
            }
            $attr['loading'] = 'eager';
            $attr['fetchpriority'] = 'high';
        } else {
            if (empty($config['thumbnailsLazy'])) {
                return null;
            }
            $attr['loading'] = 'lazy';
            if (($attr['fetchpriority'] ?? '') === 'high') {
                unset($attr['fetchpriority']);
            }
        }
        if ($this->preview) {
            $this->receipt['adapters']['wcGallery']++;
        }
        return $attr;
    }

    /** The displayed product's main and gallery image ids, read once. */
    private function product_gallery()
    {
        if ($this->gallery !== null || !$this->query_ready()) {
            return $this->gallery ?: null;
        }
        $this->gallery = false;
        $is_product = function_exists('is_product') ? is_product() : is_singular('product');
        if (!$is_product || !function_exists('wc_get_product')) {
            return null;
        }
        $product = wc_get_product(get_queried_object_id());
        if (!is_object($product) || !method_exists($product, 'get_image_id') || !method_exists($product, 'get_gallery_image_ids')) {
            return null;
        }
        $images = array();
        foreach ((array) $product->get_gallery_image_ids() as $image_id) {
            if ((int) $image_id > 0) {
                $images[(int) $image_id] = true;
            }
        }
        $this->gallery = array('main' => (int) $product->get_image_id(), 'images' => $images);
        return $this->gallery;
    }

    public function widget_product_start()
    {
        $this->in_widget_product = true;
    }

    public function widget_product_end()
    {
        $this->in_widget_product = false;
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
        if (!is_string($image) || !class_exists('WP_HTML_Tag_Processor')) {
            return $image;
        }
        // an image without attachment only matters to image_priority (classes)
        if ($attachment_id <= 0 && ($this->audit_token || empty($this->compiled['imagePriority']))) {
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
        $class = $processor->get_attribute('class');
        $src = $processor->get_attribute('src');
        $priority = $this->priority_decision(is_string($class) ? $class : '', is_string($src) ? $src : '');
        $config = $attachment_id > 0 ? $this->image_config($attachment_id) : null;
        if (!$config) {
            if (!$priority) {
                return $image;
            }
            $loading = $processor->get_attribute('loading');
            $sizes = $processor->get_attribute('sizes');
            $values = $this->priority_values($priority, is_string($loading) ? $loading : null, is_string($sizes) ? $sizes : null);
            foreach ($values as $name => $value) {
                if ($value === null) {
                    $processor->remove_attribute($name);
                } else {
                    $processor->set_attribute($name, $value);
                }
            }
            $this->count_priority($priority, is_string($src) ? $src : '');
            return $processor->get_updated_html();
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

    /**
     * image_priority: every rule of the request whose signature the image
     * bears counts it (its rank among them, in the order of the page); the
     * first one decides. An image met again (a featured image printed, then
     * its block template filtered) keeps its first decision, never counted
     * twice. null: no rule for this image.
     */
    private function priority_decision($class, $src)
    {
        $entries = (array) ($this->compiled['imagePriority'] ?? array());
        if (!$entries || !is_string($class) || trim($class) === '') {
            return null;
        }
        $src = is_string($src) ? $src : '';
        if ($src !== '' && array_key_exists($src, $this->priority_seen)) {
            return $this->priority_seen[$src];
        }
        $classes = array_flip(preg_split('/\s+/', trim($class)));
        $decision = null;
        foreach ($entries as $index => $entry) {
            foreach ((array) ($entry['signature'] ?? array()) as $wanted) {
                if (!isset($classes[$wanted])) {
                    continue 2;
                }
            }
            if (!$this->target_matches($entry['target'] ?? array())) {
                continue;
            }
            $rank = $this->priority_counts[$index] ?? 0;
            $this->priority_counts[$index] = $rank + 1;
            if ($decision === null) {
                $decision = array('entry' => $entry, 'index' => $rank);
            }
        }
        if ($src !== '') {
            $this->priority_seen[$src] = $decision;
        }
        return $decision;
    }

    /**
     * Attributes an image_priority decision sets (null: removed): the rank-th
     * image at once with priority, the ones before `eager` at once, `sizes`
     * on all, `auto, ` kept first while the image stays lazy (`auto` is only
     * valid there, WordPress may have added it before the rule).
     */
    private function priority_values(array $decision, $loading, $sizes)
    {
        $entry = $decision['entry'];
        $values = array();
        if ($decision['index'] === (int) $entry['rank']) {
            $values['loading'] = 'eager';
            $values['fetchpriority'] = 'high';
        } elseif ($decision['index'] < (int) $entry['eager']) {
            $values['loading'] = 'eager';
        }
        $lazy = strtolower((string) ($values['loading'] ?? $loading)) === 'lazy';
        $strip = function ($value) {
            return (string) preg_replace('/^\s*auto\s*(?:,\s*|$)/i', '', (string) $value);
        };
        if (isset($entry['sizes'])) {
            $base = $strip($entry['sizes']);
            $values['sizes'] = $base === '' ? ($lazy ? 'auto' : null) : ($lazy ? 'auto, ' : '') . $base;
        } elseif (!$lazy && is_string($sizes) && $strip($sizes) !== $sizes) {
            $values['sizes'] = $strip($sizes) !== '' ? $strip($sizes) : null;
        }
        return $values;
    }

    private function priority_image_attributes(array $attr, array $decision)
    {
        $values = $this->priority_values(
            $decision,
            isset($attr['loading']) ? (string) $attr['loading'] : null,
            isset($attr['sizes']) ? (string) $attr['sizes'] : null
        );
        foreach ($values as $name => $value) {
            if ($value === null) {
                unset($attr[$name]);
            } else {
                $attr[$name] = $value;
            }
        }
        $this->count_priority($decision, isset($attr['src']) ? (string) $attr['src'] : '');
        return $attr;
    }

    /** Receipt: images set per image_priority rule, each image once. */
    private function count_priority(array $decision, $src)
    {
        if (!$this->preview || ($src !== '' && isset($this->priority_applied[$src]))) {
            return;
        }
        if ($src !== '') {
            $this->priority_applied[$src] = true;
        }
        $rule = (string) ($decision['entry']['target']['rule'] ?? '');
        if ($rule !== '') {
            $this->receipt['imagePriority'][$rule] = ($this->receipt['imagePriority'][$rule] ?? 0) + 1;
        }
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
        if (isset($this->compiled['fontVariants'][(string) $handle])) {
            $src = $this->font_variants_source($src, (string) $handle);
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

    /**
     * A font stylesheet served with its kept faces only: the copy the REST
     * handler wrote for this stylesheet, while the source file still has the
     * mtime it was made from (one stat, PHP's stat cache), and the handle's
     * inline CSS (filtered here too: on the front end WordPress reads it again
     * after this filter to print it). Nothing is read or written here.
     */
    private function font_variants_source($src, $handle)
    {
        $entry = $this->first_matching((array) ($this->compiled['fontVariants'][$handle] ?? array()));
        if (!$entry) {
            return $src;
        }
        $this->filter_inline_font_faces($handle, (array) ($entry['keep'] ?? array()));
        if (empty($entry['copy']) || empty($entry['file'])) {
            return $src;
        }
        if (self::same_stylesheet($src, (string) ($entry['src'] ?? '')) && @filemtime($entry['file']) === (int) ($entry['mtime'] ?? -1)) {
            if ($this->preview) {
                $this->receipt['fontsFiltered'][$handle] = true;
            }
            return (string) $entry['copy'];
        }
        if (!isset($this->font_warnings[$handle])) {
            $this->font_warnings[$handle] = true;
            error_log('[faaaster-performance] font_variants: the copy of ' . $handle . ' is stale (source changed), the original is served');
        }
        return $src;
    }

    /** The same stylesheet, whatever its scheme, query or fragment. */
    private static function same_stylesheet($src, $rule_src)
    {
        $strip = function ($url) {
            return preg_replace(array('~^(https?:)?//~i', '/[?#].*$/s'), '', (string) $url);
        };
        return $rule_src !== '' && $strip($src) === $strip($rule_src);
    }

    /** Inline CSS of the font variant handles, before WordPress prints it. */
    public function filter_inline_fonts()
    {
        if ($this->audit_token) {
            return;
        }
        foreach (array_keys((array) ($this->compiled['fontVariants'] ?? array())) as $handle) {
            $keep = $this->font_variants_keep((string) $handle);
            if ($keep !== null) {
                $this->filter_inline_font_faces((string) $handle, $keep);
            }
        }
    }

    private function filter_inline_font_faces($handle, array $keep)
    {
        $registry = wp_styles();
        if (!is_object($registry) || empty($registry->registered[$handle]->extra['after'])) {
            return;
        }
        $after = (array) $registry->registered[$handle]->extra['after'];
        $removed = 0;
        foreach ($after as $index => $css) {
            $filtered = FaaasterPerformanceFontFaces::filter($css, $keep);
            if ($filtered !== null && $filtered['removed'] > 0) {
                $after[$index] = $filtered['css'];
                $removed += $filtered['removed'];
            }
        }
        if (!$removed) {
            return;
        }
        $registry->registered[$handle]->extra['after'] = $after;
        if ($this->preview) {
            $this->receipt['fontsFiltered'][$handle] = true;
        }
    }

    /** The kept faces of the first font_variants rule on the handle here. */
    private function font_variants_keep($handle)
    {
        $entry = $this->first_matching((array) ($this->compiled['fontVariants'][$handle] ?? array()));
        return $entry ? (array) $entry['keep'] : null;
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

    /**
     * Images the page paints first but declares late (a CSS background, a
     * builder's section), announced at the top of the <head>.
     */
    public function preload_images()
    {
        if ($this->audit_token) {
            return;
        }
        $printed = array();
        foreach ((array) ($this->compiled['preloadImages'] ?? array()) as $entry) {
            $url = (string) ($entry['url'] ?? '');
            if ($url === '' || isset($printed[$url]) || !$this->target_matches($entry['target'] ?? array())) {
                continue;
            }
            $printed[$url] = true;
            $this->own_preloads[$url] = true;
            $tag = '<link rel="preload" as="image" href="' . esc_url($url) . '"';
            if (!empty($entry['srcset'])) {
                $tag .= ' imagesrcset="' . esc_attr($entry['srcset']) . '"';
            }
            if (!empty($entry['sizes'])) {
                $tag .= ' imagesizes="' . esc_attr($entry['sizes']) . '"';
            }
            echo $tag . ' fetchpriority="high">' . "\n";
            if ($this->preview) {
                $this->receipt['preloadedImages'][$url] = true;
            }
        }
    }

    /**
     * The `.wp-container-*` layout rules WordPress prints with the late
     * core-block-supports styles, printed in the <head> instead (the layout
     * is right at first paint, no shift): each selector once, the first
     * matching rule's declarations.
     */
    public function early_layout()
    {
        if ($this->audit_token) {
            return;
        }
        $css = array();
        foreach ((array) ($this->compiled['earlyLayout'] ?? array()) as $entry) {
            if (!$this->target_matches($entry['target'] ?? array())) {
                continue;
            }
            foreach ((array) ($entry['rules'] ?? array()) as $rule) {
                $selector = (string) ($rule['selector'] ?? '');
                $rule_css = $selector . '{' . (string) ($rule['declarations'] ?? '') . '}';
                if ($selector !== '' && !isset($css[$selector]) && strpbrk($rule_css, '<>') === false) {
                    $css[$selector] = $rule_css;
                }
            }
        }
        if (!$css) {
            return;
        }
        echo '<style id="faaaster-early-layout">' . implode('', $css) . '</style>' . "\n";
        if ($this->preview) {
            $this->receipt['earlyLayout'] += count($css);
        }
    }

    /**
     * drop_preloads: the output of wp_head is buffered from the first
     * priority to the last, then its image preloads from another host, or
     * without high priority, are removed (never the plugin's own).
     */
    public function begin_head_buffer()
    {
        if ($this->audit_token || $this->head_buffer !== null) {
            return;
        }
        $drop = array('crossOrigin' => false, 'withoutHighPriority' => false);
        foreach ((array) ($this->compiled['dropPreloads'] ?? array()) as $entry) {
            if ($this->target_matches($entry['target'] ?? array())) {
                $drop['crossOrigin'] = $drop['crossOrigin'] || !empty($entry['crossOrigin']);
                $drop['withoutHighPriority'] = $drop['withoutHighPriority'] || !empty($entry['withoutHighPriority']);
            }
        }
        if ((!$drop['crossOrigin'] && !$drop['withoutHighPriority']) || !class_exists('WP_HTML_Tag_Processor') || !ob_start()) {
            return;
        }
        $this->head_buffer = array('level' => ob_get_level()) + $drop;
    }

    public function end_head_buffer()
    {
        if ($this->head_buffer === null) {
            return;
        }
        $buffer = $this->head_buffer;
        $this->head_buffer = null;
        // a buffer left open or ours closed meanwhile: the output stays as printed
        if (ob_get_level() !== $buffer['level']) {
            error_log('[faaaster-performance] drop_preloads: output buffering changed during wp_head, nothing dropped');
            return;
        }
        $html = ob_get_clean();
        if (is_string($html)) {
            echo $this->drop_preloads($html, $buffer);
        }
    }

    /** The <head> without the preloads to drop; untouched on any doubt. */
    private function drop_preloads($html, array $drop)
    {
        if (stripos($html, 'preload') === false) {
            return $html;
        }
        try {
            $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            $processor = new WP_HTML_Tag_Processor($html);
            $dropped = array();
            while ($processor->next_tag('link')) {
                $rel = $processor->get_attribute('rel');
                $as = $processor->get_attribute('as');
                if (
                    !is_string($rel) || !is_string($as) || strtolower(trim($as)) !== 'image'
                    || !in_array('preload', preg_split('/\s+/', strtolower(trim($rel))), true)
                ) {
                    continue;
                }
                $href = $processor->get_attribute('href');
                $href = is_string($href) ? trim($href) : '';
                if ($href !== '' && isset($this->own_preloads[$href])) {
                    continue;
                }
                $remove = false;
                if ($drop['crossOrigin'] && $href !== '') {
                    $host = wp_parse_url(strpos($href, '//') === 0 ? 'https:' . $href : $href, PHP_URL_HOST);
                    $remove = is_string($host) && $host !== '' && strtolower($host) !== $home;
                }
                if (!$remove && $drop['withoutHighPriority']) {
                    $priority = $processor->get_attribute('fetchpriority');
                    $remove = !is_string($priority) || strtolower(trim($priority)) !== 'high';
                }
                if ($remove) {
                    $processor->set_attribute(self::DROP_MARKER, '1');
                    $srcset = $processor->get_attribute('imagesrcset');
                    $dropped[] = $href !== '' ? $href : (is_string($srcset) ? $srcset : '');
                }
            }
            if (!$dropped) {
                return $html;
            }
            $quoted = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';
            $out = preg_replace('~<link\b' . $quoted . '?\s' . self::DROP_MARKER . '="1"' . $quoted . '>\n?~i', '', $processor->get_updated_html(), -1, $count);
            if (!is_string($out) || $count !== count($dropped)) {
                error_log('[faaaster-performance] drop_preloads: the marked preloads could not be removed, nothing dropped');
                return $html;
            }
        } catch (Throwable $error) {
            error_log('[faaaster-performance] drop_preloads: ' . $error->getMessage() . ', nothing dropped');
            return $html;
        }
        if ($this->preview) {
            foreach ($dropped as $href) {
                $this->receipt['droppedPreloads'][$href] = true;
            }
        }
        return $out;
    }

    /**
     * defer_backgrounds: with JavaScript, the backgrounds of the selected
     * elements are hidden until the element nears the viewport (or at once
     * without IntersectionObserver); without JavaScript nothing is hidden.
     */
    public function defer_backgrounds_head()
    {
        $selectors = $this->deferred_selectors();
        if (!$selectors) {
            return;
        }
        $css = '';
        foreach ($selectors as $selector) {
            $css .= '.fstr-js ' . $selector . ':not(.fstr-bg-in){background-image:none!important}';
        }
        echo '<script id="faaaster-bg-js">document.documentElement.classList.add(\'fstr-js\')</script>' . "\n"
            . '<style id="faaaster-defer-backgrounds">' . $css . '</style>' . "\n";
        if ($this->preview) {
            foreach ($selectors as $selector) {
                $this->receipt['deferredBackgrounds'][$selector] = true;
            }
        }
    }

    /** The observer, over the elements the head hid; scanned again at load (late markup). */
    public function defer_backgrounds_footer()
    {
        $selectors = $this->deferred_selectors();
        if (!$selectors) {
            return;
        }
        $list = json_encode($selectors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
        echo '<script id="faaaster-bg-observer">(function(){var d=document,w=window,s=' . $list . ',o=null;'
            . 'function show(e){e.classList.add("fstr-bg-in")}'
            . 'if("IntersectionObserver" in w){o=new IntersectionObserver(function(r){r.forEach(function(x){if(x.isIntersecting){show(x.target);o.unobserve(x.target)}})},{rootMargin:"300px 0px"})}'
            . 'function scan(){for(var i=0;i<s.length;i++){var n;try{n=d.querySelectorAll(s[i])}catch(x){continue}'
            . 'for(var j=0;j<n.length;j++){if(o){o.observe(n[j])}else{show(n[j])}}}}'
            . 'scan();w.addEventListener("load",scan)})();</script>' . "\n";
    }

    /** Selectors of the matching rules, decided once per request. */
    private function deferred_selectors()
    {
        if ($this->audit_token) {
            return array();
        }
        if ($this->deferred === null) {
            $this->deferred = array();
            foreach ((array) ($this->compiled['deferBackgrounds'] ?? array()) as $entry) {
                if (!$this->target_matches($entry['target'] ?? array())) {
                    continue;
                }
                foreach ((array) ($entry['items'] ?? array()) as $item) {
                    $selector = (string) ($item['selector'] ?? '');
                    if (preg_match(FaaasterPerformanceSettings::BACKGROUND_SELECTOR, $selector)) {
                        $this->deferred[$selector] = true;
                    }
                }
            }
        }
        return array_map('strval', array_keys($this->deferred));
    }

    /** Menus printed by wp_nav_menu(): their images deferred. */
    public function menu_images($nav_menu, $args = null)
    {
        if (!is_string($nav_menu) || stripos($nav_menu, '<img') === false || !$this->targets_match($this->compiled['menuImagesLazy'] ?? array())) {
            return $nav_menu;
        }
        return $this->lazy_images($nav_menu);
    }

    /** Navigation blocks (core/navigation and its items): same. */
    public function navigation_block_images($content, $block)
    {
        if (
            !is_string($content)
            || !is_array($block)
            || strpos((string) ($block['blockName'] ?? ''), 'core/navigation') !== 0
            || stripos($content, '<img') === false
            || !$this->targets_match($this->compiled['menuImagesLazy'] ?? array())
        ) {
            return $content;
        }
        return $this->lazy_images($content);
    }

    /** `loading="lazy" decoding="async"` on the images without a loading mode. */
    private function lazy_images($html)
    {
        if (!class_exists('WP_HTML_Tag_Processor')) {
            return $html;
        }
        $processor = new WP_HTML_Tag_Processor($html);
        $count = 0;
        while ($processor->next_tag('img')) {
            if ($processor->get_attribute('loading') !== null) {
                continue;
            }
            $processor->set_attribute('loading', 'lazy');
            if ($processor->get_attribute('decoding') === null) {
                $processor->set_attribute('decoding', 'async');
            }
            $count++;
        }
        if (!$count) {
            return $html;
        }
        if ($this->preview) {
            $this->receipt['adapters']['menuImagesLazy'] += $count;
        }
        return $processor->get_updated_html();
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
            'scripts' => $this->script_graph(),
            'images' => $images,
            'components' => $this->page_components(),
            'conditionals' => $this->audit_conditionals(),
            'capturedAt' => time(),
        );
        $snapshots = isset($session['snapshots']) && is_array($session['snapshots'])
            ? $session['snapshots']
            : array();
        $snapshots[$snapshot['path']] = $snapshot;
        $session['snapshots'] = array_slice($snapshots, -self::MAX_SESSION_PATHS, self::MAX_SESSION_PATHS, true);
        set_transient($key, $session, self::session_ttl($session['ttl'] ?? null, self::AUDIT_TTL));
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
        $this->record_conditions();
        ksort($this->receipt['conditions'], SORT_STRING);
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
            'skipped' => self::bounded_keys($this->receipt['skipped']),
            'adapters' => $this->receipt['adapters'],
            'preloadedImages' => self::bounded_keys($this->receipt['preloadedImages']),
            'fontsFiltered' => self::bounded_keys($this->receipt['fontsFiltered']),
            // rule id => applies here, for every rule whose contexts and paths match
            'conditions' => $this->receipt['conditions'] ? $this->receipt['conditions'] : new stdClass(),
            'conditionErrors' => self::bounded_keys($this->receipt['conditionErrors']),
            'earlyLayout' => $this->receipt['earlyLayout'],
            'droppedPreloads' => array_slice(self::bounded_keys($this->receipt['droppedPreloads']), 0, self::RECEIPT_DROPPED_MAX),
            'imagePriority' => $this->receipt['imagePriority'] ? $this->receipt['imagePriority'] : new stdClass(),
            'deferredBackgrounds' => self::bounded_keys($this->receipt['deferredBackgrounds']),
            'capturedAt' => time(),
        );
        set_transient(self::preview_receipt_key($this->preview['token'], $path), $receipt, $this->preview['ttl']);
    }

    /**
     * The final verdict (`unless` and `when`) of every rule of the set whose
     * contexts and paths match the page, whether a hook reached it or not:
     * Next compares it with its own simulation.
     */
    private function record_conditions()
    {
        if (!$this->query_ready()) {
            return;
        }
        foreach (self::compiled_targets($this->compiled) as $rule => $target) {
            if ($this->scope_matches($target)) {
                $this->receipt['conditions'][$rule] = $this->conditions_hold($target);
            }
        }
    }

    /** rule id => its target, found anywhere in the compiled tables. */
    private static function compiled_targets(array $compiled)
    {
        $targets = array();
        $stack = array($compiled);
        while ($stack) {
            $node = array_pop($stack);
            if (isset($node['rule'], $node['contexts']) && is_string($node['rule']) && is_array($node['contexts'])) {
                if (!isset($targets[$node['rule']])) {
                    $targets[$node['rule']] = $node;
                }
                continue;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }
        return $targets;
    }

    /** Each discovered conditional tag on the audited page; errors left out. */
    private function audit_conditionals()
    {
        $values = array();
        foreach (array_keys(FaaasterPerformanceConditionals::allowed()) as $name) {
            try {
                $values[(string) $name] = $this->conditional_value((string) $name);
            } catch (FaaasterPerformanceConditionError $error) {
                continue;
            }
        }
        return $values ? $values : new stdClass();
    }

    /**
     * Components of the displayed singular content: blocks (parse_blocks,
     * inner blocks included), registered shortcodes, Elementor widgets.
     */
    private function page_components()
    {
        $post = $this->content_post();
        if (!$post) {
            return array('blocks' => array(), 'shortcodes' => array(), 'elementorWidgets' => array());
        }
        return array(
            'blocks' => FaaasterPerformanceComponents::parsed_blocks((string) $post->post_content, 100),
            'shortcodes' => array_slice(array_map('strval', array_keys($this->components('shortcodes'))), 0, 50),
            'elementorWidgets' => array_slice(array_map('strval', array_keys($this->components('elementorWidgets'))), 0, 50),
        );
    }

    private static function bounded_keys(array $set)
    {
        return array_slice(array_map('strval', array_keys($set)), 0, self::RECEIPT_LIST_MAX);
    }

    /** A lifetime asked by Next, kept within [default, MAX_SESSION_TTL]. */
    public static function session_ttl($requested, $default)
    {
        $ttl = (int) $requested;
        return $ttl > 0 ? max($default, min($ttl, self::MAX_SESSION_TTL)) : $default;
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
                // Next extends the unload of a stylesheet to its dependents
                $asset['deps'] = array_values(array_map('strval', (array) $registry->registered[$handle]->deps));
            }
            $out[] = $asset;
        }
        return $out;
    }

    /**
     * The script dependency graph of the page, aliases included (`jquery` has
     * no file of its own): WordPress honours a defer only when every enqueued
     * dependent has a strategy too and no inline script runs after it
     * (WP_Scripts::filter_eligible_strategies). `strategy` is the site's own,
     * read while the rules are off.
     */
    private function script_graph()
    {
        $registry = wp_scripts();
        if (!is_object($registry) || !isset($registry->registered)) {
            return array();
        }
        $handles = array_unique(array_merge((array) $registry->queue, (array) $registry->done));
        $out = array();
        foreach ($handles as $handle) {
            if (!isset($registry->registered[$handle])) {
                continue;
            }
            $script = $registry->registered[$handle];
            $extra = isset($script->extra) ? (array) $script->extra : array();
            $strategy = isset($extra['strategy']) ? (string) $extra['strategy'] : '';
            $out[] = array(
                'handle' => (string) $handle,
                'deps' => array_values(array_map('strval', (array) $script->deps)),
                'alias' => (string) $script->src === '',
                'inlineAfter' => !empty($extra['after']),
                'strategy' => in_array($strategy, array('defer', 'async'), true) ? $strategy : null,
            );
            if (count($out) >= 300) {
                break;
            }
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

    /**
     * A preview evaluates every target: its receipt names each rule a content
     * condition withheld, even when another rule did the same.
     */
    private function targets_match($targets)
    {
        $matched = false;
        foreach ((array) $targets as $target) {
            if ($this->target_matches($target)) {
                $matched = true;
                if (!$this->preview) {
                    break;
                }
            }
        }
        return $matched;
    }

    /** The first entry whose target matches the request, or null. */
    private function first_matching(array $entries)
    {
        foreach ($entries as $entry) {
            if ($this->target_matches($entry['target'] ?? array())) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * A rule applies when its contexts and paths match, no `unless` condition
     * holds and its `when` condition, if any, is true.
     */
    private function target_matches($target)
    {
        if (!$this->scope_matches($target)) {
            return false;
        }
        if (empty($target['unless']) && !array_key_exists('when', (array) $target)) {
            return true;
        }
        // before the main query the displayed content is unknown: the rule waits
        if (!$this->query_ready()) {
            return false;
        }
        if ($this->conditions_hold($target)) {
            return true;
        }
        if ($this->preview && isset($target['rule'])) {
            $this->receipt['skipped'][(string) $target['rule']] = true;
        }
        return false;
    }

    /** Contexts and paths: the cheap prefilter of a target. */
    private function scope_matches($target)
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

    /**
     * No `unless` condition holds and `when` is true. A conditional tag that
     * cannot be evaluated withholds the rule, whatever the tree around it.
     */
    private function conditions_hold($target)
    {
        foreach ((array) ($target['unless'] ?? array()) as $predicate) {
            if ($this->predicate_holds($predicate)) {
                return false;
            }
        }
        if (!array_key_exists('when', (array) $target)) {
            return true;
        }
        if (!is_array($target['when'])) {
            return false;
        }
        try {
            return $this->node_holds($target['when']);
        } catch (FaaasterPerformanceConditionError $error) {
            return false;
        }
    }

    /** all/any/not short-circuit; two values only, an error stops the evaluation. */
    private function node_holds(array $node)
    {
        if (isset($node['all'])) {
            foreach ((array) $node['all'] as $child) {
                if (!$this->node_holds((array) $child)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($node['any'])) {
            foreach ((array) $node['any'] as $child) {
                if ($this->node_holds((array) $child)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($node['not'])) {
            return !$this->node_holds((array) $node['not']);
        }
        return $this->atom_holds($node);
    }

    /** One atom, evaluated once per request (key: its canonical JSON). */
    private function atom_holds(array $atom)
    {
        if (isset($atom['key'])) {
            $key = (string) $atom['key'];
        } else {
            $key = FaaasterPerformanceSettings::canonical_json($atom);
        }
        if (!array_key_exists($key, $this->atoms)) {
            try {
                $this->atoms[$key] = (bool) $this->evaluate_atom($atom);
            } catch (FaaasterPerformanceConditionError $error) {
                $this->atoms[$key] = $error;
            }
        }
        if ($this->atoms[$key] instanceof FaaasterPerformanceConditionError) {
            throw $this->atoms[$key];
        }
        return $this->atoms[$key];
    }

    /**
     * The fixed table of `when` atoms (FaaasterPerformanceSettings::WHEN_ATOMS):
     * the URL, the displayed content and the visitor being logged in, never a
     * cookie, a device or the time of day.
     */
    private function evaluate_atom(array $atom)
    {
        $kind = (string) ($atom['kind'] ?? '');
        switch ($kind) {
            case 'front_page':
                return is_front_page();
            case 'home':
                return is_home();
            case 'search':
                return is_search();
            case 'not_found':
                return is_404();
            case 'logged_in':
                return function_exists('is_user_logged_in') && is_user_logged_in();
            case 'singular':
                return is_singular(isset($atom['postTypes']) ? (array) $atom['postTypes'] : '');
            case 'archive':
                return isset($atom['postTypes']) ? is_post_type_archive((array) $atom['postTypes']) : is_archive();
            case 'taxonomy':
                $terms = isset($atom['terms']) ? (array) $atom['terms'] : '';
                if ($atom['taxonomy'] === 'category') {
                    return is_category($terms);
                }
                if ($atom['taxonomy'] === 'post_tag') {
                    return is_tag($terms);
                }
                return is_tax((string) $atom['taxonomy'], $terms);
            case 'page':
                return is_page(array_merge((array) ($atom['ids'] ?? array()), (array) ($atom['slugs'] ?? array())));
            case 'conditional':
                return $this->conditional_value((string) $atom['name']);
            case 'has_block':
                return isset($this->components('blocks')[(string) $atom['name']]);
            case 'has_shortcode':
                return isset($this->components('shortcodes')[(string) $atom['tag']])
                    || isset($this->term_shortcodes()[(string) $atom['tag']]);
            case 'elementor_widget':
                return isset($this->components('elementorWidgets')[(string) $atom['name']]);
            case 'widget':
                return $this->predicate_holds(array('kind' => 'active_widget', 'value' => (string) $atom['idBase']));
            case 'has_term':
                $post = $this->content_post();
                return $post !== null && function_exists('has_term') && has_term((array) $atom['terms'], (string) $atom['taxonomy'], $post);
            case 'product_type':
                $type = $this->product_type();
                return $type !== null && in_array($type, (array) $atom['types'], true);
            case 'template':
                $post = $this->content_post();
                if ($post === null || !function_exists('get_page_template_slug')) {
                    return false;
                }
                $slug = (string) get_page_template_slug($post);
                return in_array($slug !== '' ? $slug : 'default', (array) $atom['slugs'], true);
            case 'path':
                $path = $this->request_path();
                return isset($atom['prefix'])
                    ? strpos($path, (string) $atom['prefix']) === 0
                    : in_array($path, (array) ($atom['exact'] ?? array()), true);
        }
        return false;
    }

    /** A discovered conditional tag, called once per request, without argument. */
    private function conditional_value($name)
    {
        if (!array_key_exists($name, $this->conditionals)) {
            $this->conditionals[$name] = $this->call_conditional($name);
        }
        if ($this->conditionals[$name] instanceof FaaasterPerformanceConditionError) {
            throw $this->conditionals[$name];
        }
        return $this->conditionals[$name];
    }

    /** bool, or the error (logged once per request and name) */
    private function call_conditional($name)
    {
        if (!isset(FaaasterPerformanceConditionals::allowed()[$name])) {
            return $this->condition_error($name, 'not a discovered conditional');
        }
        if (!function_exists($name)) {
            return $this->condition_error($name, 'undefined function');
        }
        // a conditional tag prints nothing: anything it prints is discarded
        $level = ob_get_level();
        ob_start();
        try {
            $value = (bool) call_user_func($name);
        } catch (Throwable $error) {
            $value = $this->condition_error($name, 'threw ' . get_class($error) . ': ' . $error->getMessage());
        }
        if (ob_get_level() === $level + 1) {
            ob_end_clean();
        }
        return $value;
    }

    private function condition_error($name, $reason)
    {
        $error = new FaaasterPerformanceConditionError($name, $reason);
        error_log('[faaaster-performance] ' . $error->getMessage() . ', the rules using it do not apply');
        $this->receipt['conditionErrors'][$name] = true;
        return $error;
    }

    /** Shortcodes of the queried term's description, on a taxonomy archive. */
    private function term_shortcodes()
    {
        if (!isset($this->content['termShortcodes'])) {
            $set = array();
            if (is_category() || is_tag() || is_tax()) {
                $term = get_queried_object();
                if (is_object($term) && isset($term->description) && is_string($term->description)) {
                    $set = FaaasterPerformanceComponents::shortcodes($term->description, FaaasterPerformanceComponents::registered_shortcodes());
                }
            }
            $this->content['termShortcodes'] = $set;
        }
        return $this->content['termShortcodes'];
    }

    /** The WooCommerce type of the displayed product, or null off a product page. */
    private function product_type()
    {
        if (!array_key_exists('productType', $this->content)) {
            $type = null;
            $is_product = function_exists('is_product') ? is_product() : is_singular('product');
            if ($is_product && function_exists('wc_get_product')) {
                $product = wc_get_product(get_queried_object_id());
                if (is_object($product) && method_exists($product, 'get_type')) {
                    $type = (string) $product->get_type();
                }
            }
            $this->content['productType'] = $type;
        }
        return $this->content['productType'];
    }

    private function query_ready()
    {
        return did_action('wp') > 0;
    }

    /** One content condition, evaluated once per request. */
    private function predicate_holds($predicate)
    {
        $kind = (string) ($predicate['kind'] ?? '');
        $value = $predicate['value'] ?? '';
        if ($kind === 'post_ids') {
            return is_singular() && in_array((int) get_queried_object_id(), (array) $value, true);
        }
        $value = is_scalar($value) ? (string) $value : '';
        $key = $kind . ':' . $value;
        if (!isset($this->predicates[$key])) {
            $this->predicates[$key] = $this->evaluate_predicate($kind, (string) $value);
        }
        return $this->predicates[$key];
    }

    private function evaluate_predicate($kind, $value)
    {
        if ($kind === 'has_block') {
            return isset($this->components('blocks')[$value]);
        }
        if ($kind === 'has_shortcode') {
            return isset($this->components('shortcodes')[$value]);
        }
        if ($kind === 'elementor_widget') {
            return isset($this->components('elementorWidgets')[$value]);
        }
        if ($kind === 'active_widget') {
            return function_exists('is_active_widget') && is_active_widget(false, false, $value, true) !== false;
        }
        if ($kind === 'product_type') {
            return $this->product_type() === $value;
        }
        return false;
    }

    /** The singular content the request shows, read once, or null. */
    private function content_post()
    {
        if (!array_key_exists('post', $this->content)) {
            $post = is_singular() ? get_queried_object() : null;
            $this->content['post'] = is_object($post) && isset($post->ID, $post->post_content) ? $post : null;
        }
        return $this->content['post'];
    }

    /**
     * One kind of component of that content, a set computed on first use:
     * blocks as has_block() matches them, registered shortcodes (what
     * has_shortcode() finds, without one regex per tag), Elementor widget
     * types of `_elementor_data`.
     */
    private function components($kind)
    {
        if (isset($this->content[$kind])) {
            return $this->content[$kind];
        }
        $post = $this->content_post();
        $set = array();
        if ($post && $kind === 'blocks') {
            $set = FaaasterPerformanceComponents::serialized_blocks((string) $post->post_content);
        } elseif ($post && $kind === 'shortcodes') {
            $set = FaaasterPerformanceComponents::shortcodes(
                (string) $post->post_content,
                FaaasterPerformanceComponents::registered_shortcodes()
            );
        } elseif ($post && $kind === 'elementorWidgets' && function_exists('get_post_meta')) {
            $set = FaaasterPerformanceComponents::elementor_widgets(get_post_meta((int) $post->ID, '_elementor_data', true));
        }
        $this->content[$kind] = $set;
        return $set;
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
        if (strpos($context, 'taxonomy:') === 0) return $this->taxonomy_matches(substr($context, 9));
        if (strpos($context, 'page:') === 0) return is_page((int) substr($context, 5));
        return false;
    }

    /**
     * is_tax() is false on WordPress's own taxonomies: their archives answer
     * is_category() and is_tag(). Every rule on blog categories was applied
     * nowhere (18/10, inst200229: « décharger woocommerce sur les catégories
     * d'articles » found without effect).
     */
    private function taxonomy_matches($taxonomy)
    {
        if ($taxonomy === 'category') return is_category();
        if ($taxonomy === 'post_tag') return is_tag();
        return is_tax($taxonomy);
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
