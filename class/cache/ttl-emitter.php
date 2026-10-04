<?php

/**
 * Émission de X-Accel-Expires (TTL nginx par réponse) et de l'en-tête de
 * diagnostic X-Faaaster-Cache-TTL.
 *
 * Timing : les en-têtes partent avant toute sortie. On décide dans un buffer de
 * sortie démarré en tout début de template_redirect : le handler est appelé
 * juste avant l'envoi des en-têtes, après les define('DONOTCACHEPAGE') et
 * setcookie() faits pendant le rendu. header_register_callback() n'est pas
 * utilisé : un seul callback possible par requête, le suivant remplace le nôtre.
 * Kill switch : FAAASTER_CACHE_TTL_DISABLED.
 */
class FaaasterCacheTtlEmitter
{
    private $cache;
    private $nonce_seen = false;
    private $route = null;
    private $decided = false;
    /** @var callable émet un en-tête (header() en production, faux puits en test) */
    private $sink;

    public function __construct(FaaasterCache $cache, $sink = null)
    {
        $this->cache = $cache;
        $this->sink = $sink ? $sink : function ($line) {
            header($line);
        };
    }

    public function register()
    {
        $c = $this->cache;
        // Lecture seule : un nonce a été créé (ou vérifié) pour un visiteur anonyme.
        $c->hook('nonce_user_logged_out', array($this, 'on_nonce'), PHP_INT_MAX, 1, true);
        $c->hook('send_headers', array($this, 'on_send_headers'), PHP_INT_MAX, 0);
        $c->hook('template_redirect', array($this, 'on_template_redirect'), PHP_INT_MIN, 0);
    }

    public function on_nonce($uid)
    {
        $this->nonce_seen = true;
        return $uid;
    }

    /** Étape A : WooCommerce pose DONOTCACHEPAGE dans wp_headers (prio 5), plus tôt. */
    public function on_send_headers()
    {
        if ($this->settings('donotcachepage') && self::eligible() && defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            $this->emit_line('X-Accel-Expires: 0');
        }
    }

    /** Étape B : contexte calculé maintenant (conditionnelles fiables), décision au flush. */
    public function on_template_redirect()
    {
        if (!self::eligible()) {
            return;
        }
        $this->route = self::route_contexts();
        ob_start(array($this, 'on_output'), 0);
    }

    /**
     * Handler du buffer : décide une seule fois, juste avant l'envoi des en-têtes.
     * Ne fait jamais d'echo (déprécié en PHP 8.5) et rend toujours le buffer intact.
     */
    public function on_output($buffer, $phase)
    {
        if ($this->decided) {
            return $buffer;
        }
        // ob_clean() seul : rien n'est envoyé, on attend la vraie sortie.
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) && !($phase & PHP_OUTPUT_HANDLER_FINAL)) {
            return $buffer;
        }
        $this->decided = true;
        try {
            if (!headers_sent()) {
                $this->decide(http_response_code(), headers_list(), defined('DONOTCACHEPAGE') && DONOTCACHEPAGE);
            }
        } catch (\Throwable $e) {
            FaaasterCache::log_exception('ttl-emitter', $e);
        }
        return $buffer;
    }

    /** Applique la résolution (exposé pour les tests). */
    public function decide($status, array $headers, $donotcachepage)
    {
        $route = $this->route ? $this->route : array('contexts' => array(), 'commerce' => false);
        $status = (int) ($status ? $status : 200);
        $result = FaaasterCacheTtlRules::resolve(array(
            'donotcachepage' => $donotcachepage,
            'commerce' => $route['commerce'],
            'headers' => $headers,
            'status' => $status,
            'contexts' => $route['contexts'],
            'nonce_seen' => $this->nonce_seen,
            'nonce_life' => (int) apply_filters('nonce_life', 86400),
            'nginx_ttl' => FaaasterCacheNginxConf::ttl_for_status($status),
        ), (array) $this->cache->settings()->get('ttl'));

        if ($result['emit']) {
            $this->emit_line('X-Accel-Expires: ' . (int) $result['ttl']);
        }
        if ($this->settings('diagnostic')) {
            $this->emit_line('X-Faaaster-Cache-TTL: ' . FaaasterCacheTtlRules::diagnostic($result));
        }
        return $result;
    }

    public function set_route(array $route)
    {
        $this->route = $route;
    }

    /** Requête front anonyme en GET/HEAD, hors aperçu, AJAX, cron, REST, CLI, hostmanager. */
    public static function eligible()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        if ((defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST) || faaaster_is_hostmanager_request()) {
            return false;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }
        foreach (array('is_preview', 'is_customize_preview', 'is_robots', 'is_favicon', 'is_trackback') as $fn) {
            if (function_exists($fn) && call_user_func($fn)) {
                return false;
            }
        }
        return true;
    }

    /** Contextes de la page courante, du plus précis au plus général. */
    public static function route_contexts()
    {
        $contexts = array();
        if (is_404()) {
            $contexts[] = '404';
        } else {
            if (is_front_page()) {
                $contexts[] = 'front_page';
            }
            if (is_home()) {
                $contexts[] = 'home';
            }
            if (is_singular()) {
                $contexts[] = FaaasterCacheTtlRules::specific('singular', get_post_type());
                $contexts[] = 'singular';
            } elseif (is_post_type_archive()) {
                $type = get_query_var('post_type');
                $contexts[] = FaaasterCacheTtlRules::specific('archive', is_array($type) ? reset($type) : $type);
                $contexts[] = 'archive';
            } elseif (is_category() || is_tag() || is_tax()) {
                $term = get_queried_object();
                $contexts[] = FaaasterCacheTtlRules::specific('taxonomy', $term && isset($term->taxonomy) ? $term->taxonomy : '');
                $contexts[] = 'taxonomy';
            } elseif (is_author()) {
                $contexts[] = 'author';
            } elseif (is_date()) {
                $contexts[] = 'date';
            }
            if (is_search()) {
                $contexts[] = 'search';
            }
            if (is_feed()) {
                $contexts[] = 'feed';
            }
        }
        $commerce = false;
        foreach (array('is_cart', 'is_checkout', 'is_account_page') as $fn) {
            if (function_exists($fn) && call_user_func($fn)) {
                $commerce = true;
            }
        }
        return array('contexts' => array_values(array_unique($contexts)), 'commerce' => $commerce);
    }

    private function settings($flag)
    {
        return (bool) $this->cache->settings()->get('ttl', $flag, true);
    }

    private function emit_line($line)
    {
        call_user_func($this->sink, $line);
    }
}
