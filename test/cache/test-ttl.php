<?php

fc_section('ttl');

// Lecture de cache-ttl.user.conf.
fc_check('duration 10h', FaaasterCacheNginxConf::duration('10h'), 36000);
fc_check('duration 1h30m', FaaasterCacheNginxConf::duration('1h30m'), 5400);
fc_check('duration 90 (seconds)', FaaasterCacheNginxConf::duration('90'), 90);
fc_check('duration invalid', FaaasterCacheNginxConf::duration('soon'), null);
$map = FaaasterCacheNginxConf::parse("# fastcgi_cache_valid 200 302 10h;\n# fastcgi_cache_valid 301 1h;\n\nfastcgi_cache_valid 200 302 10h;\n");
fc_check('platform default parsed (comments ignored)', $map, array(200 => 36000, 302 => 36000));
fc_check('no codes → 200 301 302', FaaasterCacheNginxConf::parse('fastcgi_cache_valid 5m;'), array(200 => 300, 301 => 300, 302 => 300));
fc_check('any', FaaasterCacheNginxConf::parse('fastcgi_cache_valid any 1m;'), array('any' => 60));

$defaults = FaaasterCacheSettings::defaults();
$base = $defaults['ttl'];
$html = array('Content-Type: text/html; charset=UTF-8');

// Sans règle : aucun en-tête (cache-ttl.user.conf régit).
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('front_page', 'home')), $base);
fc_check('no rule → no header', array($r['emit'], $r['rule']), array(false, 'default'));
fc_check('diagnostic default', FaaasterCacheTtlRules::diagnostic($r), 'default; rule=default');

// Règles par contexte, du plus précis au plus général.
$ttl = $base;
$ttl['rules'] = array('front_page' => 600, 'singular' => 7200, 'singular:product' => 1800, '404' => 300, 'search' => 0);
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('front_page', 'home')), $ttl);
fc_check('front_page rule', array($r['emit'], $r['ttl'], $r['rule']), array(true, 600, 'front_page'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular:product', 'singular')), $ttl);
fc_check('specific beats generic', array($r['ttl'], $r['rule']), array(1800, 'singular:product'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular:post', 'singular')), $ttl);
fc_check('generic fallback', array($r['ttl'], $r['rule']), array(7200, 'singular'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('search')), $ttl);
fc_check('0 = do not cache', array($r['emit'], $r['ttl']), array(true, 0));

// Statuts.
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'status' => 404, 'contexts' => array('front_page')), $ttl);
fc_check('404 uses only the 404 rule', array($r['ttl'], $r['rule']), array(300, '404'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'status' => 410), $ttl);
fc_check('410 uses the 404 rule', $r['ttl'], 300);
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'status' => 301, 'contexts' => array('front_page')), $ttl);
fc_check('3xx never gets a TTL', array($r['emit'], $r['rule']), array(false, 'status:301'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'status' => 500, 'contexts' => array('front_page')), $ttl);
fc_check('5xx never gets a TTL', $r['emit'], false);

// Types de contenu.
$r = FaaasterCacheTtlRules::resolve(array('headers' => array('Content-Type: application/json'), 'contexts' => array('front_page')), $ttl);
fc_check('JSON never gets a TTL', array($r['emit'], $r['rule']), array(false, 'content_type'));
$feed = $ttl;
$feed['rules']['feed'] = 900;
$r = FaaasterCacheTtlRules::resolve(array('headers' => array('Content-Type: application/rss+xml; charset=UTF-8'), 'contexts' => array('feed')), $feed);
fc_check('feed type allowed for feed context', $r['ttl'], 900);
$r = FaaasterCacheTtlRules::resolve(array('headers' => array(), 'contexts' => array('front_page')), $ttl);
fc_check('no Content-Type = text/html', $r['ttl'], 600);

// Garde-fous.
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'donotcachepage' => true, 'contexts' => array('front_page')), $ttl);
fc_check('DONOTCACHEPAGE → 0', array($r['emit'], $r['ttl'], $r['rule']), array(true, 0, 'donotcachepage'));
// Jamais désactivable : un ancien réglage donotcachepage=false stocké est ignoré,
// et une règle longue sur le contexte ne l'emporte pas.
$off = $ttl;
$off['donotcachepage'] = false;
$off['rules']['singular:page'] = 86400;
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'donotcachepage' => true, 'contexts' => array('singular:page', 'singular')), $off);
fc_check('DONOTCACHEPAGE cannot be switched off', array($r['emit'], $r['ttl'], $r['rule']), array(true, 0, 'donotcachepage'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'commerce' => true, 'contexts' => array('singular:page', 'singular')), $off);
fc_check('cart/checkout/account → 0 despite a 1-day rule', array($r['emit'], $r['ttl'], $r['rule']), array(true, 0, 'commerce'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'commerce' => true, 'contexts' => array('singular:page')), $base);
fc_check('cart/checkout/account → 0 even without rule', array($r['emit'], $r['ttl'], $r['rule']), array(true, 0, 'commerce'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => array_merge($html, array('X-Accel-Expires: 120')), 'contexts' => array('front_page')), $ttl);
fc_check('foreign X-Accel-Expires respected', array($r['emit'], $r['rule']), array(false, 'foreign'));
$r = FaaasterCacheTtlRules::resolve(array('headers' => array_merge($html, array('X-Accel-Expires: 120')), 'donotcachepage' => true), $ttl);
fc_check('safety 0 overrides a foreign value', $r['ttl'], 0);

