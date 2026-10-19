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
    /**
     * Rule sets carrying a `when` condition or a rendering rule: an older
     * plugin refuses them, it never applies a rule without its condition.
     */
    const SCHEMA_V2 = 'faaaster-performance-runtime-v2';
    const V2_TYPES = array('early_layout_rules', 'drop_preloads', 'image_priority', 'defer_backgrounds');
    const MAX_RULES = 200;
    /** content conditions of a rule (`unless`) */
    const MAX_PREDICATES = 10;
    const MAX_PREDICATE_POST_IDS = 200;
    const MAX_KEPT_FONT_FACES = 40;

    /** value of each content condition but `post_ids` (a list of ids) */
    const PREDICATE_PATTERNS = array(
        'has_block' => '/^[a-z0-9-]+\/[a-z0-9-]+$/',
        'has_shortcode' => '/^[A-Za-z0-9_-]{1,60}$/',
        'active_widget' => '/^[a-z0-9_-]{1,80}$/',
        'product_type' => '/^[a-z0-9_-]{1,40}$/',
        'elementor_widget' => '/^[a-z0-9_-]{1,60}$/',
    );

    /** `when`: a tree of all/any/not over typed atoms (see clean_when) */
    const MAX_WHEN_DEPTH = 4;
    const MAX_WHEN_ATOMS = 16;
    const MAX_WHEN_NODES = 16;
    /** parameters of each `when` atom */
    const WHEN_ATOMS = array(
        'front_page' => array(),
        'home' => array(),
        'search' => array(),
        'not_found' => array(),
        'logged_in' => array(),
        'singular' => array('postTypes'),
        'archive' => array('postTypes'),
        'taxonomy' => array('taxonomy', 'terms'),
        'page' => array('ids', 'slugs'),
        'conditional' => array('name'),
        'has_block' => array('name'),
        'has_shortcode' => array('tag'),
        'elementor_widget' => array('name'),
        'widget' => array('idBase'),
        'has_term' => array('taxonomy', 'terms'),
        'product_type' => array('types'),
        'template' => array('slugs'),
        'path' => array('prefix', 'exact'),
    );
    const NAME_PATTERN = '/^[a-z0-9_-]{1,40}$/';
    /** slugs as stored (the inventory reports them raw): only compared, never printed */
    const SLUG_PATTERN = '~^[^\s/]{1,200}$~u';
    /** a page slug or a hierarchical page path (parent/child) */
    const PAGE_SLUG_PATTERN = '~^(?=.{1,400}$)[^\s/]+(?:/[^\s/]+)*$~u';
    const TEMPLATE_PATTERN = '~^[A-Za-z0-9_./-]{1,200}$~';

    /** properties an early layout rule may declare (core block supports) */
    const LAYOUT_PROPERTIES = array(
        'display', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content', 'gap', 'row-gap', 'column-gap',
        'grid-template-columns', 'grid-template-rows', 'grid-auto-flow', 'grid-auto-rows', 'grid-auto-columns',
        'container-type', 'max-width', 'margin-left', 'margin-right', 'padding-left', 'padding-right',
        'flex-basis', 'flex-grow', 'flex-shrink', 'width',
    );
    /** tag, classes and ids, up to 4 compounds joined by a descendant space */
    const BACKGROUND_SELECTOR = '/^(?:[A-Za-z][A-Za-z0-9-]*(?:[.#]-?[A-Za-z_][A-Za-z0-9_-]*)*|(?:[.#]-?[A-Za-z_][A-Za-z0-9_-]*)+)(?: (?:[A-Za-z][A-Za-z0-9-]*(?:[.#]-?[A-Za-z_][A-Za-z0-9_-]*)*|(?:[.#]-?[A-Za-z_][A-Za-z0-9_-]*)+)){0,3}$/';

    private $cache = null;

    public static function defaults()
    {
        return array(
            'schemaVersion' => self::SCHEMA,
            'revision' => 0,
            'checksum' => hash('sha256', self::canonical_json(array('revision' => 0, 'rules' => array()))),
            'rules' => array(),
            'compiled' => self::empty_compiled(),
        );
    }

    private static function empty_compiled()
    {
        return array(
            'unload' => array('style' => array(), 'script' => array()),
            'scriptStrategies' => array(),
            'images' => array(),
            'fonts' => array(),
            'promoteStyles' => array(),
            'preconnects' => array(),
            'wcLoopImages' => array(),
            'wcGallery' => array(),
            'preloadImages' => array(),
            'menuImagesLazy' => array(),
            'fontVariants' => array(),
            'earlyLayout' => array(),
            'dropPreloads' => array(),
            'imagePriority' => array(),
            'deferBackgrounds' => array(),
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
     * must never overwrite newer rules. Replaying the same payload is a no-op,
     * unless a font copy had to be written again.
     * The returned array carries `changed` (never stored) so the caller knows
     * whether the rendered pages differ and the page cache must be purged.
     */
    public function replace(array $input, $prepare = null)
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
        // data derived from the compiled set before it is stored (the font
        // copies, written by the REST handler only); a replay refreshes it:
        // a copy made stale by a theme update is written again
        if ($prepare !== null) {
            $clean['compiled'] = call_user_func($prepare, $clean['compiled']);
        }
        $changed = !hash_equals($current_checksum, $clean['checksum'])
            || ($current['compiled']['fontVariants'] ?? array()) !== $clean['compiled']['fontVariants'];
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
        $schema = $input['schemaVersion'] ?? '';
        if ($schema !== self::SCHEMA && $schema !== self::SCHEMA_V2) {
            throw new InvalidArgumentException('invalid schemaVersion');
        }
        $v2 = $schema === self::SCHEMA_V2;
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
        $expected = hash('sha256', self::canonical_json(array('revision' => $revision, 'rules' => self::checksum_rules($rules))));
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum) || !hash_equals($expected, $checksum)) {
            throw new InvalidArgumentException('invalid checksum');
        }

        $compiled = self::empty_compiled();
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
            if (!$v2 && (array_key_exists('when', $rule) || in_array($type, self::V2_TYPES, true))) {
                throw new InvalidArgumentException('rule ' . $id . ' requires ' . self::SCHEMA_V2);
            }
            $target = self::clean_target($rule, $id);
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
            } elseif ($type === 'wc_loop_images') {
                $eager = self::clean_int($params['eager'] ?? null, 1, 12);
                $high = self::clean_int($params['highPriority'] ?? null, 0, 4);
                if ($high > $eager) {
                    throw new InvalidArgumentException('invalid product grid params');
                }
                $clean_params = array('eager' => $eager, 'highPriority' => $high);
                $compiled['wcLoopImages'][] = array('target' => $target) + $clean_params;
            } elseif ($type === 'wc_gallery_images') {
                $main = $params['mainHighPriority'] ?? null;
                $thumbnails = $params['thumbnailsLazy'] ?? null;
                if (!is_bool($main) || !is_bool($thumbnails) || (!$main && !$thumbnails)) {
                    throw new InvalidArgumentException('invalid product gallery params');
                }
                $clean_params = array('mainHighPriority' => $main, 'thumbnailsLazy' => $thumbnails);
                $compiled['wcGallery'][] = array('target' => $target) + $clean_params;
            } elseif ($type === 'preload_image') {
                $clean_params = self::clean_preload_image($params);
                $compiled['preloadImages'][] = array('target' => $target) + $clean_params;
            } elseif ($type === 'menu_images_lazy') {
                $clean_params = array();
                $compiled['menuImagesLazy'][] = $target;
            } elseif ($type === 'font_variants') {
                $clean_params = self::clean_font_variants($params);
                // the copy itself is written by the REST handlers (FaaasterPerformanceFontFaces::generate)
                $compiled['fontVariants'][$clean_params['handle']][] = array(
                    'target' => $target,
                    'src' => $clean_params['src'],
                    'keep' => self::font_keys($clean_params['keep']),
                );
            } elseif ($type === 'early_layout_rules') {
                $clean_params = self::clean_early_layout($params);
                $compiled['earlyLayout'][] = array('target' => $target) + $clean_params;
            } elseif ($type === 'drop_preloads') {
                $cross = $params['crossOrigin'] ?? null;
                $without_high = $params['withoutHighPriority'] ?? null;
                if (!is_bool($cross) || !is_bool($without_high) || (!$cross && !$without_high)) {
                    throw new InvalidArgumentException('invalid drop preloads params');
                }
                $clean_params = array('crossOrigin' => $cross, 'withoutHighPriority' => $without_high);
                $compiled['dropPreloads'][] = array('target' => $target) + $clean_params;
            } elseif ($type === 'image_priority') {
                $clean_params = self::clean_image_priority($params);
                $compiled['imagePriority'][] = array('target' => $target) + $clean_params;
            } elseif ($type === 'defer_backgrounds') {
                $clean_params = self::clean_defer_backgrounds($params);
                $compiled['deferBackgrounds'][] = array('target' => $target) + $clean_params;
            } else {
                throw new InvalidArgumentException('invalid rule type');
            }
            $clean_rule = array(
                'id' => $id,
                'type' => $type,
                'contexts' => $target['contexts'],
                'paths' => $target['paths'],
            );
            if (isset($target['unless'])) {
                $clean_rule['unless'] = $target['unless'];
            }
            if (isset($target['when'])) {
                $clean_rule['when'] = self::unkeyed_when($target['when']);
            }
            $clean_rule['params'] = $clean_params;
            $clean_rules[] = $clean_rule;
        }
        return array(
            'schemaVersion' => $schema,
            'revision' => $revision,
            'checksum' => $checksum,
            'rules' => $clean_rules,
            'compiled' => $compiled,
        );
    }

    /**
     * The target keeps the rule id: a preview receipt names the rules a
     * content condition withheld on the page (`skipped`) and gives the
     * verdict of each rule in scope (`conditions`).
     */
    private static function clean_target(array $rule, $id)
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
        $target = array('contexts' => $contexts, 'paths' => array_keys($paths), 'rule' => (string) $id);
        $unless = self::clean_unless($rule['unless'] ?? null);
        if ($unless) {
            $target['unless'] = $unless;
        }
        if (array_key_exists('when', $rule)) {
            $atoms = 0;
            $target['when'] = self::keyed_when(self::clean_when_node($rule['when'], 1, $atoms));
        }
        return $target;
    }

    /**
     * A `when` node: `{"all":[…]}`, `{"any":[…]}`, `{"not":…}` or a typed
     * atom; depth 4 at most (root included), 16 atoms, lists of 1 to 16.
     * Anything else refuses the whole set: never a condition half understood.
     */
    private static function clean_when_node($node, $depth, &$atoms)
    {
        if ($depth > self::MAX_WHEN_DEPTH || !is_array($node) || self::is_list($node)) {
            throw new InvalidArgumentException('invalid when node');
        }
        foreach (array('all', 'any') as $operator) {
            if (!array_key_exists($operator, $node)) {
                continue;
            }
            $children = $node[$operator];
            if (count($node) !== 1 || !is_array($children) || !$children || !self::is_list($children) || count($children) > self::MAX_WHEN_NODES) {
                throw new InvalidArgumentException('invalid when ' . $operator);
            }
            $clean = array();
            foreach ($children as $child) {
                $clean[] = self::clean_when_node($child, $depth + 1, $atoms);
            }
            return array($operator => $clean);
        }
        if (array_key_exists('not', $node)) {
            if (count($node) !== 1) {
                throw new InvalidArgumentException('invalid when not');
            }
            return array('not' => self::clean_when_node($node['not'], $depth + 1, $atoms));
        }
        if (++$atoms > self::MAX_WHEN_ATOMS) {
            throw new InvalidArgumentException('too many when conditions');
        }
        return self::clean_when_atom($node);
    }

    /** One atom of the table, its parameters cleaned, nothing else kept. */
    private static function clean_when_atom(array $node)
    {
        $kind = isset($node['kind']) && is_string($node['kind']) ? $node['kind'] : '';
        if (!isset(self::WHEN_ATOMS[$kind])) {
            throw new InvalidArgumentException('invalid when kind');
        }
        foreach (array_keys($node) as $key) {
            if ($key !== 'kind' && !in_array($key, self::WHEN_ATOMS[$kind], true)) {
                throw new InvalidArgumentException('invalid when ' . $kind . ' parameter');
            }
        }
        $atom = array('kind' => $kind);
        if ($kind === 'singular' || $kind === 'archive') {
            $types = self::when_list($node, 'postTypes', self::NAME_PATTERN, 0, 10);
            if ($types) {
                $atom['postTypes'] = $types;
            }
        } elseif ($kind === 'taxonomy') {
            $atom['taxonomy'] = self::when_string($node, 'taxonomy', self::NAME_PATTERN);
            $terms = self::when_list($node, 'terms', self::SLUG_PATTERN, 0, 50);
            if ($terms) {
                $atom['terms'] = $terms;
            }
        } elseif ($kind === 'page') {
            $ids = self::when_ids($node, 'ids', 200);
            $slugs = self::when_list($node, 'slugs', self::PAGE_SLUG_PATTERN, 0, 200);
            if (!$ids && !$slugs) {
                throw new InvalidArgumentException('invalid when page');
            }
            if ($ids) {
                $atom['ids'] = $ids;
            }
            if ($slugs) {
                $atom['slugs'] = $slugs;
            }
        } elseif ($kind === 'conditional') {
            $atom['name'] = self::when_string($node, 'name', FaaasterPerformanceConditionals::PATTERN);
        } elseif ($kind === 'has_block' || $kind === 'elementor_widget') {
            $atom['name'] = self::when_string($node, 'name', self::PREDICATE_PATTERNS[$kind]);
        } elseif ($kind === 'has_shortcode') {
            $atom['tag'] = self::when_string($node, 'tag', self::PREDICATE_PATTERNS['has_shortcode']);
        } elseif ($kind === 'widget') {
            $atom['idBase'] = self::when_string($node, 'idBase', self::PREDICATE_PATTERNS['active_widget']);
        } elseif ($kind === 'has_term') {
            $atom['taxonomy'] = self::when_string($node, 'taxonomy', self::NAME_PATTERN);
            $atom['terms'] = self::when_list($node, 'terms', self::SLUG_PATTERN, 1, 50);
        } elseif ($kind === 'product_type') {
            $atom['types'] = self::when_list($node, 'types', self::PREDICATE_PATTERNS['product_type'], 1, 10);
        } elseif ($kind === 'template') {
            $atom['slugs'] = self::when_list($node, 'slugs', self::TEMPLATE_PATTERN, 1, 20);
        } elseif ($kind === 'path') {
            // one of them: a request path matches a prefix or a list, never both
            if (array_key_exists('prefix', $node) === array_key_exists('exact', $node)) {
                throw new InvalidArgumentException('invalid when path');
            }
            if (array_key_exists('prefix', $node)) {
                $atom['prefix'] = self::when_string($node, 'prefix', '~^/[^\s#?]{0,199}$~');
            } else {
                $atom['exact'] = self::when_list($node, 'exact', '~^/[^\s#?]{0,499}$~', 1, 50);
            }
        }
        return $atom;
    }

    private static function when_string(array $node, $key, $pattern)
    {
        $value = $node[$key] ?? null;
        if (!is_string($value) || !preg_match($pattern, $value)) {
            throw new InvalidArgumentException('invalid when ' . $node['kind'] . ' ' . $key);
        }
        return $value;
    }

    /** A list of strings, deduplicated in order; absent is an empty list. */
    private static function when_list(array $node, $key, $pattern, $min, $max)
    {
        if (!array_key_exists($key, $node)) {
            if ($min > 0) {
                throw new InvalidArgumentException('invalid when ' . $node['kind'] . ' ' . $key);
            }
            return array();
        }
        $values = $node[$key];
        if (!is_array($values) || !self::is_list($values) || count($values) < $min || count($values) > $max) {
            throw new InvalidArgumentException('invalid when ' . $node['kind'] . ' ' . $key);
        }
        $clean = array();
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match($pattern, $value)) {
                throw new InvalidArgumentException('invalid when ' . $node['kind'] . ' ' . $key);
            }
            $clean[$value] = true;
        }
        return array_map('strval', array_keys($clean));
    }

    private static function when_ids(array $node, $key, $max)
    {
        if (!array_key_exists($key, $node)) {
            return array();
        }
        $values = $node[$key];
        if (!is_array($values) || !self::is_list($values) || count($values) > $max) {
            throw new InvalidArgumentException('invalid when ' . $node['kind'] . ' ' . $key);
        }
        $ids = array();
        foreach ($values as $value) {
            $value = is_string($value) && ctype_digit($value) ? (int) $value : $value;
            if (!is_int($value) || $value <= 0) {
                throw new InvalidArgumentException('invalid when ' . $node['kind'] . ' ' . $key);
            }
            $ids[$value] = true;
        }
        return array_keys($ids);
    }

    /**
     * The compiled tree: each atom carries its canonical JSON (`key`), the
     * per-request memo of the runtime, computed once here.
     */
    private static function keyed_when(array $node)
    {
        foreach (array('all', 'any') as $operator) {
            if (isset($node[$operator])) {
                return array($operator => array_map(array(__CLASS__, 'keyed_when'), $node[$operator]));
            }
        }
        if (isset($node['not'])) {
            return array('not' => self::keyed_when($node['not']));
        }
        return $node + array('key' => self::canonical_json($node));
    }

    private static function unkeyed_when(array $node)
    {
        foreach (array('all', 'any') as $operator) {
            if (isset($node[$operator])) {
                return array($operator => array_map(array(__CLASS__, 'unkeyed_when'), $node[$operator]));
            }
        }
        if (isset($node['not'])) {
            return array('not' => self::unkeyed_when($node['not']));
        }
        unset($node['key']);
        return $node;
    }

    private static function is_list(array $value)
    {
        return $value === array() || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * The `.wp-container-*` rules WordPress prints at the end of the page
     * (core-block-supports), printed in the <head> instead: layout properties
     * only, plain values (no `!`, url(), comment, brace or quote).
     */
    private static function clean_early_layout(array $params)
    {
        $raw = $params['rules'] ?? null;
        if (!is_array($raw) || !$raw || !self::is_list($raw) || count($raw) > 40) {
            throw new InvalidArgumentException('invalid early layout rules');
        }
        $rules = array();
        foreach ($raw as $rule) {
            $selector = is_array($rule) && isset($rule['selector']) && is_string($rule['selector']) ? $rule['selector'] : '';
            if (!preg_match('/^\.wp-container-[a-z0-9-]{1,100}$/', $selector)) {
                throw new InvalidArgumentException('invalid early layout selector');
            }
            $declarations = self::clean_layout_declarations($rule['declarations'] ?? null);
            if (!isset($rules[$selector])) {
                $rules[$selector] = array('selector' => $selector, 'declarations' => $declarations);
            }
        }
        return array('rules' => array_values($rules));
    }

    /** `prop:value;…` (one trailing `;` allowed), normalized without spaces around. */
    private static function clean_layout_declarations($raw)
    {
        if (!is_string($raw) || strlen($raw) > 600 || preg_match('~[!{}<>@"\'\\\\]|url\(|/\*|\*/~i', $raw)) {
            throw new InvalidArgumentException('invalid early layout declarations');
        }
        $parts = explode(';', $raw);
        if (count($parts) > 1 && trim(end($parts)) === '') {
            array_pop($parts);
        }
        $clean = array();
        foreach ($parts as $part) {
            if (!preg_match('/^\s*([a-z-]{1,40})\s*:([A-Za-z0-9 .,%()+\-*\/#]{1,300})$/', $part, $match)) {
                throw new InvalidArgumentException('invalid early layout declaration');
            }
            $value = trim($match[2]);
            if (!in_array($match[1], self::LAYOUT_PROPERTIES, true) || $value === '' || !self::balanced($value)) {
                throw new InvalidArgumentException('invalid early layout declaration');
            }
            $clean[] = $match[1] . ':' . $value;
        }
        return implode(';', $clean);
    }

    /** Parentheses closed in order: an open one would swallow the rest of the style. */
    private static function balanced($value)
    {
        $depth = 0;
        foreach (str_split($value) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    /**
     * Images bearing every class of the signature, counted in the order of
     * the page: the rank-th first (LCP), the ones before `eager` at once.
     */
    private static function clean_image_priority(array $params)
    {
        $raw = $params['signature'] ?? null;
        if (!is_array($raw) || !$raw || !self::is_list($raw) || count($raw) > 6) {
            throw new InvalidArgumentException('invalid image priority signature');
        }
        $signature = array();
        foreach ($raw as $class) {
            if (!is_string($class) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $class)) {
                throw new InvalidArgumentException('invalid image priority signature');
            }
            $signature[$class] = true;
        }
        $rank = self::clean_int($params['rank'] ?? null, 0, 50);
        $eager = self::clean_int($params['eager'] ?? null, 1, 12);
        if ($rank >= $eager) {
            throw new InvalidArgumentException('invalid image priority rank');
        }
        $clean = array('signature' => array_map('strval', array_keys($signature)), 'rank' => $rank, 'eager' => $eager);
        if (array_key_exists('sizes', $params)) {
            if (!is_string($params['sizes']) || !preg_match('/^[a-z0-9 ():,.-]{1,300}$/', $params['sizes'])) {
                throw new InvalidArgumentException('invalid image priority sizes');
            }
            $clean['sizes'] = $params['sizes'];
        }
        return $clean;
    }

    /** CSS backgrounds below the fold, loaded when their element nears the viewport. */
    private static function clean_defer_backgrounds(array $params)
    {
        $raw = $params['items'] ?? null;
        if (!is_array($raw) || !$raw || !self::is_list($raw) || count($raw) > 20) {
            throw new InvalidArgumentException('invalid deferred backgrounds');
        }
        $items = array();
        foreach ($raw as $item) {
            $selector = is_array($item) && isset($item['selector']) && is_string($item['selector']) ? $item['selector'] : '';
            if (strlen($selector) > 200 || !preg_match(self::BACKGROUND_SELECTOR, $selector)) {
                throw new InvalidArgumentException('invalid deferred background selector');
            }
            $url = self::clean_https_urls(array(is_string($item['url'] ?? null) ? $item['url'] : ''), false, 1)[0];
            if (strlen($url) > 500) {
                throw new InvalidArgumentException('invalid deferred background url');
            }
            if (!isset($items[$selector])) {
                $items[$selector] = array('selector' => $selector, 'url' => $url);
            }
        }
        return array('items' => array_values($items));
    }

    /**
     * Content conditions: the rule does not apply to a request where one
     * holds. Typed and bounded, evaluated by the runtime, never free text.
     */
    private static function clean_unless($raw)
    {
        if ($raw === null) {
            return array();
        }
        if (!is_array($raw) || count($raw) > self::MAX_PREDICATES) {
            throw new InvalidArgumentException('invalid unless');
        }
        $clean = array();
        foreach ($raw as $predicate) {
            if (!is_array($predicate)) {
                throw new InvalidArgumentException('invalid unless condition');
            }
            $kind = (string) ($predicate['kind'] ?? '');
            $value = $predicate['value'] ?? null;
            if ($kind === 'post_ids') {
                if (!is_array($value) || !$value || count($value) > self::MAX_PREDICATE_POST_IDS) {
                    throw new InvalidArgumentException('invalid unless post ids');
                }
                $ids = array();
                foreach ($value as $post_id) {
                    $post_id = is_string($post_id) && ctype_digit($post_id) ? (int) $post_id : $post_id;
                    if (!is_int($post_id) || $post_id <= 0) {
                        throw new InvalidArgumentException('invalid unless post ids');
                    }
                    $ids[$post_id] = true;
                }
                $clean[] = array('kind' => $kind, 'value' => array_keys($ids));
            } elseif (isset(self::PREDICATE_PATTERNS[$kind])) {
                $value = is_string($value) ? trim($value) : '';
                if (!preg_match(self::PREDICATE_PATTERNS[$kind], $value)) {
                    throw new InvalidArgumentException('invalid unless ' . $kind);
                }
                $clean[] = array('kind' => $kind, 'value' => $value);
            } else {
                throw new InvalidArgumentException('invalid unless kind');
            }
        }
        return $clean;
    }

    private static function clean_int($value, $min, $max)
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('invalid integer param');
        }
        return $value;
    }

    /** An image painted first but declared late (CSS background, builders). */
    private static function clean_preload_image(array $params)
    {
        $url = isset($params['url']) && is_string($params['url']) ? $params['url'] : '';
        $url = self::clean_https_urls(array($url), false, 1)[0];
        if (strlen($url) > 500) {
            throw new InvalidArgumentException('invalid image preload url');
        }
        $clean = array('url' => $url);
        foreach (array('srcset' => 2000, 'sizes' => 200) as $key => $max) {
            if (!isset($params[$key])) {
                continue;
            }
            $value = is_string($params[$key]) ? trim($params[$key]) : '';
            if ($value === '' || strlen($value) > $max) {
                throw new InvalidArgumentException('invalid image preload ' . $key);
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /**
     * The faces of a stylesheet that stay served (family, weight, style) and
     * the stylesheet's URL without query: the file the copy is made of.
     */
    private static function clean_font_variants(array $params)
    {
        $handle = trim((string) ($params['handle'] ?? ''));
        $raw = $params['keep'] ?? null;
        if (!preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $handle) || !is_array($raw) || !$raw || count($raw) > self::MAX_KEPT_FONT_FACES) {
            throw new InvalidArgumentException('invalid font variants');
        }
        $src = isset($params['src']) && is_string($params['src']) ? $params['src'] : '';
        $src = self::clean_https_urls(array($src), false, 1)[0];
        if (strlen($src) > 500 || strpbrk($src, '?#') !== false) {
            throw new InvalidArgumentException('invalid font variants src');
        }
        $keep = array();
        foreach ($raw as $face) {
            if (!is_array($face)) {
                throw new InvalidArgumentException('invalid kept font face');
            }
            $family = trim(str_replace(array('"', "'"), '', (string) ($face['family'] ?? '')));
            $weight = trim((string) ($face['weight'] ?? ''));
            $style = trim((string) ($face['style'] ?? 'normal'));
            if (
                !preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $family)
                || !preg_match('/^\d{3}( \d{3})?$/', $weight)
                || !in_array($style, array('normal', 'italic'), true)
            ) {
                throw new InvalidArgumentException('invalid kept font face');
            }
            $keep[] = array('family' => $family, 'weight' => $weight, 'style' => $style);
        }
        return array('handle' => $handle, 'src' => $src, 'keep' => $keep);
    }

    /**
     * Kept faces as the runtime compares them: lower-case family with single
     * spaces, weight as an inclusive range.
     */
    private static function font_keys(array $keep)
    {
        $keys = array();
        foreach ($keep as $face) {
            $weights = array_map('intval', explode(' ', $face['weight']));
            $keys[] = array(
                'family' => strtolower(preg_replace('/\s+/', ' ', $face['family'])),
                'weight' => array(min($weights), max($weights)),
                'style' => $face['style'],
            );
        }
        return $keys;
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

    /**
     * The rules as the control plane hashed them. A JSON object decodes to a
     * PHP array: an empty `params` ({} for menu_images_lazy) would hash as []
     * and refuse every set holding such a rule.
     */
    private static function checksum_rules(array $rules)
    {
        foreach ($rules as $index => $rule) {
            if (is_array($rule) && array_key_exists('params', $rule) && $rule['params'] === array()) {
                $rules[$index]['params'] = new stdClass();
            }
        }
        return $rules;
    }

    public static function canonical_json($value)
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
