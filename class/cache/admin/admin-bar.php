<?php

/**
 * Barre d'administration : « Purger le cache » → tout / cette page. Le nœud
 * garde le style natif de WordPress (il est hors de notre page). Les pages
 * d'un utilisateur connecté ne sont jamais servies depuis le cache nginx.
 */
class FaaasterCacheAdminBar
{
    const ACTION = 'faaaster_cache_bar';

    private $cache;

    public function __construct(FaaasterCache $cache)
    {
        $this->cache = $cache;
    }

    public function register()
    {
        $this->cache->hook('admin_bar_menu', array($this, 'menu'), 100, 1);
        $this->cache->hook('admin_post_' . self::ACTION, array($this, 'handle'), 10, 0);
        $this->cache->hook('admin_notices', array($this, 'notice'), 10, 0);
    }

    public function menu($bar)
    {
        if (!is_object($bar) || !current_user_can('manage_options')) {
            return;
        }
        $bar->add_node(array(
            'id' => 'faaaster-cache',
            'title' => esc_html__('Purge cache', 'faaaster-manager-plugin'),
            'href' => FaaasterCacheAdmin::page_url('tools'),
        ));
        $bar->add_node(array(
            'parent' => 'faaaster-cache',
            'id' => 'faaaster-cache-all',
            'title' => esc_html__('Entire cache', 'faaaster-manager-plugin'),
            'href' => $this->link('all', ''),
        ));
        if (!is_admin()) {
            $current = set_url_scheme((is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'));
            $bar->add_node(array(
                'parent' => 'faaaster-cache',
                'id' => 'faaaster-cache-page',
                'title' => esc_html__('This page', 'faaaster-manager-plugin'),
                'href' => $this->link('page', $current),
            ));
        }
    }

    public function handle()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage the page cache.', 'faaaster-manager-plugin'), 403);
        }
        check_admin_referer(self::ACTION);
        $scope = isset($_GET['scope']) ? sanitize_key(wp_unslash($_GET['scope'])) : 'all';
        $queue = $this->cache->queue();
        if ($scope === 'page') {
            $url = isset($_GET['url']) ? esc_url_raw(wp_unslash($_GET['url'])) : '';
            $parsed = FaaasterCacheUrl::parse($url);
            $site = FaaasterCache::site_from_wordpress();
            // Adresse hors des hôtes du site : rien à purger (jamais de repli en purge totale).
            if ($parsed && FaaasterCacheUrl::host_allowed($parsed['host'], $site['hosts'])) {
                $queue->enqueue_url($parsed['url'], 'admin_bar');
                $queue->flush(true);
                wp_safe_redirect($parsed['url']);
                exit;
            }
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $report = $queue->enqueue_all('admin_bar', true);
        set_transient('faaaster_cache_bar_' . get_current_user_id(), !empty($report['ok']) ? 'ok' : 'danger', 120);
        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url());
        exit;
    }

    /** Notice WordPress après une purge totale lancée depuis la barre (pages admin). */
    public function notice()
    {
        $key = 'faaaster_cache_bar_' . get_current_user_id();
        $status = get_transient($key);
        if (!$status) {
            return;
        }
        delete_transient($key);
        $ok = $status === 'ok';
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $ok ? 'success' : 'error',
            esc_html($ok
                ? __('The whole page cache has been purged.', 'faaaster-manager-plugin')
                : __('The page cache could not be fully purged. Please try again; if it keeps failing, contact support.', 'faaaster-manager-plugin'))
        );
    }

    private function link($scope, $url)
    {
        $args = array('action' => self::ACTION, 'scope' => $scope);
        if ($url !== '') {
            $args['url'] = rawurlencode($url);
        }
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::ACTION);
    }
}
