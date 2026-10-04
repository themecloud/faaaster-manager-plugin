<?php

/**
 * Lecture SEULE des durées FastCGI par défaut du site (/app/conf/cache-ttl.*.conf,
 * inclus par wp-builder nginx-snippets/fastcgi_caching). Sert à l'affichage et
 * au plafond nonce ; le module n'écrit jamais dans /app/conf.
 */
class FaaasterCacheNginxConf
{
    const GLOB = '/app/conf/cache-ttl.*.conf';

    private static $cache = null;

    /** @return array code HTTP (int) ou 'any' => secondes ; vide si illisible */
    public static function valid_map($glob = self::GLOB)
    {
        if (self::$cache !== null && $glob === self::GLOB) {
            return self::$cache;
        }
        $map = array();
        $files = glob($glob);
        foreach ($files ? $files : array() as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $map = array_replace($map, self::parse($content));
            }
        }
        if ($glob === self::GLOB) {
            self::$cache = $map;
        }
        return $map;
    }

    /** Durée nginx (s) pour un statut, ou null si inconnue. */
    public static function ttl_for_status($status, $glob = self::GLOB)
    {
        $map = self::valid_map($glob);
        if (isset($map[(int) $status])) {
            return $map[(int) $status];
        }
        return isset($map['any']) ? $map['any'] : null;
    }

    /** Analyse les directives `fastcgi_cache_valid [codes…] durée;` hors commentaires. */
    public static function parse($content)
    {
        $map = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $content) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^fastcgi_cache_valid\s+([^;]+);/', $line, $m)) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($m[1]));
            $seconds = self::duration(array_pop($parts));
            if ($seconds === null) {
                continue;
            }
            // Sans code : nginx applique la durée à 200, 301 et 302.
            $codes = $parts ? $parts : array('200', '301', '302');
            foreach ($codes as $code) {
                $map[$code === 'any' ? 'any' : (int) $code] = $seconds;
            }
        }
        return $map;
    }

    /** Durée nginx (10h, 30m, 1d, 90s, 1h30m…) → secondes. */
    public static function duration($value)
    {
        if (!preg_match('/^(\d+[smhdwMy]?)+$/', (string) $value)) {
            return null;
        }
        $units = array('' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'M' => 2592000, 'y' => 31536000);
        preg_match_all('/(\d+)([smhdwMy]?)/', $value, $parts, PREG_SET_ORDER);
        $total = 0;
        foreach ($parts as $p) {
            $total += (int) $p[1] * $units[$p[2]];
        }
        return $total;
    }

    /** Tests uniquement. */
    public static function reset()
    {
        self::$cache = null;
    }
}
