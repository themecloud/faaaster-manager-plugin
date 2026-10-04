<?php

fc_section('hostmanager');

list($cache, $t, $cf) = faaaster_test_boot();
$response = $cache->hostmanager()->rest_clear_cache(null);
fc_check('clear_cache ok → 200', $response->get_status(), 200);
fc_check('clear_cache ok → code ok', $response->data['code'], 'ok');
fc_check('clear_cache: exactly one Cloudflare call', $cf->everything, 1);
fc_check('clear_cache: object cache flushed', $GLOBALS['faaaster_test']['cache_flushed'], 1);
fc_check('clear_cache: one nginx purge-all', $t->count('all'), 1);
fc_check('clear_cache: no opcache field (never reset)', array_key_exists('opcache', $response->data['data']), false);

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
fc_check('bearer ok', FaaasterHostmanagerAuth::bearer_problem(new FaaasterTestRequest('Bearer unit-test-key')), null);
fc_check('bearer wrong', FaaasterHostmanagerAuth::bearer_problem(new FaaasterTestRequest('Bearer nope')), 'invalid');
fc_check('bearer missing', FaaasterHostmanagerAuth::bearer_problem(new FaaasterTestRequest(null)), 'missing');
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
fc_check('clear_cache bearer valid → allowed', FaaasterHostmanagerAuth::authorize('clear_cache', new FaaasterTestRequest('Bearer unit-test-key'), false), true);
fc_check('valid bearer → nothing recorded', FaaasterHostmanagerAuth::stats()['count'], 0);
fc_check('missing bearer → still allowed', FaaasterHostmanagerAuth::authorize('clear_cache', new FaaasterTestRequest(null, 'fstr-worker'), false), true);
$stats = FaaasterHostmanagerAuth::stats();
fc_check('missing bearer → recorded', array($stats['count'], $stats['last_route'], $stats['last_reason'], $stats['last_agent']), array(1, 'clear_cache', 'missing', 'fstr-worker'));
fc_check('missing bearer → timestamp', is_int($stats['last_at']) && $stats['last_at'] > 0, true);
fc_check('invalid bearer → allowed, recorded', array(FaaasterHostmanagerAuth::authorize('clear_cache', new FaaasterTestRequest('Bearer nope'), false), FaaasterHostmanagerAuth::stats()['last_reason']), array(true, 'invalid'));
fc_check('require auth → refused', FaaasterHostmanagerAuth::authorize('clear_cache', new FaaasterTestRequest('Bearer nope'), true), false);
fc_check('require auth → valid bearer passes', FaaasterHostmanagerAuth::authorize('clear_cache', new FaaasterTestRequest('Bearer unit-test-key'), true), true);
fc_check('three unauthenticated calls counted', FaaasterHostmanagerAuth::stats()['count'], 3);
$stored = serialize(get_option(FaaasterHostmanagerAuth::OPTION));
fc_check('token never stored', array(strpos($stored, 'unit-test-key'), strpos($stored, 'nope'), strpos($stored, 'Bearer')), array(false, false, false));
$guard = faaaster_hostmanager_guard('site_state');
fc_check('critical route guard is blocking', $guard(new FaaasterTestRequest(null)), false);
fc_check('critical route refusal is recorded', FaaasterHostmanagerAuth::stats()['last_route'], 'site_state');
fc_check('critical route guard lets the Bearer through', $guard(new FaaasterTestRequest('Bearer unit-test-key')), true);
$phased = faaaster_hostmanager_guard_phased('clear_cache');
fc_check('phased guard (clear_cache) lets an unauthenticated call through in phase 1', $phased(new FaaasterTestRequest(null)), true);
$long = str_repeat('a', 300);
FaaasterHostmanagerAuth::authorize('clear_cache', new FaaasterTestRequest(null, $long), false);
fc_check('agent truncated to 80', strlen(FaaasterHostmanagerAuth::stats()['last_agent']), 80);

$same = new FaaasterTestRequest(null);
$before = FaaasterHostmanagerAuth::stats()['count'];
FaaasterHostmanagerAuth::authorize('clear_cache', $same, false);
FaaasterHostmanagerAuth::authorize('clear_cache', $same, false); // rest_send_allow_header
fc_check('same request counted once (Allow header re-check)', FaaasterHostmanagerAuth::stats()['count'] - $before, 1);
fc_check('same request, same decision', FaaasterHostmanagerAuth::authorize('clear_cache', $same, true), true);

