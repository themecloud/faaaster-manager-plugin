<?php

/** Inline CSS/data-bg adaptation using OD measurements and IP's existing lazy-loader. */
class FaaasterImageBackgrounds
{
    const SOURCES_META = '_faaaster_image_background_sources';
    private $sources;
    private $handled = array();
    private $measurements;
    private $dimensions = array();
    private $next_id = 0;
    private $css_groups = array();
    private $css_rules = array();
    private $css_native_groups;

    public function __construct(FaaasterImageSources $sources)
    {
        $this->sources = $sources;
    }

    public function handles($processor)
    {
        $xpath = $processor->get_xpath();
        return isset($this->handled[$xpath]) && $this->handled[$xpath]['id'] === $processor->get_attribute('data-faaaster-bg');
    }

    /** The native IP identity plus our geometry, consistently observed within a viewport group. */
    public static function css_lcp_groups($collection)
    {
        $out = array();
        foreach ($collection as $index => $group) {
            $candidate = null;
            $valid = $group->count() === $group->get_sample_size();
            foreach ($group as $metric) {
                $image = $metric->get('lcpElementExternalBackgroundImage');
                $geometry = $metric->get('faaasterCssLcp');
                if (!is_array($image) || !is_array($geometry)
                    || !isset($image['url'], $image['tag'], $geometry['url'], $geometry['width'], $geometry['height'])
                    || $image['url'] !== $geometry['url']) { $valid = false; break; }
                $key = FaaasterImageSources::variant_key($image['url']);
                $width = $geometry['width']; $height = $geometry['height'];
                if (!$key || !is_numeric($width) || !is_numeric($height)
                    || !is_finite((float) $width) || !is_finite((float) $height) || $width <= 0 || $height <= 0) {
                    $valid = false; break;
                }
                $target = array('tag' => strtoupper($image['tag']), 'id' => $image['id'] ?? null, 'class' => $image['class'] ?? null);
                if ($candidate !== null && ($candidate['key'] !== $key || $candidate['target'] !== $target)) { $valid = false; break; }
                if ($candidate === null) {
                    $candidate = array('key' => $key, 'target' => $target, 'width' => 0, 'height' => 0,
                        'min' => $group->get_minimum_viewport_width(), 'max' => $group->get_maximum_viewport_width());
                }
                $candidate['width'] = max($candidate['width'], $width);
                $candidate['height'] = max($candidate['height'], $height);
            }
            $out[$index] = $valid ? $candidate : null;
        }
        return $out;
    }

    public static function css_identity(array $target)
    {
        return substr(hash('sha256', wp_json_encode($target)), 0, 16);
    }

    public static function matches_css_target($processor, array $target)
    {
        $style = $processor->get_attribute('style');
        if (is_string($style) && preg_match('/(?:^|;)\s*background(?:-image)?\s*:/i', preg_replace('~/\*.*?\*/~s', '', $style))) { return false; }
        return $processor->get_tag() === $target['tag']
            && $processor->get_attribute('id') === $target['id']
            && $processor->get_attribute('class') === $target['class'];
    }

    /** Source identities trigger discovery; only rounded decisions can trigger subsequent purges. */
    public static function css_lcp_decisions($collection, array $dimensions, array $widths)
    {
        $out = array();
        foreach (self::css_lcp_groups($collection) as $candidate) {
            if ($candidate === null) { $out[] = null; continue; }
            $key = $candidate['key'];
            $source = $dimensions['CSS:' . $key][0] ?? null;
            $measurement = array('widths' => array($key => $candidate['width']), 'heights' => array($key => $candidate['height']));
            $out[] = array('min' => $candidate['min'], 'max' => $candidate['max'], 'target' => $candidate['target'], 'source' => $key,
                'width' => is_array($source) && isset($source['width'], $source['height']) && $source['width'] > 0 && $source['height'] > 0
                    ? self::painted_width($source, $measurement, $key, $widths) : null);
        }
        return $out;
    }

