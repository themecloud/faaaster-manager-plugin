<?php

fc_section('admin');

/** Rend un onglet ; toute notice/warning PHP pendant le rendu est un échec. */
function fc_admin_render($cache, $tab, $notice = null)
{
    $errors = array();
    set_error_handler(function ($no, $str, $file, $line) use (&$errors) {
        $errors[] = basename($file) . ':' . $line . ' ' . $str;
        return true;
    });
    ob_start();
    try {
        $views = new FaaasterCacheAdminViews($cache);
        $views->page($tab, $notice);
    } finally {
        $html = ob_get_clean();
        restore_error_handler();
    }
    fc_check("render {$tab}: no PHP notice", $errors, array());
    return $html;
}

/** Exécute un handler admin-post ; renvoie ['redirect' => …] ou ['die' => code]. */
function fc_admin_post($admin, $method, array $post = array(), array $get = array())
{
    $_POST = $post;
    $_GET = $get;
    try {
        $admin->$method();
    } catch (FaaasterTestRedirect $r) {
        return array('redirect' => $r->location);
    } catch (FaaasterTestDie $d) {
        return array('die' => $d->getCode());
    } finally {
        $_POST = array();
        $_GET = array();
    }
    return array();
}

function fc_admin_boot()
{
    faaaster_test_reset_admin();
    $booted = faaaster_test_boot();
    $GLOBALS['faaaster_test']['user_id'] = 7;
    return $booted;
}

// ---- Rendu : les cinq onglets, sans notice PHP, un nonce par formulaire.
list($cache, $transport) = fc_admin_boot();
foreach (FaaasterCacheAdmin::TABS as $tab) {
    $html = fc_admin_render($cache, $tab);
    fc_check("render {$tab}: DS scope", strpos($html, '<div class="fstr-ds">') !== false, true);
    fc_check("render {$tab}: one nonce per form", substr_count($html, 'name="_wpnonce"'), substr_count($html, '<form'));
    fc_check("render {$tab}: no WordPress UI class", preg_match('/class="[^"]*\b(button|form-table|notice)\b/', $html), 0);
    fc_check("render {$tab}: active tab", strpos($html, 'aria-selected="true"') !== false, true);
}
$html = fc_admin_render($cache, 'rules');
fc_check('toolbar: tabs and purge-all on the same line', (bool) preg_match('#<div class="toolbar"><div class="seg fstr-tabs".*?</div><form [^>]*class="fstr-push"[^>]*>.*?faaaster_cache_purge_all.*?</form></div>#s', $html), true);
fc_check('page header carries no action', (bool) preg_match('#<div class="page-h">(?:(?!</div></div></div>).)*<form#s', $html), false);
fc_check('no white-on-white secondary button in cards', strpos(file_get_contents(__DIR__ . '/../../class/cache/admin/views.php'), 'btn-secondary'), false);
fc_check('rules: attachment not listed', strpos($html, 'purge[post_types][attachment]'), false);
fc_check('rules: CPT listed', strpos($html, 'purge[post_types][book][enabled]') !== false, true);
fc_check('rules: taxonomy chips are list fields', strpos($html, 'name="purge[post_types][post][taxonomies][]"') !== false, true);
$html = fc_admin_render($cache, 'ttl');
fc_check('ttl: CPT archive context', strpos($html, 'ttl[rules][archive:book][value]') !== false, true);
fc_check('ttl: no archive context without has_archive', strpos($html, 'ttl[rules][archive:page]'), false);
fc_check('ttl: 404 context', strpos($html, 'ttl[rules][404][value]') !== false, true);
fc_check('ttl: duration column right-aligned (header + cells)', substr_count($html, 'class="fstr-end"') > 10, true);
$html = fc_admin_render($cache, 'tools');
fc_check('tools: health probe on the purge route', $GLOBALS['fta']['remote_calls'][0][0], 'http://127.0.0.1/purge/__faaaster_health__');
fc_check('tools: 412 = reachable', strpos($html, 'reachable') !== false && strpos($html, 'not reachable') === false, true);

