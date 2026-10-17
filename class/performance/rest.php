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
            $state = $this->settings->replace(is_array($input) ? $input : array());
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
        set_transient(
            FaaasterPerformanceRuntime::preview_transient_key($token),
            array(
                'compiled' => $compiled['compiled'],
                'checksum' => $compiled['checksum'],
                'paths' => $paths,
                'createdAt' => time(),
            ),
            FaaasterPerformanceRuntime::PREVIEW_TTL
        );
        return array(
            'code' => 'ok',
            'checksum' => $compiled['checksum'],
            'paths' => $paths,
            'expiresIn' => FaaasterPerformanceRuntime::PREVIEW_TTL,
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
        foreach (array_slice((array) $raw, 0, 12) as $path) {
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
        foreach (array_slice((array) ($input['paths'] ?? array()), 0, 12) as $path) {
            $path = trim((string) $path);
            if (!preg_match('~^/[^\s#]{0,499}$~', $path)) {
                return new WP_Error('invalid_audit_path', 'Invalid audit path', array('status' => 400));
            }
            $paths[$path] = true;
        }
        set_transient(
            FaaasterPerformanceRuntime::audit_transient_key($token),
            array('paths' => array_keys($paths), 'snapshots' => array(), 'createdAt' => time()),
            FaaasterPerformanceRuntime::AUDIT_TTL
        );
        return array('code' => 'ok', 'expiresIn' => FaaasterPerformanceRuntime::AUDIT_TTL);
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
        $representatives = array(array('kind' => 'home', 'path' => '/'));
        $this->add_latest_post($representatives, 'post', 'article');
        $this->add_latest_post($representatives, 'page', 'page');
        if (post_type_exists('product')) {
            $this->add_latest_post($representatives, 'product', 'product');
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1));
            if (!is_wp_error($terms) && !empty($terms[0])) {
                $link = get_term_link($terms[0]);
                if (!is_wp_error($link)) {
                    $this->add_representative($representatives, 'product_category', $link);
                }
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
        return array(
            'code' => 'ok',
            'supported' => true,
            'representatives' => array_slice($representatives, 0, 12),
            'templates' => $this->templates(),
            'builders' => array_keys($builders),
            'plugins' => array_slice(array_keys($slugs), 0, 80),
            'theme' => get_stylesheet(),
        );
    }

    /**
     * Templates a rule can target as a whole, each with a few of its pages
     * (latest modified first): an optimisation found on one product page is
     * validated on others before it applies to every product page. Pages and
     * builder pages are left out: they do not share a layout.
     */
    private function templates()
    {
        $templates = array(array('kind' => 'home', 'context' => 'front_page', 'paths' => array('/')));
        $types = array('post' => 'article');
        if (post_type_exists('product')) {
            $types['product'] = 'product';
        }
        foreach ($types as $post_type => $kind) {
            $ids = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => 3,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
            ));
            $paths = $this->paths_of(array_map('get_permalink', (array) $ids));
            if ($paths) {
                $templates[] = array('kind' => $kind, 'context' => 'post_type:' . $post_type, 'paths' => $paths);
            }
        }
        if (taxonomy_exists('product_cat')) {
            $terms = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => true,
                'number' => 3,
                'orderby' => 'count',
                'order' => 'DESC',
            ));
            if (!is_wp_error($terms)) {
                $links = array();
                foreach ((array) $terms as $term) {
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        $links[] = $link;
                    }
                }
                $paths = $this->paths_of($links);
                if ($paths) {
                    $templates[] = array('kind' => 'product_category', 'context' => 'taxonomy:product_cat', 'paths' => $paths);
                }
            }
        }
        return $templates;
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

    private function add_latest_post(&$representatives, $post_type, $kind)
    {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'numberposts' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ));
        if (!empty($posts[0])) {
            $this->add_representative($representatives, $kind, get_permalink($posts[0]));
        }
    }

    private function add_representative(&$representatives, $kind, $url)
    {
        $path = wp_parse_url((string) $url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $representatives[] = array('kind' => $kind, 'path' => $path);
        }
    }
}
