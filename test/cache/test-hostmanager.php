<?php

fc_section('hostmanager');

list($cache, $t, $cf) = faaaster_test_boot();
$response = $cache->hostmanager()->rest_clear_cache(null);
fc_check('clear_cache ok → 200', $response->get_status(), 200);
fc_check('clear_cache ok → code ok', $response->data['code'], 'ok');
fc_check('clear_cache: exactly one Cloudflare call', $cf->everything, 1);
fc_check('clear_cache: object cache flushed', $GLOBALS['faaaster_test']['cache_flushed'], 1);
fc_check('clear_cache: one nginx purge-all', $t->count('all'), 1);

// Purge totale lancée en CLI : l'object cache de FPM est vidé par la route dédiée.
list($cache, $t, $cf) = faaaster_test_boot(array('cli' => true));
$cache->queue()->enqueue_all('upgrader:plugin');
$r = $cache->queue()->flush(false);
fc_check('CLI purge-all → FPM object cache flushed once', $t->count('object_cache'), 1);
fc_check('report carries object_cache_fpm', $r['object_cache_fpm']['status'], 200);
list($cache, $t, $cf) = faaaster_test_boot(array('cli' => true));
$cache->queue()->enqueue_url('https://example.com/a/', 'cli');
$cache->queue()->flush(true);
fc_check('CLI URL purge → no object cache flush', $t->count('object_cache'), 0);
list($cache, $t, $cf) = faaaster_test_boot();
$cache->queue()->enqueue_all('web', true);
fc_check('FPM (non-CLI) purge-all → no loopback flush', $t->count('object_cache'), 0);

// Route flush_object_cache : Bearer obligatoire, comparaison stricte.
if (!defined('WP_API_KEY')) {
    define('WP_API_KEY', 'unit-test-key');
}
class FaaasterTestRequest
{
    private $auth;
    private $agent;

    public function __construct($auth, $agent = null)
    {
        $this->auth = $auth;
        $this->agent = $agent;
    }

    public function get_header($name)
    {
        $name = strtolower($name);
        if ($name === 'authorization') {
            return $this->auth;
        }
        return $name === 'user-agent' ? $this->agent : null;
    }
}
fc_check('bearer ok', FaaasterCacheHostmanager::bearer_ok(new FaaasterTestRequest('Bearer unit-test-key')), true);
fc_check('bearer wrong', FaaasterCacheHostmanager::bearer_ok(new FaaasterTestRequest('Bearer nope')), false);
fc_check('bearer missing', FaaasterCacheHostmanager::bearer_ok(new FaaasterTestRequest(null)), false);
list($cache, $t, $cf) = faaaster_test_boot();
$response = $cache->hostmanager()->rest_flush_object_cache(null);
fc_check('flush_object_cache → ok + wp_cache_flush', array($response->get_status(), $GLOBALS['faaaster_test']['cache_flushed']), array(200, 1));

list($cache, $t, $cf) = faaaster_test_boot();
$t->all_status = 502;
$response = $cache->hostmanager()->rest_clear_cache(null);
fc_check('FastCGI failure → 502', $response->get_status(), 502);
fc_check('FastCGI failure → purge_failed', $response->data['code'], 'purge_failed');
fc_check('failure carries the nginx status', $response->data['data']['fastcgi']['status'], 502);