    /** Preflight shares the existing CSP lookahead. Never evaluate stylesheet selectors in PHP. */
    public function prepare_css_lcp($context, array $groups, array $styles, array $counts, $can_rewrite = true)
    {
        $sources = array();
        foreach ($can_rewrite ? $styles : array() as $css) {
            if (self::css_rules_offset($css) === null) { continue; }
            foreach (self::background_urls($css, true) as $token) {
                $source = $this->sources->resolve(str_replace(' ', '%20', $token['url']));
                if ($source) { $sources[$this->sources->url($source)] = $source; }
            }
        }
        $unmatched = array();
        foreach ($groups as $index => $candidate) {
            if ($candidate === null) { continue; }
            $count = $counts[self::css_identity($candidate['target'])] ?? 0;
            $reason = !$can_rewrite ? 'csp' : ($count === 0 ? 'target_not_found'
                : ($count !== 1 ? 'target_not_unique' : (!isset($sources[$candidate['key']]) ? 'source_not_in_style' : null)));
            if ($reason !== null) { $unmatched[$reason] = ($unmatched[$reason] ?? 0) + 1; continue; }
            $key = $candidate['key'];
            $source = $sources[$key];
            $variable = '--faaaster-css-lcp-' . substr(hash('sha256', $key), 0, 16);
            $this->css_groups[$index] = $candidate + array('source' => $source, 'variable' => $variable);
            $this->dimensions['CSS:' . $key] = array(array('width' => $source['width'], 'height' => $source['height']));
            $measurement = array('widths' => array($key => $candidate['width']), 'heights' => array($key => $candidate['height']));
            $width = self::painted_width($source, $measurement, $key, $this->sources->widths());
            foreach ($this->variants($source, $width) as $variant) {
                $attrs = array('rel' => 'preload', 'as' => 'image', 'fetchpriority' => 'high', 'href' => $variant['url']);
                if ($variant['media'] !== null) { $attrs['media'] = $variant['media']; }
                $context->link_collection->add_link($attrs, $candidate['min'], $candidate['max']);
                $selector = '[data-faaaster-css-lcp="' . self::css_identity($candidate['target']) . '"]';
                $declaration = $variable . ':url("' . $variant['url'] . '")';
                $rule_key = $declaration . '|' . $variant['media'];
                if (!isset($this->css_rules[$key][$selector][$rule_key])) {
                    $this->css_rules[$key][$selector][$rule_key] = array('declaration' => $declaration, 'density' => $variant['media'], 'ranges' => array());
                }
                $this->css_rules[$key][$selector][$rule_key]['ranges'][] = array($candidate['min'], $candidate['max']);
            }
        }
        if ($unmatched) { FaaasterImagePurges::record_css_lcp_unmatched($unmatched); }
        if ($this->css_groups) {
            $metrics = array();
            foreach ($context->url_metric_group_collection as $index => $group) {
                if (!isset($this->css_groups[$index])) { foreach ($group as $metric) { $metrics[] = $metric; } }
            }
            $collection = $context->url_metric_group_collection;
            $this->css_native_groups = new OD_URL_Metric_Group_Collection($metrics, $collection->get_current_etag(),
                $collection->get_breakpoints(), $collection->get_sample_size(), $collection->get_freshness_ttl());
        }
    }

    /** Native inline lazy loading keeps the original metrics; handled CSS preloads use our variants. */
    public function css_native_context($context)
    {
        $style = $context->processor->get_attribute('style');
        $native_inline = is_string($style)
            && preg_match('/background(?:-image)?\s*:[^;]*?url\(\s*[\'"]?\s*(?<background_image>.+?)\s*[\'"]?\s*\)/', $style, $match)
            && stripos($match['background_image'], 'data:') !== 0;
        if (!$this->css_native_groups || $native_inline) { return $context; }
        return new OD_Tag_Visitor_Context($context->processor, $this->css_native_groups, $context->link_collection,
            new OD_Visited_Tag_State(), $context->url_metrics_id);
    }

    private function mark_css_lcp($processor)
    {
        foreach ($this->css_groups as $candidate) {
            if (self::matches_css_target($processor, $candidate['target'])) {
                $processor->set_attribute('data-faaaster-css-lcp', self::css_identity($candidate['target']));
                break;
            }
        }
    }

