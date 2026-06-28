<?php

if (!defined('ABSPATH')) {
    exit;
}

function faaaster_mcp_get_registered_ability_names()
{
    return array(
        'faaaster-mcp/wp-site-overview',
        'faaaster-mcp/wp-list-plugins',
        'faaaster-mcp/wp-list-themes',
        'faaaster-mcp/wp-search-content',
        'faaaster-mcp/wp-get-post-details',
        'faaaster-mcp/wp-updates-summary',
        'faaaster-mcp/wp-create-draft-post',
        'faaaster-mcp/wp-list-content',
        'faaaster-mcp/wp-list-media',
        'faaaster-mcp/wp-list-users',
        'faaaster-mcp/wp-list-taxonomy-terms',
        'faaaster-mcp/wp-list-comments',
        'faaaster-mcp/wp-update-post',
        'faaaster-mcp/wp-moderate-comment',
        'faaaster-mcp/wp-refresh-abilities',
        // NB: plugin/theme/core lifecycle (install/toggle/update) is wp-cli-direct
        // (worker), NOT abilities — don't dual-path. cf. agent-build-vision.md §12.
        // NB: deliberately EXCLUDED (too dangerous as a generic ability): arbitrary
        // code-snippet creation (RCE) and DB-wide search-replace (corruption).
    );
}

