<?php

/**
 * Politique plateforme pour WP Rocket (reprise de wp-builder conf/faaaster-wp-rocket.php).
 *
 * Chargée hors de l'interrupteur du module cache : ce n'est pas du pilotage de
 * cache mais une protection des pods (2 workers FPM).
 * - le cache de pages de WP Rocket est coupé (nginx FastCGI fait ce travail),
 *   sauf si DISABLE_WPROCKET vaut autre chose que « true » ;
 * - le préchargement et le RUCSS sont bridés (lots plus petits, intervalles et
 *   délai plus longs). Filtres vérifiés : docs/cache/hooks-verified.md (D).
 */
final class FaaasterWpRocketPolicy
{
    const PRELOAD_ROWS = 25;
    const PRELOAD_MIN_IN_PROGRESS = 3;
    const PRELOAD_INTERVAL = 120;
    /** Microsecondes (passé à usleep par WP Rocket) : 1 s, contre 0,5 s par défaut. */
    const PRELOAD_DELAY_US = 1000000;
    const SAAS_ROWS = 25;
    const SAAS_INTERVAL = 120;

    public static function register()
    {
        // Lu tôt par WP Rocket (buffer de sortie) : posé au chargement du mu-plugin.
        if (!isset($_SERVER['DISABLE_WPROCKET']) || $_SERVER['DISABLE_WPROCKET'] === 'true') {
            add_filter('do_rocket_generate_caching_files', '__return_false');
        }
        if (defined('FAAASTER_WP_ROCKET_POLICY_DISABLED') && constant('FAAASTER_WP_ROCKET_POLICY_DISABLED')) {
            return;
        }
        add_action('plugins_loaded', array(__CLASS__, 'throttle'));
    }

    /** @return array filtre => valeur (exposé pour les tests) */
    public static function values($version)
    {
        $values = array(
            'rocket_preload_cache_pending_jobs_cron_rows_count' => self::PRELOAD_ROWS,
            'rocket_preload_pending_jobs_cron_interval' => self::PRELOAD_INTERVAL,
            'rocket_preload_delay_between_requests' => self::PRELOAD_DELAY_US,
        );
        if (version_compare($version, '3.16.2', '>=')) {
            $values['rocket_preload_cache_min_in_progress_jobs_count'] = self::PRELOAD_MIN_IN_PROGRESS;
        }
        // 3.16 renomme les filtres RUCSS en SaaS (l'ancien nom reste appliqué en déprécié).
        if (version_compare($version, '3.16', '>=')) {
            $values['rocket_saas_pending_jobs_cron_rows_count'] = self::SAAS_ROWS;
            $values['rocket_saas_pending_jobs_cron_interval'] = self::SAAS_INTERVAL;
        } else {
            $values['rocket_rucss_pending_jobs_cron_rows_count'] = self::SAAS_ROWS;
            $values['rocket_rucss_pending_jobs_cron_interval'] = self::SAAS_INTERVAL;
        }
        return $values;
    }

    public static function throttle()
    {
        if (!defined('WP_ROCKET_VERSION')) {
            return;
        }
        foreach (self::values(WP_ROCKET_VERSION) as $filter => $value) {
            add_filter($filter, function () use ($value) {
                return $value;
            });
        }
    }
}
