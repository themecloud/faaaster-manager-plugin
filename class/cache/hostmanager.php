<?php

/**
 * Purge complète déclenchée par la plateforme (route hostmanager/v1/clear_cache :
 * bouton « Vider le cache », changement de domaine, fstr-worker).
 *
 * C'est le SEUL endroit qui vide aussi l'object cache et l'opcache : ce sont des
 * caches de code, qu'un vidage automatique ne doit jamais toucher (un reset
 * opcache en boucle recompile tout le code sous charge).
 */
class FaaasterCacheHostmanager
{
    private $queue;

    public function __construct(FaaasterCachePurgeQueue $queue)
    {
        $this->queue = $queue;
    }

    /** @return array rapport de purge + object_cache / opcache */
    public function hard_flush()
    {
        $report = $this->queue->enqueue_all('hostmanager', true);
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        $report['object_cache'] = function_exists('wp_cache_flush');
        $report['opcache'] = function_exists('opcache_reset') ? (bool) opcache_reset() : false;
        return $report;
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
            'opcache' => $report['opcache'],
        );
        if (!empty($report['ok'])) {
            return new WP_REST_Response(array('code' => 'ok', 'data' => $data), 200);
        }
        return new WP_REST_Response(array('code' => 'purge_failed', 'data' => $data), 502);
    }
}
