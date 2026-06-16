<?php

if (!defined('ABSPATH')) {
    exit;
}

function faaaster_mcp_get_registered_ability_names()
{
    return array(
        'faaaster-mcp/wp/site-overview',
        'faaaster-mcp/wp/list-plugins',
        'faaaster-mcp/wp/list-themes',
        'faaaster-mcp/wp/search-content',
        'faaaster-mcp/wp/get-post-details',
        'faaaster-mcp/wp/updates-summary',
        'faaaster-mcp/wp/create-draft-post',
        'faaaster-mcp/wp/install-plugin',
        'faaaster-mcp/wp/toggle-plugin',
    );
}

function faaaster_mcp_get_ability_definition($name)
{
    $definitions = array(
        'faaaster-mcp/wp/site-overview' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array('type' => 'null', 'required' => false),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_site_overview',
            'label' => __('WordPress Site Overview', 'faaaster-manager-plugin'),
            'description' => __('Returns compact WordPress site overview.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/list-plugins' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 500),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_plugins',
            'label' => __('WordPress List Plugins', 'faaaster-manager-plugin'),
            'description' => __('Lists installed plugins with activation and update status.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/list-themes' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 500),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_themes',
            'label' => __('WordPress List Themes', 'faaaster-manager-plugin'),
            'description' => __('Lists installed themes and active theme.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/search-content' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'query' => array('type' => 'string'),
                    'postTypes' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_search_content',
            'label' => __('WordPress Search Content', 'faaaster-manager-plugin'),
            'description' => __('Searches posts/pages content.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/get-post-details' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'postId' => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_get_post_details',
            'label' => __('WordPress Get Post Details', 'faaaster-manager-plugin'),
            'description' => __('Returns details for a post/page by ID.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/updates-summary' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array('type' => 'null', 'required' => false),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_updates_summary',
            'label' => __('WordPress Updates Summary', 'faaaster-manager-plugin'),
            'description' => __('Returns core/plugin/theme updates summary.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/create-draft-post' => array(
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'title' => array('type' => 'string'),
                    'content' => array('type' => 'string'),
                    'excerpt' => array('type' => 'string'),
                    'postType' => array('type' => 'string', 'enum' => array('post', 'page')),
                    'status' => array('type' => 'string', 'enum' => array('draft')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_create_draft_post',
            'label' => __('WordPress Create Draft Post', 'faaaster-manager-plugin'),
            'description' => __('Creates a draft post/page.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/install-plugin' => array(
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'pluginSlug' => array('type' => 'string'),
                    'activate' => array('type' => 'boolean'),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_install_plugin',
            'label' => __('WordPress Install Plugin', 'faaaster-manager-plugin'),
            'description' => __('Installs plugin from wordpress.org.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp/toggle-plugin' => array(
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'pluginFile' => array('type' => 'string'),
                    'enabled' => array('type' => 'boolean'),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_toggle_plugin',
            'label' => __('WordPress Toggle Plugin', 'faaaster-manager-plugin'),
            'description' => __('Activates or deactivates a plugin.', 'faaaster-manager-plugin'),
        ),
    );

    return $definitions[$name] ?? null;
}

function faaaster_mcp_base64url_decode($data)
{
    $padding = strlen($data) % 4;
    if ($padding) {
        $data .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function faaaster_mcp_get_bearer_token($request)
{
    $auth_header = $request->get_header('Authorization');
    if (!$auth_header || !preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
        return null;
    }
    return trim($matches[1]);
}

function faaaster_mcp_validate_token($request, $ability_name = null, $operation_id = null)
{
    $secret = defined('WP_API_KEY') ? WP_API_KEY : false;
    if (!$secret) {
        return new WP_Error('config_error', 'WP_API_KEY not configured', array('status' => 500));
    }

    $token = faaaster_mcp_get_bearer_token($request);
    if (!$token) {
        return new WP_Error('unauthorized', 'Missing Bearer token', array('status' => 401));
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return new WP_Error('unauthorized', 'Invalid token format', array('status' => 401));
    }

    list($encoded_header, $encoded_payload, $encoded_signature) = $parts;

    $header_raw = faaaster_mcp_base64url_decode($encoded_header);
    $payload_raw = faaaster_mcp_base64url_decode($encoded_payload);
    $signature_raw = faaaster_mcp_base64url_decode($encoded_signature);

    if ($header_raw === null || $payload_raw === null || $signature_raw === null) {
        return new WP_Error('unauthorized', 'Invalid token encoding', array('status' => 401));
    }

    $header = json_decode($header_raw, true);
    $claims = json_decode($payload_raw, true);

    if (!is_array($header) || !is_array($claims)) {
        return new WP_Error('unauthorized', 'Invalid token payload', array('status' => 401));
    }

    if (($header['alg'] ?? '') !== 'HS256') {
        return new WP_Error('unauthorized', 'Unsupported token algorithm', array('status' => 401));
    }

    $signed_part = $encoded_header . '.' . $encoded_payload;
    $expected_signature = hash_hmac('sha256', $signed_part, $secret, true);
    if (!hash_equals($expected_signature, $signature_raw)) {
        return new WP_Error('unauthorized', 'Invalid token signature', array('status' => 401));
    }

    $now = time();
    $exp = isset($claims['exp']) ? intval($claims['exp']) : 0;
    if ($exp <= $now) {
        return new WP_Error('unauthorized', 'Token expired', array('status' => 401));
    }

    $expected_iss = defined('FAAASTER_MCP_WP_TOKEN_ISSUER') ? FAAASTER_MCP_WP_TOKEN_ISSUER : 'faaaster-next-mcp';
    $expected_aud = defined('FAAASTER_MCP_WP_TOKEN_AUDIENCE') ? FAAASTER_MCP_WP_TOKEN_AUDIENCE : 'faaaster-wp-mcp';

    if (($claims['iss'] ?? '') !== $expected_iss || ($claims['aud'] ?? '') !== $expected_aud) {
        return new WP_Error('unauthorized', 'Token issuer/audience mismatch', array('status' => 401));
    }

    if (($claims['type'] ?? '') !== 'faaaster_wp_call') {
        return new WP_Error('unauthorized', 'Invalid token type', array('status' => 401));
    }

    $expected_app = defined('APP_ID') ? APP_ID : null;
    if ($expected_app && ($claims['appId'] ?? '') !== $expected_app) {
        return new WP_Error('unauthorized', 'Application mismatch', array('status' => 403));
    }

    if (defined('BRANCH') && BRANCH && !empty($claims['branch']) && $claims['branch'] !== BRANCH) {
        return new WP_Error('unauthorized', 'Branch mismatch', array('status' => 403));
    }

    if ($ability_name && ($claims['ability'] ?? '') !== $ability_name) {
        return new WP_Error('unauthorized', 'Ability mismatch', array('status' => 403));
    }

    if ($operation_id && ($claims['operationId'] ?? '') !== $operation_id) {
        return new WP_Error('unauthorized', 'Operation mismatch', array('status' => 403));
    }

    $jti = isset($claims['jti']) ? sanitize_key($claims['jti']) : '';
    if (!$jti) {
        return new WP_Error('unauthorized', 'Missing token jti', array('status' => 401));
    }

    $jti_key = 'faaaster_mcp_jti_' . md5($jti);
    if (get_transient($jti_key)) {
        return new WP_Error('unauthorized', 'Token replay detected', array('status' => 401));
    }

    $ttl = max(1, $exp - $now);
    set_transient($jti_key, '1', $ttl + 30);

    return $claims;
}

function faaaster_mcp_set_context($claims)
{
    $GLOBALS['faaaster_mcp_context'] = array(
        'authorized' => true,
        'claims' => $claims,
    );
}

function faaaster_mcp_clear_context()
{
    $GLOBALS['faaaster_mcp_context'] = array(
        'authorized' => false,
        'claims' => null,
    );
}

function faaaster_mcp_ability_permission($input = null)
{
    return !empty($GLOBALS['faaaster_mcp_context']['authorized']);
}

function faaaster_mcp_register_ability_categories()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }

    wp_register_ability_category(
        'faaaster-mcp',
        array(
            'label' => __('Faaaster MCP', 'faaaster-manager-plugin'),
            'description' => __('Abilities exposed for secure Faaaster MCP operations.', 'faaaster-manager-plugin'),
        )
    );
}
add_action('wp_abilities_api_categories_init', 'faaaster_mcp_register_ability_categories');

function faaaster_mcp_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    foreach (faaaster_mcp_get_registered_ability_names() as $ability_name) {
        $definition = faaaster_mcp_get_ability_definition($ability_name);
        if (!$definition) {
            continue;
        }

        wp_register_ability(
            $ability_name,
            array(
                'label' => $definition['label'],
                'description' => $definition['description'],
                'category' => 'faaaster-mcp',
                'input_schema' => $definition['input_schema'],
                'output_schema' => $definition['output_schema'],
                'execute_callback' => $definition['callback'],
                'permission_callback' => 'faaaster_mcp_ability_permission',
                'meta' => array(
                    'show_in_rest' => true,
                    'annotations' => array(
                        'readonly' => (bool) $definition['readonly'],
                        'destructive' => (bool) $definition['destructive'],
                        'idempotent' => (bool) $definition['idempotent'],
                    ),
                ),
            )
        );
    }
}
add_action('wp_abilities_api_init', 'faaaster_mcp_register_abilities');

function faaaster_mcp_register_rest_routes()
{
    register_rest_route('faaaster-mcp/v1', '/abilities', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'faaaster_mcp_rest_list_abilities',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('faaaster-mcp/v1', '/abilities/run', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_mcp_rest_run_ability',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'faaaster_mcp_register_rest_routes');

function faaaster_mcp_rest_list_abilities($request)
{
    $claims = faaaster_mcp_validate_token($request);
    if (is_wp_error($claims)) {
        return $claims;
    }

    $abilities = array();

    foreach (faaaster_mcp_get_registered_ability_names() as $ability_name) {
        $ability = wp_get_ability($ability_name);
        if (!$ability) {
            continue;
        }

        $abilities[] = array(
            'name' => $ability->get_name(),
            'label' => $ability->get_label(),
            'description' => $ability->get_description(),
            'category' => $ability->get_category(),
            'input_schema' => $ability->get_input_schema(),
            'output_schema' => $ability->get_output_schema(),
            'meta' => $ability->get_meta(),
        );
    }

    return new WP_REST_Response(array(
        'ok' => true,
        'abilities' => $abilities,
    ), 200);
}

function faaaster_mcp_rest_run_ability($request)
{
    $params = $request->get_json_params();

    $ability_name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
    $operation_id = isset($params['operationId']) ? sanitize_text_field($params['operationId']) : '';

    if (!$ability_name) {
        return new WP_Error('invalid_request', 'Ability name is required', array('status' => 400));
    }

    if (!in_array($ability_name, faaaster_mcp_get_registered_ability_names(), true)) {
        return new WP_Error('rest_ability_not_found', 'Ability not found', array('status' => 404));
    }

    $claims = faaaster_mcp_validate_token($request, $ability_name, $operation_id);
    if (is_wp_error($claims)) {
        return $claims;
    }

    $ability = wp_get_ability($ability_name);
    if (!$ability || !$ability->get_meta_item('show_in_rest')) {
        return new WP_Error('rest_ability_not_found', 'Ability not found', array('status' => 404));
    }

    $input = isset($params['input']) ? $params['input'] : null;

    faaaster_mcp_set_context($claims);

    try {
        $input = $ability->normalize_input($input);
        $valid = $ability->validate_input($input);
        if (is_wp_error($valid)) {
            $valid->add_data(array('status' => 400));
            return $valid;
        }

        $permission = $ability->check_permissions($input);
        if (is_wp_error($permission)) {
            $permission->add_data(array('status' => 403));
            return $permission;
        }
        if (!$permission) {
            return new WP_Error('rest_ability_cannot_execute', 'Permission denied', array('status' => 403));
        }

        $result = $ability->execute($input);
        if (is_wp_error($result)) {
            if (!$result->get_error_data('status')) {
                $result->add_data(array('status' => 500));
            }
            return $result;
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'ability' => $ability_name,
            'operationId' => $operation_id ?: null,
            'result' => $result,
        ), 200);
    } finally {
        faaaster_mcp_clear_context();
    }
}

function faaaster_mcp_ability_site_overview($input = null)
{
    $post_counts = wp_count_posts('post');
    $page_counts = wp_count_posts('page');

    return array(
        'siteName' => get_bloginfo('name'),
        'siteUrl' => home_url('/'),
        'wordpressVersion' => get_bloginfo('version'),
        'language' => get_bloginfo('language'),
        'timezone' => wp_timezone_string(),
        'public' => get_option('blog_public') === '1',
        'posts' => array(
            'publish' => intval($post_counts->publish ?? 0),
            'draft' => intval($post_counts->draft ?? 0),
            'private' => intval($post_counts->private ?? 0),
        ),
        'pages' => array(
            'publish' => intval($page_counts->publish ?? 0),
            'draft' => intval($page_counts->draft ?? 0),
            'private' => intval($page_counts->private ?? 0),
        ),
    );
}

function faaaster_mcp_ability_list_plugins($input = null)
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $limit = 100;
    if (is_array($input) && isset($input['limit'])) {
        $limit = max(1, min(500, intval($input['limit'])));
    }

    $all_plugins = get_plugins();
    $active_plugins = (array) get_option('active_plugins', array());
    $update_transient = get_site_transient('update_plugins');

    $items = array();
    foreach ($all_plugins as $plugin_file => $plugin_data) {
        $items[] = array(
            'pluginFile' => $plugin_file,
            'name' => $plugin_data['Name'] ?? null,
            'version' => $plugin_data['Version'] ?? null,
            'author' => $plugin_data['Author'] ?? null,
            'active' => in_array($plugin_file, $active_plugins, true),
            'updateAvailable' => isset($update_transient->response[$plugin_file]),
            'newVersion' => isset($update_transient->response[$plugin_file]) ? ($update_transient->response[$plugin_file]->new_version ?? null) : null,
        );
    }

    usort($items, function ($a, $b) {
        return strcmp((string) $a['name'], (string) $b['name']);
    });

    return array(
        'totalCount' => count($items),
        'items' => array_slice($items, 0, $limit),
    );
}

function faaaster_mcp_ability_list_themes($input = null)
{
    $limit = 100;
    if (is_array($input) && isset($input['limit'])) {
        $limit = max(1, min(500, intval($input['limit'])));
    }

    $themes = wp_get_themes();
    $current = wp_get_theme();
    $current_stylesheet = $current ? $current->get_stylesheet() : '';

    $items = array();
    foreach ($themes as $stylesheet => $theme) {
        $items[] = array(
            'stylesheet' => $stylesheet,
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'author' => $theme->get('Author'),
            'active' => $stylesheet === $current_stylesheet,
            'parent' => $theme->get_template() !== $stylesheet ? $theme->get_template() : null,
        );
    }

    usort($items, function ($a, $b) {
        return strcmp((string) $a['name'], (string) $b['name']);
    });

    return array(
        'totalCount' => count($items),
        'items' => array_slice($items, 0, $limit),
        'activeStylesheet' => $current_stylesheet ?: null,
    );
}

function faaaster_mcp_ability_search_content($input)
{
    $query = is_array($input) ? sanitize_text_field($input['query'] ?? '') : '';
    if (!$query) {
        return new WP_Error('rest_ability_invalid_input', 'query is required', array('status' => 400));
    }

    $limit = 10;
    if (is_array($input) && isset($input['limit'])) {
        $limit = max(1, min(50, intval($input['limit'])));
    }

    $post_types = array('post', 'page');
    if (is_array($input) && isset($input['postTypes']) && is_array($input['postTypes']) && !empty($input['postTypes'])) {
        $post_types = array_values(array_filter(array_map('sanitize_key', $input['postTypes'])));
        if (empty($post_types)) {
            $post_types = array('post', 'page');
        }
    }

    $wp_query = new WP_Query(array(
        'post_type' => $post_types,
        'post_status' => array('publish', 'draft', 'private', 'future'),
        's' => $query,
        'posts_per_page' => $limit,
        'no_found_rows' => false,
    ));

    $items = array();
    foreach ($wp_query->posts as $post) {
        $items[] = array(
            'postId' => intval($post->ID),
            'postType' => $post->post_type,
            'status' => $post->post_status,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'date' => get_post_datetime($post, 'date', 'gmt') ? get_post_datetime($post, 'date', 'gmt')->format(DATE_ATOM) : null,
            'modified' => get_post_datetime($post, 'modified', 'gmt') ? get_post_datetime($post, 'modified', 'gmt')->format(DATE_ATOM) : null,
            'link' => get_permalink($post),
        );
    }

    return array(
        'query' => $query,
        'postTypes' => $post_types,
        'totalCount' => intval($wp_query->found_posts),
        'items' => $items,
    );
}

function faaaster_mcp_ability_get_post_details($input)
{
    $post_id = is_array($input) ? intval($input['postId'] ?? 0) : 0;
    if ($post_id <= 0) {
        return new WP_Error('rest_ability_invalid_input', 'postId is required', array('status' => 400));
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('rest_ability_not_found', 'Post not found', array('status' => 404));
    }

    $raw_content = (string) $post->post_content;
    if (strlen($raw_content) > 5000) {
        $raw_content = substr($raw_content, 0, 5000);
    }

    return array(
        'postId' => intval($post->ID),
        'postType' => $post->post_type,
        'status' => $post->post_status,
        'title' => get_the_title($post),
        'slug' => $post->post_name,
        'authorId' => intval($post->post_author),
        'date' => get_post_datetime($post, 'date', 'gmt') ? get_post_datetime($post, 'date', 'gmt')->format(DATE_ATOM) : null,
        'modified' => get_post_datetime($post, 'modified', 'gmt') ? get_post_datetime($post, 'modified', 'gmt')->format(DATE_ATOM) : null,
        'excerptRendered' => apply_filters('the_excerpt', $post->post_excerpt),
        'contentRaw' => $raw_content,
        'link' => get_permalink($post),
        'editLink' => get_edit_post_link($post->ID, 'raw'),
    );
}

function faaaster_mcp_ability_updates_summary($input = null)
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    require_once ABSPATH . 'wp-admin/includes/update.php';

    wp_version_check();
    wp_update_plugins();
    wp_update_themes();

    $core_updates = get_core_updates(array('dismissed' => false));
    $plugin_updates = get_plugin_updates();
    $theme_updates = get_theme_updates();

    $core_item = null;
    if (is_array($core_updates) && !empty($core_updates)) {
        $core = $core_updates[0];
        $core_item = array(
            'response' => $core->response ?? null,
            'current' => $core->current ?? null,
            'version' => $core->version ?? null,
            'locale' => $core->locale ?? null,
        );
    }

    $plugins = array();
    foreach ($plugin_updates as $plugin_file => $plugin_data) {
        $plugins[] = array(
            'pluginFile' => $plugin_file,
            'slug' => $plugin_data->update->slug ?? null,
            'name' => $plugin_data->Name ?? null,
            'version' => $plugin_data->Version ?? null,
            'newVersion' => $plugin_data->update->new_version ?? null,
        );
    }

    $themes = array();
    foreach ($theme_updates as $stylesheet => $theme_data) {
        $themes[] = array(
            'stylesheet' => $stylesheet,
            'name' => $theme_data['Name'] ?? null,
            'version' => $theme_data['Version'] ?? null,
            'newVersion' => $theme_data['update']['new_version'] ?? null,
        );
    }

    return array(
        'core' => $core_item,
        'plugins' => $plugins,
        'themes' => $themes,
        'counts' => array(
            'core' => $core_item ? 1 : 0,
            'plugins' => count($plugins),
            'themes' => count($themes),
        ),
    );
}

function faaaster_mcp_ability_create_draft_post($input)
{
    if (!is_array($input)) {
        $input = array();
    }

    $title = sanitize_text_field($input['title'] ?? '');
    if (!$title) {
        return new WP_Error('rest_ability_invalid_input', 'title is required', array('status' => 400));
    }

    $post_type = sanitize_key($input['postType'] ?? 'post');
    if (!in_array($post_type, array('post', 'page'), true)) {
        $post_type = 'post';
    }

    $post_arr = array(
        'post_title' => $title,
        'post_content' => isset($input['content']) ? (string) $input['content'] : '',
        'post_excerpt' => isset($input['excerpt']) ? (string) $input['excerpt'] : '',
        'post_status' => 'draft',
        'post_type' => $post_type,
    );

    $post_id = wp_insert_post($post_arr, true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    return array(
        'postId' => intval($post_id),
        'postType' => $post_type,
        'status' => get_post_status($post_id),
        'editLink' => get_edit_post_link($post_id, 'raw'),
        'link' => get_permalink($post_id),
    );
}

function faaaster_mcp_ability_install_plugin($input)
{
    if (!is_array($input)) {
        $input = array();
    }

    $plugin_slug = sanitize_key($input['pluginSlug'] ?? '');
    if (!$plugin_slug) {
        return new WP_Error('rest_ability_invalid_input', 'pluginSlug is required', array('status' => 400));
    }

    $activate = !empty($input['activate']);

    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $plugin_info = plugins_api('plugin_information', array(
        'slug' => $plugin_slug,
        'fields' => array('sections' => false),
    ));

    if (is_wp_error($plugin_info)) {
        return $plugin_info;
    }

    $skin = class_exists('HostManagerQuietSkin') ? new HostManagerQuietSkin() : new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $installed = $upgrader->install($plugin_info->download_link);

    if (is_wp_error($installed)) {
        return $installed;
    }

    if (!$installed) {
        return new WP_Error('install_failed', 'Plugin installation failed', array('status' => 500));
    }

    $plugin_file = $upgrader->plugin_info();
    if (!$plugin_file) {
        return new WP_Error('install_failed', 'Plugin installed but plugin file not detected', array('status' => 500));
    }

    if ($activate) {
        $activation = activate_plugin($plugin_file);
        if (is_wp_error($activation)) {
            return $activation;
        }
    }

    return array(
        'pluginSlug' => $plugin_slug,
        'pluginFile' => $plugin_file,
        'activated' => is_plugin_active($plugin_file),
    );
}

function faaaster_mcp_ability_toggle_plugin($input)
{
    if (!is_array($input)) {
        $input = array();
    }

    $plugin_file = sanitize_text_field($input['pluginFile'] ?? '');
    if (!$plugin_file) {
        return new WP_Error('rest_ability_invalid_input', 'pluginFile is required', array('status' => 400));
    }

    if (!isset($input['enabled'])) {
        return new WP_Error('rest_ability_invalid_input', 'enabled is required', array('status' => 400));
    }

    $enabled = (bool) $input['enabled'];

    if ($enabled) {
        $activation = activate_plugin($plugin_file);
        if (is_wp_error($activation)) {
            return $activation;
        }
    } else {
        deactivate_plugins($plugin_file, false, false);
    }

    return array(
        'pluginFile' => $plugin_file,
        'enabled' => is_plugin_active($plugin_file),
    );
}