    /** Stylesheet conversion is restricted to background declarations, preserving other URL uses. */
    public function rewrite_css($css)
    {
        $edits = array();
        $adapted = array();
        foreach (self::background_urls($css, true) as $token) {
            $source = $this->sources->resolve(str_replace(' ', '%20', $token['url']));
            if ($source) {
                $key = $this->sources->url($source);
                $image = 'url("' . $key . '")';
                if (isset($this->css_rules[$key])) {
                    $variable = '--faaaster-css-lcp-' . substr(hash('sha256', $key), 0, 16);
                    $adapted[$key] = $variable;
                    $image = 'var(' . $variable . ',' . $image . ')';
                }
                $edits[] = array($token['start'], $token['length'], $image);
            }
        }
        foreach (array_reverse($edits) as $edit) {
            $css = substr_replace($css, $edit[2], $edit[0], $edit[1]);
        }
        $offset = self::css_rules_offset($css);
        if ($adapted && $offset !== null) {
            // Every element/pseudo-element starts at the existing w0 value, without inheriting a parent's variant.
            $prefix = '*,::before,::after{';
            foreach ($adapted as $variable) { $prefix .= $variable . ':initial;'; }
            $prefix .= '}';
            foreach ($adapted as $key => $variable) {
                foreach ($this->css_rules[$key] as $selector => $rules) { $prefix .= self::render_rules($rules, $selector); }
            }
            // Retain this STYLE's nonce/media/CSP and leading @import/@namespace ordering.
            $css = substr_replace($css, $prefix, $offset, 0);
        }
        return $css;
    }

    /** Element geometry; media queries supply the visiting browser's actual DPR. */
    public static function measurements($collection)
    {
        $out = array();
        foreach ($collection as $group) {
            $widths = array();
            $heights = array();
            foreach ($group->get_xpath_elements_map() as $xpath => $elements) {
                // IMG sizing is native; non-IMG tags tracked by our visitors are backgrounds.
                if (preg_match('~/(?:IMG|\*\[\d+\]\[self::IMG\])(?:\[[^\]]*\])*$~i', $xpath)) {
                    continue;
                }
                foreach ($elements as $element) {
                    $rect = $element->get('boundingClientRect');
                    $width = is_array($rect) && isset($rect['width']) ? $rect['width'] : null;
                    if ((is_int($width) || is_float($width)) && is_finite($width) && $width > 0) {
                        $widths[$xpath] = max(isset($widths[$xpath]) ? $widths[$xpath] : 0, $width);
                        $height = isset($rect['height']) ? $rect['height'] : null;
                        if ((is_int($height) || is_float($height)) && is_finite($height) && $height > 0) {
                            $heights[$xpath] = max(isset($heights[$xpath]) ? $heights[$xpath] : 0, $height);
                        }
                    }
                }
            }
            ksort($widths);
            ksort($heights);
            $out[] = array('min' => $group->get_minimum_viewport_width(),
                'max' => $group->get_maximum_viewport_width(), 'widths' => $widths, 'heights' => $heights);
        }
        return $out;
    }

    /** Only admitted sizing decisions enter the purge fingerprint, never raw geometry. */
    public static function decisions($collection, array $dimensions, array $widths)
    {
        $out = array();
        foreach (self::measurements($collection) as $measurement) {
            $decisions = array();
            foreach ($measurement['widths'] as $xpath => $unused) {
                if (!isset($dimensions[$xpath]) || !is_array($dimensions[$xpath])) { continue; }
                foreach ($dimensions[$xpath] as $source) {
                    if (is_array($source) && isset($source['width'], $source['height']) && $source['width'] > 0 && $source['height'] > 0) {
                        $decisions[$xpath][] = self::painted_width($source, $measurement, $xpath, $widths);
                    }
                }
            }
            $out[] = array('min' => $measurement['min'], 'max' => $measurement['max'], 'widths' => $decisions);
        }
        return $out;
    }