// ---- clear_cache : Bearer journalisé, bloquant seulement sur demande.
list($cache, $t, $cf) = faaaster_test_boot();
fc_check('clear_cache bearer valid → allowed', FaaasterCacheHostmanager::authorize('clear_cache', new FaaasterTestRequest('Bearer unit-test-key'), false), true);
fc_check('valid bearer → nothing recorded', FaaasterCacheHostmanager::auth_stats()['count'], 0);
fc_check('missing bearer → still allowed', FaaasterCacheHostmanager::authorize('clear_cache', new FaaasterTestRequest(null, 'fstr-worker'), false), true);
$stats = FaaasterCacheHostmanager::auth_stats();
fc_check('missing bearer → recorded', array($stats['count'], $stats['last_route'], $stats['last_reason'], $stats['last_agent']), array(1, 'clear_cache', 'missing', 'fstr-worker'));
fc_check('missing bearer → timestamp', is_int($stats['last_at']) && $stats['last_at'] > 0, true);
fc_check('invalid bearer → allowed, recorded', array(FaaasterCacheHostmanager::authorize('clear_cache', new FaaasterTestRequest('Bearer nope'), false), FaaasterCacheHostmanager::auth_stats()['last_reason']), array(true, 'invalid'));
fc_check('require auth → refused', FaaasterCacheHostmanager::authorize('clear_cache', new FaaasterTestRequest('Bearer nope'), true), false);
fc_check('require auth → valid bearer passes', FaaasterCacheHostmanager::authorize('clear_cache', new FaaasterTestRequest('Bearer unit-test-key'), true), true);
fc_check('three unauthenticated calls counted', FaaasterCacheHostmanager::auth_stats()['count'], 3);
$stored = serialize(get_option(FaaasterCacheHostmanager::AUTH_OPTION));
fc_check('token never stored', array(strpos($stored, 'unit-test-key'), strpos($stored, 'nope'), strpos($stored, 'Bearer')), array(false, false, false));
fc_check('clear_cache_permission defaults to non-blocking', FaaasterCacheHostmanager::clear_cache_permission(new FaaasterTestRequest(null)), true);
$long = str_repeat('a', 300);
FaaasterCacheHostmanager::authorize('clear_cache', new FaaasterTestRequest(null, $long), false);
fc_check('agent truncated to 80', strlen(FaaasterCacheHostmanager::auth_stats()['last_agent']), 80);

$same = new FaaasterTestRequest(null);
$before = FaaasterCacheHostmanager::auth_stats()['count'];
FaaasterCacheHostmanager::authorize('clear_cache', $same, false);
FaaasterCacheHostmanager::authorize('clear_cache', $same, false); // rest_send_allow_header
fc_check('same request counted once (Allow header re-check)', FaaasterCacheHostmanager::auth_stats()['count'] - $before, 1);
fc_check('same request, same decision', FaaasterCacheHostmanager::authorize('clear_cache', $same, true), true);

// ---- site_state : other_data.cache.
$state = faaaster_cache_state();
fc_check('state: module active', array($state['module'], $state['takeover'], $state['ttl_rules'], $state['purge_rules_customized']), array('active', false, 0, false));
fc_check('state: unauthenticated counter exposed', $state['clear_cache_unauthenticated']['count'], 6);
$cache->settings()->update_section('ttl', array('rules' => array('front_page' => 600, '404' => 120)));
$purge = $cache->settings()->get('purge');
$purge['always_paths'] = array('/contact/');
$cache->settings()->update_section('purge', $purge);
$state = faaaster_cache_state();
fc_check('state: rules counted', array($state['ttl_rules'], $state['purge_rules_customized']), array(2, true));
faaaster_test_reset_wp();
FaaasterCache::reset();
$state = faaaster_cache_state();
fc_check('state: module disabled', array($state['module'], $state['ttl_rules'], $state['clear_cache_unauthenticated']['count']), array('disabled', 0, 0));
fc_check('state: disabled module writes nothing', $GLOBALS['faaaster_test']['options'], array());

// ---- Câblage : clear_cache passe par la permission journalisée ; capacité exposée.
$main = file_get_contents(__DIR__ . '/../../faaaster-manager-plugin.php');
fc_check('route clear_cache uses clear_cache_permission', (bool) preg_match("#'/clear_cache',\s*array\((?:(?!register_rest_route).)*'permission_callback' => array\('FaaasterCacheHostmanager', 'clear_cache_permission'\)#s", $main), true);
fc_check('site_state carries other_data.cache', strpos(file_get_contents(__DIR__ . '/../../class/site-state.php'), "faaaster_cache_state()") !== false, true);
fc_check('manager_version exposes cacheModule', strpos(file_get_contents(__DIR__ . '/../../class/mcp-abilities.php'), "'cacheModule' =>") !== false, true);
