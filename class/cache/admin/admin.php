<?php

/**
 * Page Réglages › Cache Faaaster (manage_options). Rendu serveur aligné sur le
 * DS Calm Grid de Next (classes .fstr-ds, scripts/sync-ds.mjs). Toutes les
 * actions passent par admin-post.php (nonce + capacité), puis redirection.
 */
class FaaasterCacheAdmin
{
    const SLUG = 'faaaster-cache';
    const NONCE = 'faaaster_cache_admin';
    const TABS = array('rules', 'ttl', 'tools', 'events', 'integrations');

    private $cache;

    public function __construct(FaaasterCache $cache)
    {
        $this->cache = $cache;
    }

    public function register()
    {
        $c = $this->cache;
        $c->hook('init', array($this, 'load_textdomain'), 1, 0);
        $c->hook('admin_menu', array($this, 'menu'), 10, 0);
        $c->hook('admin_enqueue_scripts', array($this, 'assets'), 10, 1);
        foreach (array('save_rules', 'save_ttl', 'purge_all', 'purge_url', 'test_url', 'clear_events') as $action) {
            $c->hook('admin_post_faaaster_cache_' . $action, array($this, 'handle_' . $action), 10, 0);
        }
        $bar = new FaaasterCacheAdminBar($c);
        $bar->register();
    }

    public function load_textdomain()
    {
        if (function_exists('load_muplugin_textdomain')) {
            load_muplugin_textdomain('faaaster-manager-plugin', basename(self::plugin_root()) . '/languages');
        }
    }

    public function menu()
    {
        add_options_page(
            __('Faaaster Cache', 'faaaster-manager-plugin'),
            __('Faaaster Cache', 'faaaster-manager-plugin'),
            'manage_options',
            self::SLUG,
            array($this, 'render')
        );
    }

