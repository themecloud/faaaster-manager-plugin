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
require_once __DIR__ . '/takeover.php';
require_once __DIR__ . '/cascade.php';
require_once __DIR__ . '/content-listener.php';
require_once __DIR__ . '/global-triggers.php';
require_once __DIR__ . '/integrations/integration.php';
require_once __DIR__ . '/integrations/builders.php';
require_once __DIR__ . '/integrations/plugins.php';
require_once __DIR__ . '/integrations/registry.php';
require_once __DIR__ . '/nginx-conf.php';
require_once __DIR__ . '/ttl-rules.php';
require_once __DIR__ . '/ttl-emitter.php';
require_once __DIR__ . '/admin/ds-icons.php';
require_once __DIR__ . '/admin/views.php';
require_once __DIR__ . '/admin/admin-bar.php';
require_once __DIR__ . '/admin/admin.php';
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
 * Résumé pour site_state (other_data.cache), lu par Next et le support. Aucun
 * secret ; module désactivé : rien n'est lu (pas d'initialisation des réglages).
 */
function faaaster_cache_state()
{
    $cache = faaaster_cache();
    $state = array(
        'module' => $cache ? 'active' : 'disabled',
        'takeover' => $cache ? (bool) $cache->context('takeover') : false,
        'ttl_rules' => 0,
        'purge_rules_customized' => false,
    );
    if ($cache) {
        $defaults = FaaasterCacheSettings::defaults();
        $state['ttl_rules'] = count((array) $cache->settings()->get('ttl', 'rules', array()));
        $state['purge_rules_customized'] = $cache->settings()->get('purge') != $defaults['purge'];
    }
    return $state;
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
