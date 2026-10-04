<?php

/**
 * Compatibilité avec l'API du fork Nginx Helper pour ses appelants :
 * - le build-agent de Next (`wp eval` + `global $nginx_purger`) ;
 * - les extensions qui émettent `rt_nginx_helper_purge_all`.
 * On ne définit JAMAIS `$nginx_helper` : son absence garde éteinte l'intégration
 * nginx-helper native de WP Rocket (purge totale à chaque job RUCSS).
 */
class FaaasterNginxPurgerCompat
{
    public function purge_url($url, $feed = true)
    {
        $cache = faaaster_cache();
        if (!$cache) {
            return;
        }
        $cache->queue()->enqueue_url($url, 'compat:purge_url');
        if ($cache->context('cli')) {
            $cache->queue()->flush(true);
        }
    }

    public function purge_all($force = false)
    {
        $cache = faaaster_cache();
        if ($cache) {
            $cache->queue()->enqueue_all('compat:purge_all', $force === true || $cache->context('cli'));
        }
    }

    public function purge_post($post_id)
    {
        $cache = faaaster_cache();
        if (!$cache || !function_exists('get_permalink')) {
            return;
        }
        $url = get_permalink($post_id);
        if ($url) {
            $this->purge_url($url);
        }
    }

    public function custom_purge_urls()
    {
    }

    public function log($msg = '', $level = 'INFO')
    {
    }

    public function __call($name, $arguments)
    {
        FaaasterCache::log('compat: unknown nginx_purger method ' . $name);
        return null;
    }
}

class FaaasterCacheCompat
{
    public static function register(FaaasterCache $cache)
    {
        // Fork présent : sa reprise en main installe le shim (takeover.php).
        if ($cache->context('fork_present')) {
            return;
        }
        if (!isset($GLOBALS['nginx_purger'])) {
            $GLOBALS['nginx_purger'] = new FaaasterNginxPurgerCompat();
        }
        self::listen($cache);
    }

    /** Une extension tierce demande une purge totale via l'API du fork. */
    public static function listen(FaaasterCache $cache)
    {
        $cache->hook('rt_nginx_helper_purge_all', function ($force = '') use ($cache) {
            $cache->queue()->enqueue_all('rt_nginx_helper_purge_all', $force === true);
        }, 10, 1);
    }
}
