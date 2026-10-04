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

    public function __construct($auth)
    {
        $this->auth = $auth;
    }

    public function get_header($name)
    {
        return strtolower($name) === 'authorization' ? $this->auth : null;
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
