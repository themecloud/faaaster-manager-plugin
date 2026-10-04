<?php

/**
 * Reprise en main du fork faaaster-cache-manager (Nginx Helper) quand il est
 * encore chargé : le module devient seul pilote des purges et de l'interface.
 *
 * Les mu-plugins se chargent par ordre alphabétique : faaaster-cache-manager.php
 * a déjà enregistré tous ses hooks (sur $nginx_helper_admin et $nginx_purger)
 * quand faaaster-manager-plugin.php s'exécute. En pratique, la release d'image
 * retire le fork : cette reprise est un filet de sécurité (et couvre le plugin
 * Nginx Helper classique, si un client le réinstalle).
 */
class FaaasterCacheTakeover
{
    /** Callbacks de l'intégration nginx-helper native de WP Rocket (hooks-verified.md, D). */
    const WP_ROCKET_NGINX_CALLBACKS = array(
        array('admin_init', 'rocket_clear_cache_after_nginx_helper_purge'),
        array('init', 'rocket_clear_current_page_after_nginx_helper_purge'),
        array('after_rocket_clean_home', 'rocket_clean_nginx_cache_home'),
        array('after_rocket_clean_file', 'rocket_clean_nginx_cache_url'),
        array('rocket_rucss_after_clearing_usedcss', 'rocket_clean_nginx_cache_url'),
        array('rocket_after_clean_domain', 'rocket_clean_nginx_helper_cache'),
        array('rocket_saas_complete_job_status', 'rocket_clean_nginx_helper_cache'),
    );

    /** @var string[] trace « hook@prio::Classe->méthode » des callbacks retirés */
    private static $removed = array();

    public static function run(FaaasterCache $cache)
    {
        self::sweep();
        // Sans $nginx_helper, WP Rocket ne charge pas son intégration nginx-helper
        // (sur plugins_loaded) : plus de purge totale à chaque job RUCSS.
        unset($GLOBALS['nginx_helper']);
        $GLOBALS['nginx_purger'] = new FaaasterNginxPurgerCompat();
        FaaasterCacheCompat::listen($cache);
        $cache->hook('init', array(__CLASS__, 'late'), 1, 0);
    }

    /** Après le chargement des extensions : WP Rocket et un éventuel Nginx Helper classique. */
    public static function late()
    {
        foreach (self::WP_ROCKET_NGINX_CALLBACKS as $pair) {
            if (remove_action($pair[0], $pair[1], 10)) {
                self::$removed[] = $pair[0] . '@10::' . $pair[1];
            }
        }
        if (self::sweep() > 0) {
            unset($GLOBALS['nginx_helper']);
            if (!($GLOBALS['nginx_purger'] instanceof FaaasterNginxPurgerCompat)) {
                $GLOBALS['nginx_purger'] = new FaaasterNginxPurgerCompat();
            }
        }
    }

    /** Retire tout callback porté par un objet du fork. @return int nombre retiré */
    public static function sweep()
    {
        if (empty($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
            return 0;
        }
        $count = 0;
        foreach ($GLOBALS['wp_filter'] as $hook => $wp_hook) {
            $callbacks = is_object($wp_hook) && isset($wp_hook->callbacks) ? $wp_hook->callbacks : (is_array($wp_hook) ? $wp_hook : array());
            foreach ($callbacks as $priority => $items) {
                foreach ((array) $items as $item) {
                    $fn = isset($item['function']) ? $item['function'] : null;
                    if (is_array($fn) && isset($fn[0], $fn[1]) && is_object($fn[0]) && self::is_fork_object($fn[0])) {
                        remove_filter($hook, $fn, $priority);
                        self::$removed[] = $hook . '@' . $priority . '::' . get_class($fn[0]) . '->' . $fn[1];
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    public static function is_fork_object($object)
    {
        return is_a($object, 'Nginx_Helper_Admin') || is_a($object, 'Purger') || is_a($object, 'Nginx_Helper');
    }

    public static function removed()
    {
        return self::$removed;
    }

    /** Tests uniquement. */
    public static function reset()
    {
        self::$removed = array();
    }
}