// ---- Échappement : journal et résultats de test alimentés par des valeurs hostiles.
$evil = '"><script>alert(1)</script>';
update_option(FaaasterCacheEvents::OPTION, array(array(
    't' => time(), 'type' => 'purge', 'source' => $evil, 'context' => $evil, 'forced' => true,
    'user_id' => 1, 'count' => 1, 'urls' => array('https://example.com/' . $evil), 'result' => array('nginx' => array('ok' => false)),
)));
set_transient('faaaster_cache_test_7', array('url' => 'https://example.com/' . $evil, 'results' => array(
    array('status' => 200, 'fastcgi' => $evil, 'ttl' => $evil, 'set_cookie' => false, 'ms' => 3),
    array('error' => $evil, 'ms' => 1),
)));
$html = fc_admin_render($cache, 'events') . fc_admin_render($cache, 'tools', array('type' => 'danger', 'message' => $evil));
fc_check('escaping: no raw script', strpos($html, '<script>'), false);
fc_check('escaping: encoded', substr_count($html, '&lt;script&gt;') >= 6, true);
fc_check('notice: danger alert', strpos($html, 'alert alert-danger') !== false, true);
fc_check('test result consumed once', get_transient('faaaster_cache_test_7'), false);

// ---- Traduction fr_FR (compilée) : chaînes, pluriels (n > 1), durées.
faaaster_test_locale('fr_FR');
$html = fc_admin_render($cache, 'rules');
fc_check('fr: tab label', strpos($html, 'Règles de purge') !== false, true);
fc_check('fr: save button', strpos($html, 'Enregistrer') !== false, true);
update_option(FaaasterCacheEvents::OPTION, array(
    array('t' => time(), 'type' => 'purge', 'source' => 'save_post', 'context' => 'web', 'forced' => false, 'user_id' => 1, 'count' => 3, 'urls' => array(), 'result' => array('nginx' => array('ok' => true))),
    array('t' => time(), 'type' => 'purge', 'source' => 'save_post', 'context' => 'web', 'forced' => false, 'user_id' => 1, 'count' => 1, 'urls' => array(), 'result' => array('nginx' => array('ok' => true))),
));
$html = fc_admin_render($cache, 'events');
fc_check('fr: plural', strpos($html, '3 adresses') !== false, true);
fc_check('fr: singular', strpos($html, '1 adresse<') !== false, true);
fc_check('fr: human 10h', FaaasterCacheAdminViews::human(36000), '10 heures');
fc_check('fr: human 1 day', FaaasterCacheAdminViews::human(86400), '1 jour');
faaaster_test_locale(null);
fc_check('en: human 90s', FaaasterCacheAdminViews::human(90), '90 seconds');
fc_check('en: human 1 minute', FaaasterCacheAdminViews::human(60), '1 minute');

// ---- Durées saisies.
fc_check('to_seconds 2 h', FaaasterCacheAdmin::to_seconds('2', 'h'), 7200);
fc_check('to_seconds 0', FaaasterCacheAdmin::to_seconds('0', 'd'), 0);
fc_check('to_seconds empty → default', FaaasterCacheAdmin::to_seconds('', 'h'), null);
fc_check('to_seconds negative', FaaasterCacheAdmin::to_seconds('-1', 'h'), null);
fc_check('to_seconds 1.5 m', FaaasterCacheAdmin::to_seconds('1.5', 'm'), 90);
fc_check('split 36000', FaaasterCacheAdminViews::split_duration(36000), array(10, 'h'));
fc_check('split 90', FaaasterCacheAdminViews::split_duration(90), array(90, 's'));
fc_check('split 0', FaaasterCacheAdminViews::split_duration(0), array(0, 's'));

// ---- Actions : capacité et nonce obligatoires.
list($cache, $transport) = fc_admin_boot();
$admin = new FaaasterCacheAdmin($cache);
$GLOBALS['fta']['caps'] = array();
fc_check('no capability → 403', fc_admin_post($admin, 'handle_purge_all'), array('die' => 403));
fc_check('no capability → no purge', $transport->calls, array());
$GLOBALS['fta']['caps'] = array('manage_options' => true);
$GLOBALS['fta']['nonce_ok'] = false;
fc_check('bad nonce → stop', isset(fc_admin_post($admin, 'handle_purge_all')['die']), true);
fc_check('bad nonce → no purge', $transport->calls, array());
$GLOBALS['fta']['nonce_ok'] = true;

