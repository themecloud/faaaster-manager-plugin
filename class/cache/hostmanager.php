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
    /** Appels hostmanager sans Bearer valide (sans autoload) : compteur, date, motif. */
    const AUTH_OPTION = 'faaaster_cache_hostmanager_auth';

    /**
     * Décisions déjà prises pour un objet requête : WordPress rappelle le
     * permission_callback dans rest_send_allow_header (en-tête Allow) avec la
     * même requête. SplObjectStorage garde la référence (pas de hash réutilisé).
     */
    private static $decided = null;

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
        return self::bearer_problem($request) === null;
    }

    /**
     * null si le Bearer est valide ; sinon le motif : no_key (WP_API_KEY absent
     * du pod), missing (pas d'en-tête Bearer), invalid (jeton différent).
     */
    public static function bearer_problem($request)
    {
        $expected = defined('WP_API_KEY') ? (string) WP_API_KEY : '';
        if ($expected === '') {
            return 'no_key';
        }
        $header = (is_object($request) && method_exists($request, 'get_header')) ? (string) $request->get_header('Authorization') : '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return 'missing';
        }
        return hash_equals($expected, trim($m[1])) ? null : 'invalid';
    }

    /**
     * permission_callback de clear_cache. Bearer vérifié et tout appel sans
     * Bearer valide journalisé, mais accepté tant que les appelants ne sont pas
     * tous à jour (fstr-worker) ; FAAASTER_HOSTMANAGER_REQUIRE_AUTH le rend bloquant.
     */
    public static function clear_cache_permission($request)
    {
        return self::authorize('clear_cache', $request, defined('FAAASTER_HOSTMANAGER_REQUIRE_AUTH') && constant('FAAASTER_HOSTMANAGER_REQUIRE_AUTH'));
    }

    public static function authorize($route, $request, $require_auth)
    {
        if (self::$decided === null) {
            self::$decided = new SplObjectStorage();
        }
        if (is_object($request) && self::$decided->contains($request)) {
            return self::$decided[$request];
        }
        $problem = self::bearer_problem($request);
        $allowed = $problem === null || !$require_auth;
        if ($problem !== null) {
            $agent = (is_object($request) && method_exists($request, 'get_header')) ? substr((string) $request->get_header('User-Agent'), 0, 80) : '';
            self::record_unauthenticated($route, $problem, $agent);
            FaaasterCache::log(sprintf('hostmanager/%s called without a valid Bearer (%s)%s', $route, $problem, $require_auth ? ': refused' : ''), array('agent' => $agent));
        }
        if (is_object($request)) {
            self::$decided[$request] = $allowed;
        }
        return $allowed;
    }

    /** Jamais le jeton ni l'en-tête : seulement le motif et l'agent. */
    private static function record_unauthenticated($route, $problem, $agent)
    {
        if (!function_exists('get_option')) {
            return;
        }
        $stats = self::auth_stats();
        $stats['count']++;
        $stats['last_at'] = time();
        $stats['last_route'] = (string) $route;
        $stats['last_reason'] = (string) $problem;
        $stats['last_agent'] = (string) $agent;
        if (get_option(self::AUTH_OPTION, null) === null) {
            add_option(self::AUTH_OPTION, $stats, '', 'no');
        } else {
            update_option(self::AUTH_OPTION, $stats, false);
        }
    }

    /** @return array count, last_at, last_route, last_reason, last_agent */
    public static function auth_stats()
    {
        $defaults = array('count' => 0, 'last_at' => null, 'last_route' => null, 'last_reason' => null, 'last_agent' => null);
        $stored = function_exists('get_option') ? get_option(self::AUTH_OPTION, array()) : array();
        return is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
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
