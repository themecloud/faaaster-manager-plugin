<?php

/**
 * Faaaster Managed Cron — signal de rafraîchissement des URLs
 * (wp-builder TODO/managed-cron.md).
 *
 * Le runner managed-cron (root, in-pod) tire wp-cron sur les URLs mises en
 * cache dans son state file par managed-cron-apply. Certains événements WP
 * changent ces URLs SANS aucun événement pod : sous-site multisite créé ou
 * supprimé, home/siteurl modifié depuis wp-admin. Cette classe pose alors un
 * flag tmpfs ; le runner le détecte à son prochain tick (test d'existence,
 * zéro coût) et ré-invoque managed-cron-apply, qui re-résout les URLs depuis
 * la DB et consomme le flag.
 */
class FaaasterManagedCronManager
{
    const REFRESH_FLAG = '/tmp/managed-cron-refresh';

    public function init()
    {
        // Managed Cron actif ⇒ DISABLE_WP_CRON est convergée par l'apply, la
        // constante est donc déjà en mémoire : garde à zéro I/O. Elle est aussi
        // vraie sur un site à cron externe (posée à la main) — touch inoffensif
        // dans ce cas, personne ne lit le flag sans runner.
        if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            return;
        }
        add_action('wp_initialize_site', [$this, 'flagRefresh'], 10, 0);
        add_action('wp_uninitialize_site', [$this, 'flagRefresh'], 10, 0);
        add_action('update_option_home', [$this, 'flagRefresh'], 10, 0);
        add_action('update_option_siteurl', [$this, 'flagRefresh'], 10, 0);
    }

    public function flagRefresh()
    {
        @touch(self::REFRESH_FLAG);
    }
}
