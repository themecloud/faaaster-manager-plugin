<?php

/**
 * Purge complète déclenchée par la plateforme (route hostmanager/v1/clear_cache :
 * bouton « Vider le cache », changement de domaine, fstr-worker, restauration).
 *
 * C'est le SEUL endroit qui vide aussi l'object cache (APCu de PHP-FPM) : les
 * mutations WP-CLI (toggle, mises à jour, restauration) ne l'invalident pas, leur
 * segment APCu est séparé. Jamais l'opcache : les images valident les dates des
 * fichiers (validate_timestamps=1, revalidate_freq=2), un fichier modifié est
 * recompilé en ≤ 2 s ; un reset ne rafraîchit rien et recompile tout le code à
 * froid (premier MISS mesuré à 8,5 s contre 1,2 s le 04/10/2026). Code réellement
 * périmé : redémarrage de php-fpm (consumer, scope php).
 */
class FaaasterCacheHostmanager
{
    private $queue;

    public function __construct(FaaasterCachePurgeQueue $queue)
    {
        $this->queue = $queue;
    }

    /** @return array rapport de purge + object_cache */
    public function hard_flush()
    {
        $report = $this->queue->enqueue_all('hostmanager', true);
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        $report['object_cache'] = function_exists('wp_cache_flush');
        return $report;
    }

    /**
     * hostmanager/v1/flush_object_cache : vide l'object cache DANS PHP-FPM, pour
     * le module quand il tourne en CLI (segment APCu séparé). Pas d'opcache.
     */
    public function rest_flush_object_cache($request = null)
    {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        return new WP_REST_Response(array('code' => 'ok'), 200);
    }

    /**
     * Réponse honnête : 502 purge_failed si la purge FastCGI n'a pas abouti
     * (le consumer en fait un avertissement, Next un message d'erreur client).
     */
    public function rest_clear_cache($request = null)
    {
        $report = $this->hard_flush();
        $data = array(
            'fastcgi' => $report['nginx'],
            'cloudflare' => $report['cloudflare'],
            'object_cache' => $report['object_cache'],
        );
        if (!empty($report['ok'])) {
            return new WP_REST_Response(array('code' => 'ok', 'data' => $data), 200);
        }
        return new WP_REST_Response(array('code' => 'purge_failed', 'data' => $data), 502);
    }
}
