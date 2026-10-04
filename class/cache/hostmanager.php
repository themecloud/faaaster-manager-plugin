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

    /** Bearer WP_API_KEY obligatoire (comparaison en temps constant). */
    public static function bearer_ok($request)
    {
        $expected = defined('WP_API_KEY') ? (string) WP_API_KEY : '';
        if ($expected === '' || !is_object($request) || !method_exists($request, 'get_header')) {
            return false;
        }
        $header = (string) $request->get_header('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return false;
        }
        return hash_equals($expected, trim($m[1]));
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
