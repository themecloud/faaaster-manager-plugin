<?php

/**
 * Normalisation d'URL pour la purge FastCGI (fonctions pures, sans WordPress).
 *
 * La clé de cache nginx est "$host$request_method$uri$cacheargs5" (wp-builder
 * http.conf:366) et la route de purge "${host}GET$1${is_args}${args}" (:596) :
 * l'hôte compte, le chemin est décodé des deux côtés par nginx.
 */
class FaaasterCacheUrl
{
    /** Fichiers servis en statique par nginx : jamais dans le cache FastCGI. */
    const STATIC_EXT = '/\.(css|js|mjs|map|json|xml|txt|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf|zip|gz)$/i';

    /**
     * @return array|null ['url','scheme','host','path','query'] ou null si inexploitable
     */
    public static function parse($url)
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $query = isset($parts['query']) ? $parts['query'] : '';

        return array(
            'url' => $scheme . '://' . $host . self::encode_path($path) . ($query !== '' ? '?' . $query : ''),
            'scheme' => $scheme,
            'host' => $host,
            'path' => self::encode_path($path),
            'query' => $query,
        );
    }

    /** Clé de dédoublonnage : hôte + chemin (+ query pour Cloudflare). */
    public static function key(array $parsed)
    {
        return $parsed['host'] . $parsed['path'] . ($parsed['query'] !== '' ? '?' . $parsed['query'] : '');
    }

    /**
     * Percent-encode les octets non imprimables / non ASCII sans toucher aux
     * %XX existants ni aux caractères réservés : nginx décode $uri des deux côtés.
     */
    public static function encode_path($path)
    {
        return preg_replace_callback('/[^\x21-\x7E]/', function ($m) {
            return rawurlencode($m[0]);
        }, $path);
    }

    public static function is_static($path)
    {
        return (bool) preg_match(self::STATIC_EXT, $path);
    }

    /**
     * Chemins à purger côté nginx pour une URL.
     *
     * - query non vide : rien (cache_query_args.lua ne met jamais en cache une
     *   requête dont les arguments ne sont pas tous ignorés) ;
     * - fichier statique : rien (jamais dans le cache FastCGI) ;
     * - accueil en sous-répertoire : aussi <path>index.php, car la réécriture
     *   `location = /purge/` → /purge/index.php ne couvre que la racine.
     *
     * @param string $home_path chemin de home_url() ('/' ou '/blog/')
     * @return string[]
     */
    public static function purge_paths(array $parsed, $home_path = '/')
    {
        if ($parsed['query'] !== '' || self::is_static($parsed['path'])) {
            return array();
        }
        $paths = array($parsed['path']);
        $home_path = self::trailing($home_path);
        if ($home_path !== '/' && self::trailing($parsed['path']) === $home_path) {
            $paths[] = $home_path . 'index.php';
        }
        return $paths;
    }

    /** L'hôte est-il autorisé pour Cloudflare (hôtes du site uniquement) ? */
    public static function host_allowed($host, array $allowed_hosts)
    {
        return in_array(strtolower($host), array_map('strtolower', $allowed_hosts), true);
    }

    private static function trailing($path)
    {
        return rtrim($path, '/') . '/';
    }
}
