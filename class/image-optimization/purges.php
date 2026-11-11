<?php

/** Coalesces semantic changes using OD's existing ten-minute invalidation event. */
class FaaasterImagePurges
{
    const META = '_faaaster_image_decisions';
    const PENDING = '_faaaster_image_pending';
    const LAST = '_faaaster_image_purged';
    const INTERVAL = 600;
    const MAX_ATTEMPTS = 5;
    private static $lock_failed = false;

    public static function fingerprint($collection, $url_metrics_id = 0)
    {
        if (FaaasterImageOptimization::parser_revision()) {
            $collection = FaaasterImageOptimization::decision_metrics($collection);
        }
        $dimensions = $url_metrics_id ? get_post_meta($url_metrics_id, FaaasterImageBackgrounds::SOURCES_META, true) : array();
        $groups = array();
        foreach ($collection as $group) {
            $lcp = $group->get_lcp_element();
            $external = $lcp ? $lcp->get_url_metric()->get('lcpElementExternalBackgroundImage') : null;
            if (is_array($external) && isset($external['url'])) {
                $source_key = FaaasterImageSources::variant_key($external['url']);
                if ($source_key) { $external['url'] = $source_key; }
            }
            $groups[] = array($group->get_minimum_viewport_width(), $group->get_maximum_viewport_width(),
                $lcp ? $lcp->get_xpath() : null,
                $external);
        }
        $visible = array_map(function ($ratio) { return $ratio > 0; }, $collection->get_all_element_max_intersection_ratios());
        $positioned = $collection->get_all_elements_positioned_in_any_initial_viewport();
        ksort($visible);
        ksort($positioned);
        // Count matters only for the mobile+desktop population gate, not sample totals/noisy geometry.
        return hash('sha256', wp_json_encode(array(
            'groups' => $groups, 'visible' => $visible, 'positioned' => $positioned,
            'ready' => $collection->get_first_group()->count() > 0 && $collection->get_last_group()->count() > 0,
            'css_lcp' => FaaasterImageOptimization::feature_enabled('css_lcp')
                ? FaaasterImageBackgrounds::css_lcp_decisions($collection, is_array($dimensions) ? $dimensions : array(), FaaasterImageOptimization::resize_widths()) : array(),
            'background_widths' => FaaasterImageOptimization::feature_enabled('inline_bg')
                ? FaaasterImageBackgrounds::decisions($collection, is_array($dimensions) ? $dimensions : array(), FaaasterImageOptimization::resize_widths()) : array(),
        )));
    }

    /** Injection failures and CSS coverage share one durable snapshot and SQL budget. */
    public static function record_detection_failure()
    {
        self::record_counters(1, array());
    }

    public static function record_css_lcp_unmatched(array $increments)
    {
        self::record_counters(0, $increments);
    }

