<?php

fc_section('hostmanager');

list($cache, $t, $cf) = faaaster_test_boot();
$response = $cache->hostmanager()->rest_clear_cache(null);
fc_check('clear_cache ok → 200', $response->get_status(), 200);
fc_check('clear_cache ok → code ok', $response->data['code'], 'ok');
fc_check('clear_cache: exactly one Cloudflare call', $cf->everything, 1);
fc_check('clear_cache: object cache flushed', $GLOBALS['faaaster_test']['cache_flushed'], 1);
fc_check('clear_cache: one nginx purge-all', $t->count('all'), 1);

list($cache, $t, $cf) = faaaster_test_boot();
$t->all_status = 502;
$response = $cache->hostmanager()->rest_clear_cache(null);
fc_check('FastCGI failure → 502', $response->get_status(), 502);
fc_check('FastCGI failure → purge_failed', $response->data['code'], 'purge_failed');
fc_check('failure carries the nginx status', $response->data['data']['fastcgi']['status'], 502);