// Purge totale.
$r = fc_admin_post($admin, 'handle_purge_all', array('tab' => 'rules'));
fc_check('purge all: redirect to the calling tab', $r['redirect'], FaaasterCacheAdmin::page_url('rules'));
fc_check('purge all: nonce checked', end($GLOBALS['fta']['nonce_checked']), FaaasterCacheAdmin::NONCE);
fc_check('purge all: one nginx purge-all', count(array_filter($transport->calls, function ($c) { return $c[0] === 'all'; })), 1);
fc_check('purge all: ok notice', get_transient('faaaster_cache_notice_7')['type'], 'ok');
$transport->all_status = 500;
fc_admin_post($admin, 'handle_purge_all', array('tab' => '../evil'));
fc_check('purge all failure: danger notice', get_transient('faaaster_cache_notice_7')['type'], 'danger');

// Purge d'une adresse : hôte du site uniquement.
list($cache, $transport) = fc_admin_boot();
$admin = new FaaasterCacheAdmin($cache);
$r = fc_admin_post($admin, 'handle_purge_url', array('url' => 'https://evil.test/page/'));
fc_check('purge url: foreign host refused', array($r['redirect'], $transport->calls), array(FaaasterCacheAdmin::page_url('tools'), array()));
fc_check('purge url: foreign host notice', get_transient('faaaster_cache_notice_7')['type'], 'danger');
fc_admin_post($admin, 'handle_purge_url', array('url' => 'https://example.com/hello/'));
fc_check('purge url: purged', $transport->paths(), array('/hello/'));

// Test d'adresse : deux requêtes en boucle locale, hôte imposé.
$GLOBALS['fta']['remote'] = array(
    array('response' => array('code' => 200), 'headers' => array('x-fastcgi-cache' => 'MISS', 'x-faaaster-cache-ttl' => 'default; rule=default')),
    array('response' => array('code' => 200), 'headers' => array('x-fastcgi-cache' => 'HIT', 'x-faaaster-cache-ttl' => 'default; rule=default')),
);
$r = fc_admin_post($admin, 'handle_test_url', array('url' => 'https://example.com/hello/?a=1'));
fc_check('test url: two loopback requests', count($GLOBALS['fta']['remote_calls']), 2);
fc_check('test url: loopback address', $GLOBALS['fta']['remote_calls'][0][0], 'http://127.0.0.1/hello/?a=1');
fc_check('test url: site host', $GLOBALS['fta']['remote_calls'][0][1]['headers']['Host'], 'example.com');
fc_check('test url: no redirect followed', $GLOBALS['fta']['remote_calls'][0][1]['redirection'], 0);
$stored = get_transient('faaaster_cache_test_7');
fc_check('test url: results stored', array($stored['results'][0]['fastcgi'], $stored['results'][1]['fastcgi']), array('MISS', 'HIT'));
$GLOBALS['fta']['remote_calls'] = array();
fc_admin_post($admin, 'handle_test_url', array('url' => 'https://evil.test/'));
fc_check('test url: foreign host → no request', $GLOBALS['fta']['remote_calls'], array());

// Durées : vide = défaut, unités converties, clé 404 conservée.
$r = fc_admin_post($admin, 'handle_save_ttl', array('ttl' => array(
    'rules' => array(
        'front_page' => array('value' => '2', 'unit' => 'h'),
        'singular' => array('value' => '', 'unit' => 'h'),
        '404' => array('value' => '5', 'unit' => 'm'),
    ),
    'donotcachepage' => '1', 'nonce_cap' => '1', 'diagnostic' => '0',
)));
fc_check('save ttl: redirect', $r['redirect'], FaaasterCacheAdmin::page_url('ttl'));
$ttl = $cache->settings()->get('ttl');
fc_check('save ttl: rules', $ttl['rules'], array('front_page' => 7200, '404' => 300));
fc_check('save ttl: diagnostic off', empty($ttl['diagnostic']), true);

// Journal vidé.
$cache->events()->record(array('type' => 'purge', 'source' => 'x', 'urls' => array('https://example.com/')));
$cache->events()->persist();
fc_admin_post($admin, 'handle_clear_events');
fc_check('clear events', $cache->events()->all(), array());

// ---- Feuilles chargées uniquement sur notre page.
$GLOBALS['fta']['enqueued'] = array();
$admin->assets('index.php');
fc_check('assets: not on other pages', $GLOBALS['fta']['enqueued'], array());
$admin->assets('settings_page_' . FaaasterCacheAdmin::SLUG);
fc_check('assets: on our page', $GLOBALS['fta']['enqueued'], array('faaaster-ds-tokens', 'faaaster-ds-components', 'faaaster-cache-admin', 'faaaster-cache-admin'));

