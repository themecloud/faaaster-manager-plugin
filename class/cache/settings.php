<?php

/**
 * Réglages du module cache : une option autoloadée, fusionnée avec les valeurs
 * par défaut du code à la lecture, assainie à l'écriture.
 */
class FaaasterCacheSettings
{
    const OPTION = 'faaaster_cache_settings';
    const MAX_PATHS = 20;
    const MAX_TTL = 604800;

    private $cache = null;

    public static function defaults()
    {
        return array(
            'version' => 1,
            'purge' => array(
                // Surcharges par type de contenu ; un type absent prend les
                // valeurs par défaut calculées par la cascade.
                'post_types' => array(),
                'always_paths' => array(),
                'comments' => true,
                'escalate_threshold' => 100,
            ),
            'ttl' => array(
                // context_id => secondes (0 = ne pas mettre en cache).
                'rules' => array(),
                'donotcachepage' => true,
                'nonce_cap' => true,
                'diagnostic' => true,
            ),
        );
    }

    public function all()
    {
        if ($this->cache === null) {
            $stored = function_exists('get_option') ? get_option(self::OPTION, null) : null;
            if ($stored === null || $stored === false) {
                $stored = $this->initialise();
            }
            $this->cache = self::merge(self::defaults(), is_array($stored) ? $stored : array());
        }
        return $this->cache;
    }

    public function get($section, $key = null, $fallback = null)
    {
        $all = $this->all();
        if (!isset($all[$section])) {
            return $fallback;
        }
        if ($key === null) {
            return $all[$section];
        }
        return array_key_exists($key, $all[$section]) ? $all[$section][$key] : $fallback;
    }

    /** Remplace une section après assainissement. */
    public function update_section($section, array $values)
    {
        $all = $this->all();
        $all[$section] = $section === 'purge'
            ? self::sanitize_purge($values)
            : self::sanitize_ttl($values);
        update_option(self::OPTION, $all, true);
        $this->cache = $all;
        return $all[$section];
    }

    public function reset_cache()
    {
        $this->cache = null;
    }

    /**
     * Première lecture : crée l'option, en important une seule fois les URL
     * personnalisées du fork Nginx Helper (lecture seule de son option).
     */
    private function initialise()
    {
        $settings = self::defaults();
        if (function_exists('get_site_option')) {
            $fork = get_site_option('rt_wp_nginx_helper_options', array());
            if (is_array($fork) && !empty($fork['purge_url'])) {
                $settings['purge']['always_paths'] = self::sanitize_paths(preg_split('/\r\n|\r|\n/', (string) $fork['purge_url']));
            }
        }
        if (function_exists('add_option')) {
            add_option(self::OPTION, $settings, '', true);
        }
        return $settings;
    }

    /** Fusion profonde : les tableaux associatifs fusionnent, les listes remplacent. */
    public static function merge(array $defaults, array $stored)
    {
        foreach ($stored as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && self::is_assoc($defaults[$key])) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }

    public static function sanitize_purge(array $in)
    {
        $out = self::defaults()['purge'];
        if (isset($in['post_types']) && is_array($in['post_types'])) {
            foreach ($in['post_types'] as $type => $rules) {
                if (!is_string($type) || !preg_match('/^[a-z0-9_-]{1,20}$/', $type) || !is_array($rules)) {
                    continue;
                }
                $out['post_types'][$type] = array(
                    'enabled' => !empty($rules['enabled']),
                    'homepage' => !empty($rules['homepage']),
                    'archive' => !empty($rules['archive']),
                    'author' => !empty($rules['author']),
                    'date' => !empty($rules['date']),
                    'feeds' => !empty($rules['feeds']),
                    'paged' => max(0, min(10, (int) (isset($rules['paged']) ? $rules['paged'] : 0))),
                    'taxonomies' => self::sanitize_slugs(isset($rules['taxonomies']) ? $rules['taxonomies'] : array()),
                    'custom_paths' => self::sanitize_paths(isset($rules['custom_paths']) ? $rules['custom_paths'] : array()),
                );
            }
        }
        $out['always_paths'] = self::sanitize_paths(isset($in['always_paths']) ? $in['always_paths'] : array());
        $out['comments'] = !empty($in['comments']);
        $threshold = isset($in['escalate_threshold']) ? (int) $in['escalate_threshold'] : 100;
        $out['escalate_threshold'] = max(10, min(500, $threshold));
        return $out;
    }

    public static function sanitize_ttl(array $in)
    {
        $out = self::defaults()['ttl'];
        if (isset($in['rules']) && is_array($in['rules'])) {
            foreach ($in['rules'] as $context => $seconds) {
                if (!is_string($context) || !preg_match('/^[a-z0-9_:-]{1,60}$/', $context)) {
                    continue;
                }
                if ($seconds === '' || $seconds === null) {
                    continue;
                }
                $out['rules'][$context] = max(0, min(self::MAX_TTL, (int) $seconds));
            }
        }
        foreach (array('donotcachepage', 'nonce_cap', 'diagnostic') as $flag) {
            $out[$flag] = !empty($in[$flag]);
        }
        return $out;
    }

    /** Chemins relatifs au site : « /… », sans espace ni joker, 200 caractères au plus. */
    public static function sanitize_paths($paths)
    {
        if (is_string($paths)) {
            $paths = preg_split('/\r\n|\r|\n/', $paths);
        }
        $clean = array();
        foreach ((array) $paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || strpos($path, '*') !== false) {
                continue;
            }
            if (preg_match('#^https?://#i', $path)) {
                $parsed = parse_url($path);
                $path = isset($parsed['path']) ? $parsed['path'] : '/';
            }
            if (!preg_match('#^/[^\s]*$#', $path) || strlen($path) > 200) {
                continue;
            }
            $clean[$path] = true;
            if (count($clean) >= self::MAX_PATHS) {
                break;
            }
        }
        return array_keys($clean);
    }

    private static function sanitize_slugs($slugs)
    {
        $clean = array();
        foreach ((array) $slugs as $slug) {
            if (is_string($slug) && preg_match('/^[a-z0-9_-]{1,32}$/', $slug)) {
                $clean[] = $slug;
            }
        }
        return array_values(array_unique($clean));
    }

    private static function is_assoc(array $array)
    {
        return $array === array() || array_keys($array) !== range(0, count($array) - 1);
    }
}
