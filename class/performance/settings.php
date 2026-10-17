<?php

if (!defined('ABSPATH')) {
    exit;
}

/** A payload older than the rules in place (see FaaasterPerformanceSettings::replace). */
class FaaasterPerformanceStaleRevision extends RuntimeException
{
    public $current_revision;

    public function __construct($current_revision)
    {
        $this->current_revision = (int) $current_revision;
        parent::__construct('performance rules revision ' . $this->current_revision . ' is already in place');
    }
}

/**
 * Compiled performance rules. The option is autoloaded so a frontend request
 * adds no SQL query; the control plane keeps the richer business JSON.
 */
class FaaasterPerformanceSettings
{
    const OPTION = 'faaaster_performance_optimizer_v1';
    const SCHEMA = 'faaaster-performance-runtime-v1';
    const MAX_RULES = 200;

    private $cache = null;

    public static function defaults()
    {
        return array(
            'schemaVersion' => self::SCHEMA,
            'revision' => 0,
            'checksum' => hash('sha256', self::canonical_json(array('revision' => 0, 'rules' => array()))),
            'rules' => array(),
            'compiled' => array(
                'unload' => array('style' => array(), 'script' => array()),
                'scriptStrategies' => array(),
                'images' => array(),
                'fonts' => array(),
                'promoteStyles' => array(),
                'preconnects' => array(),
            ),
        );
    }

