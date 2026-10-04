<?php

/**
 * Résolution du TTL d'une réponse (fonction pure, sans WordPress).
 *
 * nginx respecte X-Accel-Expires (absent de fastcgi_ignore_headers) en 200
 * comme en 404, et ne le transmet jamais au client (mesuré le 04/10/2026).
 * Sans règle : AUCUN en-tête, /app/conf/cache-ttl.user.conf continue de régir.
 */
class FaaasterCacheTtlRules
{
    /** Contextes réglables, dans l'ordre de priorité. */
    const CONTEXTS = array('404', 'front_page', 'home', 'singular', 'archive', 'taxonomy', 'author', 'date', 'search', 'feed');

    const FEED_TYPES = array('application/rss+xml', 'application/atom+xml', 'application/rdf+xml', 'application/xml', 'text/xml');

    /**
     * @param array $in donotcachepage (bool), commerce (bool), headers (string[]),
     *                  status (int), contexts (string[] par priorité), nonce_seen
     *                  (bool), nonce_life (int, s), nginx_ttl (int|null pour ce statut)
     * @param array $ttl section ttl des réglages (rules, donotcachepage, nonce_cap)
     * @return array ['emit' => bool, 'ttl' => int|null, 'rule' => string, 'capped' => bool]
     */
    public static function resolve(array $in, array $ttl)
    {
        $in = array_merge(array(
            'donotcachepage' => false, 'commerce' => false, 'headers' => array(),
            'status' => 200, 'contexts' => array(), 'nonce_seen' => false,
            'nonce_life' => 86400, 'nginx_ttl' => null,
        ), $in);
        $rules = isset($ttl['rules']) && is_array($ttl['rules']) ? $ttl['rules'] : array();

        // 1-2. Garde-fous : ce que nginx ne peut pas voir. Jamais désactivables :
        //      aucune règle ni aucun réglage ne met en cache une page qui le refuse.
        if ($in['donotcachepage']) {
            return self::result(true, 0, 'donotcachepage');
        }
        if ($in['commerce']) {
            return self::result(true, 0, 'commerce');
        }
        // 3. Un autre composant a déjà fixé la durée : on la respecte.
        if (self::header_value($in['headers'], 'X-Accel-Expires') !== null) {
            return self::result(false, null, 'foreign');
        }
        // 4. Statut : 200, ou 404/410 via la seule règle « 404 ».
        $status = (int) $in['status'];
        if ($status === 404 || $status === 410) {
            $contexts = array('404');
        } elseif ($status === 200) {
            $contexts = (array) $in['contexts'];
        } else {
            return self::result(false, null, 'status:' . $status);
        }
        // 5. Type de contenu : HTML, ou flux pour le contexte feed.
        $type = strtolower((string) self::header_value($in['headers'], 'Content-Type'));
        $type = trim(strtok($type === '' ? 'text/html' : $type, ';'));
        $is_feed = in_array('feed', $contexts, true);
        if ($type !== 'text/html' && !($is_feed && in_array($type, self::FEED_TYPES, true))) {
            return self::result(false, null, 'content_type');
        }
        // 6-7. Première règle applicable, sinon le défaut nginx (aucun en-tête).
        $value = null;
        $rule = 'default';
        foreach ($contexts as $context) {
            if (array_key_exists($context, $rules)) {
                $value = (int) $rules[$context];
                $rule = $context;
                break;
            }
        }
        // 8. Plafond nonce : jamais au-delà de la validité garantie d'un nonce
        //    (nonce_life/2, moins une heure de marge). Ne fait jamais allonger.
        if (!empty($ttl['nonce_cap']) && $in['nonce_seen']) {
            $cap = max(0, (int) floor($in['nonce_life'] / 2) - 3600);
            $effective = $value !== null ? $value : $in['nginx_ttl'];
            if ($effective !== null && $effective > $cap) {
                return self::result(true, $cap, $rule, true);
            }
        }
        return $value === null ? self::result(false, null, $rule) : self::result(true, $value, $rule);
    }

    /** Contexte « singular:post », « archive:product », « taxonomy:category »… */
    public static function specific($base, $suffix)
    {
        return $suffix !== '' && $suffix !== null ? $base . ':' . $suffix : $base;
    }

    public static function diagnostic(array $result)
    {
        $value = $result['emit'] ? (string) $result['ttl'] : 'default';
        return $value . '; rule=' . $result['rule'] . ($result['capped'] ? '; capped=nonce' : '');
    }

    private static function result($emit, $ttl, $rule, $capped = false)
    {
        return array('emit' => $emit, 'ttl' => $ttl, 'rule' => $rule, 'capped' => $capped);
    }

    private static function header_value(array $headers, $name)
    {
        $prefix = strtolower($name) . ':';
        foreach ($headers as $header) {
            if (strpos(strtolower($header), $prefix) === 0) {
                return trim(substr($header, strlen($prefix)));
            }
        }
        return null;
    }
}
