<?php

/** Reuses OD's processor; never rewrites HTML through a second parser/buffer. */
class FaaasterImageVisitor
{
    private $sources;
    private $backgrounds;
    private $skipped = array();
    private $enhancements;
    private $rewrite_styles = true;

    public function __construct(FaaasterImageSources $sources, $enhancements = true)
    {
        $this->sources = $sources;
        $this->backgrounds = new FaaasterImageBackgrounds($sources);
        $this->enhancements = $enhancements;
    }

    public static function js_loaded($processor)
    {
        // Called for every tag of the page: one read of the data-* names instead of seven lookups.
        $names = $processor->get_attribute_names_with_prefix('data-');
        if (!$names) {
            return false;
        }
        $names = array_flip($names);
        foreach (array('data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset', 'data-original', 'data-lazyload', 'data-bg') as $name) {
            if (isset($names[$name])) {
                return true;
            }
        }
        return false;
    }

    public function skips($processor)
    {
        return self::js_loaded($processor) || ($this->skipped && isset($this->skipped[$processor->get_xpath()]));
    }

    public function handles_background($processor)
    {
        return $this->backgrounds->handles($processor);
    }

    public function css_native_context($context)
    {
        return $this->backgrounds->css_native_context($context);
    }

    public function prioritize_background($context, $native)
    {
        return $this->backgrounds->prioritize($context, $native);
    }

    public function finish($context)
    {
        remove_action('od_finish_template_optimization', array($this, 'finish'));
        $this->backgrounds->store_dimensions($context->url_metrics_id);
    }

    public function __invoke($context)
    {
        $processor = $context->processor;
        $tag = $processor->get_tag();
        if ($tag === 'HTML' && $this->enhancements) {
            $this->scan_csp($processor, $context);
            return false;
        }
        if ($tag === 'STYLE' && $this->enhancements && FaaasterImageOptimization::feature_enabled('style_bg') && $this->rewrite_styles && method_exists($processor, 'set_modifiable_text')) {
            $text = $processor->get_modifiable_text();
            $rewritten = $this->backgrounds->rewrite_css($text);
            if ($rewritten !== $text) { $processor->set_modifiable_text($rewritten); }
            return false;
        }
        if ($tag === 'IMG' && self::js_loaded($processor)) {
            if (FaaasterImageOptimization::feature_enabled('lazy') && !isset($this->skipped[$processor->get_xpath()])) {
                $plan = $this->lazy_plan($processor);
                if ($plan !== null) {
                    foreach ($plan as $attribute => $value) { $processor->set_attribute($attribute, $value); }
                }
            }
            return false;
        }
        if ($tag !== 'PICTURE' && self::js_loaded($processor)
            && ($tag === 'IMG' || $processor->get_attribute('data-bg') === null)) {
            return false;
        }
        if ($tag === 'PICTURE') {
            $this->picture($processor);
        } elseif ($tag === 'IMG') {
            if (FaaasterImageOptimization::feature_enabled('srcset') && !isset($this->skipped[$processor->get_xpath()])) {
                $this->image($processor);
            }
        } else {
            return $this->enhancements ? $this->backgrounds->rewrite($context) : false;
        }
        // Tracking/prioritization belongs to Image Prioritizer.
        return false;
    }

    public static function has_style_hash_policy(array $policies)
    {
        foreach ($policies as $policy) {
            $directives = array();
            foreach (explode(';', $policy) as $directive) {
                $parts = preg_split('/\s+/', trim($directive), 2);
                $name = strtolower($parts[0]);
                if (!isset($directives[$name])) { $directives[$name] = isset($parts[1]) ? $parts[1] : ''; }
            }
            foreach (array('style-src-elem', 'style-src', 'default-src') as $name) {
                if (isset($directives[$name])) {
                    if (preg_match("/'sha(?:256|384|512)-[^']+'/i", $directives[$name])) { return true; }
                    break;
                }
            }
        }
        return false;
    }