    /** One complete snapshot per OD URL; repeated measurements never write this metadata. */
    public function store_dimensions($url_metrics_id)
    {
        if (!$url_metrics_id) { return; } // OD creates its post only after the first sample.
        ksort($this->dimensions);
        $previous = get_post_meta($url_metrics_id, self::SOURCES_META, true);
        if ($previous !== $this->dimensions && ($this->dimensions || $previous)) {
            update_post_meta($url_metrics_id, self::SOURCES_META, $this->dimensions);
        }
    }

    public function rewrite($context)
    {
        $p = $context->processor;
        $this->mark_css_lcp($p);
        if (!FaaasterImageOptimization::feature_enabled('inline_bg')) { return false; }
        $xpath = $p->get_xpath();
        $id = substr(hash('sha256', $xpath . '|' . $this->next_id++), 0, 16);
        $data = $p->get_attribute('data-bg');
        $style = $p->get_attribute('style');
        $is_data = is_string($data) && trim($data) !== '';
        $value = $is_data ? $data : $style;
        if (!is_string($value)) {
            return false;
        }
        $tokens = $is_data ? self::urls($value) : self::background_urls($value);
        if ($is_data && !$tokens) {
            // Common loaders expect a bare URL and wrap it in url() themselves.
            $tokens = array(array('start' => 0, 'length' => strlen($value), 'url' => trim($value), 'bare' => true));
        }
        $items = array();
        $edits = array();
        $adaptive_edits = array();
        foreach ($tokens as $token) {
            $url = str_replace(' ', '%20', $token['url']);
            $source = $this->sources->resolve($url);
            if (!$source) {
                continue;
            }
            $fallback = $this->sources->url($source);
            $variable = '--faaaster-bg-' . $id . '-' . count($items);
            $image = 'url("' . $fallback . '")';
            $items[] = array('source' => $source, 'variable' => $variable, 'fallback' => $fallback);
            $replacement = $is_data ? (!empty($token['bare']) ? $fallback : $image)
                : 'var(' . $variable . ',' . $image . ')';
            $edits[] = array($token['start'], $token['length'], $replacement);
            $adaptive_edits[] = array($token['start'], $token['length'], 'var(' . $variable . ',' . $image . ')');
        }
        if (!$items) {
            return false;
        }
        // data-bg is author text (wp_kses_post keeps it for Contributors and Authors) that the
        // adaptive rule below copies into a generated STYLE element: only the url() tokens we
        // resolved are replaced. Anything else than plain layer syntax around them leaves the
        // element exactly as authored, before any mutation.
        if ($is_data && !self::safe_layer_remainder($value, $edits)) {
            return false;
        }
        foreach ($items as $item) {
            $this->dimensions[$xpath][] = array('width' => $item['source']['width'], 'height' => $item['source']['height']);
        }
        $adaptive_value = $value;
        foreach (array_reverse($adaptive_edits) as $edit) {
            $adaptive_value = substr_replace($adaptive_value, $edit[2], $edit[0], $edit[1]);
        }
        foreach (array_reverse($edits) as $edit) {
            $value = substr_replace($value, $edit[2], $edit[0], $edit[1]);
        }
        $selector = '[data-faaaster-bg="' . $id . '"]';
        $p->set_attribute('data-faaaster-bg', $id);
        $p->set_attribute($is_data ? 'data-bg' : 'style', $value);
        // data-bg must remain dormant until its existing loader copies it into the style.
        if ($is_data) {
            $selector .= '[style*="' . $items[0]['fallback'] . '"]';
        }
        $this->handled[$xpath] = array('id' => $id, 'data' => $is_data, 'items' => $items);
        $css = $is_data ? $selector . '{background-image:' . $adaptive_value . '!important}' : '';
        if (!$is_data) {
            // Also suppress URLs inside an authored inline !important declaration while IP is lazy.
            $css .= $selector . '.od-lazy-bg-image{';
            foreach ($items as $item) { $css .= $item['variable'] . ':none;'; }
            $css .= '}';
        }
        $groups = iterator_to_array($context->url_metric_group_collection);
        if ($this->measurements === null) {
            $this->measurements = self::measurements($context->url_metric_group_collection);
        }
        $rules = array();
        foreach ($this->measurements as $index => $measurement) {
            $group = $groups[$index];
            $width = isset($measurement['widths'][$xpath]) ? $measurement['widths'][$xpath] : null;
            $lcp = $group->get_lcp_element();
            $critical = $lcp && $lcp->get_xpath() === $xpath;
            foreach ($items as $item) {
                $image_width = self::painted_width($item['source'], $measurement, $xpath, $this->sources->widths());
                $variants = $width === null ? array(array('url' => $item['fallback'], 'media' => null))
                    : $this->variants($item['source'], $image_width);
                foreach ($variants as $variant) {
                    if ($width !== null) {
                        $declaration = $item['variable'] . ':url("' . $variant['url'] . '")';
                        $key = $declaration . '|' . $variant['media'];
                        if (!isset($rules[$key])) {
                            $rules[$key] = array('declaration' => $declaration, 'density' => $variant['media'], 'ranges' => array());
                        }
                        $rules[$key]['ranges'][] = array($measurement['min'], $measurement['max']);
                    }
                    if ($critical) {
                        $attrs = array('rel' => 'preload', 'as' => 'image', 'fetchpriority' => 'high', 'href' => $variant['url']);
                        if ($variant['media'] !== null) { $attrs['media'] = $variant['media']; }
                        $context->link_collection->add_link($attrs, $measurement['min'], $measurement['max']);
                    }
                }
            }
        }
        $css .= self::render_rules($rules, $selector);
        // Defence in depth: raw text of a STYLE element ends at "</style". Generated rules never
        // hold "</" (URL-safe bytes, see FaaasterImageSources::resolve; "<=" only in media
        // queries); if one ever does, the element keeps its w0 fallback without adaptive rules.
        if ($css !== '' && strpos($css, '</') === false) {
            $p->append_head_html('<style data-faaaster-images="' . $id . '">' . $css . '</style>');
        }
        return true;
    }