// ---- Barre d'administration.
list($cache, $transport) = fc_admin_boot();
$bar = new FaaasterCacheAdminBar($cache);
$nodes = new class {
    public $nodes = array();
    public function add_node($node)
    {
        $this->nodes[$node['id']] = $node;
    }
};
$GLOBALS['fta']['caps'] = array();
$bar->menu($nodes);
fc_check('bar: hidden without capability', $nodes->nodes, array());
$GLOBALS['fta']['caps'] = array('manage_options' => true);
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['REQUEST_URI'] = '/hello/';
$bar->menu($nodes);
fc_check('bar: three nodes on the front', array_keys($nodes->nodes), array('faaaster-cache', 'faaaster-cache-all', 'faaaster-cache-page'));
fc_check('bar: links carry a nonce', strpos($nodes->nodes['faaaster-cache-page']['href'], '_wpnonce=nonce-' . FaaasterCacheAdminBar::ACTION) !== false, true);
$r = fc_admin_post($bar, 'handle', array(), array('scope' => 'page', 'url' => 'https://example.com/hello/'));
fc_check('bar page: only this page', array($r['redirect'], $transport->paths()), array('https://example.com/hello/', array('/hello/')));
$transport->calls = array();
$r = fc_admin_post($bar, 'handle', array(), array('scope' => 'page', 'url' => 'https://evil.test/x/'));
fc_check('bar page: foreign host → nothing purged', $transport->calls, array());
$r = fc_admin_post($bar, 'handle', array(), array('scope' => 'all'));
fc_check('bar all: purge-all', $transport->calls[0][0], 'all');
fc_check('bar all: notice status', get_transient('faaaster_cache_bar_7'), 'ok');
unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);

// ---- Statique : chaque chaîne de l'interface est traduite, sans entrée orpheline.
$source_strings = array();
foreach (glob(__DIR__ . '/../../class/cache/admin/*.php') as $file) {
    $code = file_get_contents($file);
    preg_match_all("/\\b(?:__|esc_html__|esc_attr__)\\('((?:[^'\\\\]|\\\\.)*)', 'faaaster-manager-plugin'\\)/", $code, $m);
    foreach ($m[1] as $s) {
        $source_strings[stripslashes($s)] = true;
    }
    preg_match_all("/\\b_n\\('((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)'/", $code, $m);
    foreach ($m[1] as $i => $s) {
        $source_strings[stripslashes($s) . "\0" . stripslashes($m[2][$i])] = true;
    }
}
$fr = require __DIR__ . '/../../languages/faaaster-manager-plugin-fr_FR.l10n.php';
fc_check('i18n: strings found', count($source_strings) > 100, true);
fc_check('i18n: fr covers every string', array_keys(array_diff_key($source_strings, $fr['messages'])), array());
fc_check('i18n: no orphan fr entry', array_keys(array_diff_key($fr['messages'], $source_strings)), array());
fc_check('i18n: plural rule', $fr['plural-forms'], 'nplurals=2; plural=(n > 1);');

// ---- Statique : aucune couleur en dur hors fichiers générés.
foreach (array('assets/admin.css', 'assets/admin.js', 'class/cache/admin/views.php') as $rel) {
    $code = preg_replace('#/\*.*?\*/#s', '', file_get_contents(__DIR__ . '/../../' . $rel));
    fc_check("no hard-coded colour in {$rel}", preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $code), 0);
}

// ---- Statique : polices du tableau de bord en repli, rien de téléchargé.
$tokens = file_get_contents(__DIR__ . '/../../assets/ds/tokens.css');
fc_check('fonts: WordPress dashboard stack as fallback', strpos($tokens, '"Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;') !== false, true);
fc_check('fonts: WordPress mono stack as fallback', strpos($tokens, 'Consolas, Monaco, monospace;') !== false, true);
$all_css = '';
foreach (array('assets/admin.css', 'assets/ds/tokens.css', 'assets/ds/components.css') as $rel) {
    $all_css .= file_get_contents(__DIR__ . '/../../' . $rel);
}
fc_check('fonts: nothing embedded or fetched', preg_match('/@font-face|@import|fonts\.googleapis|fonts\.gstatic/i', $all_css), 0);
