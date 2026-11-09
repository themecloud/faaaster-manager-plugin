<?php

/** Reuses OD's processor; never rewrites HTML through a second parser/buffer. */
class FaaasterImageVisitor
{
    private $sources;
    private $backgrounds;
    private $skipped = array();

    public function __construct(FaaasterImageSources $sources)
    {
        $this->sources = $sources;
        $this->backgrounds = new FaaasterImageBackgrounds($sources);
    }

    public static function js_loaded($processor)
    {
        foreach (array('data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset', 'data-original', 'data-lazyload', 'data-bg') as $name) {
            if ($processor->get_attribute($name) !== null) {
                return true;
            }
        }
        return false;
    }

    public function skips($processor)
    {
        return self::js_loaded($processor) || isset($this->skipped[$processor->get_xpath()]);
    }

    public function handles_background($processor)
    {
        return $this->backgrounds->handles($processor);
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
        if ($tag !== 'PICTURE' && self::js_loaded($processor)
            && ($tag === 'IMG' || $processor->get_attribute('data-bg') === null)) {
            return false;
        }
        if ($tag === 'PICTURE') {
            $this->picture($processor);
        } elseif ($tag === 'IMG') {
            if (!isset($this->skipped[$processor->get_xpath()])) {
                $this->image($processor);
            }
        } else {
            return $this->backgrounds->rewrite($context);
        }
        // Tracking/prioritization belongs to Image Prioritizer.
        return false;
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
        $bookmark = 'faaaster-picture-start';
        $processor->set_bookmark($bookmark);
        // Validate the whole picture first, to avoid mixed declarations / partial rewrites.
        while ($processor->next_tag(array('tag_closers' => 'visit'))) {
            if ($processor->get_tag() === 'PICTURE' && $processor->is_tag_closer()) {
                break;
            }
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

}