    public function all()
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, null);
            $this->cache = is_array($stored) ? $stored : self::defaults();
        }
        return $this->cache;
    }

    /**
     * Revisions only move forward: an older payload, or another payload under
     * the revision already in place, comes from a stale or concurrent caller and
     * must never overwrite newer rules. Replaying the same payload is a no-op.
     * The returned array carries `changed` (never stored) so the caller knows
     * whether the rendered pages differ and the page cache must be purged.
     */
    public function replace(array $input)
    {
        $clean = self::compile($input);
        $current = $this->all();
        $current_revision = isset($current['revision']) ? (int) $current['revision'] : 0;
        $current_checksum = isset($current['checksum']) ? (string) $current['checksum'] : '';
        if (
            $clean['revision'] < $current_revision
            || ($clean['revision'] === $current_revision && !hash_equals($current_checksum, $clean['checksum']))
        ) {
            throw new FaaasterPerformanceStaleRevision($current_revision);
        }
        $changed = !hash_equals($current_checksum, $clean['checksum']);
        if ($changed) {
            update_option(self::OPTION, $clean, true);
        }
        $this->cache = $clean;
        return $clean + array('changed' => $changed);
    }

    public function reset_cache()
    {
        $this->cache = null;
    }

    public static function compile(array $input)
    {
        if (($input['schemaVersion'] ?? '') !== self::SCHEMA) {
            throw new InvalidArgumentException('invalid schemaVersion');
        }
        $revision = isset($input['revision']) ? (int) $input['revision'] : -1;
        if ($revision < 0) {
            throw new InvalidArgumentException('invalid revision');
        }
        $rules = isset($input['rules']) && is_array($input['rules']) ? $input['rules'] : null;
        if ($rules === null || count($rules) > self::MAX_RULES) {
            throw new InvalidArgumentException('invalid rules');
        }
        if (strlen(self::canonical_json($rules)) > 512 * 1024) {
            throw new InvalidArgumentException('rules payload too large');
        }
        $checksum = strtolower((string) ($input['checksum'] ?? ''));
        $expected = hash('sha256', self::canonical_json(array('revision' => $revision, 'rules' => $rules)));
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum) || !hash_equals($expected, $checksum)) {
            throw new InvalidArgumentException('invalid checksum');
        }

        $compiled = array(
            'unload' => array('style' => array(), 'script' => array()),
            'scriptStrategies' => array(),
            'images' => array(),
            'fonts' => array(),
            'promoteStyles' => array(),
            'preconnects' => array(),
        );
        $clean_rules = array();
        $seen = array();
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException('invalid rule');
            }
            $id = (string) ($rule['id'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/', $id) || isset($seen[$id])) {
                throw new InvalidArgumentException('invalid rule id');
            }
            $seen[$id] = true;
            $type = (string) ($rule['type'] ?? '');
            $target = self::clean_target($rule);
            $params = isset($rule['params']) && is_array($rule['params']) ? $rule['params'] : array();
            if ($type === 'unload_unused_asset') {
                $asset_type = (string) ($params['assetType'] ?? '');
                if (!in_array($asset_type, array('style', 'script'), true)) {
                    throw new InvalidArgumentException('invalid asset type');
                }
                $handles = self::clean_strings($params['handles'] ?? array(), '/^[A-Za-z0-9_.-]{1,120}$/', 50);
                if (!$handles) {
                    throw new InvalidArgumentException('empty asset handles');
                }
                $clean_params = array('assetType' => $asset_type, 'handles' => $handles);
                foreach ($handles as $handle) {
                    if (!isset($compiled['unload'][$asset_type][$handle])) {
                        $compiled['unload'][$asset_type][$handle] = array();
                    }
                    $compiled['unload'][$asset_type][$handle][] = $target;
                }
            } elseif ($type === 'script_strategy') {
                $handles = self::clean_strings($params['handles'] ?? array(), '/^[A-Za-z0-9_.-]{1,120}$/', 50);
                $strategy = (string) ($params['strategy'] ?? '');
                if (!$handles || !in_array($strategy, array('defer', 'async'), true)) {
                    throw new InvalidArgumentException('invalid script strategy');
                }
                $clean_params = array('handles' => $handles, 'strategy' => $strategy);
                foreach ($handles as $handle) {
                    $compiled['scriptStrategies'][$handle][] = array(
                        'target' => $target,
                        'strategy' => $strategy,
                    );
                }
            } elseif ($type === 'image_delivery') {
                $ids = array();
                foreach ((array) ($params['attachmentIds'] ?? array()) as $attachment_id) {
                    $attachment_id = (int) $attachment_id;
                    if ($attachment_id <= 0) {
                        throw new InvalidArgumentException('invalid attachment id');
                    }
                    $ids[$attachment_id] = true;
                }
                $loading = (string) ($params['loading'] ?? '');
                $priority = (string) ($params['fetchPriority'] ?? '');
                if (!$ids || !in_array($loading, array('lazy', 'eager'), true) || !in_array($priority, array('auto', 'high', 'low'), true)) {
                    throw new InvalidArgumentException('invalid image params');
                }
                $clean_params = array(
                    'attachmentIds' => array_keys($ids),
                    'loading' => $loading,
                    'fetchPriority' => $priority,
                );
                foreach (array_keys($ids) as $attachment_id) {
                    $compiled['images'][(string) $attachment_id][] = array(
                        'target' => $target,
                        'loading' => $loading,
                        'fetchPriority' => $priority,
                    );
                }
            } elseif ($type === 'promote_late_style') {
                $handles = self::clean_strings($params['handles'] ?? array(), '/^[A-Za-z0-9_.-]{1,120}$/', 50);
                if (!$handles) {
                    throw new InvalidArgumentException('empty style handles');
                }
                $clean_params = array('handles' => $handles);
                foreach ($handles as $handle) {
                    $compiled['promoteStyles'][$handle][] = $target;
                }
            } elseif ($type === 'preconnect_origins') {
                $raw_origins = $params['origins'] ?? null;
                if (!is_array($raw_origins) || !$raw_origins || count($raw_origins) > 4) {
                    throw new InvalidArgumentException('invalid preconnect origins');
                }
                $origins = array();
                foreach ($raw_origins as $entry) {
                    if (!is_array($entry) || !isset($entry['crossorigin']) || !is_bool($entry['crossorigin'])) {
                        throw new InvalidArgumentException('invalid preconnect origin');
                    }
                    $origin = self::clean_https_urls(array($entry['origin'] ?? ''), true, 1);
                    $origins[] = array('origin' => $origin[0], 'crossorigin' => $entry['crossorigin']);
                }
                $clean_params = array('origins' => $origins);
                $compiled['preconnects'][] = array('target' => $target, 'origins' => $origins);
            } elseif ($type === 'font_delivery') {
                $clean_params = self::clean_font_params($params);
                $compiled['fonts'][] = array('target' => $target, 'params' => $clean_params);
            } else {
                throw new InvalidArgumentException('invalid rule type');
            }
            $clean_rules[] = array(
                'id' => $id,
                'type' => $type,
                'contexts' => $target['contexts'],
                'paths' => $target['paths'],
                'params' => $clean_params,
            );
        }
        return array(
            'schemaVersion' => self::SCHEMA,
            'revision' => $revision,
            'checksum' => $checksum,
            'rules' => $clean_rules,
            'compiled' => $compiled,
        );
    }

    private static function clean_target(array $rule)
    {
        $contexts = self::clean_strings(
            $rule['contexts'] ?? array(),
            '/^(all|home|front_page|singular|archive|search|404|post_type:[a-z0-9_-]{1,40}|taxonomy:[a-z0-9_-]{1,40}|page:[1-9][0-9]{0,9})$/',
            30
        );
        $paths = array();
        foreach ((array) ($rule['paths'] ?? array()) as $path) {
            $path = trim((string) $path);
            if (!preg_match('~^/[^\s#]{0,499}$~', $path)) {
                throw new InvalidArgumentException('invalid target path');
            }
            $paths[$path] = true;
        }
        if (!$contexts && !$paths) {
            throw new InvalidArgumentException('empty target');
        }
        return array('contexts' => $contexts, 'paths' => array_keys($paths));
    }

    private static function clean_font_params(array $params)
    {
        $urls = self::clean_https_urls($params['preloadUrls'] ?? array(), false, 4);
        $origins = self::clean_https_urls($params['preconnectOrigins'] ?? array(), true, 4);
        $metrics = array();
        foreach (array_slice((array) ($params['metricOverrides'] ?? array()), 0, 4) as $metric) {
            $family = trim((string) ($metric['family'] ?? ''));
            $fallback = trim((string) ($metric['fallback'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $family) || !preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $fallback)) {
                throw new InvalidArgumentException('invalid font metric family');
            }
            $clean = array('family' => $family, 'fallback' => $fallback);
            foreach (array('sizeAdjust', 'ascentOverride', 'descentOverride', 'lineGapOverride') as $key) {
                $value = isset($metric[$key]) ? (float) $metric[$key] : -1;
                if ($value < 0 || $value > 300) {
                    throw new InvalidArgumentException('invalid font metric value');
                }
                $clean[$key] = $value;
            }
            $metrics[] = $clean;
        }
        $swap = self::clean_strings($params['displaySwapHandles'] ?? array(), '/^[A-Za-z0-9_.-]{1,120}$/', 20);
        if (!$urls && !$origins && !$metrics && !$swap) {
            throw new InvalidArgumentException('empty font params');
        }
        return array(
            'preloadUrls' => $urls,
            'preconnectOrigins' => $origins,
            'metricOverrides' => $metrics,
            'displaySwapHandles' => $swap,
        );
    }

    private static function clean_strings($values, $pattern, $max)
    {
        if (!is_array($values) || count($values) > $max) {
            throw new InvalidArgumentException('invalid list');
        }
        $clean = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            if (!preg_match($pattern, $value)) {
                throw new InvalidArgumentException('invalid list value');
            }
            $clean[$value] = true;
        }
        return array_keys($clean);
    }

    private static function clean_https_urls($values, $origin_only, $max)
    {
        if (!is_array($values) || count($values) > $max) {
            throw new InvalidArgumentException('invalid url list');
        }
        $clean = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($origin_only) {
                $value = rtrim($value, '/');
            }
            $parts = wp_parse_url($value);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException('invalid https url');
            }
            if ($origin_only && (!empty($parts['path']) || !empty($parts['query']) || !empty($parts['fragment']))) {
                throw new InvalidArgumentException('invalid origin');
            }
            $clean[$value] = true;
        }
        return array_keys($clean);
    }

    private static function canonical_json($value)
    {
        return json_encode(self::canonical_value($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonical_value($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        $is_list = $value === array() || $keys === range(0, count($value) - 1);
        if ($is_list) {
            return array_map(array(__CLASS__, 'canonical_value'), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical_value($item);
        }
        return $value;
    }
}
