<?php

/**
 * Chargement du module cache de pages (voir docs/cache/README.md).
 */
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/transport.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/purge-queue.php';
require_once __DIR__ . '/hostmanager.php';
require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/cli.php';
require_once __DIR__ . '/cache.php';

/** @return FaaasterCache|null null si le module est désactivé ou n'a pas démarré */
function faaaster_cache()
{
    return FaaasterCache::instance();
}

/**
 * Même critère que le mu-plugin (sous-chaîne « hostmanager » dans l'URI) : sur
 * ces requêtes, les extensions et le thème ne sont pas chargés.
 */
function faaaster_is_hostmanager_request()
{
    return isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'hostmanager') !== false;
}

/**
 * Démarre le module. Kill switch : FAAASTER_CACHE_MODULE_DISABLED dans wp-config.php.
 */
function faaaster_cache_boot(array $args)
{
    if (defined('FAAASTER_CACHE_MODULE_DISABLED') && constant('FAAASTER_CACHE_MODULE_DISABLED')) {
        return null;
    }
    try {
        return FaaasterCache::boot($args);
    } catch (\Throwable $e) {
        FaaasterCache::log_exception('boot', $e);
        return null;
    }
}
