<?php

/**
 * Journal circulaire des purges : 100 entrées, sans autoload, une seule
 * écriture par requête. En cas de requêtes concurrentes, la dernière écriture
 * gagne : une perte occasionnelle d'événement est acceptée.
 */
class FaaasterCacheEvents
{
    const OPTION = 'faaaster_cache_events';
    const MAX = 100;
    const MAX_URLS = 10;

    private $pending = array();

    /**
     * @param array $entry type, source, forced, count, urls, result
     */
    public function record(array $entry)
    {
        $urls = array();
        foreach (array_slice(isset($entry['urls']) ? (array) $entry['urls'] : array(), 0, self::MAX_URLS) as $url) {
            $urls[] = substr((string) $url, 0, 300);
        }
        $this->pending[] = array(
            't' => time(),
            'type' => isset($entry['type']) ? (string) $entry['type'] : 'purge',
            'source' => isset($entry['source']) ? substr((string) $entry['source'], 0, 80) : '',
            'context' => self::context(),
            'forced' => !empty($entry['forced']),
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'count' => isset($entry['count']) ? (int) $entry['count'] : count($urls),
            'urls' => $urls,
            'result' => isset($entry['result']) ? $entry['result'] : array(),
        );
    }

    /** Écrit les entrées de la requête en tête du journal. */
    public function persist()
    {
        if (empty($this->pending) || !function_exists('get_option')) {
            return;
        }
        $log = get_option(self::OPTION, array());
        if (!is_array($log)) {
            $log = array();
        }
        $log = array_slice(array_merge(array_reverse($this->pending), $log), 0, self::MAX);
        $this->pending = array();
        update_option(self::OPTION, $log, false);
    }

    public function all()
    {
        $log = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return is_array($log) ? $log : array();
    }

    public function clear()
    {
        $this->pending = array();
        update_option(self::OPTION, array(), false);
    }

    public static function context()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }
        if (function_exists('faaaster_is_hostmanager_request') && faaaster_is_hostmanager_request()) {
            return 'hostmanager';
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        return 'web';
    }
}
