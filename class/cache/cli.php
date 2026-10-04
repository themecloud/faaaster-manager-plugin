<?php

/**
 * Pilote le cache de pages Faaaster (FastCGI + Cloudflare).
 */
class FaaasterCacheCli
{
    public static function register()
    {
        if (!class_exists('WP_CLI')) {
            return;
        }
        WP_CLI::add_command('faaaster cache', __CLASS__);
        // Alias déprécié de la commande du fork, seulement si le fork n'est pas chargé
        // (sinon sa propre commande existe déjà et passe par le shim $nginx_purger).
        if (!class_exists('Nginx_Helper', false)) {
            WP_CLI::add_command('nginx-helper', 'FaaasterCacheCliNginxHelperAlias');
        }
    }

    /**
     * Purge des URL, ou tout le cache.
     *
     * ## OPTIONS
     *
     * [<url>...]
     * : URL à purger (en arguments : `--url` est une option globale de WP-CLI).
     *
     * [--all]
     * : Purge tout le cache de pages.
     *
     * ## EXAMPLES
     *
     *     wp faaaster cache purge --all
     *     wp faaaster cache purge https://example.com/contact/
     *
     * @when after_wp_load
     */
    public function purge($args, $assoc_args)
    {
        $cache = faaaster_cache();
        if (!$cache) {
            WP_CLI::error('Faaaster cache module is disabled.');
        }
        if (!empty($assoc_args['all'])) {
            $report = $cache->queue()->enqueue_all('cli', true);
            self::print_report($report);
            return;
        }
        $urls = $args;
        if (empty($urls)) {
            WP_CLI::error('Give at least one URL, or --all.');
        }
        foreach ($urls as $url) {
            if (!$cache->queue()->enqueue_url($url, 'cli')) {
                WP_CLI::warning('Ignored invalid URL: ' . $url);
            }
        }
        self::print_report($cache->queue()->flush(true));
    }

    /**
     * Affiche l'état du module.
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args)
    {
        $cache = faaaster_cache();
        WP_CLI::line(json_encode(array(
            'module' => (bool) $cache,
            'fork_loaded' => class_exists('Nginx_Helper', false),
            'takeover' => $cache ? (bool) $cache->context('takeover') : false,
            'takeover_removed' => FaaasterCacheTakeover::removed(),
            'integrations' => $cache && $cache->integrations() ? $cache->integrations()->status() : array(),
            'third_party_page_cache' => FaaasterCacheIntegrationRegistry::third_party_page_cache(),
            'settings' => $cache ? $cache->settings()->all() : null,
        ), JSON_PRETTY_PRINT));
    }

    /**
     * Règles de TTL par contexte (X-Accel-Expires). Sans règle, la durée nginx
     * par défaut (/app/conf/cache-ttl.user.conf) s'applique.
     *
     * ## OPTIONS
     *
     * <action>
     * : list | set | unset | reset
     *
     * [<context>]
     * : 404, front_page, home, singular[:<type>], archive[:<type>], taxonomy[:<tax>], author, date, search, feed
     *
     * [<seconds>]
     * : Durée en secondes (0 = ne pas mettre en cache). Pour `set`.
     *
     * ## EXAMPLES
     *
     *     wp faaaster cache ttl list
     *     wp faaaster cache ttl set front_page 3600
     *     wp faaaster cache ttl set 404 600
     *     wp faaaster cache ttl unset front_page
     *
     * @when after_wp_load
     */
    public function ttl($args, $assoc_args)
    {
        $cache = faaaster_cache();
        if (!$cache) {
            WP_CLI::error('Faaaster cache module is disabled.');
        }
        $action = isset($args[0]) ? $args[0] : 'list';
        $ttl = (array) $cache->settings()->get('ttl');
        if ($action === 'set' || $action === 'unset') {
            if (empty($args[1])) {
                WP_CLI::error('Give a context.');
            }
            if ($action === 'set') {
                if (!isset($args[2]) || !is_numeric($args[2])) {
                    WP_CLI::error('Give a duration in seconds.');
                }
                $ttl['rules'][$args[1]] = (int) $args[2];
            } else {
                unset($ttl['rules'][$args[1]]);
            }
            $ttl = $cache->settings()->update_section('ttl', $ttl);
        } elseif ($action === 'reset') {
            $ttl['rules'] = array();
            $ttl = $cache->settings()->update_section('ttl', $ttl);
        } elseif ($action !== 'list') {
            WP_CLI::error('Unknown action: ' . $action);
        }
        WP_CLI::line(json_encode(array(
            'rules' => (object) $ttl['rules'],
            'nginx_default' => FaaasterCacheNginxConf::valid_map(),
            'guards' => array(
                'donotcachepage' => $ttl['donotcachepage'],
                'nonce_cap' => $ttl['nonce_cap'],
                'diagnostic' => $ttl['diagnostic'],
            ),
        ), JSON_PRETTY_PRINT));
        if ($action !== 'list') {
            WP_CLI::warning('Pages already cached keep their previous duration until purged.');
        }
    }

    public static function print_report($report)
    {
        $line = json_encode($report);
        if (!empty($report['ok'])) {
            WP_CLI::success($line);
        } else {
            WP_CLI::error($line);
        }
    }
}

class FaaasterCacheCliNginxHelperAlias
{
    /**
     * Déprécié : utiliser `wp faaaster cache purge --all`.
     *
     * @when after_wp_load
     */
    public function purge_all($args, $assoc_args)
    {
        WP_CLI::warning('Deprecated: use `wp faaaster cache purge --all`.');
        $cache = faaaster_cache();
        if (!$cache) {
            WP_CLI::error('Faaaster cache module is disabled.');
        }
        FaaasterCacheCli::print_report($cache->queue()->enqueue_all('cli:nginx-helper', true));
    }
}