    private function scan_csp($processor, $context)
    {
        $policies = array();
        foreach (headers_list() as $header) {
            if (preg_match('/^Content-Security-Policy\s*:\s*(.*)$/i', $header, $match)) { $policies[] = $match[1]; }
        }
        $groups = FaaasterImageOptimization::feature_enabled('css_lcp')
            ? FaaasterImageBackgrounds::css_lcp_groups($context->url_metric_group_collection) : array();
        $targets = array(); $counts = array(); $styles = array();
        foreach ($groups as $candidate) {
            if ($candidate !== null) { $targets[FaaasterImageBackgrounds::css_identity($candidate['target'])] = $candidate['target']; }
        }
        // Without CSS LCP targets this pre-walk only looks for a CSP META element: skip it when the
        // page holds no "http-equiv" at all, and otherwise visit META elements only.
        if ($targets || FaaasterImageOptimization::meta_policy_possible()) {
            $bookmark = 'faaaster-csp-scan';
            $processor->set_bookmark($bookmark);
            $query = $targets ? array('tag_closers' => 'skip') : array('tag_name' => 'META', 'tag_closers' => 'skip');
            while ($processor->next_tag($query)) {
                if (!$targets) {
                    if (strcasecmp((string) $processor->get_attribute('http-equiv'), 'Content-Security-Policy') !== 0) { continue; }
                    if (in_array('NOSCRIPT', $processor->get_breadcrumbs(), true) || $processor->is_admin_bar()) { continue; }
                    $policies[] = (string) $processor->get_attribute('content');
                    continue;
                }
                if (in_array('NOSCRIPT', $processor->get_breadcrumbs(), true) || $processor->is_admin_bar()) { continue; }
                if ($processor->get_tag() === 'STYLE' && method_exists($processor, 'get_modifiable_text')) { $styles[] = $processor->get_modifiable_text(); }
                foreach ($targets as $identity => $target) {
                    if (!self::js_loaded($processor) && FaaasterImageBackgrounds::matches_css_target($processor, $target)) { $counts[$identity] = ($counts[$identity] ?? 0) + 1; }
                }
                if ($processor->get_tag() === 'META' && strcasecmp((string) $processor->get_attribute('http-equiv'), 'Content-Security-Policy') === 0) {
                    $policies[] = (string) $processor->get_attribute('content');
                }
            }
            $processor->seek($bookmark);
            $processor->release_bookmark($bookmark);
        }
        $this->rewrite_styles = !self::has_style_hash_policy($policies);
        if ($targets) { $this->backgrounds->prepare_css_lcp($context, $groups, $styles, $counts, $this->rewrite_styles); }
    }

    private function lazy_plan($processor)
    {
        foreach (array('data-original', 'data-lazyload', 'data-bg') as $unsupported) {
            if ($processor->get_attribute($unsupported) !== null) { return null; }
        }
        $plan = array();
        foreach (array('data-src' => 'data-srcset', 'data-lazy-src' => 'data-lazy-srcset') as $src => $set) {
            $raw = $processor->get_attribute($set);
            if (is_string($raw) && trim($raw) !== '') {
                $converted = $this->sources->convert_candidates($raw);
                if ($converted === null) { return null; }
                $plan[$set] = $converted;
            } elseif (($raw === null || $raw === '') && $processor->get_attribute('srcset') === null) {
                // A lazy loader must not replace an authored responsive set with our single density candidate.
                $source = $this->sources->resolve($processor->get_attribute($src));
                if ($source) { $plan[$set] = $this->sources->url($source) . ' 1x'; }
            }
        }
        return $plan;
    }

    private function image($processor)
    {
        $source = $this->sources->resolve($processor->get_attribute('src'));
        if (!$source) {
            return;
        }
        $raw = $processor->get_attribute('srcset');
        $candidates = $this->sources->candidates($raw);
        if ($candidates === null) {
            return;
        }
        $sizes = $processor->get_attribute('sizes');
        $sizes = is_string($sizes) ? trim($sizes) : '';
        $fallback_sizes = trim((string) preg_replace('/^auto\s*(?:,\s*)?/i', '', $sizes));
        $width = $processor->get_attribute('width');
        $width = is_string($width) && preg_match('/^[1-9][0-9]*$/', $width) ? (int) $width : 0;
        $known_size = $fallback_sizes !== '' || $width > 0;
        if (!$candidates && !$known_size) {
            // A density candidate converts without assuming a rendered width or changing src.
            $processor->set_attribute('srcset', $this->sources->url($source) . ' 1x');
            return;
        }
        if ($fallback_sizes === '' && $width > 0) {
            $processor->set_attribute('sizes', ($sizes !== '' ? 'auto, ' : '')
                . '(max-width: ' . $width . 'px) 100vw, ' . $width . 'px');
        } elseif ($fallback_sizes === '' && $candidates && substr($candidates[0]['descriptor'], -1) === 'w') {
            // Make the browser's existing implicit width-descriptor default explicit.
            $processor->set_attribute('sizes', $sizes !== '' ? 'auto, 100vw' : '100vw');
        }
        $srcset = $this->sources->srcset($source, $candidates, $known_size);
        if ($srcset !== '') {
            $processor->set_attribute('srcset', $srcset);
        }
    }