    private static function record_counters($injection_increment, array $increments)
    {
        if (FaaasterImageOptimization::is_cli()) { return; }
        $lock = null;
        try {
            $lock = self::lock(0);
            $data = FaaasterImageOptimization::read_detection_counter();
            if ($data === null) {
                wp_cache_delete(FaaasterImageOptimization::DETECTION_FAILURES_OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
                $data = FaaasterImageOptimization::counter_totals(get_option(FaaasterImageOptimization::DETECTION_FAILURES_OPTION, 0));
                $data['next_flush'] = 0;
            }
            $data['count'] += $injection_increment;
            foreach ($increments as $reason => $increment) {
                if (!in_array($reason, FaaasterImageOptimization::CSS_LCP_REASONS, true) || !is_int($increment) || $increment < 1) {
                    throw new RuntimeException('Invalid image CSS coverage increment.');
                }
                $data['css_lcp_unmatched'][$reason] += $increment;
            }
            $checkpoint = time() >= $data['next_flush'];
            if ($checkpoint) { $data['next_flush'] = time() + 60; }
            // Persist both the increments and the shared SQL budget before database I/O.
            self::write_detection_counter($data);
            if ($checkpoint) {
                wp_cache_delete(FaaasterImageOptimization::DETECTION_FAILURES_OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
                $totals = array('count' => $data['count'], 'css_lcp_unmatched' => $data['css_lcp_unmatched']);
                if (!update_option(FaaasterImageOptimization::DETECTION_FAILURES_OPTION, $totals, false)
                    && get_option(FaaasterImageOptimization::DETECTION_FAILURES_OPTION, 0) !== $totals) {
                    throw new RuntimeException('Unable to checkpoint image detection counters.');
                }
            }
        } catch (\Throwable $e) {
            FaaasterCache::log_exception('image-optimization:detection-counter', $e);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function write_detection_counter(array $data)
    {
        $path = FaaasterImageOptimization::detection_counter_path();
        $temporary = @tempnam(dirname($path), '.detection-');
        if (!$temporary) { throw new RuntimeException('Unable to create image detection counter.'); }
        try {
            $json = wp_json_encode($data);
            if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !@chmod($temporary, 0644) || !@rename($temporary, $path)) {
                throw new RuntimeException('Unable to persist image detection counter.');
            }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    public static function stored($context)
    {
        if (FaaasterImageOptimization::is_cli()) {
            return;
        }
        // Old cached HTML may still submit its old ETag. Store it normally, but never
        // use those samples to request new XPath-based cache decisions.
        if (FaaasterImageOptimization::parser_revision()
            && $context->url_metric->get(FaaasterImageOptimization::PARSER_METRIC_PROPERTY) !== FaaasterImageOptimization::parser_revision()) {
            return;
        }
        $id = (int) $context->url_metrics_id;
        $url = FaaasterImageOptimization::purge_url($context->url_metric->get_url());
        if (!FaaasterImageOptimization::local_page($url)) {
            return;
        }
        $post_id = (int) $context->request->get_param('cache_purge_post_id');
        if (!$post_id) {
            return;
        }
        try {
            // Held only for postmeta I/O: purges run outside this lock (see flush()).
            self::locked($post_id, function () use ($post_id, $id, $url, $context) {
                // Another PHP worker may have written while this request waited for the lock.
                wp_cache_delete($post_id, 'post_meta');
                wp_cache_delete($id, 'post_meta');
                $fingerprint = self::fingerprint($context->url_metric_group_collection, $id);
                $pending = self::pending($post_id);
                if (get_post_meta($id, self::META, true) !== $fingerprint) {
                    // Persist the work before marking the decision as seen.
                    $pending[$id] = array('url' => $url, 'attempts' => 0, 'next_attempt' => 0);
                    if (update_post_meta($post_id, self::PENDING, $pending) !== false
                        || get_post_meta($post_id, self::PENDING, true) === $pending) {
                        update_post_meta($id, self::META, $fingerprint);
                    }
                }
                self::schedule_pending($post_id, $pending);
            });
        } catch (\Throwable $e) {
            // The sample is already stored: a lock or postmeta error must not become a REST 500.
            FaaasterCache::log_exception('image-optimization:decision', $e);
        }
    }

    public static function flush($post_id)
    {
        if (FaaasterImageOptimization::is_cli()) {
            // WP-CLI cron has no FPM configuration: leave the purge to the next HTTP cron run.
            FaaasterImageOptimization::defer_cli_invalidation($post_id);
            return;
        }
        $post_id = (int) $post_id;
        self::$lock_failed = false;
        try {
            // 1. Claim due entries under the lock; their attempt is persisted before any I/O.
            $batch = self::locked($post_id, function () use ($post_id) { return self::claim($post_id); });
            if (!$batch) {
                return;
            }
            // 2. Purge outside the lock: nginx (3 s budget) then Cloudflare must never hold up
            //    the anonymous store requests that wait for this post's lock.
            $result = self::send($batch);
            // 3. Settle on a fresh read: a decision stored meanwhile stays pending.
            self::locked($post_id, function () use ($post_id, $batch, $result) { self::settle($post_id, $batch, $result); });
        } catch (\Throwable $e) {
            // Cron must not die on a lock or transport error. Work stays in postmeta.
            self::record_problem(self::$lock_failed ? 'purge_lock_unavailable' : 'purge_exception', $post_id);
            FaaasterCache::log_exception('image-optimization:purge', $e);
        }
    }

    private static function locked($post_id, callable $callback)
    {
        $lock = self::lock($post_id);
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Due entries of one post, attempts persisted; null when nothing is due. */
    private static function claim($post_id)
    {
        wp_cache_delete($post_id, 'post_meta');
        $pending = self::pending($post_id);
        if (!$pending) {
            return null;
        }
        $eligible = array();
        foreach ($pending as $id => $entry) {
            if (!is_array($entry) || empty($entry['url']) || !FaaasterImageOptimization::local_page($entry['url'])) {
                unset($pending[$id]);
                continue;
            }
            $attempts = isset($entry['attempts']) ? (int) $entry['attempts'] : 0;
            if ($attempts >= self::MAX_ATTEMPTS) {
                continue;
            }
            wp_cache_delete($id, 'post_meta');
            $last = (int) get_post_meta($id, self::LAST, true);
            $due = max($last + self::INTERVAL, isset($entry['next_attempt']) ? (int) $entry['next_attempt'] : 0);
            if ($due > time()) {
                continue;
            }
            // Persist the attempt BEFORE any I/O, including an interrupted worker.
            $pending[$id]['attempts'] = $attempts + 1;
            $pending[$id]['next_attempt'] = time() + self::INTERVAL;
            $eligible[$id] = $pending[$id];
        }
        if (update_post_meta($post_id, self::PENDING, $pending) === false
            && get_post_meta($post_id, self::PENDING, true) !== $pending) {
            throw new RuntimeException('Unable to persist image purge attempts.');
        }
        self::schedule_pending($post_id, $pending);
        if (!$eligible) {
            return null;
        }
        if (!faaaster_cache()) {
            self::record_problem('cache_module_unavailable', $post_id);
            return null;
        }
        return $eligible;
    }

    /** Lock-free: enqueue the claimed URLs and purge them now (nginx, then Cloudflare). */
    private static function send(array $batch)
    {
        $cache = faaaster_cache();
        $queued = array();
        foreach ($batch as $id => $entry) {
            if ($cache && $cache->queue()->enqueue_url($entry['url'], 'image-optimization')) {
                $queued[] = $id;
            }
        }
        $report = $queued ? $cache->queue()->flush(false) : array();
        return array('queued' => $queued, 'confirmed' => !empty($report['confirmed']));
    }

    private static function settle($post_id, array $batch, array $result)
    {
        wp_cache_delete($post_id, 'post_meta');
        $pending = self::pending($post_id);
        if ($result['confirmed']) {
            foreach ($result['queued'] as $id) {
                update_post_meta($id, self::LAST, time());
                // stored() resets an entry for a new decision: that one still needs its purge.
                if (isset($pending[$id]) && $pending[$id] == $batch[$id]) {
                    unset($pending[$id]);
                }
            }
            $previous_error = get_option(FaaasterImageOptimization::PURGE_ERROR_OPTION, array());
            if (is_array($previous_error) && isset($previous_error['post_id']) && $previous_error['post_id'] === $post_id) {
                delete_option(FaaasterImageOptimization::PURGE_ERROR_OPTION);
            }
        } else {
            $exhausted = false;
            foreach ($batch as $entry) {
                $exhausted = $exhausted || $entry['attempts'] >= self::MAX_ATTEMPTS;
            }
            self::record_problem($exhausted ? 'purge_retry_exhausted' : 'purge_unconfirmed', $post_id);
        }
        update_post_meta($post_id, self::PENDING, $pending);
        self::schedule_pending($post_id, $pending);
    }

    private static function record_problem($reason, $post_id)
    {
        update_option(FaaasterImageOptimization::PURGE_ERROR_OPTION, array(
            'reason' => $reason, 'post_id' => $post_id, 'observed_at' => time(),
        ), false);
    }

    private static function schedule_pending($post_id, array $pending)
    {
        $next = null;
        foreach ($pending as $id => $entry) {
            if (isset($entry['attempts']) && $entry['attempts'] >= self::MAX_ATTEMPTS) {
                continue;
            }
            $due = max(time() + self::INTERVAL, (int) get_post_meta($id, self::LAST, true) + self::INTERVAL,
                isset($entry['next_attempt']) ? (int) $entry['next_attempt'] : 0);
            $next = $next === null ? $due : min($next, $due);
        }
        if ($next !== null) {
            self::schedule($post_id, $next);
        } else {
            // No loop once exhausted. Pending data is kept until a new decision resets its budget.
            wp_clear_scheduled_hook('od_trigger_page_cache_invalidation', array((int) $post_id));
        }
    }

    private static function pending($post_id)
    {
        $pending = get_post_meta($post_id, self::PENDING, true);
        return is_array($pending) ? $pending : array();
    }

    private static function schedule($post_id, $time)
    {
        $args = array((int) $post_id);
        if (!wp_next_scheduled('od_trigger_page_cache_invalidation', $args)) {
            wp_schedule_single_event($time, 'od_trigger_page_cache_invalidation', $args);
        }
    }

    /** Shared-volume lock scoped to one WP post; independent of the image URL whitelist. */
    private static function lock($post_id)
    {
        self::$lock_failed = true;
        $directory = WP_CONTENT_DIR . '/cache/faaaster-image-optimization';
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new RuntimeException('Unable to create image purge lock directory.');
        }
        $path = $directory . '/.purges-' . get_current_blog_id() . '-' . (int) $post_id . '.lock';
        $lock = @fopen($path, 'c');
        if (!$lock) {
            throw new RuntimeException('Unable to open image purge lock.');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Unable to acquire image purge lock.');
        }
        self::$lock_failed = false;
        return $lock;
    }
}