    /**
     * Author text around our url() replacements in a data-bg value: only layer syntax
     * (keywords, colors, numbers, gradients) separated by commas. No quote, CSS escape,
     * comment, ";", "{", "}", "!", "@", ":" or "<", and balanced parentheses: it can neither
     * end the STYLE element nor leave the declaration it is copied into.
     */
    private static function safe_layer_remainder($value, array $edits)
    {
        $remainder = '';
        $offset = 0;
        foreach ($edits as $edit) { // ascending, non-overlapping url() tokens
            $remainder .= substr($value, $offset, $edit[0] - $offset) . ' ';
            $offset = $edit[0] + $edit[1];
        }
        $remainder .= substr($value, $offset);
        if (!preg_match('~^[A-Za-z0-9\s,().%#+_/-]*$~D', $remainder)) { // no "*": no comment either
            return false;
        }
        $depth = 0;
        for ($i = 0, $n = strlen($remainder); $i < $n; $i++) {
            if ($remainder[$i] === '(') {
                $depth++;
            } elseif ($remainder[$i] === ')' && --$depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    private static function render_rules(array $rules, $selector)
    {
        $css = '';
        foreach ($rules as $rule) {
            $ranges = array();
            foreach ($rule['ranges'] as $range) {
                $last = count($ranges) - 1;
                if ($last >= 0 && $ranges[$last][1] === $range[0]) {
                    $ranges[$last][1] = $range[1];
                } else {
                    $ranges[] = $range;
                }
            }
            $queries = array();
            foreach ($ranges as $range) {
                $queries[] = implode(' and ', array_filter(array(od_generate_media_query($range[0], $range[1]), $rule['density'])));
            }
            $query = implode(',', $queries);
            $declaration = $selector . '{' . $rule['declaration'] . '}';
            $css .= $query === '' ? $declaration : '@media ' . $query . '{' . $declaration . '}';
        }
        return $css;
    }

    /** cover can paint wider than the element; quantize before either CSS or fingerprinting. */
    private static function painted_width(array $source, array $measurement, $xpath, array $widths)
    {
        if (!isset($measurement['widths'][$xpath])) { return null; }
        $width = $measurement['widths'][$xpath];
        if (isset($measurement['heights'][$xpath])) {
            $width = max($width, $measurement['heights'][$xpath] * $source['width'] / $source['height']);
        }
        return self::bucket($width, $widths, $source['width']);
    }

    private static function bucket($width, array $widths, $maximum)
    {
        foreach ($widths as $bucket) {
            if ($bucket >= $width) { return min($bucket, $maximum); }
        }
        return $maximum;
    }

    /** Keep only buckets reachable for DPR 1..3; the end buckets cover densities outside this range. */
    private function variants(array $source, $width)
    {
        $urls = array();
        foreach ($this->sources->widths() as $bucket) {
            if ($bucket >= $width && $bucket < $source['width']) {
                $urls[$bucket] = $this->sources->url($source, $bucket);
                if ($bucket >= 3 * $width) { break; }
            }
        }
        if (!$urls || max(array_keys($urls)) < 3 * $width) {
            $urls[$source['width']] = $this->sources->url($source);
        }
        ksort($urls, SORT_NUMERIC);
        $out = array();
        $previous = null;
        $last = array_key_last($urls);
        foreach ($urls as $bucket => $url) {
            $terms = array();
            if ($previous !== null) { $terms[] = '(resolution > ' . self::number($previous / $width) . 'dppx)'; }
            if ($bucket !== $last) { $terms[] = '(resolution <= ' . self::number($bucket / $width) . 'dppx)'; }
            $out[] = array('url' => $url, 'media' => $terms ? implode(' and ', $terms) : null);
            $previous = $bucket;
        }
        return $out;
    }

    private static function number($number)
    {
        return rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');
    }

    /** Keep IP's single lazy-loader instance; discard its w0 preload in favor of ours. */
    public function prioritize($context, $native)
    {
        $p = $context->processor;
        $record = $this->handled[$p->get_xpath()];
        if ($record['data']) { return false; }
        $style = $p->get_attribute('style');
        $p->set_attribute('style', 'background-image:url("' . $record['items'][0]['fallback'] . '")');
        try {
            $native_context = new OD_Tag_Visitor_Context($p, $context->url_metric_group_collection,
                new OD_Link_Collection(), new OD_Visited_Tag_State(), $context->url_metrics_id);
            return $native($native_context);
        } finally {
            $p->set_attribute('style', $style);
        }
    }

    /** Insert generated rules after stylesheet headers (@import/@namespace), before the first block. */
    private static function css_rules_offset($css)
    {
        $start = 0; $depth = 0; $length = strlen($css);
        for ($i = 0; $i < $length; ++$i) {
            if ($css[$i] === '\\') { ++$i; continue; }
            if ($css[$i] === '"' || $css[$i] === "'") { $i = self::string_end($css, $i); continue; }
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) { return null; }
                $i = $end + 1; continue;
            }
            if ($css[$i] === '(') { ++$depth; }
            if ($css[$i] === ')') { --$depth; }
            if ($depth === 0 && $css[$i] === ';') { $start = $i + 1; }
            if ($depth === 0 && $css[$i] === '{') { return $start; }
        }
        return null;
    }

    /** Declaration boundaries respect strings, comments and nested functions. */
    private static function background_urls($css, $stylesheet = false)
    {
        $out = array();
        $important = false;
        $start = 0;
        $depth = 0;
        $length = strlen($css);
        for ($i = 0; $i <= $length; $i++) {
            if ($i < $length && $css[$i] === '\\') { $i++; continue; }
            if ($i < $length && ($css[$i] === '"' || $css[$i] === "'")) { $i = self::string_end($css, $i); continue; }
            if ($i < $length && substr($css, $i, 2) === '/*') { $end = strpos($css, '*/', $i + 2); $i = $end === false ? $length : $end + 1; continue; }
            if ($i < $length && $css[$i] === '(') { $depth++; }
            if ($i < $length && $css[$i] === ')') { $depth--; }
            if ($stylesheet && $i < $length && $css[$i] === '{' && $depth === 0) {
                $start = $i + 1;
                continue;
            }
            if ($i === $length || (($css[$i] === ';' || ($stylesheet && $css[$i] === '}')) && $depth === 0)) {
                $declaration = substr($css, $start, $i - $start);
                if (preg_match('~^(?:\s|/\*.*?\*/)*background(?:-image)?(?:\s|/\*.*?\*/)*:~is', $declaration, $match)) {
                    $is_important = preg_match('/!\s*important\s*$/i', preg_replace('~/\*.*?\*/~s', '', $declaration)) === 1;
                    if (!$stylesheet && $important && !$is_important) {
                        $start = $i + 1;
                        continue;
                    }
                    // Background shorthand and background-image share the image cascade.
                    // Do not preload layers from an overridden declaration.
                    if (!$stylesheet) {
                        $out = array();
                        $important = $is_important;
                    }
                    foreach (self::urls(substr($declaration, strlen($match[0]))) as $token) {
                        $token['start'] += $start + strlen($match[0]);
                        $out[] = $token;
                    }
                }
                $start = $i + 1;
            }
        }
        return $out;
    }

    private static function string_end($value, $start)
    {
        $quote = $value[$start];
        for ($i = $start + 1, $n = strlen($value); $i < $n; $i++) {
            if ($value[$i] === '\\') { $i++; }
            elseif ($value[$i] === $quote) { return $i; }
        }
        return strlen($value);
    }

    /** Return actual url() tokens only, never a URL-looking string or CSS comment. */
    private static function urls($value)
    {
        $out = array();
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            if ($value[$i] === '"' || $value[$i] === "'") { $i = self::string_end($value, $i); continue; }
            if (substr($value, $i, 2) === '/*') { $end = strpos($value, '*/', $i + 2); $i = $end === false ? $n : $end + 1; continue; }
            if (strtolower(substr($value, $i, 4)) !== 'url(' || ($i > 0 && preg_match('/[a-z0-9_-]/i', $value[$i - 1]))) { continue; }
            $start = $i;
            $i += 4;
            while ($i < $n && ctype_space($value[$i])) { $i++; }
            if ($i >= $n) { break; }
            if ($value[$i] === '"' || $value[$i] === "'") {
                $end = self::string_end($value, $i);
                if ($end >= $n) { break; }
                $url = substr($value, $i + 1, $end - $i - 1);
                $i = $end + 1;
                while ($i < $n && ctype_space($value[$i])) { $i++; }
            } else {
                $from = $i;
                for (; $i < $n && $value[$i] !== ')'; $i++) {
                    if ($value[$i] === '\\') {
                        if ($i + 1 < $n && strpos("\r\n\f", $value[$i + 1]) !== false) { break; }
                        if (preg_match('/^\\\\[0-9a-f]{1,6}(?:\r\n|[ \t\r\n\f])?/i', substr($value, $i), $escape)) {
                            $i += strlen($escape[0]) - 1;
                        } else { $i++; }
                    }
                    elseif (ctype_space($value[$i])) {
                        while ($i < $n && ctype_space($value[$i])) { $i++; }
                        break;
                    }
                    elseif ($value[$i] === '(' || $value[$i] === '"' || $value[$i] === "'") { break; }
                }
                $url = rtrim(substr($value, $from, $i - $from));
            }
            if ($i < $n && $value[$i] === ')') {
                $out[] = array('start' => $start, 'length' => $i - $start + 1, 'url' => self::unescape($url));
            }
        }
        return $out;
    }

    private static function unescape($value)
    {
        return preg_replace_callback('/\\\\(?:([0-9a-f]{1,6})(?:\r\n|[ \t\r\n\f])?|(\r\n|[\r\n\f])|(.))/is', function ($match) {
            if (!empty($match[1])) {
                $cp = hexdec($match[1]);
                if ($cp === 0 || $cp > 0x10ffff || ($cp >= 0xd800 && $cp <= 0xdfff)) { return "\xef\xbf\xbd"; }
                if ($cp < 0x80) { return chr($cp); }
                if ($cp < 0x800) { return chr(0xc0 | ($cp >> 6)) . chr(0x80 | ($cp & 63)); }
                if ($cp < 0x10000) { return chr(0xe0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 63)) . chr(0x80 | ($cp & 63)); }
                return chr(0xf0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 63)) . chr(0x80 | (($cp >> 6) & 63)) . chr(0x80 | ($cp & 63));
            }
            return isset($match[3]) ? $match[3] : '';
        }, $value);
    }
}
