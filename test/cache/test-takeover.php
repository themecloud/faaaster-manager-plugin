<?php

require_once __DIR__ . '/fixtures/fork-3.2.10.php';

fc_section('takeover (fork present)');

// Le fork se charge avant le manager-plugin (ordre alphabétique des mu-plugins).
faaaster_test_reset_hooks();
faaaster_test_reset_wp();
faaaster_test_reset_content();
FaaasterCache::reset();
FaaasterCacheTakeover::reset();
$fork_purger = faaaster_test_load_fork();
fc_check('fixture: fork hooks registered', faaaster_test_fork_callbacks(), 24);
fc_check('fork detected before boot', FaaasterCache::fork_present(), true);

$t = new FaaasterTestTransport();
$cf = new FaaasterTestCloudflare();
$cache = FaaasterCache::boot(array(
    'transport' => $t,
    'cloudflare' => $cf,
    'cf_enabled' => true,
    'site_provider' => function () {
        return array('home_url' => home_url('/'), 'hosts' => array('example.com'));
    },
));

fc_check('takeover: no fork callback left', faaaster_test_fork_callbacks(), 0);
fc_check('takeover: trace recorded', count(FaaasterCacheTakeover::removed()), 24);
fc_check('takeover: $nginx_helper removed (WP Rocket native integration off)', isset($GLOBALS['nginx_helper']), false);
fc_check('takeover: $nginx_purger is the shim', $GLOBALS['nginx_purger'] instanceof FaaasterNginxPurgerCompat, true);
fc_check('takeover: context flag', $cache->context('takeover'), true);
fc_check('fork no longer "present" (legacy Cloudflare wiring stays off)', FaaasterCache::fork_present(), false);

do_action('rt_nginx_helper_purge_all');
do_action('shutdown');
fc_check('rt_nginx_helper_purge_all routed to the module', $t->count('all'), 1);
fc_check('fork purger never called', $fork_purger->purged_all, 0);

// Une sauvegarde d'article : une seule purge par URL (plus de double purge du fork).
faaaster_test_reset_content();
$post = ft_post(array('ID' => 60, 'post_name' => 'once'));
$before = new WP_Post(array('ID' => 60, 'post_name' => 'once'));
do_action('transition_post_status', 'publish', 'publish', $post);
do_action('wp_after_insert_post', 60, $post, true, $before);
$calls_before = count($t->calls);
$cache->queue()->flush(false);
$paths = array();
foreach (array_slice($t->calls, $calls_before) as $call) {
    $paths[] = $call[2];
}
fc_check('single purge per URL after takeover', count($paths), count(array_unique($paths)));

// Callbacks natifs WP Rocket (si un Nginx Helper classique les a fait charger).
add_action('rocket_saas_complete_job_status', 'rocket_clean_nginx_helper_cache', 10, 0);
add_action('after_rocket_clean_home', 'rocket_clean_nginx_cache_home', 10, 2);
do_action('init');
fc_check('late: WP Rocket nginx-helper callbacks removed', array(
    has_action('rocket_saas_complete_job_status', 'rocket_clean_nginx_helper_cache'),
    has_action('after_rocket_clean_home', 'rocket_clean_nginx_cache_home'),
), array(false, false));

// Un Nginx Helper classique chargé après nous (plugins_loaded) : rebalayé sur init.
$late_admin = new Nginx_Helper_Admin();
add_action('admin_menu', array($late_admin, 'nginx_helper_admin_menu'));
$GLOBALS['nginx_helper'] = new Nginx_Helper();
FaaasterCacheTakeover::late();
fc_check('late: classic Nginx Helper swept again', faaaster_test_fork_callbacks(), 0);
fc_check('late: $nginx_helper removed again', isset($GLOBALS['nginx_helper']), false);
