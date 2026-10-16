<?php

/**
 * Appels HTTPS du site vers lui-même : l'API HTTP de WordPress fait confiance
 * au certificat de boucle locale du pod.
 *
 * Dans le pod, chaque domaine servi est résolu vers 127.0.0.1 (hostAliases posés
 * par k8s-consumer) et nginx y répond sur :443 avec un certificat auto-signé
 * généré au démarrage (wp-builder provisionning.sh setup_ssl_loopback), ajouté
 * au magasin système /etc/ssl/certs/ca-certificates.crt. curl et PHP le
 * reconnaissent ; wp_remote_*() non : Requests vérifie avec le bundle de
 * WordPress (wp-includes/certificates/ca-bundle.crt) → « cURL error 60:
 * SSL certificate problem: self-signed certificate » (graazie.com, inst123562,
 * 13/10/2026 : GET https://graazie.com/wp-json/wc/v3/products).
 *
 * Pour un hôte de boucle locale seulement, le bundle par défaut de WordPress est
 * remplacé par le magasin système. sslverify n'est jamais touché, un bundle
 * choisi par l'appelant est respecté, les requêtes vers l'extérieur ne changent
 * pas.
 */
final class FaaasterLoopbackTls
{
    const HOSTS_FILE = '/etc/hosts';
    const SYSTEM_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    /** @var string[]|null hôtes de boucle locale, lus une fois par requête PHP */
    private static $hosts = null;

    public static function register()
    {
        add_filter('http_request_args', array(__CLASS__, 'filter'), 10, 2);
    }

    public static function filter($args, $url)
    {
        if (!is_array($args) || !is_string($url) || stripos($url, 'https://') !== 0) {
            return $args;
        }
        if (self::$hosts === null) {
            $content = @is_readable(self::HOSTS_FILE) ? @file_get_contents(self::HOSTS_FILE) : false;
            self::$hosts = is_string($content) ? self::loopbackHosts($content) : array();
        }
        if (!self::$hosts) {
            return $args;
        }

        return self::withSystemBundle(
            $args,
            $url,
            self::$hosts,
            ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            self::SYSTEM_BUNDLE,
            @is_readable(self::SYSTEM_BUNDLE)
        );
    }

    /**
     * Noms des lignes 127.0.0.1 de /etc/hosts, en minuscules (même lecture que
     * l'awk de setup_ssl_loopback qui construit le SAN du certificat).
     *
     * @return string[]
     */
    public static function loopbackHosts($hostsContent)
    {
        $hosts = array();
        foreach (preg_split('/\R/', (string) $hostsContent) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            $fields = $line === '' ? array() : preg_split('/\s+/', $line);
            if (count($fields) < 2 || $fields[0] !== '127.0.0.1') {
                continue;
            }
            foreach (array_slice($fields, 1) as $name) {
                $hosts[strtolower($name)] = true;
            }
        }
        return array_keys($hosts);
    }

    /** Logique pure (exposée pour les tests). */
    public static function withSystemBundle(array $args, $url, array $hosts, $wpBundle, $systemBundle, $systemBundleReadable)
    {
        if (!$systemBundleReadable) {
            return $args;
        }
        if (isset($args['sslcertificates']) && $args['sslcertificates'] !== $wpBundle) {
            return $args;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https' || empty($parts['host'])) {
            return $args;
        }
        if (!in_array(strtolower($parts['host']), $hosts, true)) {
            return $args;
        }
        $args['sslcertificates'] = $systemBundle;
        return $args;
    }
}
