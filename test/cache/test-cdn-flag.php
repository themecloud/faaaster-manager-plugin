<?php

/**
 * CFCACHE_ENABLED lu à chaud (env php-fpm) avant la constante du démarrage :
 * la plateforme le bascule sans redémarrer le pod (25/11/2026).
 */
fc_section('CFCACHE_ENABLED effectif');

$fc_cdn_server_before = isset($_SERVER['CFCACHE_ENABLED']) ? $_SERVER['CFCACHE_ENABLED'] : null;
$fc_cdn_env_before = getenv('CFCACHE_ENABLED');

$fc_cdn_set = function ($server, $env) {
    if ($server === null) {
        unset($_SERVER['CFCACHE_ENABLED']);
    } else {
        $_SERVER['CFCACHE_ENABLED'] = $server;
    }
    putenv($env === null ? 'CFCACHE_ENABLED' : 'CFCACHE_ENABLED=' . $env);
};

$fc_cdn_set(null, null);
fc_check('sans valeur à chaud ni constante : off', FaaasterCloudflare::hotEnabled(), 'false');

$fc_cdn_set(null, 'true');
fc_check('env php-fpm à true : on', FaaasterCloudflare::hotEnabled(), 'true');

$fc_cdn_set('false', 'true');
fc_check('$_SERVER prime sur getenv', FaaasterCloudflare::hotEnabled(), 'false');

$fc_cdn_set(null, 'n/a');
fc_check('valeur illisible : repli (pas de constante) = off', FaaasterCloudflare::hotEnabled(), 'false');

if (!defined('CFCACHE_ENABLED')) {
    define('CFCACHE_ENABLED', 'true');
}
$fc_cdn_set(null, null);
fc_check('sans valeur à chaud : constante du démarrage', FaaasterCloudflare::hotEnabled(), 'true');

$fc_cdn_set('false', null);
fc_check('la valeur à chaud prime sur la constante', FaaasterCloudflare::hotEnabled(), 'false');

$fc_cdn_set($fc_cdn_server_before, $fc_cdn_env_before === false ? null : $fc_cdn_env_before);