    /** OD restores the cursor after each visitor; rewrite SOURCE before IP builds its preload. */
    private function picture($processor)
    {
        $depth = $processor->get_current_depth();
        $sources = array();
        $images = array();
        $picture_sizes = '100vw';
        $valid = !self::js_loaded($processor);
        $has_lazy = false;
        $bookmark = 'faaaster-picture-start';
        $processor->set_bookmark($bookmark);
        // Validate the whole picture first, to avoid mixed declarations / partial rewrites.
        while ($processor->next_tag(array('tag_closers' => 'visit'))) {
            if ($processor->get_tag() === 'PICTURE' && $processor->is_tag_closer()) {
                break;
            }
            $has_lazy = $has_lazy || self::js_loaded($processor);
            if ($processor->get_current_depth() > $depth + 1 || self::js_loaded($processor)) {
                $valid = false;
            }
            if ($processor->get_tag() === 'IMG') {
                $images[] = $processor->get_xpath();
                $sizes = $processor->get_attribute('sizes');
                $sizes = is_string($sizes) ? trim((string) preg_replace('/^auto\s*(?:,\s*)?/i', '', trim($sizes))) : '';
                $width = $processor->get_attribute('width');
                if ($sizes !== '') {
                    $picture_sizes = $sizes;
                } elseif (is_string($width) && preg_match('/^[1-9][0-9]*$/', $width)) {
                    $picture_sizes = '(max-width: ' . (int) $width . 'px) 100vw, ' . (int) $width . 'px';
                }
                if (!$this->sources->resolve($processor->get_attribute('src'))
                    || $this->sources->candidates($processor->get_attribute('srcset')) === null) {
                    $valid = false;
                }
            }
            if ($processor->get_tag() === 'SOURCE') {
                $type = $processor->get_attribute('type');
                if ($type !== null && !in_array(strtolower((string) $type), array('image/jpeg', 'image/png', 'image/webp'), true)) {
                    $valid = false;
                }
                $candidates = $this->sources->candidates($processor->get_attribute('srcset'));
                if (!$candidates) {
                    $valid = false;
                }
                $sources[] = $candidates;
            }
        }
        $processor->seek($bookmark);
        if (($has_lazy && !FaaasterImageOptimization::feature_enabled('lazy'))
            || (!$has_lazy && !FaaasterImageOptimization::feature_enabled('srcset'))) {
            foreach ($images as $xpath) { $this->skipped[$xpath] = true; }
            $processor->release_bookmark($bookmark);
            return;
        }
        if ($has_lazy) {
            $this->lazy_picture($processor, $bookmark, $depth, $images);
            return;
        }
        if (!$valid) {
            foreach ($images as $xpath) {
                $this->skipped[$xpath] = true;
            }
            $processor->release_bookmark($bookmark);
            return;
        }
        $index = 0;
        while ($processor->next_tag(array('tag_closers' => 'visit'))) {
            if ($processor->get_tag() === 'PICTURE' && $processor->is_tag_closer()) {
                break;
            }
            if ($processor->get_tag() === 'SOURCE' && isset($sources[$index])) {
                $candidates = $sources[$index++];
                $processor->set_attribute('srcset', $this->sources->srcset($candidates[0]['source'], $candidates, false));
                $processor->set_attribute('type', 'image/webp');
                if (substr($candidates[0]['descriptor'], -1) === 'w') {
                    $sizes = $processor->get_attribute('sizes');
                    $sizes = is_string($sizes) ? trim($sizes) : '';
                    if (trim((string) preg_replace('/^auto\s*(?:,\s*)?/i', '', $sizes)) === '') {
                        $processor->set_attribute('sizes', ($sizes !== '' ? 'auto, ' : '') . $picture_sizes);
                    }
                }
            }
        }
        $processor->seek($bookmark);
        $processor->release_bookmark($bookmark);
    }

    private function lazy_picture($processor, $bookmark, $depth, array $images)
    {
        $picture_xpath = $processor->get_xpath();
        $valid = !self::js_loaded($processor);
        while ($processor->next_tag(array('tag_closers' => 'visit'))) {
            $tag = $processor->get_tag();
            if ($tag === 'PICTURE' && $processor->is_tag_closer()) { break; }
            if ($processor->get_current_depth() > $depth + 1) { $valid = false; }
            if ($processor->is_tag_closer() || !in_array($tag, array('IMG', 'SOURCE'), true)) { continue; }
            if ($this->lazy_plan($processor) === null) { $valid = false; }
            if ($tag === 'SOURCE') {
                $type = $processor->get_attribute('type');
                if ($type !== null && !in_array(strtolower((string) $type), array('image/jpeg', 'image/png', 'image/webp'), true)) { $valid = false; }
                foreach (array('data-srcset', 'data-lazy-srcset') as $attribute) {
                    $raw = $processor->get_attribute($attribute);
                    if ($raw !== null && !$this->sources->candidates($raw)) { $valid = false; }
                }
            }
        }
        $processor->seek($bookmark);
        if ($valid) {
            while ($processor->next_tag(array('tag_closers' => 'visit'))) {
                $tag = $processor->get_tag();
                if ($tag === 'PICTURE' && $processor->is_tag_closer()) { break; }
                if ($processor->is_tag_closer() || !in_array($tag, array('IMG', 'SOURCE'), true)) { continue; }
                $plan = $this->lazy_plan($processor);
                foreach ($plan as $attribute => $value) { $processor->set_attribute($attribute, $value); }
                if ($tag === 'SOURCE' && $plan) { $processor->set_attribute('type', 'image/webp'); }
            }
        }
        $processor->seek($bookmark);
        $processor->release_bookmark($bookmark);
        $this->skipped[$picture_xpath] = true;
        foreach ($images as $xpath) { $this->skipped[$xpath] = true; }
    }

}
