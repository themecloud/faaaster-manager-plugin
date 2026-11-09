<?php

/** Coalesces semantic changes using OD's existing ten-minute invalidation event. */
class FaaasterImagePurges
{
    const META = '_faaaster_image_decisions';
    const PENDING = '_faaaster_image_pending';
    const LAST = '_faaaster_image_purged';
    const INTERVAL = 600;
    const MAX_ATTEMPTS = 5;

    public static function fingerprint($collection, $url_metrics_id = 0)
    {
        $dimensions = $url_metrics_id ? get_post_meta($url_metrics_id, FaaasterImageBackgrounds::SOURCES_META, true) : array();
        $groups = array();
        foreach ($collection as $group) {
            $lcp = $group->get_lcp_element();
            $groups[] = array($group->get_minimum_viewport_width(), $group->get_maximum_viewport_width(),
                $lcp ? $lcp->get_xpath() : null,
                $lcp ? $lcp->get_url_metric()->get('lcpElementExternalBackgroundImage') : null);
        }
        $visible = array_map(function ($ratio) { return $ratio > 0; }, $collection->get_all_element_max_intersection_ratios());
        $positioned = $collection->get_all_elements_positioned_in_any_initial_viewport();
        ksort($visible);
        ksort($positioned);
        // Count matters only for the mobile+desktop population gate, not sample totals/noisy geometry.
        return hash('sha256', wp_json_encode(array(
            'groups' => $groups, 'visible' => $visible, 'positioned' => $positioned,
            'ready' => $collection->get_first_group()->count() > 0 && $collection->get_last_group()->count() > 0,
            'background_widths' => FaaasterImageBackgrounds::decisions($collection, is_array($dimensions) ? $dimensions : array(), FaaasterImageOptimization::resize_widths()),
        )));
    }

    public static function stored($context)
    {
        if (FaaasterImageOptimization::is_cli()) {
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
        $lock = self::lock($post_id);
        try {
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
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function flush($post_id)
    {
        if (FaaasterImageOptimization::is_cli()) {
            return;
        }
        $post_id = (int) $post_id;
        $lock = null;
        try {
            $lock = self::lock($post_id);
            self::flush_locked($post_id);
        } catch (\Throwable $e) {
            // Cron must not die on a lock or transport error. Work stays in postmeta.
            self::record_problem(is_resource($lock) ? 'purge_exception' : 'purge_lock_unavailable', $post_id);
            FaaasterCache::log_exception('image-optimization:purge', $e);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function flush_locked($post_id)
    {
        wp_cache_delete($post_id, 'post_meta');
        $pending = self::pending($post_id);
        if (!$pending) {
            return;
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
            $eligible[] = $id;
        }
        if (update_post_meta($post_id, self::PENDING, $pending) === false
            && get_post_meta($post_id, self::PENDING, true) !== $pending) {
            throw new RuntimeException('Unable to persist image purge attempts.');
        }
        self::schedule_pending($post_id, $pending);
        $cache = faaaster_cache();
        if (!$eligible || !$cache) {
            if ($eligible && !$cache) {
                self::record_problem('cache_module_unavailable', $post_id);
            }
            return;
        }
        $queued = array();
        foreach ($eligible as $id) {
            if ($cache->queue()->enqueue_url($pending[$id]['url'], 'image-optimization')) {
                $queued[] = $id;
            }
        }
        $report = $queued ? $cache->queue()->flush(false) : array();
        if (!empty($report['confirmed'])) {
            foreach ($queued as $id) {
                update_post_meta($id, self::LAST, time());
                unset($pending[$id]);
            }
            $previous_error = get_option(FaaasterImageOptimization::PURGE_ERROR_OPTION, array());
            if (is_array($previous_error) && isset($previous_error['post_id']) && $previous_error['post_id'] === $post_id) {
                delete_option(FaaasterImageOptimization::PURGE_ERROR_OPTION);
            }
        } else {
            $exhausted = false;
            foreach ($eligible as $id) {
                $exhausted = $exhausted || $pending[$id]['attempts'] >= self::MAX_ATTEMPTS;
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
        return $lock;
    }
}