function faaaster_mcp_get_ability_definition($name)
{
    $definitions = array(
        'faaaster-mcp/wp-site-overview' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array('type' => 'null', 'required' => false),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_site_overview',
            'label' => __('WordPress Site Overview', 'faaaster-manager-plugin'),
            'description' => __('Returns compact WordPress site overview.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-list-plugins' => array(
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
        'faaaster-mcp/wp-list-themes' => array(
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
        'faaaster-mcp/wp-search-content' => array(
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
        'faaaster-mcp/wp-get-post-details' => array(
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
        'faaaster-mcp/wp-updates-summary' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array('type' => 'null', 'required' => false),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_updates_summary',
            'label' => __('WordPress Updates Summary', 'faaaster-manager-plugin'),
            'description' => __('Returns core/plugin/theme updates summary.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-create-draft-post' => array(
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
                    // Editorial author (a real site user id) — attribution is decoupled
                    // from the agent's execution identity. cf. agent-build-vision.md §12.
                    'author' => array('type' => 'integer', 'minimum' => 1),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_create_draft_post',
            'label' => __('WordPress Create Draft Post', 'faaaster-manager-plugin'),
            'description' => __('Creates a draft post/page.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-list-content' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'postType' => array('type' => 'string', 'enum' => array('post', 'page'), 'description' => __('Content type to list. Default "post".', 'faaaster-manager-plugin')),
                    'status' => array('type' => 'string', 'enum' => array('any', 'publish', 'draft', 'pending', 'private', 'future'), 'description' => __('Status filter. Default "any" (all statuses the user may read).', 'faaaster-manager-plugin')),
                    'search' => array('type' => 'string', 'description' => __('Optional keyword filter. OMIT to list everything — this is a LIST, not a search.', 'faaaster-manager-plugin')),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => __('Max items to return (1–100). Default 20.', 'faaaster-manager-plugin')),
                    'orderby' => array('type' => 'string', 'enum' => array('date', 'modified', 'title'), 'description' => __('Sort field. Default "date".', 'faaaster-manager-plugin')),
                    'order' => array('type' => 'string', 'enum' => array('asc', 'desc'), 'description' => __('Sort direction. Default "desc".', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_content',
            'label' => __('List WordPress content', 'faaaster-manager-plugin'),
            'description' => __('Lists posts or pages (most recent first by default), optionally filtered by status or keyword. Use THIS to enumerate/list content; use wp-search-content only for a keyword search.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-list-media' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'mimeType' => array('type' => 'string', 'description' => __('Optional MIME filter, e.g. "image" or "image/png".', 'faaaster-manager-plugin')),
                    'search' => array('type' => 'string', 'description' => __('Optional keyword filter on title/filename.', 'faaaster-manager-plugin')),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => __('Max items (1–100). Default 20.', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_media',
            'label' => __('List WordPress media', 'faaaster-manager-plugin'),
            'description' => __('Lists media-library items (attachments), most recent first; filter by MIME type or keyword.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-list-users' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'role' => array('type' => 'string', 'description' => __('Optional role filter, e.g. "author", "editor".', 'faaaster-manager-plugin')),
                    'search' => array('type' => 'string', 'description' => __('Optional keyword filter on name/login.', 'faaaster-manager-plugin')),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50, 'description' => __('Max users (1–100). Default 50.', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_users',
            'label' => __('List WordPress users', 'faaaster-manager-plugin'),
            'description' => __('Lists site users (id, name, slug, roles — NO email or other PII). Useful to choose a content author.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-list-taxonomy-terms' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'taxonomy' => array('type' => 'string', 'enum' => array('category', 'post_tag'), 'description' => __('Which taxonomy. Default "category".', 'faaaster-manager-plugin')),
                    'search' => array('type' => 'string', 'description' => __('Optional keyword filter on term name.', 'faaaster-manager-plugin')),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => __('Max terms (1–200). Default 50.', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_taxonomy_terms',
            'label' => __('List taxonomy terms', 'faaaster-manager-plugin'),
            'description' => __('Lists categories or tags (id, name, slug, post count).', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-list-comments' => array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array(
                'type' => 'object',
                'required' => false,
                'properties' => array(
                    'status' => array('type' => 'string', 'enum' => array('approve', 'hold', 'spam', 'trash', 'all'), 'description' => __('Comment status filter. Default "approve" (approved).', 'faaaster-manager-plugin')),
                    'postId' => array('type' => 'integer', 'minimum' => 1, 'description' => __('Optional: only comments on this post id.', 'faaaster-manager-plugin')),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => __('Max comments (1–100). Default 20.', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_list_comments',
            'label' => __('List comments', 'faaaster-manager-plugin'),
            'description' => __('Lists comments by status (default approved), optionally for one post. Returns author name, excerpt, status — NO email.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-update-post' => array(
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'id' => array('type' => 'integer', 'minimum' => 1, 'description' => __('REQUIRED. Id of the post/page to update.', 'faaaster-manager-plugin')),
                    'title' => array('type' => 'string', 'description' => __('New title (optional).', 'faaaster-manager-plugin')),
                    'content' => array('type' => 'string', 'description' => __('New content (optional).', 'faaaster-manager-plugin')),
                    'excerpt' => array('type' => 'string', 'description' => __('New excerpt (optional).', 'faaaster-manager-plugin')),
                    'status' => array('type' => 'string', 'enum' => array('publish', 'draft', 'pending', 'private'), 'description' => __('New status (optional).', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_update_post',
            'label' => __('Update a post', 'faaaster-manager-plugin'),
            'description' => __('Updates an existing post/page by id (title/content/excerpt/status). Requires edit permission on the target post. "id" is REQUIRED.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-moderate-comment' => array(
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
            'input_schema' => array(
                'type' => 'object',
                'required' => true,
                'properties' => array(
                    'id' => array('type' => 'integer', 'minimum' => 1, 'description' => __('REQUIRED. Comment id.', 'faaaster-manager-plugin')),
                    'action' => array('type' => 'string', 'enum' => array('approve', 'hold', 'spam', 'trash'), 'description' => __('REQUIRED. approve | hold (unapprove) | spam | trash.', 'faaaster-manager-plugin')),
                ),
            ),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_moderate_comment',
            'label' => __('Moderate a comment', 'faaaster-manager-plugin'),
            'description' => __('Sets a comment status: approve | hold | spam | trash. Requires moderate permission. "id" and "action" REQUIRED.', 'faaaster-manager-plugin'),
        ),
        'faaaster-mcp/wp-refresh-abilities' => array(
            'readonly' => false, // writes the cached catalogue option
            'destructive' => false,
            'idempotent' => true,
            'input_schema' => array('type' => 'null', 'required' => false),
            'output_schema' => array('type' => 'object', 'required' => true),
            'callback' => 'faaaster_mcp_ability_refresh_abilities',
            'label' => __('Refresh Ability Catalogue', 'faaaster-manager-plugin'),
            'description' => __('Rebuilds the cached discovery catalogue from the live registry (core + Faaaster + client-plugin abilities).', 'faaaster-manager-plugin'),
        ),
    );

    return $definitions[$name] ?? null;
}

// Legacy JWT-direct token helpers removed — agent abilities now run via the
// faaaster-agent/v1 shim AS a scoped opt-in user. cf. agent-build-vision.md §12.

// Permission for the Faaaster abilities, evaluated against the CURRENT WP user
// (the scoped opt-in agent user set by the faaaster-agent/v1 shim) — defense in
// depth under the Next broker. Replaces the old global-flag-set-by-JWT model.
function faaaster_mcp_ability_permission($input = null)
{
    return is_user_logged_in() && current_user_can('edit_posts');
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

/** The legacy ability defs use `required` as a BOOLEAN (invalid JSON Schema — the
 *  WP 7.0 core Abilities API rejects it, so registration silently fails) and
 *  `type:null` for no-input. Normalize to valid schema before registering. */
function faaaster_mcp_normalize_schema($schema)
{
    if (!is_array($schema)) {
        return array('type' => 'object');
    }
    if (isset($schema['required']) && !is_array($schema['required'])) {
        unset($schema['required']);
    }
    if (($schema['type'] ?? '') === 'null') {
        return array('type' => 'object');
    }
    return $schema;
}

function faaaster_mcp_register_abilities()
{
    static $done = false;
    if ($done || !function_exists('wp_register_ability')) {
        return;
    }
    $done = true;

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
                'input_schema' => faaaster_mcp_normalize_schema($definition['input_schema']),
                'output_schema' => faaaster_mcp_normalize_schema($definition['output_schema']),
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
// Register on both candidate hooks (WP 7.0 fires the abilities init under one of
// these; static-guarded so it runs once). Mirrors the validated POC.
foreach (array('abilities_api_init', 'wp_abilities_api_init') as $faaaster_mcp_init_hook) {
    add_action($faaaster_mcp_init_hook, 'faaaster_mcp_register_abilities');
}

/* ---------------------------------------------------------------------------
 * Faaaster agent — abilities access (opt-in, scoped identity)
 *
 * The agent runs WP abilities via the CORE Abilities API (/wp-abilities/v1), AS
 * a dedicated scoped WP user that the OWNER explicitly provisions (opt-in). These
 * localhost-only routes live under `faaaster-agent/v1` — a namespace that does
 * NOT match the main plugin's hostmanager/sso/faaaster-mcp skip-plugins filter,
 * so client/ecosystem abilities load their plugins normally.
 *
 * Replaces the old faaaster-mcp/v1 JWT-direct surface (validated live 2026-06-20;
 * cf. agent-build-vision.md §12). Authorship = a CHOSEN author passed in `input`,
 * never the executor. Method follows the ability annotation (readonly→GET).
 * ------------------------------------------------------------------------- */

const FAAASTER_AGENT_USER_OPTION = 'faaaster_agent_ability_user';

// Cached discovery catalogue. Stored with autoload=NO so it never bloats the
// per-request alloptions bulk-load — it is read ONLY by the list_abilities route
// (one indexed SELECT) and written ONLY on refresh (rare). This is what lets the
// cheap, skip-plugins hostmanager route serve the full registry without ever
// paying a live plugin bootstrap on the discovery path.
const FAAASTER_AGENT_CATALOG_OPTION = 'faaaster_agent_ability_catalog';

// Cached REST surface catalogue (auto-derivation): the live /wp-json route index
// (core + every plugin) compacted to {route, namespace, methods, endpoints[].args}.
// The per-endpoint args ARE the agent-facing "how to call" doc (type/required/enum/
// default/description) — authored by WordPress + each plugin, zero manual upkeep.
// autoload=no (read only by the discovery route).
const FAAASTER_AGENT_REST_CATALOG_OPTION = 'faaaster_agent_rest_catalog';

/** The opt-in scoped user the agent runs abilities as. 0 = access NOT enabled
 *  (no get-or-create here — the user is created only by the owner opt-in below). */
function faaaster_agent_ability_user_id()
{
    $id = (int) get_option(FAAASTER_AGENT_USER_OPTION, 0);
    return ($id && get_user_by('id', $id)) ? $id : 0;
}

/** Build the discovery catalogue from the LIVE registry and cache it (autoload=no).
 *  MUST be called from a plugins-loaded context (the faaaster-agent enable/run
 *  routes, or the refresh ability) so client-plugin abilities are present — NEVER
 *  from the skip-plugins hostmanager path. Returns the stored catalogue. */
function faaaster_agent_build_catalog()
{
    $uid = faaaster_agent_ability_user_id();
    if ($uid && get_current_user_id() !== $uid) {
        wp_set_current_user($uid); // the core list route needs a permitted identity
    }
    $resp = rest_do_request(new WP_REST_Request('GET', '/wp-abilities/v1/abilities'));
    $data = rest_get_server()->response_to_data($resp, false);
    $catalog = array(
        'data'    => is_array($data) ? $data : array(),
        'builtAt' => time(),
    );
    update_option(FAAASTER_AGENT_CATALOG_OPTION, $catalog, false); // autoload=no
    return $catalog;
}

/** Build the REST surface catalogue from the LIVE /wp-json index and cache it
 *  (autoload=no). Per route: namespace, methods, and per-endpoint args (the param
 *  schema — the agent's "how to call" doc). MUST run plugins-loaded (faaaster-agent
 *  context) so plugin routes are present. This is the auto-derived tool surface —
 *  WP + plugins author the schemas; we don't hand-maintain any of it. */
function faaaster_agent_build_rest_catalog()
{
    $uid = faaaster_agent_ability_user_id();
    if ($uid && get_current_user_id() !== $uid) {
        wp_set_current_user($uid);
    }
    $resp   = rest_do_request(new WP_REST_Request('GET', '/'));
    $data   = rest_get_server()->response_to_data($resp, false);
    $routes = (is_array($data) && isset($data['routes']) && is_array($data['routes'])) ? $data['routes'] : array();

    $out = array();
    foreach ($routes as $route => $info) {
        if (!is_array($info)) {
            continue;
        }
        $namespace = isset($info['namespace']) ? (string) $info['namespace'] : '';
        if ($namespace === '') {
            continue; // skip the index/self + namespace-root meta routes
        }
        $endpoints = array();
        if (isset($info['endpoints']) && is_array($info['endpoints'])) {
            foreach ($info['endpoints'] as $ep) {
                $methods = (isset($ep['methods']) && is_array($ep['methods'])) ? array_values($ep['methods']) : array();
                $args    = array();
                if (isset($ep['args']) && is_array($ep['args'])) {
                    foreach ($ep['args'] as $arg_name => $arg_def) {
                        if (!is_array($arg_def)) {
                            continue;
                        }
                        // Keep only the agent-relevant schema bits (drop validate/
                        // sanitize callbacks etc., which aren't JSON anyway).
                        $kept = array();
                        foreach (array('type', 'description', 'required', 'enum', 'default', 'items', 'format') as $k) {
                            if (array_key_exists($k, $arg_def)) {
                                $kept[$k] = $arg_def[$k];
                            }
                        }
                        $args[$arg_name] = $kept;
                    }
                }
                $endpoints[] = array('methods' => $methods, 'args' => $args);
            }
        }
        $out[] = array(
            'route'     => (string) $route,
            'namespace' => $namespace,
            'methods'   => (isset($info['methods']) && is_array($info['methods'])) ? array_values($info['methods']) : array(),
            'endpoints' => $endpoints,
        );
    }

    $catalog = array('data' => $out, 'builtAt' => time());
    update_option(FAAASTER_AGENT_REST_CATALOG_OPTION, $catalog, false); // autoload=no
    return $catalog;
}

function faaaster_agent_register_routes()
{
    $localhost = array('permission_callback' => '__return_true'); // localhost-only (worker only)

    // DISCOVERY (proxy-reachable): serves the CACHED catalogue, under the
    // hostmanager namespace so it reaches Next via the infrapi/hostmanager proxy
    // (matches lib/infrapi.ts listAbilities). It only reads an option (get_option
    // works fine under skip-plugins) — the catalogue is built elsewhere, in
    // plugins-loaded contexts, so this path NEVER pays a live plugin bootstrap.
    // Gated like every other hostmanager route. Returns { data, builtAt, supported }.
    register_rest_route('hostmanager/v1', '/list_abilities', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'faaaster_agent_rest_list',
        'permission_callback' => '__return_true',
    ));
    // DISCOVERY (proxy-reachable): the cached REST surface catalogue (auto-derived
    // tool surface). Same cheap cached-option read as list_abilities.
    register_rest_route('hostmanager/v1', '/list_rest_routes', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'faaaster_agent_rest_routes_list',
        'permission_callback' => '__return_true',
    ));

    // CAPABILITY (proxy-reachable): version du manager-plugin + capacités
    // dérivées. Volontairement TRIVIALE (aucune énumération WP/plugins, juste des
    // constantes) → rapide, à l'inverse de site_state. Le dashboard la lit au
    // moment du SSO pour décider le mode (JWT vs opaque legacy) d'après le plugin
    // RÉELLEMENT en place : un 200 + `ssoJwt:true` ⟹ JWT ; un 404 (vieux plugin
    // sans cette route) ⟹ repli opaque. cf. Next sso/index.ts.
    register_rest_route('hostmanager/v1', '/manager_version', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'faaaster_manager_version',
        'permission_callback' => '__return_true',
    ));

    // EXECUTION (localhost-only): run as the opt-in scoped user, reached ONLY by
    // the in-pod worker over 127.0.0.1 — never the external proxy.
    register_rest_route('faaaster-agent/v1', '/abilities/run', array_merge($localhost, array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_agent_rest_run',
    )));
    // Generalized REST proxy: run ANY WP REST route AS the opt-in scoped user
    // (auto-derivation surface — wp/v2, wc/v3, any plugin route). The scoped user's
    // CAPS are the real containment (rest_do_request honors each route's
    // permission_callback). Read/write classification + per-namespace TOFU trust +
    // write approval are decided UPSTREAM by the Next broker; here we execute +
    // re-enforce a defensive `readOnly` guard. Localhost-only (worker only).
    register_rest_route('faaaster-agent/v1', '/rest', array_merge($localhost, array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_agent_rest_proxy',
    )));
    register_rest_route('faaaster-agent/v1', '/enable', array_merge($localhost, array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_agent_rest_enable',
    )));
    register_rest_route('faaaster-agent/v1', '/disable', array_merge($localhost, array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'faaaster_agent_rest_disable',
    )));
}
add_action('rest_api_init', 'faaaster_agent_register_routes');

/**
 * hostmanager/v1/manager_version — sonde de capacité LÉGÈRE. Renvoie la version du
 * manager-plugin + des flags de capacité. `ssoJwt` est true sur tout build qui
 * embarque le loginSSO JWT-aware (celui-ci le fait) → le dashboard mint un token
 * SSO JWT signé ; l'ABSENCE de cette route (404 sur un vieux plugin) → repli sur
 * le token opaque legacy. Aucune énumération WP/plugins → instantané.
 */
function faaaster_manager_version()
{
    return array(
        'version'      => defined('FAAASTER_MANAGER_VERSION') ? FAAASTER_MANAGER_VERSION : '0',
        'capabilities' => array(
            'ssoJwt' => true,
        ),
    );
}

/** Discovery (served under hostmanager/v1/list_abilities): returns the CACHED
 *  catalogue (core + Faaaster + client-plugin abilities) — a single get_option, so
 *  it is cheap even under skip-plugins and never triggers a live plugin bootstrap.
 *  The catalogue is (re)built by faaaster_agent_build_catalog() on enable / after a
 *  run / via the refresh ability. `builtAt` lets Next decide whether to refresh.
 *  Shape matches lib/infrapi.ts + the list-abilities agent tool. */
function faaaster_agent_rest_list($request)
{
    $catalog = get_option(FAAASTER_AGENT_CATALOG_OPTION, array());
    $data    = (is_array($catalog) && isset($catalog['data']) && is_array($catalog['data'])) ? $catalog['data'] : array();
    return new WP_REST_Response(array(
        'code'      => 'ok',
        'ok'        => true,
        'supported' => true,
        'builtAt'   => (is_array($catalog) && isset($catalog['builtAt'])) ? (int) $catalog['builtAt'] : null,
        'data'      => $data,
    ), 200);
}

/** Discovery (hostmanager/v1/list_rest_routes): the cached REST surface catalogue —
 *  a single get_option, cheap even under skip-plugins. Built in plugins-loaded
 *  contexts (enable / refresh ability). Returns { data, builtAt, count, supported }. */
function faaaster_agent_rest_routes_list($request)
{
    $catalog = get_option(FAAASTER_AGENT_REST_CATALOG_OPTION, array());
    $data    = (is_array($catalog) && isset($catalog['data']) && is_array($catalog['data'])) ? $catalog['data'] : array();
    return new WP_REST_Response(array(
        'code'      => 'ok',
        'ok'        => true,
        'supported' => true,
        'builtAt'   => (is_array($catalog) && isset($catalog['builtAt'])) ? (int) $catalog['builtAt'] : null,
        'count'     => count($data),
        'data'      => $data,
    ), 200);
}

/** Run an ability AS the opt-in scoped user, via the core /run route.
 *  Body: { name, input }. Method follows the annotation (readonly→GET / mutation→POST);
 *  input is passed under the `input` object param (core contract). */
function faaaster_agent_rest_run($request)
{
    $params = $request->get_json_params();
    $name   = isset($params['name']) ? (string) $params['name'] : '';
    $input  = isset($params['input']) ? $params['input'] : array();

    // Core ability names are single-namespace: lowercase alphanumerics + hyphens,
    // exactly one "/" separating namespace from ability (e.g. faaaster-mcp/wp-create-draft-post).
    if (!preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $name)) {
        return new WP_Error('invalid_request', 'Invalid ability name', array('status' => 400));
    }

    $uid = faaaster_agent_ability_user_id();
    if (!$uid) {
        return new WP_Error('ability_access_disabled', 'Agent ability access is not enabled for this site', array('status' => 403));
    }
    wp_set_current_user($uid);

    $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;
    if (!$ability) {
        return new WP_Error('rest_ability_not_found', 'Ability not found', array('status' => 404));
    }
    $meta     = method_exists($ability, 'get_meta') ? (array) $ability->get_meta() : array();
    $readonly = (bool) ($meta['annotations']['readonly'] ?? true);

    // Defense-in-depth: a read-scoped caller (the agent's read-ability tool) sets
    // readonlyOnly so a mutation ability can never be run through the read path
    // (privilege escalation). The annotation is the authority — resolved here.
    if (!empty($params['readonlyOnly']) && !$readonly) {
        return new WP_Error('ability_not_readonly', 'Ability is not read-only', array('status' => 403));
    }

    $req = new WP_REST_Request($readonly ? 'GET' : 'POST', '/wp-abilities/v1/abilities/' . $name . '/run');
    // Core requires `input` to ALWAYS be an object param (even for no-input abilities,
    // whose schema normalizes to type:object) — send an empty object when there's none.
    $input_obj = (is_array($input) && !empty($input)) ? $input : new stdClass();
    $body = array('input' => $input_obj);
    if ($readonly) {
        $req->set_query_params($body);
    } else {
        $req->set_header('Content-Type', 'application/json');
        $req->set_body_params($body);
        $req->set_body(wp_json_encode($body));
    }
    $resp = rest_do_request($req);

    // Opportunistic catalogue refresh: plugins + registry are already loaded for
    // this run, so a rebuild is ~free. Only when stale (>1h) and not the refresh
    // ability itself (it already rebuilt) — keeps discovery warm via normal usage.
    if ($name !== 'faaaster-mcp/wp-refresh-abilities') {
        $cat     = get_option(FAAASTER_AGENT_CATALOG_OPTION, array());
        $builtAt = (is_array($cat) && isset($cat['builtAt'])) ? (int) $cat['builtAt'] : 0;
        if ((time() - $builtAt) > HOUR_IN_SECONDS) {
            faaaster_agent_build_catalog();
        }
    }

    return new WP_REST_Response(array(
        'ok'      => !$resp->is_error(),
        'status'  => $resp->get_status(),
        'ability' => $name,
        'ranAs'   => $uid,
        'data'    => rest_get_server()->response_to_data($resp, false),
    ), 200);
}

/** Generalized REST proxy — run a WP REST route AS the opt-in scoped user.
 *  Body: { method, route, params?, body?, readOnly? }. Executes in-process via
 *  rest_do_request, so each route's permission_callback runs against the scoped
 *  user — its CAPS are the containment (an editor-scoped user can read/write posts
 *  but not manage_options, install plugins, edit users, …). The Next broker decides
 *  WHAT to call and whether a write needs approval / a namespace needs trust; this
 *  only executes + re-enforces a defensive readOnly guard. */
function faaaster_agent_rest_proxy($request)
{
    $params   = $request->get_json_params();
    $method   = strtoupper(isset($params['method']) ? (string) $params['method'] : 'GET');
    $route    = isset($params['route']) ? (string) $params['route'] : '';
    $query    = (isset($params['params']) && is_array($params['params'])) ? $params['params'] : array();
    $body     = $params['body'] ?? null;
    $readOnly = !empty($params['readOnly']);

    if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), true)) {
        return new WP_Error('invalid_request', 'Invalid method', array('status' => 400));
    }
    // Route must be a REST path: leading slash, /namespace/… charset, no traversal,
    // and NO double-slash (else nginx merge_slashes could collapse `/wp/v2//users`
    // → `/wp/v2/users` and dodge the write denylist below).
    if ($route === '' || $route[0] !== '/' || !preg_match('#^/[a-z0-9][a-z0-9._/-]*$#i', $route) || strpos($route, '..') !== false || strpos($route, '//') !== false) {
        return new WP_Error('invalid_request', 'Invalid route', array('status' => 400));
    }
    // Defense-in-depth: a read-scoped task can NEVER perform a write.
    if ($readOnly && $method !== 'GET') {
        return new WP_Error('rest_not_read_only', 'Route call is not read-only', array('status' => 403, 'method' => $method));
    }
    // Defense-in-depth (never trust the queue): re-enforce the dangerous-route WRITE
    // denylist in-pod too — the Next broker (validateRestWrite) is the primary gate,
    // this is the last line. Lowercased to defeat a case bypass. Mirrors
    // lib/services/rest/broker.ts WRITE_DENYLIST.
    if ($method !== 'GET') {
        $low  = strtolower($route);
        $deny = array(
            '/wp/v2/users', '/wp/v2/settings', '/wp/v2/plugins', '/wp/v2/themes',
            '/wp/v2/media', '/wp/v2/templates', '/wp/v2/template-parts',
            '/wp/v2/global-styles', '/wp/v2/font-families', '/wp/v2/font-faces',
            '/wp/v2/widgets', '/wp/v2/block-renderer',
        );
        foreach ($deny as $d) {
            if ($low === $d || strpos($low, $d . '/') === 0) {
                return new WP_Error('rest_write_denied', 'Route is denied for writes', array('status' => 403, 'route' => $route));
            }
        }
    }

    $uid = faaaster_agent_ability_user_id();
    if (!$uid) {
        return new WP_Error('ability_access_disabled', 'Agent ability access is not enabled for this site', array('status' => 403));
    }
    wp_set_current_user($uid);

    $req = new WP_REST_Request($method, $route);
    if (!empty($query)) {
        $req->set_query_params($query);
    }
    if ($method !== 'GET' && $body !== null) {
        $req->set_header('Content-Type', 'application/json');
        $req->set_body_params(is_array($body) ? $body : array());
        $req->set_body(wp_json_encode($body));
    }
    $resp = rest_do_request($req);

    return new WP_REST_Response(array(
        'ok'     => !$resp->is_error(),
        'status' => $resp->get_status(),
        'route'  => $route,
        'method' => $method,
        'ranAs'  => $uid,
        'data'   => rest_get_server()->response_to_data($resp, false),
    ), 200);
}

/** Owner opt-in: provision the scoped agent user (chosen displayName + scoped role).
 *  Creating this user IS the act of granting ability access. */
function faaaster_agent_rest_enable($request)
{
    $existing = faaaster_agent_ability_user_id();
    if ($existing) {
        return new WP_REST_Response(array('ok' => true, 'userId' => $existing, 'already' => true), 200);
    }

    $params = $request->get_json_params();
    $name   = isset($params['displayName']) ? sanitize_text_field($params['displayName']) : 'Faaaster Agent';
    $role   = isset($params['role']) ? sanitize_key($params['role']) : 'editor';
    if (!get_role($role)) {
        $role = 'editor';
    }

    $login = 'faaaster-agent';
    $user  = get_user_by('login', $login);
    if ($user) {
        $id = (int) $user->ID;
        wp_update_user(array('ID' => $id, 'display_name' => $name, 'role' => $role));
    } else {
        $id = wp_insert_user(array(
            'user_login'   => $login,
            'user_pass'    => wp_generate_password(64, true, true),
            'display_name' => $name,
            'role'         => $role,
        ));
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
    }
    update_option(FAAASTER_AGENT_USER_OPTION, $id, false);
    // Populate BOTH discovery catalogues now — enable runs in a plugins-loaded
    // context (faaaster-agent namespace), so client abilities + plugin REST routes
    // are captured.
    $catalog = faaaster_agent_build_catalog();
    $rest    = faaaster_agent_build_rest_catalog();
    return new WP_REST_Response(array(
        'ok'        => true,
        'userId'    => $id,
        'role'      => $role,
        'catalog'   => array('count' => count($catalog['data']), 'builtAt' => $catalog['builtAt']),
        'restRoutes' => array('count' => count($rest['data']), 'builtAt' => $rest['builtAt']),
    ), 200);
}

/** Kill-switch: revoke ability access — remove the options AND the scoped user. */
function faaaster_agent_rest_disable($request)
{
    $id = faaaster_agent_ability_user_id();
    delete_option(FAAASTER_AGENT_USER_OPTION);
    delete_option(FAAASTER_AGENT_CATALOG_OPTION);      // drop the cached ability catalogue
    delete_option(FAAASTER_AGENT_REST_CATALOG_OPTION); // and the REST surface catalogue
    if ($id) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($id);
    }
    return new WP_REST_Response(array('ok' => true, 'removed' => $id), 200);
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

    // Editorial authorship is decoupled from the execution identity: attribute the
    // post to a chosen real site user when provided (the agent runs as a scoped
    // editor with edit_others_posts). AI provenance is recorded separately as meta —
    // the displayed author is NOT the agent. cf. agent-build-vision.md §12.
    $author_id = isset($input['author']) ? absint($input['author']) : 0;
    if ($author_id && get_user_by('id', $author_id)) {
        $post_arr['post_author'] = $author_id;
    }

    $post_id = wp_insert_post($post_arr, true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, '_faaaster_agent_provenance', 'ai');
    update_post_meta($post_id, '_faaaster_agent_executed_by', get_current_user_id());

    return array(
        'postId' => intval($post_id),
        'postType' => $post_type,
        'status' => get_post_status($post_id),
        'author' => intval(get_post_field('post_author', $post_id)),
        'editLink' => get_edit_post_link($post_id, 'raw'),
        'link' => get_permalink($post_id),
    );
}

function faaaster_mcp_ability_list_content($input)
{
    if (!is_array($input)) {
        $input = array();
    }
    $post_type = sanitize_key($input['postType'] ?? 'post');
    if (!in_array($post_type, array('post', 'page'), true)) {
        $post_type = 'post';
    }

    $status = sanitize_key($input['status'] ?? 'any');
    if (!in_array($status, array('any', 'publish', 'draft', 'pending', 'private', 'future'), true)) {
        $status = 'any';
    }
    $post_status = $status === 'any'
        ? array('publish', 'draft', 'pending', 'private', 'future')
        : $status;

    $limit = isset($input['limit']) ? max(1, min(100, intval($input['limit']))) : 20;

    $orderby = sanitize_key($input['orderby'] ?? 'date');
    if (!in_array($orderby, array('date', 'modified', 'title'), true)) {
        $orderby = 'date';
    }
    $order = strtoupper(sanitize_key($input['order'] ?? 'desc'));
    if (!in_array($order, array('ASC', 'DESC'), true)) {
        $order = 'DESC';
    }

    $args = array(
        'post_type' => $post_type,
        'post_status' => $post_status,
        'posts_per_page' => $limit,
        'orderby' => $orderby,
        'order' => $order,
        'no_found_rows' => false,
    );
    $search = sanitize_text_field($input['search'] ?? '');
    if ($search !== '') {
        $args['s'] = $search;
    }

    $wp_query = new WP_Query($args);
    $items = array();
    foreach ($wp_query->posts as $post) {
        $items[] = array(
            'postId' => intval($post->ID),
            'postType' => $post->post_type,
            'status' => $post->post_status,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'authorId' => intval($post->post_author),
            'date' => get_post_datetime($post, 'date', 'gmt') ? get_post_datetime($post, 'date', 'gmt')->format(DATE_ATOM) : null,
            'modified' => get_post_datetime($post, 'modified', 'gmt') ? get_post_datetime($post, 'modified', 'gmt')->format(DATE_ATOM) : null,
            'link' => get_permalink($post),
        );
    }

    return array(
        'postType' => $post_type,
        'totalCount' => intval($wp_query->found_posts),
        'count' => count($items),
        'items' => $items,
    );
}

function faaaster_mcp_ability_list_media($input)
{
    if (!is_array($input)) {
        $input = array();
    }
    $limit = isset($input['limit']) ? max(1, min(100, intval($input['limit']))) : 20;

    $args = array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => false,
    );
    $mime = sanitize_text_field($input['mimeType'] ?? '');
    if ($mime !== '') {
        $args['post_mime_type'] = $mime;
    }
    $search = sanitize_text_field($input['search'] ?? '');
    if ($search !== '') {
        $args['s'] = $search;
    }

    $wp_query = new WP_Query($args);
    $items = array();
    foreach ($wp_query->posts as $post) {
        $items[] = array(
            'id' => intval($post->ID),
            'title' => get_the_title($post),
            'mimeType' => $post->post_mime_type,
            'url' => wp_get_attachment_url($post->ID),
            'date' => get_post_datetime($post, 'date', 'gmt') ? get_post_datetime($post, 'date', 'gmt')->format(DATE_ATOM) : null,
        );
    }

    return array(
        'totalCount' => intval($wp_query->found_posts),
        'count' => count($items),
        'items' => $items,
    );
}

function faaaster_mcp_ability_list_users($input)
{
    // Enumerating users requires the real WP capability — caps CONTAIN this even
    // though the generic ability gate is only edit_posts. A scoped editor gets 403.
    if (!current_user_can('list_users')) {
        return new WP_Error('rest_ability_forbidden', 'list_users capability required', array('status' => 403));
    }
    if (!is_array($input)) {
        $input = array();
    }
    $limit = isset($input['limit']) ? max(1, min(100, intval($input['limit']))) : 50;

    $args = array('number' => $limit);
    $role = sanitize_key($input['role'] ?? '');
    if ($role !== '') {
        $args['role'] = $role;
    }
    $search = sanitize_text_field($input['search'] ?? '');
    if ($search !== '') {
        $args['search'] = '*' . $search . '*';
        $args['search_columns'] = array('user_login', 'display_name', 'user_nicename');
    }

    $users = get_users($args);
    $items = array();
    foreach ($users as $u) {
        // id / name / slug / roles only — NO email, login or registration data:
        // PII stays out of the model.
        $items[] = array(
            'id' => intval($u->ID),
            'name' => $u->display_name,
            'slug' => $u->user_nicename,
            'roles' => array_values($u->roles),
        );
    }

    return array('count' => count($items), 'items' => $items);
}

function faaaster_mcp_ability_list_taxonomy_terms($input)
{
    if (!is_array($input)) {
        $input = array();
    }
    $taxonomy = sanitize_key($input['taxonomy'] ?? 'category');
    if (!in_array($taxonomy, array('category', 'post_tag'), true)) {
        $taxonomy = 'category';
    }
    $limit = isset($input['limit']) ? max(1, min(200, intval($input['limit']))) : 50;

    $args = array(
        'taxonomy' => $taxonomy,
        'number' => $limit,
        'hide_empty' => false,
        'orderby' => 'count',
        'order' => 'DESC',
    );
    $search = sanitize_text_field($input['search'] ?? '');
    if ($search !== '') {
        $args['search'] = $search;
    }

    $terms = get_terms($args);
    if (is_wp_error($terms)) {
        return $terms;
    }
    $items = array();
    foreach ($terms as $t) {
        $items[] = array(
            'id' => intval($t->term_id),
            'name' => $t->name,
            'slug' => $t->slug,
            'count' => intval($t->count),
        );
    }

    return array('taxonomy' => $taxonomy, 'count' => count($items), 'items' => $items);
}

function faaaster_mcp_ability_list_comments($input)
{
    if (!is_array($input)) {
        $input = array();
    }
    $status = sanitize_key($input['status'] ?? 'approve');
    if (!in_array($status, array('approve', 'hold', 'spam', 'trash', 'all'), true)) {
        $status = 'approve';
    }
    // Non-public statuses require moderation rights — caps contain this.
    if ($status !== 'approve' && !current_user_can('moderate_comments')) {
        return new WP_Error('rest_ability_forbidden', 'moderate_comments capability required for non-approved comments', array('status' => 403));
    }
    $limit = isset($input['limit']) ? max(1, min(100, intval($input['limit']))) : 20;

    $args = array('number' => $limit, 'status' => $status);
    $post_id = isset($input['postId']) ? absint($input['postId']) : 0;
    if ($post_id) {
        $args['post_id'] = $post_id;
    }

    $comments = get_comments($args);
    $items = array();
    foreach ($comments as $c) {
        $content = (string) $c->comment_content;
        if (strlen($content) > 500) {
            $content = substr($content, 0, 500);
        }
        // author display name + content + status only — NO email or IP (PII).
        $items[] = array(
            'id' => intval($c->comment_ID),
            'postId' => intval($c->comment_post_ID),
            'author' => $c->comment_author,
            'content' => $content,
            'status' => wp_get_comment_status($c->comment_ID),
            'date' => $c->comment_date_gmt ? mysql2date(DATE_ATOM, $c->comment_date_gmt, false) : null,
        );
    }

    return array('status' => $status, 'count' => count($items), 'items' => $items);
}

function faaaster_mcp_ability_update_post($input)
{
    if (!is_array($input)) {
        $input = array();
    }
    $post_id = absint($input['id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('rest_ability_invalid_input', 'id is required', array('status' => 400));
    }
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('rest_ability_not_found', 'Post not found', array('status' => 404));
    }
    // Per-object capability — the real boundary (the generic gate is only edit_posts).
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('rest_ability_forbidden', 'Not allowed to edit this post', array('status' => 403));
    }

    $update = array('ID' => $post_id);
    if (isset($input['title'])) {
        $update['post_title'] = sanitize_text_field($input['title']);
    }
    if (isset($input['content'])) {
        $update['post_content'] = (string) $input['content'];
    }
    if (isset($input['excerpt'])) {
        $update['post_excerpt'] = (string) $input['excerpt'];
    }
    if (isset($input['status'])) {
        $status = sanitize_key($input['status']);
        if (in_array($status, array('publish', 'draft', 'pending', 'private'), true)) {
            if ($status === 'publish') {
                // Publishing needs the post type's publish capability — contain it.
                $pt = get_post_type_object($post->post_type);
                $publish_cap = ($pt && isset($pt->cap->publish_posts)) ? $pt->cap->publish_posts : 'publish_posts';
                if (!current_user_can($publish_cap)) {
                    return new WP_Error('rest_ability_forbidden', 'Not allowed to publish', array('status' => 403));
                }
            }
            $update['post_status'] = $status;
        }
    }
    if (count($update) <= 1) {
        return new WP_Error('rest_ability_invalid_input', 'No updatable fields provided', array('status' => 400));
    }

    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        return $result;
    }

    update_post_meta($post_id, '_faaaster_agent_last_edited_by', get_current_user_id());

    return array(
        'postId' => intval($post_id),
        'postType' => get_post_type($post_id),
        'status' => get_post_status($post_id),
        'title' => get_the_title($post_id),
        'link' => get_permalink($post_id),
        'editLink' => get_edit_post_link($post_id, 'raw'),
    );
}

function faaaster_mcp_ability_moderate_comment($input)
{
    if (!is_array($input)) {
        $input = array();
    }
    $comment_id = absint($input['id'] ?? 0);
    $action = sanitize_key($input['action'] ?? '');
    if ($comment_id <= 0 || !in_array($action, array('approve', 'hold', 'spam', 'trash'), true)) {
        return new WP_Error('rest_ability_invalid_input', 'id and a valid action are required', array('status' => 400));
    }
    $comment = get_comment($comment_id);
    if (!$comment) {
        return new WP_Error('rest_ability_not_found', 'Comment not found', array('status' => 404));
    }
    // Per-object moderation capability — the real boundary.
    if (!current_user_can('edit_comment', $comment_id)) {
        return new WP_Error('rest_ability_forbidden', 'Not allowed to moderate this comment', array('status' => 403));
    }

    // wp_set_comment_status accepts approve | hold | spam | trash.
    $ok = wp_set_comment_status($comment_id, $action, false);
    if (!$ok) {
        return new WP_Error('rest_ability_failed', 'Could not update comment status', array('status' => 500));
    }

    return array(
        'id' => $comment_id,
        'action' => $action,
        'status' => wp_get_comment_status($comment_id),
    );
}

/** On-demand discovery refresh: rebuild + cache the catalogue. Runs in the
 *  faaaster-agent run context (plugins loaded) so client abilities are captured.
 *  The single explicit refresh lever (agent / UI / throttled session-start). */
function faaaster_mcp_ability_refresh_abilities($input)
{
    $catalog = faaaster_agent_build_catalog();
    $rest    = faaaster_agent_build_rest_catalog();
    return array(
        'count'          => count($catalog['data']),
        'builtAt'        => $catalog['builtAt'],
        'restRouteCount' => count($rest['data']),
    );
}

// install-plugin / toggle-plugin callbacks removed — plugin lifecycle is
// wp-cli-direct (worker), not an ability. cf. agent-build-vision.md §12.
