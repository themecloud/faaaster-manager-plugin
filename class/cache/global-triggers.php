<?php

/**
 * Filet global : purge totale sur les changements de structure (mises à jour,
 * thème, menus, customizer, réglages de lecture…). Couvre tous les
 * constructeurs, même inconnus : une mise à jour d'extension peut effacer leurs
 * CSS générés (incident Elementor du 30/09/2026).
 */
class FaaasterCacheGlobalTriggers
{
    const OPTIONS = array(
        'blogname', 'blogdescription', 'site_icon', 'permalink_structure', 'category_base',
        'tag_base', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page',
        'date_format', 'time_format', 'start_of_week', 'timezone_string', 'gmt_offset',
        'WPLANG', 'home', 'siteurl', 'sidebars_widgets', 'page_comments', 'comments_per_page',
        'default_comments_page', 'comment_order',
    );
    const OPTION_PREFIXES = array('widget_', 'theme_mods_');

    private $cache;

    public function __construct(FaaasterCache $cache)
    {
        $this->cache = $cache;
    }

    public function register()
    {
        $c = $this->cache;
        $c->hook('upgrader_process_complete', array($this, 'on_upgrade'), 10, 2);
        foreach (array('activated_plugin', 'deactivated_plugin', 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'wp_delete_nav_menu') as $hook) {
            $c->hook($hook, function () use ($hook) {
                faaaster_cache()->queue()->enqueue_all($hook);
            }, 10, 0);
        }
        $c->hook('updated_option', array($this, 'on_option'), 10, 1);
        $c->hook('added_option', array($this, 'on_option'), 10, 1);
    }

    public function on_upgrade($upgrader = null, $hook_extra = array())
    {
        $type = (is_array($hook_extra) && isset($hook_extra['type'])) ? $hook_extra['type'] : 'unknown';
        $this->cache->queue()->enqueue_all('upgrader:' . $type);
    }

    public function on_option($option)
    {
        if (!self::watched($option)) {
            return;
        }
        // Certains thèmes réécrivent un theme_mod à chaque vue : une écriture
        // pendant la visite d'un anonyme ne déclenche pas de purge totale.
        if (self::anonymous_front_get()) {
            return;
        }
        $this->cache->queue()->enqueue_all('option:' . $option);
    }

    public static function watched($option)
    {
        if (in_array($option, self::OPTIONS, true)) {
            return true;
        }
        foreach (self::OPTION_PREFIXES as $prefix) {
            if (strpos($option, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function anonymous_front_get()
    {
        if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        return !(function_exists('is_user_logged_in') && is_user_logged_in());
    }
}
