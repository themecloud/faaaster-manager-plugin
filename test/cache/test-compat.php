<?php

fc_section('compat (fork absent)');

list($cache, $t, $cf) = faaaster_test_boot();
fc_check('nginx_purger shim installed', $GLOBALS['nginx_purger'] instanceof FaaasterNginxPurgerCompat, true);
fc_check('nginx_helper never defined', isset($GLOBALS['nginx_helper']), false);

do_action('rt_nginx_helper_purge_all');
fc_check('rt_nginx_helper_purge_all deferred to shutdown', $t->count('all'), 0);
do_action('shutdown');
fc_check('rt_nginx_helper_purge_all purges at shutdown', $t->count('all'), 1);

list($cache, $t, $cf) = faaaster_test_boot();
do_action('rt_nginx_helper_purge_all', true);
fc_check('rt_nginx_helper_purge_all(true) is immediate', $t->count('all'), 1);

list($cache, $t, $cf) = faaaster_test_boot();
global $nginx_purger;
$nginx_purger->purge_url('https://example.com/build/');
do_action('shutdown');
fc_check('shim purge_url queued', $t->calls[0], array('path', 'example.com', '/build/'));
$nginx_purger->some_unknown_method('x');
fc_check('shim unknown method does not fatal', true, true);

list($cache, $t, $cf) = faaaster_test_boot();
$nginx_purger = $GLOBALS['nginx_purger'];
$nginx_purger->purge_all(true);
fc_check('shim purge_all(true) immediate', $t->count('all'), 1);

// Exceptions du module : jamais propagées.
list($cache, $t, $cf) = faaaster_test_boot();
$cache->hook('faaaster_test_boom', function () {
    throw new RuntimeException('boom');
});
do_action('faaaster_test_boom');
fc_check('hook exception contained', true, true);
$cache->hook('faaaster_test_filter', function ($v) {
    throw new RuntimeException('boom');
}, 10, 1, true);
fc_check('filter exception returns input', apply_filters('faaaster_test_filter', 'keep'), 'keep');
