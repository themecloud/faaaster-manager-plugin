<?php

fc_section('static');

$files = array_merge(
    glob(__DIR__ . '/../../class/cache/*.php'),
    glob(__DIR__ . '/../../class/cache/*/*.php') ?: array()
);

foreach ($files as $file) {
    $code = file_get_contents($file);
    $name = basename(dirname($file)) . '/' . basename($file);
    // PHP 7.4 : pas de syntaxe ni de fonctions PHP 8 uniquement.
    fc_check("{$name}: no PHP 8-only functions", preg_match('/\b(str_contains|str_starts_with|str_ends_with|array_is_list)\s*\(/', $code), 0);
    fc_check("{$name}: no match expression", preg_match('/\bmatch\s*\(/', $code), 0);
    fc_check("{$name}: no nullsafe operator", strpos($code, '?->'), false);
    // Les caches de code ne se vident que sur la purge complète de la plateforme.
    if (basename($file) !== 'hostmanager.php') {
        fc_check("{$name}: no opcache_reset", preg_match('/opcache_reset\s*\(/', $code), 0);
        fc_check("{$name}: no wp_cache_flush", preg_match('/wp_cache_flush\s*\(/', $code), 0);
    }
}
