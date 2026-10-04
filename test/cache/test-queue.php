<?php

fc_section('queue');

// Dédoublonnage + vidage unique au shutdown.
list($cache, $t, $cf) = faaaster_test_boot();
$q = $cache->queue();
$q->enqueue_url('https://example.com/a/', 'test');
$q->enqueue_url('https://EXAMPLE.com/a/', 'test');
$q->enqueue_url('https://example.com/b/', 'test');
fc_check('nothing purged before shutdown', count($t->calls), 0);
fc_check('shutdown hook registered once', has_action('shutdown', array($q, 'on_shutdown')), FaaasterCachePurgeQueue::SHUTDOWN_PRIORITY);
do_action('shutdown');
fc_check('dedup: two nginx purges', $t->count('path'), 2);
fc_check('one Cloudflare batch', count($cf->url_batches), 1);
fc_check('Cloudflare batch has both URLs', count($cf->url_batches[0]), 2);
fc_check('event recorded', count(get_option(FaaasterCacheEvents::OPTION)), 1);

// Purge totale : remplace les URL côté nginx ET Cloudflare.
list($cache, $t, $cf) = faaaster_test_boot();
$q = $cache->queue();
$q->enqueue_url('https://example.com/a/', 'test');
$q->enqueue_all('test');
do_action('shutdown');
fc_check('purge-all supersedes URLs (nginx)', array($t->count('all'), $t->count('path')), array(1, 0));
fc_check('purge-all: one Cloudflare everything, no URL batch', array($cf->everything, count($cf->url_batches)), array(1, 0));

// Mode forcé : synchrone, avec rapport.
list($cache, $t, $cf) = faaaster_test_boot();
$report = $cache->queue()->enqueue_all('manual', true);
fc_check('forced purge-all runs immediately', $t->count('all'), 1);
fc_check('forced report ok', $report['ok'], true);
fc_check('forced report mode', $report['mode'], 'all');

// Escalade au-delà du seuil.
list($cache, $t, $cf) = faaaster_test_boot();
update_option(FaaasterCacheSettings::OPTION, array('purge' => array('escalate_threshold' => 10)));
$cache->settings()->reset_cache();
for ($i = 0; $i < 11; $i++) {
    $cache->queue()->enqueue_url("https://example.com/p{$i}/", 'bulk');
}
do_action('shutdown');
fc_check('escalation to purge-all over threshold', array($t->count('all'), $t->count('path')), array(1, 0));

// 412 = clé absente (ngx_cache_purge), 404 toléré ; 403 = erreur de configuration.
foreach (array(412, 404) as $absent) {
    list($cache, $t, $cf) = faaaster_test_boot();
    $t->path_status = $absent;
    $cache->queue()->enqueue_url('https://example.com/x/', 'test');
    $r = $cache->queue()->flush(true);
    fc_check("{$absent} (absent from cache) counts as ok", $r['ok'], true);
}
list($cache, $t, $cf) = faaaster_test_boot();
$t->path_status = 403;
$cache->queue()->enqueue_url('https://example.com/x/', 'test');
$r = $cache->queue()->flush(true);
fc_check('403 is a failure', $r['ok'], false);
list($cache, $t, $cf) = faaaster_test_boot();
$t->all_status = 0;
$r = $cache->queue()->enqueue_all('manual', true);
fc_check('purge-all transport error is a failure', $r['ok'], false);

// Query string et fichier statique : Cloudflare seulement.
list($cache, $t, $cf) = faaaster_test_boot();
$cache->queue()->enqueue_url('https://example.com/shop/?orderby=price', 'test');
$cache->queue()->enqueue_url('https://example.com/wp-content/uploads/a.css', 'test');
$cache->queue()->flush(true);
fc_check('no nginx purge for query/static', $t->count('path'), 0);
fc_check('both sent to Cloudflare', count($cf->url_batches[0]), 2);

// Hôte étranger : nginx oui (404 au pire), Cloudflare non.
list($cache, $t, $cf) = faaaster_test_boot();
$cache->queue()->enqueue_url('https://other.test/page/', 'test');
$cache->queue()->flush(true);
fc_check('foreign host purged on nginx with its own Host', $t->calls[0], array('path', 'other.test', '/page/'));
fc_check('foreign host not sent to Cloudflare', count($cf->url_batches), 0);

// Cloudflare désactivé.
list($cache, $t, $cf) = faaaster_test_boot(array('cf_enabled' => false));
$cache->queue()->enqueue_all('manual', true);
fc_check('Cloudflare disabled: no call', $cf->everything, 0);

// Mise en file après le shutdown : vidage immédiat.
list($cache, $t, $cf) = faaaster_test_boot();
$cache->queue()->enqueue_url('https://example.com/a/', 'test');
do_action('shutdown');
$cache->queue()->enqueue_url('https://example.com/late/', 'late');
fc_check('late enqueue after shutdown flushes immediately', $t->count('path'), 2);

// Budget de temps dépassé : bascule en purge totale.
list($cache, $t, $cf) = faaaster_test_boot();
$t->delay_ms = 1600;
$cache->queue()->enqueue_url('https://example.com/1/', 'slow');
$cache->queue()->enqueue_url('https://example.com/2/', 'slow');
$cache->queue()->enqueue_url('https://example.com/3/', 'slow');
$r = $cache->queue()->flush(true);
fc_check('budget exceeded escalates to purge-all', array($r['mode'], $t->count('all')), array('all', 1));
fc_check('budget escalation purges Cloudflare everything', $cf->everything, 1);