// ---- site_state : other_data.cache.
$state = faaaster_cache_state();
fc_check('state: module active', array($state['module'], $state['takeover'], $state['ttl_rules'], $state['purge_rules_customized']), array('active', false, 0, false));
fc_check('state: auth counter no longer in the cache block', array_key_exists('clear_cache_unauthenticated', $state), false);
fc_check('auth counter exposed by FaaasterHostmanagerAuth::stats', FaaasterHostmanagerAuth::stats()['count'], 7);
$cache->settings()->update_section('ttl', array('rules' => array('front_page' => 600, '404' => 120)));
$purge = $cache->settings()->get('purge');
$purge['always_paths'] = array('/contact/');
$cache->settings()->update_section('purge', $purge);
$state = faaaster_cache_state();
fc_check('state: rules counted', array($state['ttl_rules'], $state['purge_rules_customized']), array(2, true));
faaaster_test_reset_wp();
FaaasterCache::reset();
$state = faaaster_cache_state();
fc_check('state: module disabled', array($state['module'], $state['ttl_rules']), array('disabled', 0));
fc_check('state: disabled module writes nothing', $GLOBALS['faaaster_test']['options'], array());

// ---- Câblage : clear_cache passe par la permission journalisée ; capacité exposée.
$main = file_get_contents(__DIR__ . '/../../faaaster-manager-plugin.php');
// Aucune route locale de la plateforme sans garde : hostmanager/v1 et
// faaaster-agent/v1 ; seule la route publique sso/v1/login (jeton validé auprès
// de Next) reste ouverte. toggle_mu_plugin (code mort) a été supprimée.
$sources = array(
    'faaaster-manager-plugin.php' => $main,
    'class/mcp-abilities.php' => file_get_contents(__DIR__ . '/../../class/mcp-abilities.php'),
);
$unguarded = array();
$phased_routes = array();
$routes = 0;
foreach ($sources as $file => $code) {
    preg_match_all("#register_rest_route\(\s*([^,]+),\s*'([^']+)'(.*?)\)\);#s", $code, $all, PREG_SET_ORDER);
    foreach ($all as $r) {
        $routes++;
        $id = trim($r[1], " '") . $r[2];
        $public = $id === 'sso/v1/login';
        $guarded = (bool) preg_match('#faaaster_hostmanager_guard(_phased)?\(|\$localhost\(|\'faaaster_agent_hostmanager_permission\'|\'faaaster_mcp_ability_permission\'#', $r[3]);
        if (preg_match('#faaaster_hostmanager_guard_phased\(#', $r[3])) {
            $phased_routes[] = $id;
        }
        if (!$guarded && !$public) {
            $unguarded[] = $id;
        }
    }
}
fc_check('platform routes found', $routes >= 27, true);
fc_check('toggle_mu_plugin removed', strpos($main, 'toggle_mu_plugin') === false && !file_exists(__DIR__ . '/../../class/mu-plugin-manager.php'), true);
fc_check('every platform route is guarded', $unguarded, array());
fc_check('only clear_cache is phased, every other local route blocks', $phased_routes, array('$namespace/clear_cache'));
fc_check('faaaster-agent localhost routes use the blocking guard', strpos($sources['class/mcp-abilities.php'], 'faaaster_hostmanager_guard_phased') === false, true);
fc_check('faaaster-agent localhost routes use the common guard', strpos($sources['class/mcp-abilities.php'], 'faaaster_hostmanager_guard(\'faaaster-agent/\' . $route)') !== false, true);
$site_state_src = file_get_contents(__DIR__ . '/../../class/site-state.php');
fc_check('site_state carries other_data.cache and hostmanager_auth', array(strpos($site_state_src, "faaaster_cache_state()") !== false, strpos($site_state_src, "FaaasterHostmanagerAuth::stats()") !== false), array(true, true));
fc_check('manager_version exposes cacheModule', strpos(file_get_contents(__DIR__ . '/../../class/mcp-abilities.php'), "'cacheModule' =>") !== false, true);
