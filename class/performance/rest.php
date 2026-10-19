<?php

if (!defined('ABSPATH')) {
    exit;
}

class FaaasterPerformanceRest
{
    private $settings;

    public function __construct(FaaasterPerformanceSettings $settings)
    {
        $this->settings = $settings;
    }

    public function register_routes()
    {
        register_rest_route('hostmanager/v1', '/performance_optimizer', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'state'),
                'permission_callback' => faaaster_hostmanager_guard('performance_optimizer_state'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'apply'),
                'permission_callback' => faaaster_hostmanager_guard('performance_optimizer_apply'),
            ),
        ));
        register_rest_route('hostmanager/v1', '/performance_audit', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'begin_audit'),
                'permission_callback' => faaaster_hostmanager_guard('performance_audit_begin'),
            ),
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'audit_result'),
                'permission_callback' => faaaster_hostmanager_guard('performance_audit_result'),
            ),
        ));
        register_rest_route('hostmanager/v1', '/performance_preview', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'begin_preview'),
                'permission_callback' => faaaster_hostmanager_guard('performance_preview_begin'),
            ),
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'preview_result'),
                'permission_callback' => faaaster_hostmanager_guard('performance_preview_result'),
            ),
        ));
        register_rest_route('faaaster-agent/v1', '/performance/inventory', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'inventory'),
            'permission_callback' => faaaster_hostmanager_guard('faaaster-agent/performance/inventory'),
        ));
    }

    public function state()
    {
        $state = $this->settings->all();
        return array(
            'code' => 'ok',
            'schemaVersion' => $state['schemaVersion'],
            'compiledRevision' => $state['revision'],
            'checksum' => $state['checksum'],
            'ruleCount' => count($state['rules']),
        );
    }

    public function apply($request)
    {
        try {
            $input = $request->get_json_params();
            // the font copies are written here, never during a page request
            $state = $this->settings->replace(
                is_array($input) ? $input : array(),
                array('FaaasterPerformanceFontFaces', 'generate')
            );
            FaaasterPerformanceFontFaces::cleanup($state['compiled']);
            // the conditional tags `when` may call, as the site declares them now
            FaaasterPerformanceConditionals::refresh();
            return array(
                'code' => 'ok',
                'compiledRevision' => $state['revision'],
                'checksum' => $state['checksum'],
                'ruleCount' => count($state['rules']),
                'changed' => $state['changed'],
            );
        } catch (FaaasterPerformanceStaleRevision $error) {
            return new WP_Error('stale_performance_revision', $error->getMessage(), array(
                'status' => 409,
                'currentRevision' => $error->current_revision,
            ));
        } catch (InvalidArgumentException $error) {
            return new WP_Error('invalid_performance_rules', $error->getMessage(), array('status' => 400));
        }
    }

    /**
     * Registers a compiled rule set that only requests carrying the token
     * render (see FaaasterPerformanceRuntime::PREVIEW_PARAM). Validated exactly
     * like an applied payload; the live rules are never touched.
     */
    public function begin_preview($request)
    {
        $input = $request->get_json_params();
        $input = is_array($input) ? $input : array();
        $token = isset($input['token']) ? (string) $input['token'] : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return new WP_Error('invalid_preview_token', 'Invalid preview token', array('status' => 400));
        }
        $paths = $this->clean_paths($input['paths'] ?? array());
        if ($paths instanceof WP_Error) {
            return $paths;
        }
        if (!$paths) {
            return new WP_Error('invalid_preview_paths', 'At least one preview path is required', array('status' => 400));
        }
        try {
            $compiled = FaaasterPerformanceSettings::compile(
                isset($input['compiled']) && is_array($input['compiled']) ? $input['compiled'] : array()
            );
        } catch (InvalidArgumentException $error) {
            return new WP_Error('invalid_performance_rules', $error->getMessage(), array('status' => 400));
        }
        FaaasterPerformanceConditionals::refresh();
        $ttl = FaaasterPerformanceRuntime::session_ttl($input['ttl'] ?? null, FaaasterPerformanceRuntime::PREVIEW_TTL);
        set_transient(
            FaaasterPerformanceRuntime::preview_transient_key($token),
            array(
                // the preview's font copies, written now: its pages only read them
                'compiled' => FaaasterPerformanceFontFaces::generate($compiled['compiled']),
                'checksum' => $compiled['checksum'],
                'paths' => $paths,
                'ttl' => $ttl,
                'createdAt' => time(),
            ),
            $ttl
        );
        return array(
            'code' => 'ok',
            'checksum' => $compiled['checksum'],
            'paths' => $paths,
            'expiresIn' => $ttl,
        );
    }

    public function preview_result($request)
    {
        $token = (string) $request->get_param('token');
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return new WP_Error('invalid_preview_token', 'Invalid preview token', array('status' => 400));
        }
        $session = get_transient(FaaasterPerformanceRuntime::preview_transient_key($token));
        if (!is_array($session)) {
            return new WP_Error('preview_session_not_found', 'Preview session not found', array('status' => 404));
        }
        $receipts = array();
        foreach ((array) ($session['paths'] ?? array()) as $path) {
            $receipt = get_transient(FaaasterPerformanceRuntime::preview_receipt_key($token, $path));
            if (is_array($receipt)) {
                $receipts[] = $receipt;
            }
        }
        return array(
            'code' => 'ok',
            'checksum' => (string) ($session['checksum'] ?? ''),
            'receipts' => $receipts,
        );
    }

    /** @return string[]|WP_Error */
    private function clean_paths($raw)
    {
        $paths = array();
        foreach (array_slice((array) $raw, 0, FaaasterPerformanceRuntime::MAX_SESSION_PATHS) as $path) {
            $path = trim((string) $path);
            if (!preg_match('~^/[^\s#?]{0,499}$~', $path)) {
                return new WP_Error('invalid_preview_path', 'Invalid preview path', array('status' => 400));
            }
            $paths[$path] = true;
        }
        return array_keys($paths);
    }

    public function begin_audit($request)
    {
        $input = $request->get_json_params();
        $token = isset($input['token']) ? (string) $input['token'] : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return new WP_Error('invalid_audit_token', 'Invalid audit token', array('status' => 400));
        }
        $paths = array();
        foreach (array_slice((array) ($input['paths'] ?? array()), 0, FaaasterPerformanceRuntime::MAX_SESSION_PATHS) as $path) {
            $path = trim((string) $path);
            if (!preg_match('~^/[^\s#]{0,499}$~', $path)) {
                return new WP_Error('invalid_audit_path', 'Invalid audit path', array('status' => 400));
            }
            $paths[$path] = true;
        }
        // one page per template is measured, one per request: a large site
        // needs the session longer than the default half hour
        $ttl = FaaasterPerformanceRuntime::session_ttl($input['ttl'] ?? null, FaaasterPerformanceRuntime::AUDIT_TTL);
        set_transient(
            FaaasterPerformanceRuntime::audit_transient_key($token),
            array('paths' => array_keys($paths), 'snapshots' => array(), 'ttl' => $ttl, 'createdAt' => time()),
            $ttl
        );
        return array('code' => 'ok', 'expiresIn' => $ttl);
    }

    public function audit_result($request)
    {
        $token = (string) $request->get_param('token');
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return new WP_Error('invalid_audit_token', 'Invalid audit token', array('status' => 400));
        }
        $session = get_transient(FaaasterPerformanceRuntime::audit_transient_key($token));
        if (!is_array($session)) {
            return new WP_Error('audit_session_not_found', 'Audit session not found', array('status' => 404));
        }
        return array(
            'code' => 'ok',
            'snapshots' => array_values((array) ($session['snapshots'] ?? array())),
        );
    }

    public function inventory()
    {
        // one measured page per template: every content type and taxonomy of
        // the site is audited, not a fixed list (17/10)
        $templates = $this->templates();
        $representatives = array(array('kind' => 'home', 'path' => '/'));
        foreach ($templates as $template) {
            if ($template['context'] !== 'front_page' && !empty($template['paths'][0])) {
                $representatives[] = array('kind' => $template['kind'], 'path' => $template['paths'][0]);
            }
        }
        $plugins = (array) get_option('active_plugins', array());
        $builders = array();
        $needles = array(
            'elementor/' => 'elementor',
            'stackable-ultimate-gutenberg-blocks/' => 'stackable',
            'woocommerce/' => 'woocommerce',
        );
        foreach ($needles as $needle => $label) {
            foreach ($plugins as $plugin) {
                if (strpos($plugin, $needle) === 0) {
                    $builders[$label] = true;
                    break;
                }
            }
        }
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $builders['fse'] = true;
        }
        if (isset($builders['elementor'])) {
            $elementor = get_posts(array(
                'post_type' => array('page', 'post'),
                'post_status' => 'publish',
                'numberposts' => 1,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'meta_key' => '_elementor_edit_mode',
                'meta_value' => 'builder',
            ));
            if (!empty($elementor[0])) {
                $this->add_representative($representatives, 'builder_elementor', get_permalink($elementor[0]));
            }
        }
        if (isset($builders['stackable'])) {
            global $wpdb;
            $stackable_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s ORDER BY post_modified_gmt DESC LIMIT 1",
                '%' . $wpdb->esc_like('<!-- wp:stackable/') . '%'
            ));
            if ($stackable_id) {
                $this->add_representative($representatives, 'builder_stackable', get_permalink((int) $stackable_id));
            }
        }
        $slugs = array();
        foreach ($plugins as $plugin) {
            $slug = strpos((string) $plugin, '/') !== false ? dirname((string) $plugin) : basename((string) $plugin, '.php');
            $slugs[$slug] = true;
        }
        $scan = $this->components_scan(isset($builders['elementor']));
        return array(
            'code' => 'ok',
            'supported' => true,
            // what this inventory carries for the optimizer V2 (Next refuses a
            // V2 audit without it, never falls back)
            'capabilities' => self::CAPABILITIES,
            'representatives' => array_slice($representatives, 0, FaaasterPerformanceRuntime::MAX_SESSION_PATHS),
            'templates' => $templates,
            'builders' => array_keys($builders),
            'plugins' => array_slice(array_keys($slugs), 0, 80),
            'theme' => get_stylesheet(),
            // every published content counted by type, template and builder,
            // past the bounded scan: the classes Next builds are exhaustive
            'classCounts' => $this->class_counts(isset($builders['elementor'])),
            'taxonomyCounts' => $this->taxonomy_counts(),
            'archives' => $this->archives(),
            'versions' => $this->versions(),
            'registry' => $this->registry(isset($builders['elementor'])),
            // name => paths, a JSON object even when empty
            'components' => array_map(function ($names) {
                return $names ? $names : new stdClass();
            }, $scan['components']),
            // content type => contents read and the components they use
            'componentsByType' => $scan['byType'] ? $scan['byType'] : new stdClass(),
            'componentsScan' => $scan['scan'],
            // one entry per content read by the scan: what a condition sees there
            'contentIndex' => $scan['index'],
            'site' => $this->site(),
            'conditionals' => FaaasterPerformanceConditionals::refresh(),
        );
    }

    /** The static front page and posts page, 0 without a static front page;
     *  the posts page's path and the shop pages, each with its role. */
    private function site()
    {
        $static = get_option('show_on_front') === 'page';
        $posts_page = $static ? (int) get_option('page_for_posts') : 0;
        $posts_path = $posts_page ? $this->paths_of(array((string) get_permalink($posts_page))) : array();
        return array(
            'frontPageId' => $static ? (int) get_option('page_on_front') : 0,
            'postsPageId' => $posts_page,
            'postsPagePath' => $posts_path ? $posts_path[0] : '',
            'special' => $this->special_pages(),
        );
    }

    /** what the inventory carries beyond 0.15 (optimizer V2) */
    const CAPABILITIES = array('inventory-v2');
    /** groups of contents counted at most (type × template × builder) */
    const CLASS_GROUPS_MAX = 500;

    /**
     * Every published content of a public type counted by type, page template
     * and builder (`elementor` when Elementor's builder mode is on, `null`
     * otherwise), with one example each: one grouped query, the template and
     * the builder read from indexed post meta — never the content.
     */
    private function class_counts($elementor)
    {
        global $wpdb;
        $types = $this->public_post_types();
        if (!is_object($wpdb) || !$types) {
            return array();
        }
        $in_types = implode(',', array_fill(0, count($types), '%s'));
        $builder = $elementor
            ? "LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_elementor_edit_mode' AND e.meta_value = 'builder'"
            : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_type AS type, t.meta_value AS template, " . ($elementor ? 'MAX(e.post_id IS NOT NULL)' : '0') . " AS el,"
            . " COUNT(DISTINCT p.ID) AS n, MAX(p.ID) AS example FROM {$wpdb->posts} p"
            . " LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_wp_page_template' {$builder}"
            . " WHERE p.post_status = 'publish' AND p.post_type IN ($in_types)"
            . ' GROUP BY p.post_type, t.meta_value' . ($elementor ? ', e.post_id IS NOT NULL' : '')
            . ' LIMIT %d',
            array_merge($types, array(self::CLASS_GROUPS_MAX))
        ));
        $groups = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $template = (string) $row->template;
            // as the content index names it: a value a condition cannot name is the default
            if ($template === '' || !preg_match(FaaasterPerformanceSettings::TEMPLATE_PATTERN, $template)) {
                $template = 'default';
            }
            $key = $row->type . '|' . $template . '|' . ((int) $row->el ? 'elementor' : '');
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'type' => (string) $row->type,
                    'template' => $template,
                    'builder' => (int) $row->el ? 'elementor' : null,
                    'count' => 0,
                    'example' => '',
                );
            }
            $groups[$key]['count'] += (int) $row->n;
            if ($groups[$key]['example'] === '' && (int) $row->example > 0) {
                $post = get_post((int) $row->example);
                $groups[$key]['example'] = $post ? self::content_path($post) : '';
            }
        }
        return array_values($groups);
    }

    /** Terms with contents per public taxonomy: an archive page each. */
    private function taxonomy_counts()
    {
        $counts = array();
        foreach ($this->public_taxonomies() as $taxonomy) {
            $count = wp_count_terms(array('taxonomy' => $taxonomy, 'hide_empty' => true));
            $counts[$taxonomy] = is_wp_error($count) ? 0 : (int) $count;
        }
        return $counts ? $counts : new stdClass();
    }

    /** Archive pages of the public content types that have one. */
    private function archives()
    {
        $archives = array();
        foreach ($this->public_post_types() as $post_type) {
            if (in_array($post_type, array('post', 'page'), true)) {
                continue;
            }
            $object = get_post_type_object($post_type);
            if (!is_object($object) || empty($object->has_archive)) {
                continue;
            }
            $paths = $this->paths_of(array((string) get_post_type_archive_link($post_type)));
            if ($paths) {
                $archives[] = array('postType' => (string) $post_type, 'path' => $paths[0]);
            }
        }
        return $archives;
    }

    /** WooCommerce shop pages with their role, the home page never one. */
    private function special_pages()
    {
        if (!function_exists('wc_get_page_id')) {
            return array();
        }
        $front = (int) get_option('page_on_front');
        $pages = array();
        foreach (array('shop', 'cart', 'checkout', 'myaccount') as $role) {
            $id = (int) wc_get_page_id($role);
            if ($id <= 0 || $id === $front) {
                continue;
            }
            $paths = $this->paths_of(array((string) get_permalink($id)));
            if ($paths && $paths[0] !== '/') {
                $pages[] = array('role' => $role, 'id' => $id, 'path' => $paths[0]);
            }
        }
        return $pages;
    }

    /**
     * The versions a structural change is told from: WordPress, the theme and
     * its parent, each active extension.
     */
    private function versions()
    {
        if (!function_exists('get_plugins') && defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = function_exists('get_plugins') ? (array) get_plugins() : array();
        $plugins = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $file = (string) $file;
            $slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');
            $plugins[$slug] = isset($installed[$file]['Version']) ? (string) $installed[$file]['Version'] : '';
        }
        ksort($plugins, SORT_STRING);
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $parent = is_object($theme) && method_exists($theme, 'parent') ? $theme->parent() : null;
        return array(
            'wordpress' => (string) get_bloginfo('version'),
            'theme' => array(
                'slug' => get_stylesheet(),
                'version' => is_object($theme) ? (string) $theme->get('Version') : '',
                'parent' => is_object($parent) ? (string) $parent->get_stylesheet() : '',
                'parentVersion' => is_object($parent) ? (string) $parent->get('Version') : '',
            ),
            'plugins' => $plugins ? array_slice($plugins, 0, 120, true) : new stdClass(),
        );
    }

    /**
     * What WordPress registers: the names a content condition may use (Next
     * refuses a condition on an unknown name), and the extension declaring
     * each one (`owners`, read by reflection).
     */
    private function registry($elementor)
    {
        $callbacks = isset($GLOBALS['shortcode_tags']) && is_array($GLOBALS['shortcode_tags']) ? $GLOBALS['shortcode_tags'] : array();
        $shortcodes = array();
        foreach ($callbacks as $tag => $callback) {
            if (preg_match('/^[A-Za-z0-9_-]{1,60}$/', (string) $tag) && count($shortcodes) < 300) {
                $shortcodes[(string) $tag] = $callback;
            }
        }
        $blocks = class_exists('WP_Block_Type_Registry')
            ? array_slice((array) WP_Block_Type_Registry::get_instance()->get_all_registered(), 0, 600, true)
            : array();
        $widgets = array();
        $factory = isset($GLOBALS['wp_widget_factory']) ? $GLOBALS['wp_widget_factory'] : null;
        if (is_object($factory) && isset($factory->widgets)) {
            foreach ((array) $factory->widgets as $widget) {
                if (is_object($widget) && !empty($widget->id_base) && !isset($widgets[(string) $widget->id_base]) && count($widgets) < 200) {
                    $widgets[(string) $widget->id_base] = $widget;
                }
            }
        }
        // placed in a sidebar, as is_active_widget() counts them: never the
        // inactive or orphaned ones
        $active = array();
        if (function_exists('wp_get_sidebars_widgets')) {
            foreach ((array) wp_get_sidebars_widgets() as $sidebar => $ids) {
                $sidebar = (string) $sidebar;
                if ($sidebar === 'wp_inactive_widgets' || strpos($sidebar, 'orphaned_widgets') === 0 || !is_array($ids)) {
                    continue;
                }
                foreach ($ids as $widget_id) {
                    $active[preg_replace('/-\d+$/', '', (string) $widget_id)] = true;
                }
            }
        }
        $owners = (new FaaasterPerformanceOwners())->registry($shortcodes, $blocks, $widgets, $elementor);
        return array(
            'shortcodes' => array_map('strval', array_keys($shortcodes)),
            'blocks' => array_map('strval', array_keys($blocks)),
            'widgets' => array_map('strval', array_keys($widgets)),
            'activeWidgets' => array_slice(array_map('strval', array_keys($active)), 0, 200),
            // name => origin, a JSON object even when empty
            'owners' => array_map(function ($names) {
                return $names ? $names : new stdClass();
            }, $owners),
        );
    }

    /** published contents scanned at most, read by chunks */
    const SCAN_MAX_CONTENTS = 3000;
    const SCAN_CHUNK = 300;
    /** Elementor data is large: read by smaller chunks */
    const SCAN_ELEMENTOR_CHUNK = 50;
    const SCAN_PATHS = 3;
    const SCAN_MAX_NAMES = 200;
    const SCAN_BUDGET_SECONDS = 5;
    /** contentIndex bounds: names of a content, names in the dictionary */
    const INDEX_ITEM_NAMES = 80;
    const INDEX_MAX_NAMES = 6000;
    /** names listed per content type */
    const TYPE_MAX_BLOCKS = 100;
    const TYPE_MAX_NAMES = 50;

    /**
     * Where the published content uses each component: registered shortcodes,
     * blocks outside core/ and Elementor widgets, up to 3 paths each; and per
     * content type, the components its contents use (`byType`). A bounded
     * scan of the latest contents; `scan.complete` says whether every
     * published content was read, so an absent component means unused.
     * `index` (contentIndex) lists every content read with what a condition
     * sees there: components, terms, page template (names by index).
     */
    private function components_scan($elementor)
    {
        global $wpdb;
        $found = array('shortcodes' => array(), 'blocks' => array(), 'elementorWidgets' => array());
        // per content type: what its published contents use, core blocks included
        $by_type = array();
        $scan = array('contents' => 0, 'complete' => false);
        $index = array('names' => array(), 'items' => array());
        $types = $this->public_post_types();
        if (!is_object($wpdb) || !$types) {
            return array('components' => $found, 'byType' => $by_type, 'scan' => $scan, 'index' => $index);
        }
        // name => position in `names`
        $dictionary = array();
        $taxonomies = array_values(array_unique(array_merge($this->public_taxonomies(), array('product_type'))));
        $registered = FaaasterPerformanceComponents::registered_shortcodes();
        $in_types = implode(',', array_fill(0, count($types), '%s'));
        $started = microtime(true);
        $last = PHP_INT_MAX;
        $complete = false;
        while ($scan['contents'] < self::SCAN_MAX_CONTENTS) {
            $limit = min(self::SCAN_CHUNK, self::SCAN_MAX_CONTENTS - $scan['contents']);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_type, post_name, post_parent, post_date, post_status, post_content FROM {$wpdb->posts}"
                . " WHERE post_status = 'publish' AND post_type IN ($in_types) AND ID < %d ORDER BY ID DESC LIMIT %d",
                array_merge($types, array($last, $limit))
            ));
            $rows = is_array($rows) ? $rows : array();
            $posts = array();
            $paths = array();
            // id => names of the content by kind, for the index
            $named = array();
            foreach ($rows as $row) {
                $id = (int) $row->ID;
                $last = $id;
                $content = (string) $row->post_content;
                // a permalink needs the post, never its content again
                $row->ID = $id;
                $row->post_parent = (int) $row->post_parent;
                $row->post_content = '';
                $row->filter = 'raw';
                $posts[$id] = $row;
                $paths[$id] = self::content_path($row);
                $named[$id] = array('s' => array(), 'e' => array(), 'b' => array(), 'core' => array());
                $type = (string) $row->post_type;
                if (!isset($by_type[$type])) {
                    $by_type[$type] = array('contents' => 0, 'blocks' => array(), 'shortcodes' => array(), 'elementorWidgets' => array());
                }
                $by_type[$type]['contents']++;
                foreach (array_keys(FaaasterPerformanceComponents::serialized_blocks($content)) as $name) {
                    self::bounded_add($by_type[$type]['blocks'], $name, self::TYPE_MAX_BLOCKS);
                    if (strpos($name, 'core/') !== 0) {
                        $this->found($found['blocks'], $name, $paths[$id]);
                        $named[$id]['b'][] = 'b:' . $name;
                    } else {
                        $named[$id]['core'][] = 'b:' . $name;
                    }
                }
                foreach (array_keys(FaaasterPerformanceComponents::shortcodes($content, $registered)) as $tag) {
                    self::bounded_add($by_type[$type]['shortcodes'], (string) $tag, self::TYPE_MAX_NAMES);
                    $this->found($found['shortcodes'], (string) $tag, $paths[$id]);
                    $named[$id]['s'][] = 's:' . $tag;
                }
            }
            if ($elementor && $posts) {
                foreach (array_chunk(array_keys($posts), self::SCAN_ELEMENTOR_CHUNK) as $ids) {
                    foreach ($this->elementor_data($ids) as $id => $data) {
                        if (!isset($posts[$id])) {
                            continue;
                        }
                        foreach (array_keys(FaaasterPerformanceComponents::elementor_widgets($data)) as $name) {
                            self::bounded_add($by_type[(string) $posts[$id]->post_type]['elementorWidgets'], (string) $name, self::TYPE_MAX_NAMES);
                            $this->found($found['elementorWidgets'], (string) $name, $paths[$id]);
                            $named[$id]['e'][] = 'e:' . $name;
                        }
                    }
                }
            }
            if ($posts) {
                $terms = $this->content_terms(array_keys($posts), $taxonomies);
                $templates = $this->page_templates(array_keys($posts));
                $built = $elementor ? $this->elementor_built(array_keys($posts)) : array();
                foreach ($posts as $id => $post) {
                    // the most telling names first: a content over the bound loses core blocks
                    $names = array_merge(
                        array('p:' . ($templates[$id] ?? 'default')),
                        $terms[$id] ?? array(),
                        $named[$id]['s'],
                        $named[$id]['e'],
                        $named[$id]['b'],
                        $named[$id]['core']
                    );
                    $item = array(
                        'id' => $id,
                        'type' => (string) $post->post_type,
                        'slug' => (string) $post->post_name,
                        'path' => $paths[$id],
                        'c' => self::index_names($index['names'], $dictionary, $names),
                    );
                    // built with Elementor, as classCounts counts it
                    if (isset($built[$id])) {
                        $item['el'] = 1;
                    }
                    $index['items'][] = $item;
                }
            }
            $scan['contents'] += count($rows);
            if (count($rows) < $limit) {
                $complete = true;
                break;
            }
            if (microtime(true) - $started > self::SCAN_BUDGET_SECONDS) {
                break;
            }
        }
        if (!$complete && $scan['contents'] >= self::SCAN_MAX_CONTENTS) {
            // the bound reached exactly on the last content: nothing left unread
            $complete = !$wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in_types) AND ID < %d LIMIT 1",
                array_merge($types, array($last))
            ));
        }
        $scan['complete'] = $complete;
        foreach ($by_type as $type => $used) {
            foreach (array('blocks', 'shortcodes', 'elementorWidgets') as $kind) {
                $by_type[$type][$kind] = array_map('strval', array_keys($used[$kind]));
            }
        }
        return array('components' => $found, 'byType' => $by_type, 'scan' => $scan, 'index' => $index);
    }

    /** Positions of a content's names in the dictionary, both bounded. */
    private static function index_names(array &$names, array &$dictionary, array $wanted)
    {
        $positions = array();
        foreach ($wanted as $name) {
            if (count($positions) >= self::INDEX_ITEM_NAMES) {
                break;
            }
            if (!isset($dictionary[$name])) {
                if (count($names) >= self::INDEX_MAX_NAMES) {
                    continue;
                }
                $dictionary[$name] = count($names);
                $names[] = $name;
            }
            $positions[$dictionary[$name]] = true;
        }
        return array_keys($positions);
    }

    /**
     * Terms of some contents, one query: id => [`t:<taxonomy>:<slug>`], public
     * taxonomies and product_type (the WooCommerce product type).
     */
    private function content_terms(array $ids, array $taxonomies)
    {
        global $wpdb;
        if (!$ids || !$taxonomies) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, tt.taxonomy, t.slug FROM {$wpdb->term_relationships} tr"
            . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . " INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
            . ' WHERE tr.object_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')'
            . ' AND tt.taxonomy IN (' . implode(',', array_fill(0, count($taxonomies), '%s')) . ')',
            array_merge($ids, $taxonomies)
        ));
        $terms = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $terms[(int) $row->object_id][] = 't:' . $row->taxonomy . ':' . $row->slug;
        }
        return $terms;
    }

    /** Page templates of some contents, one query: id => template (`default` left out). */
    private function page_templates(array $ids)
    {
        global $wpdb;
        if (!$ids) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND post_id IN ("
            . implode(',', array_fill(0, count($ids), '%d')) . ')',
            $ids
        ));
        $templates = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $template = (string) $row->meta_value;
            // a value a `template` condition cannot name is left out
            if ($template !== '' && $template !== 'default' && preg_match(FaaasterPerformanceSettings::TEMPLATE_PATTERN, $template)) {
                $templates[(int) $row->post_id] = $template;
            }
        }
        return $templates;
    }

    /** The content's path; '' for a permalink with a query (plain permalinks). */
    private static function content_path($post)
    {
        $parts = wp_parse_url((string) get_permalink($post));
        return is_array($parts) && empty($parts['query']) && isset($parts['path']) && strpos($parts['path'], '/') === 0
            ? $parts['path']
            : '';
    }

    private static function bounded_add(array &$set, $name, $max)
    {
        if (!isset($set[$name]) && count($set) < $max) {
            $set[$name] = true;
        }
    }

    /** A component found in a content: its path kept, up to SCAN_PATHS. */
    private function found(array &$names, $name, $path)
    {
        if (!isset($names[$name])) {
            if (count($names) >= self::SCAN_MAX_NAMES) {
                return;
            }
            $names[$name] = array();
        }
        if (count($names[$name]) < self::SCAN_PATHS && $path !== '' && !in_array($path, $names[$name], true)) {
            $names[$name][] = $path;
        }
    }

    /** Posts of some ids in Elementor's builder mode: id => true, one query. */
    private function elementor_built(array $ids)
    {
        global $wpdb;
        if (!$ids) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_edit_mode' AND meta_value = 'builder' AND post_id IN ("
            . implode(',', array_fill(0, count($ids), '%d')) . ')',
            $ids
        ));
        $built = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $built[(int) $row->post_id] = true;
        }
        return $built;
    }

    /** `_elementor_data` of some posts: id => JSON. */
    private function elementor_data(array $ids)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND post_id IN ("
            . implode(',', array_fill(0, count($ids), '%d')) . ')',
            $ids
        ));
        $data = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $data[(int) $row->post_id] = (string) $row->meta_value;
        }
        return $data;
    }

    /** contents sampled per template: an extended rule is validated on them */
    const TEMPLATE_SAMPLES = 3;
    /** public content types listed at most (a template each) */
    const MAX_TEMPLATE_TYPES = 40;

    /**
     * Templates a rule can target as a whole, each with a few of its contents
     * (latest modified first): an optimisation found on one content is
     * validated on others of its type before it applies to the whole type.
     * Every public content type is listed — pages and custom types included:
     * Next decides on the sample whether a rule extends (17/10). The shop
     * pages (cart, checkout, account) are pages too: they are listed apart,
     * as `special`, for a rule that could break them to be tested on them.
     */
    private function templates()
    {
        $templates = array(array('kind' => 'home', 'context' => 'front_page', 'paths' => array('/')));
        $front = (int) get_option('page_on_front');
        $special = $this->commerce_pages();
        foreach ($this->public_post_types() as $post_type) {
            $ids = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => self::TEMPLATE_SAMPLES + count($special) + 1,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
            ));
            $sample = array();
            foreach ((array) $ids as $id) {
                if ((int) $id !== $front && !isset($special[(int) $id])) {
                    $sample[] = get_permalink($id);
                }
            }
            $paths = $this->paths_of(array_slice($sample, 0, self::TEMPLATE_SAMPLES));
            if (!$paths) {
                continue;
            }
            $template = array(
                'kind' => $this->kind_of($post_type),
                'context' => 'post_type:' . $post_type,
                'paths' => $paths,
            );
            if ($post_type === 'page' && $special) {
                $template['special'] = $this->paths_of(array_values($special));
            }
            $templates[] = $template;
        }
        foreach ($this->public_taxonomies() as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
                'number' => self::TEMPLATE_SAMPLES,
                'orderby' => 'count',
                'order' => 'DESC',
            ));
            if (is_wp_error($terms)) {
                continue;
            }
            $links = array();
            foreach ((array) $terms as $term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    $links[] = $link;
                }
            }
            $paths = $this->paths_of($links);
            if ($paths) {
                $templates[] = array(
                    'kind' => $taxonomy === 'product_cat' ? 'product_category' : (string) $taxonomy,
                    'context' => 'taxonomy:' . $taxonomy,
                    'paths' => $paths,
                );
            }
        }
        return $templates;
    }

    /** Public taxonomies with archives, the shop's first; never formats. */
    private function public_taxonomies()
    {
        $taxonomies = function_exists('get_taxonomies')
            ? array_values((array) get_taxonomies(array('public' => true), 'names'))
            : (taxonomy_exists('product_cat') ? array('product_cat') : array());
        $taxonomies = array_values(array_diff(array_map('strval', $taxonomies), array('post_format')));
        $first = array_values(array_intersect(array('product_cat', 'category'), $taxonomies));
        return array_slice(array_merge($first, array_values(array_diff($taxonomies, $first))), 0, self::MAX_TEMPLATE_TYPES);
    }

    /** Public content types, the common ones first; never attachments. */
    private function public_post_types()
    {
        $types = function_exists('get_post_types')
            ? array_values((array) get_post_types(array('public' => true), 'names'))
            : array('post', 'page');
        $types = array_values(array_diff(array_map('strval', $types), array('attachment')));
        $first = array_values(array_intersect(array('post', 'page', 'product'), $types));
        $types = array_merge($first, array_values(array_diff($types, $first)));
        return array_slice($types, 0, self::MAX_TEMPLATE_TYPES);
    }

    private function kind_of($post_type)
    {
        $kinds = array('post' => 'article', 'page' => 'page', 'product' => 'product');
        return isset($kinds[$post_type]) ? $kinds[$post_type] : (string) $post_type;
    }

    /**
     * WooCommerce shop, cart, checkout and account pages: id => URL. The home
     * page is never one of them (a shop page set as home, or a page id WordPress
     * no longer resolves, both come out as "/": inst200229, 17/10).
     */
    private function commerce_pages()
    {
        if (!function_exists('wc_get_page_id')) {
            return array();
        }
        $front = (int) get_option('page_on_front');
        $pages = array();
        foreach (array('shop', 'cart', 'checkout', 'myaccount') as $name) {
            $id = (int) wc_get_page_id($name);
            if ($id <= 0 || $id === $front) {
                continue;
            }
            $url = get_permalink($id);
            $path = $url ? wp_parse_url((string) $url, PHP_URL_PATH) : null;
            if (is_string($path) && $path !== '' && $path !== '/') {
                $pages[$id] = $url;
            }
        }
        return $pages;
    }

    private function paths_of(array $urls)
    {
        $paths = array();
        foreach ($urls as $url) {
            $path = wp_parse_url((string) $url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $paths[$path] = true;
            }
        }
        return array_keys($paths);
    }

    private function add_representative(&$representatives, $kind, $url)
    {
        $path = wp_parse_url((string) $url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $representatives[] = array('kind' => $kind, 'path' => $path);
        }
    }
}
