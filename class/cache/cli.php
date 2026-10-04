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
            'settings' => $cache ? $cache->settings()->all() : null,
        ), JSON_PRETTY_PRINT));
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
