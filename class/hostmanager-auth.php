<?php

/**
 * Garde des routes locales de la plateforme (hostmanager/v1, faaaster-agent/v1),
 * appelées en boucle locale : pont du k8s-consumer (tout Next passe par lui),
 * fstr-wp-op.sh, fstr-worker.
 *
 * nginx n'ouvre ces routes qu'à 127.0.0.1 ; la garde exige en plus le Bearer
 * WP_API_KEY (comparaison en temps constant) pour qu'aucun autre client local
 * (extension, script dans le pod) ne puisse les appeler.
 *
 * - faaaster_hostmanager_guard() : BLOQUANT. Routes critiques que seuls Next (via
 *   le pont, qui envoie toujours le Bearer) et fstr-worker de la même image
 *   appellent, ou que plus rien n'appelle.
 * - faaaster_hostmanager_guard_phased() : par phases, pour les routes à
 *   appelants multiples et peu critiques (clear_cache) : phase 1, l'appel sans
 *   Bearer valide passe ; FAAASTER_HOSTMANAGER_REQUIRE_AUTH le bloque (phase 2).
 *
 * Tout appel sans Bearer valide, refusé ou non, est journalisé et compté
 * (site_state → other_data.hostmanager_auth) pour repérer un appelant oublié.
 * Indépendante de l'interrupteur du module cache.
 */
class FaaasterHostmanagerAuth
{
    /** Appels sans Bearer valide (sans autoload) : compteur, date, route, motif. */
    const OPTION = 'faaaster_hostmanager_auth';

    /**
     * Décisions déjà prises pour un objet requête : WordPress rappelle le
     * permission_callback dans rest_send_allow_header (en-tête Allow) avec la
     * même requête. SplObjectStorage garde la référence (pas de hash réutilisé).
     */
    private static $decided = null;

    public static function require_auth()
    {
        return defined('FAAASTER_HOSTMANAGER_REQUIRE_AUTH') && constant('FAAASTER_HOSTMANAGER_REQUIRE_AUTH');
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
            self::record($route, $problem, $agent);
            error_log(sprintf('[faaaster-hostmanager] %s called without a valid Bearer (%s)%s %s', $route, $problem, $require_auth ? ': refused' : '', json_encode(array('agent' => $agent))));
        }
        if (is_object($request)) {
            self::$decided[$request] = $allowed;
        }
        return $allowed;
    }

    /** Jamais le jeton ni l'en-tête : seulement la route, le motif et l'agent. */
    private static function record($route, $problem, $agent)
    {
        if (!function_exists('get_option')) {
            return;
        }
        $stats = self::stats();
        $stats['count']++;
        $stats['last_at'] = time();
        $stats['last_route'] = (string) $route;
        $stats['last_reason'] = (string) $problem;
        $stats['last_agent'] = (string) $agent;
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $stats, '', 'no');
        } else {
            update_option(self::OPTION, $stats, false);
        }
    }

    /** @return array count, last_at, last_route, last_reason, last_agent */
    public static function stats()
    {
        $defaults = array('count' => 0, 'last_at' => null, 'last_route' => null, 'last_reason' => null, 'last_agent' => null);
        $stored = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    }

    /** Tests uniquement. */
    public static function reset()
    {
        self::$decided = null;
    }
}

/** permission_callback BLOQUANT d'une route locale critique. */
function faaaster_hostmanager_guard($route)
{
    return function ($request) use ($route) {
        return FaaasterHostmanagerAuth::authorize($route, $request, true);
    };
}

/** permission_callback par phases (appelants multiples, peu critique). */
function faaaster_hostmanager_guard_phased($route)
{
    return function ($request) use ($route) {
        return FaaasterHostmanagerAuth::authorize($route, $request, FaaasterHostmanagerAuth::require_auth());
    };
}