    /** Feuilles et script uniquement sur notre page. */
    public function assets($hook_suffix)
    {
        if ($hook_suffix !== 'settings_page_' . self::SLUG) {
            return;
        }
        $base = self::plugin_root() . '/faaaster-manager-plugin.php';
        $ver = defined('FAAASTER_MANAGER_VERSION') ? FAAASTER_MANAGER_VERSION : '0';
        wp_enqueue_style('faaaster-ds-tokens', plugins_url('assets/ds/tokens.css', $base), array(), $ver);
        wp_enqueue_style('faaaster-ds-components', plugins_url('assets/ds/components.css', $base), array('faaaster-ds-tokens'), $ver);
        wp_enqueue_style('faaaster-cache-admin', plugins_url('assets/admin.css', $base), array('faaaster-ds-components'), $ver);
        wp_enqueue_script('faaaster-cache-admin', plugins_url('assets/admin.js', $base), array(), $ver, true);
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'rules';
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'rules';
        }
        $views = new FaaasterCacheAdminViews($this->cache);
        $views->page($tab, $this->take_notice());
    }

    // ---- Actions -------------------------------------------------------------

    public function handle_save_rules()
    {
        $this->guard();
        $in = isset($_POST['purge']) && is_array($_POST['purge']) ? wp_unslash($_POST['purge']) : array();
        $this->cache->settings()->update_section('purge', $in);
        $this->done('rules', 'ok', __('Purge rules saved.', 'faaaster-manager-plugin'));
    }

    public function handle_save_ttl()
    {
        $this->guard();
        $in = isset($_POST['ttl']) && is_array($_POST['ttl']) ? wp_unslash($_POST['ttl']) : array();
        $rules = array();
        if (isset($in['rules']) && is_array($in['rules'])) {
            foreach ($in['rules'] as $context => $rule) {
                $seconds = self::to_seconds(isset($rule['value']) ? $rule['value'] : '', isset($rule['unit']) ? $rule['unit'] : 's');
                if ($seconds !== null) {
                    $rules[(string) $context] = $seconds;
                }
            }
        }
        $in['rules'] = $rules;
        $this->cache->settings()->update_section('ttl', $in);
        $this->done('ttl', 'ok', __('Cache durations saved. Pages already cached keep their previous duration until they are purged.', 'faaaster-manager-plugin'));
    }

    public function handle_purge_all()
    {
        $this->guard();
        $report = $this->cache->queue()->enqueue_all('admin', true);
        $this->done(
            self::return_tab(),
            !empty($report['ok']) ? 'ok' : 'danger',
            !empty($report['ok'])
                ? __('The whole page cache has been purged.', 'faaaster-manager-plugin')
                : __('The page cache could not be fully purged. Please try again; if it keeps failing, contact support.', 'faaaster-manager-plugin')
        );
    }

    public function handle_purge_url()
    {
        $this->guard();
        $url = isset($_POST['url']) ? esc_url_raw(trim(wp_unslash($_POST['url']))) : '';
        $parsed = FaaasterCacheUrl::parse($url);
        $site = FaaasterCache::site_from_wordpress();
        if (!$parsed || !FaaasterCacheUrl::host_allowed($parsed['host'], $site['hosts'])) {
            $this->done('tools', 'danger', __('Enter a full address of this site, for example https://example.com/page/.', 'faaaster-manager-plugin'));
        }
        $this->cache->queue()->enqueue_url($parsed['url'], 'admin');
        $report = $this->cache->queue()->flush(true);
        $this->done(
            'tools',
            !empty($report['ok']) ? 'ok' : 'danger',
            !empty($report['ok'])
                ? sprintf(__('Purged: %s', 'faaaster-manager-plugin'), $parsed['url'])
                : __('This address could not be purged. Please try again; if it keeps failing, contact support.', 'faaaster-manager-plugin')
        );
    }

    /**
     * Deux requêtes en boucle locale (127.0.0.1) avec l'hôte du site : statut,
     * cache nginx, durée et règle appliquées. Hôte limité aux hôtes du site.
     */
    public function handle_test_url()
    {
        $this->guard();
        $url = isset($_POST['url']) ? esc_url_raw(trim(wp_unslash($_POST['url']))) : '';
        $parsed = FaaasterCacheUrl::parse($url);
        $site = FaaasterCache::site_from_wordpress();
        if (!$parsed || !FaaasterCacheUrl::host_allowed($parsed['host'], $site['hosts'])) {
            $this->done('tools', 'danger', __('Enter a full address of this site, for example https://example.com/page/.', 'faaaster-manager-plugin'));
        }
        $results = array();
        for ($i = 0; $i < 2; $i++) {
            $start = microtime(true);
            $response = wp_remote_get('http://127.0.0.1' . $parsed['path'] . ($parsed['query'] !== '' ? '?' . $parsed['query'] : ''), array(
                'headers' => array('Host' => $parsed['host'], 'X-Forwarded-Proto' => 'https', 'User-Agent' => 'Faaaster-Cache-Test/1.0'),
                'redirection' => 0,
                'timeout' => 15,
                'cookies' => array(),
            ));
            $ms = (int) round((microtime(true) - $start) * 1000);
            if (is_wp_error($response)) {
                $results[] = array('error' => $response->get_error_message(), 'ms' => $ms);
                continue;
            }
            $results[] = array(
                'status' => (int) wp_remote_retrieve_response_code($response),
                'fastcgi' => (string) wp_remote_retrieve_header($response, 'x-fastcgi-cache'),
                'ttl' => (string) wp_remote_retrieve_header($response, 'x-faaaster-cache-ttl'),
                'set_cookie' => wp_remote_retrieve_header($response, 'set-cookie') ? true : false,
                'ms' => $ms,
            );
        }
        set_transient('faaaster_cache_test_' . get_current_user_id(), array('url' => $parsed['url'], 'results' => $results), 300);
        $this->done('tools', null, null);
    }

    public function handle_clear_events()
    {
        $this->guard();
        $this->cache->events()->clear();
        $this->done('events', 'ok', __('Purge log cleared.', 'faaaster-manager-plugin'));
    }

    // ---- Outils ------------------------------------------------------------

    public static function action_url($action)
    {
        return admin_url('admin-post.php');
    }

    public static function page_url($tab = 'rules')
    {
        return admin_url('options-general.php?page=' . self::SLUG . '&tab=' . $tab);
    }

    /** « 10 », « 2 » + « h » → secondes ; vide → null (défaut nginx). */
    public static function to_seconds($value, $unit)
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            return null;
        }
        $units = array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400);
        return (int) round((float) $value * (isset($units[$unit]) ? $units[$unit] : 1));
    }

    public static function plugin_root()
    {
        return dirname(dirname(dirname(__DIR__)));
    }

    private function guard()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage the page cache.', 'faaaster-manager-plugin'), 403);
        }
        check_admin_referer(self::NONCE);
    }

    private static function return_tab()
    {
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'tools';
        return in_array($tab, self::TABS, true) ? $tab : 'tools';
    }

    private function done($tab, $type, $message)
    {
        if ($message !== null) {
            set_transient('faaaster_cache_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        }
        wp_safe_redirect(self::page_url($tab));
        exit;
    }

    private function take_notice()
    {
        $key = 'faaaster_cache_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
        }
        return $notice ? $notice : null;
    }
}
