<?php

fc_section('wp rocket');

// Politique : valeurs selon la version (bug corrigé : intervalle RUCSS < 3.16 désormais posé).
$v315 = FaaasterWpRocketPolicy::values('3.15.10');
fc_check('< 3.16: rucss rows', $v315['rocket_rucss_pending_jobs_cron_rows_count'], 25);
fc_check('< 3.16: rucss interval (was never applied)', $v315['rocket_rucss_pending_jobs_cron_interval'], 120);
fc_check('< 3.16: no saas filters', isset($v315['rocket_saas_pending_jobs_cron_rows_count']), false);
fc_check('< 3.16.2: no min-in-progress', isset($v315['rocket_preload_cache_min_in_progress_jobs_count']), false);
$v316 = FaaasterWpRocketPolicy::values('3.16.0');
fc_check('3.16: saas rows', $v316['rocket_saas_pending_jobs_cron_rows_count'], 25);
fc_check('3.16: no min-in-progress before 3.16.2', isset($v316['rocket_preload_cache_min_in_progress_jobs_count']), false);
$v323 = FaaasterWpRocketPolicy::values('3.23.5.1');
fc_check('3.23: min-in-progress 3', $v323['rocket_preload_cache_min_in_progress_jobs_count'], 3);
fc_check('delay in microseconds (1 s)', $v323['rocket_preload_delay_between_requests'], 1000000);
fc_check('preload rows 25', $v323['rocket_preload_cache_pending_jobs_cron_rows_count'], 25);
fc_check('preload interval 120', $v323['rocket_preload_pending_jobs_cron_interval'], 120);

// do_rocket_generate_caching_files : coupé par défaut, gouverné par DISABLE_WPROCKET.
faaaster_test_reset_hooks();
unset($_SERVER['DISABLE_WPROCKET']);
FaaasterWpRocketPolicy::register();
fc_check('page cache disabled by default', apply_filters('do_rocket_generate_caching_files', true), false);
fc_check('throttle scheduled on plugins_loaded', has_action('plugins_loaded', array('FaaasterWpRocketPolicy', 'throttle')), 10);
faaaster_test_reset_hooks();
$_SERVER['DISABLE_WPROCKET'] = 'false';
FaaasterWpRocketPolicy::register();
fc_check('DISABLE_WPROCKET=false keeps WP Rocket page cache', apply_filters('do_rocket_generate_caching_files', true), true);
unset($_SERVER['DISABLE_WPROCKET']);

// Throttle réel (WP Rocket « chargé »).
define('WP_ROCKET_VERSION', '3.23.5.1');
faaaster_test_reset_hooks();
FaaasterWpRocketPolicy::throttle();
fc_check('throttle applies saas interval', apply_filters('rocket_saas_pending_jobs_cron_interval', 60), 120);
fc_check('throttle applies preload delay', apply_filters('rocket_preload_delay_between_requests', 500000), 1000000);

// Adaptateur de purge (contrat Varnish).
list($cache, $t) = faaaster_test_boot();
$wpr = new FaaasterCacheIntegrationWpRocket($cache);
$wpr->register();
do_action('before_rocket_clean_domain', '/cache', '', 'https://example.com/');
do_action('shutdown');
fc_check('clean domain → purge-all', $t->count('all'), 1);

list($cache, $t) = faaaster_test_boot();
$wpr = new FaaasterCacheIntegrationWpRocket($cache);
$wpr->register();
do_action('before_rocket_clean_home', '/cache', '');
do_action('shutdown');
fc_check('clean home → home + pages 2-3', $t->paths(), array('/', '/page/2/', '/page/3/'));

list($cache, $t) = faaaster_test_boot();
$wpr = new FaaasterCacheIntegrationWpRocket($cache);
$wpr->register();
do_action('before_rocket_clean_file', 'https://example.com/article/');
do_action('before_rocket_clean_file', 'https://example.com/*');
do_action('before_rocket_clean_file', 'https://example.com/index-https.html');
do_action('shutdown');
fc_check('clean file → URL only (wildcards and index files skipped)', $t->paths(), array('/article/'));

list($cache, $t) = faaaster_test_boot();
$wpr = new FaaasterCacheIntegrationWpRocket($cache);
$wpr->register();
for ($i = 0; $i < 25; $i++) {
    do_action('rocket_saas_complete_job_status', "https://example.com/p{$i}/", array());
}
do_action('shutdown');
fc_check('RUCSS jobs → targeted purges, capped at 20, no purge-all', array($t->count('path'), $t->count('all')), array(20, 0));