// Plafond nonce : 11 h par défaut ; ne fait jamais allonger ; défaut nginx connu ou non.
$long = $base;
$long['rules'] = array('singular' => 86400);
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => true), $long);
fc_check('nonce cap on a long rule', array($r['ttl'], $r['capped']), array(39600, true));
fc_check('diagnostic shows the cap', FaaasterCacheTtlRules::diagnostic($r), '39600; rule=singular; capped=nonce');
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => false), $long);
fc_check('no nonce → no cap', $r['ttl'], 86400);
$short = $base;
$short['rules'] = array('singular' => 3600);
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => true), $short);
fc_check('cap never lengthens', array($r['ttl'], $r['capped']), array(3600, false));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => true, 'nginx_ttl' => 36000), $base);
fc_check('platform default 10h under the cap → no header', $r['emit'], false);
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => true, 'nginx_ttl' => 172800), $base);
fc_check('long nginx default capped', array($r['emit'], $r['ttl']), array(true, 39600));
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => true, 'nginx_ttl' => null), $base);
fc_check('unknown nginx default → no header', $r['emit'], false);
$nocap = $long;
$nocap['nonce_cap'] = false;
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'contexts' => array('singular'), 'nonce_seen' => true), $nocap);
fc_check('nonce cap can be switched off', $r['ttl'], 86400);

// Émetteur : en-têtes produits, phases du buffer.
$GLOBALS['fc_sent'] = array();
$sink = function ($line) {
    $GLOBALS['fc_sent'][] = $line;
};
list($cache) = faaaster_test_boot(array('header_sink' => $sink));
$cache->settings()->update_section('ttl', array('rules' => array('front_page' => 600), 'donotcachepage' => 1, 'nonce_cap' => 1, 'diagnostic' => 1));
$emitter = $cache->ttl();
fc_check('emitter registered on template_redirect', has_action('template_redirect') !== false, true);
$emitter->set_route(array('contexts' => array('front_page'), 'commerce' => false));
$emitter->decide(200, $html, false);
fc_check('emits X-Accel-Expires + diagnostic', $GLOBALS['fc_sent'], array('X-Accel-Expires: 600', 'X-Faaaster-Cache-TTL: 600; rule=front_page'));

$GLOBALS['fc_sent'] = array();
list($cache) = faaaster_test_boot(array('header_sink' => $sink));
$cache->ttl()->set_route(array('contexts' => array('singular'), 'commerce' => false));
$cache->ttl()->decide(200, $html, false);
fc_check('no rule → diagnostic only', $GLOBALS['fc_sent'], array('X-Faaaster-Cache-TTL: default; rule=default'));

$GLOBALS['fc_sent'] = array();
list($cache) = faaaster_test_boot(array('header_sink' => $sink));
$cache->settings()->update_section('ttl', array('rules' => array('singular' => 86400), 'donotcachepage' => 1, 'nonce_cap' => 1, 'diagnostic' => 0));
$cache->ttl()->set_route(array('contexts' => array('singular'), 'commerce' => false));
apply_filters('nonce_user_logged_out', 0, 'wp_rest');
$cache->ttl()->decide(200, $html, false);
fc_check('nonce seen via filter → capped, diagnostic off', $GLOBALS['fc_sent'], array('X-Accel-Expires: 39600'));
fc_check('nonce filter returns the uid unchanged', apply_filters('nonce_user_logged_out', 0, 'x'), 0);

// Phases : CLEAN seul ignoré ; FINAL décide une fois.
list($cache) = faaaster_test_boot(array('header_sink' => $sink));
$emitter = $cache->ttl();
fc_check('CLEAN alone keeps the buffer', $emitter->on_output('abc', PHP_OUTPUT_HANDLER_CLEAN), 'abc');
fc_check('FINAL returns the buffer intact', $emitter->on_output('<html></html>', PHP_OUTPUT_HANDLER_FINAL), '<html></html>');
fc_check('decides once', $emitter->on_output('again', PHP_OUTPUT_HANDLER_FINAL), 'again');

// Éligibilité.
$_SERVER['REQUEST_METHOD'] = 'GET';
fc_check('anonymous GET eligible', FaaasterCacheTtlEmitter::eligible(), true);
$GLOBALS['ft']['logged_in'] = true;
fc_check('logged-in not eligible', FaaasterCacheTtlEmitter::eligible(), false);
$GLOBALS['ft']['logged_in'] = false;
$_SERVER['REQUEST_METHOD'] = 'POST';
fc_check('POST not eligible', FaaasterCacheTtlEmitter::eligible(), false);
unset($_SERVER['REQUEST_METHOD']);

// La clé « 404 » (entier en PHP) survit à l'assainissement.
list($cache) = faaaster_test_boot();
$saved = $cache->settings()->update_section('ttl', array('rules' => array('404' => 120, 'front_page' => 60)));
fc_check('404 rule kept (int key)', isset($saved['rules']['404']) ? $saved['rules']['404'] : null, 120);
$r = FaaasterCacheTtlRules::resolve(array('headers' => $html, 'status' => 404), $saved);
fc_check('404 rule applied after save', array($r['ttl'], $r['rule']), array(120, '404'));

// Réglages modifiés en CLI → object cache FPM vidé ; hors CLI, rien.
list($cache, $t) = faaaster_test_boot(array('cli' => true));
$cache->settings()->update_section('ttl', array('rules' => array('front_page' => 60)));
fc_check('CLI settings change → FPM object cache flushed', $t->count('object_cache'), 1);
list($cache, $t) = faaaster_test_boot();
$cache->settings()->update_section('ttl', array('rules' => array('front_page' => 60)));
fc_check('FPM settings change → no loopback', $t->count('object_cache'), 0);

// Requête hostmanager : pas d'émetteur.
list($cache) = faaaster_test_boot(array('hostmanager' => true));
fc_check('hostmanager request: no emitter', $cache->ttl(), null);
