<?php

/**
 * Module cache de pages : point d'entrée unique (singleton).
 *
 * Les variables de premier niveau du mu-plugin ne sont pas de vraies globales
 * sous WP-CLI (wp-settings.php y est chargé dans une fonction) : tout passe par
 * faaaster_cache() / FaaasterCache::instance().
 */
final class FaaasterCache
{
    private static $instance = null;

    private $context = array();
    private $settings;
    private $events;
    private $queue;
    private $hostmanager;
    private $cloudflare;
    private $cascade;
    private $integrations;

    /**
     * @param array $args cloudflare, cf_enabled, hostmanager ; pour les tests :
     *                    settings, events, transport, site_provider
     */
    public static function boot(array $args)
    {
        if (self::$instance === null) {
            self::$instance = new self($args);
            self::$instance->register();
        }
        return self::$instance;
    }

    public static function instance()
    {
        return self::$instance;
    }

    /** Tests uniquement. */
    public static function reset()
    {
        self::$instance = null;
    }

    private function __construct(array $args)
    {
        $this->context = array(
            'hostmanager' => !empty($args['hostmanager']),
            'cli' => isset($args['cli']) ? (bool) $args['cli'] : (defined('WP_CLI') && WP_CLI),
            'fork_present' => self::fork_present(),
        );
        $this->cloudflare = isset($args['cloudflare']) ? $args['cloudflare'] : null;
        $this->settings = isset($args['settings']) ? $args['settings'] : new FaaasterCacheSettings();
        $this->events = isset($args['events']) ? $args['events'] : new FaaasterCacheEvents();
        $transport = isset($args['transport']) ? $args['transport'] : new FaaasterCacheTransport();
        $site_provider = isset($args['site_provider']) ? $args['site_provider'] : array(__CLASS__, 'site_from_wordpress');
        $this->queue = new FaaasterCachePurgeQueue(
            $transport,
            $this->cloudflare,
            !empty($args['cf_enabled']),
            $this->events,
            $this->settings,
            $site_provider,
            $this->context['cli']
        );
        $this->hostmanager = new FaaasterCacheHostmanager($this->queue);
    }

    private function register()
    {
        if ($this->context['fork_present']) {
            FaaasterCacheTakeover::run($this);
            $this->context['takeover'] = true;
        } else {
            FaaasterCacheCompat::register($this);
            $this->context['takeover'] = false;
        }
        $this->cascade = new FaaasterCacheCascade($this->settings);
        $listener = new FaaasterCacheContentListener($this, $this->cascade);
        $listener->register();
        $triggers = new FaaasterCacheGlobalTriggers($this);
        $triggers->register();
        $this->integrations = new FaaasterCacheIntegrationRegistry($this);
        // Requêtes hostmanager : extensions et thème non chargés, rien à adapter.
        if (!$this->context['hostmanager']) {
            $this->integrations->register();
        }
        if ($this->context['cli']) {
            FaaasterCacheCli::register();
        }
    }

    public function cascade()
    {
        return $this->cascade;
    }

    public function integrations()
    {
        return $this->integrations;
    }

    /**
     * Enregistre un callback protégé : une exception du module ne doit jamais
     * casser la page (error-handler.php ignore les erreurs de ce plugin, d'où
     * le journal explicite). Pour un filtre, la valeur d'entrée est rendue.
     */
    public function hook($hook, $callback, $priority = 10, $accepted_args = 1, $is_filter = false)
    {
        $wrapped = function () use ($callback, $hook, $is_filter) {
            $args = func_get_args();
            try {
                return call_user_func_array($callback, $args);
            } catch (\Throwable $e) {
                FaaasterCache::log_exception($hook, $e);
                return ($is_filter && array_key_exists(0, $args)) ? $args[0] : null;
            }
        };
        add_filter($hook, $wrapped, $priority, $accepted_args);
        return $wrapped;
    }

    public function context($key)
    {
        return isset($this->context[$key]) ? $this->context[$key] : null;
    }

    public function settings()
    {
        return $this->settings;
    }

    public function events()
    {
        return $this->events;
    }

    public function queue()
    {
        return $this->queue;
    }

    public function hostmanager()
    {
        return $this->hostmanager;
    }

    /** Le fork faaaster-cache-manager (Nginx Helper) est-il chargé ? */
    public static function fork_present()
    {
        return class_exists('Nginx_Helper', false)
            && isset($GLOBALS['nginx_purger'])
            && is_object($GLOBALS['nginx_purger'])
            && !($GLOBALS['nginx_purger'] instanceof FaaasterNginxPurgerCompat);
    }

    public static function site_from_wordpress()
    {
        $hosts = array();
        foreach (array(home_url('/'), site_url('/')) as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $hosts[] = strtolower($host);
            }
        }
        return array(
            'home_url' => home_url('/'),
            'hosts' => (array) apply_filters('faaaster_cache_hosts', array_values(array_unique($hosts))),
        );
    }

    public static function log($message, array $context = array())
    {
        error_log('[faaaster-cache] ' . $message . ($context ? ' ' . json_encode($context) : ''));
    }

    public static function log_exception($where, $e)
    {
        self::log(sprintf(
            'exception in %s: %s: %s @ %s:%d',
            $where,
            get_class($e),
            $e->getMessage(),
            basename($e->getFile()),
            $e->getLine()
        ));
    }
}
