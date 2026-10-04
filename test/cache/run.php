<?php

/**
 * Tests du module cache, sans WordPress : php test/cache/run.php
 * Code de sortie non nul en cas d'échec.
 */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
// Les error_log('[faaaster-cache] …') attendus des tests d'échec restent muets
// (FC_VERBOSE=1 pour les voir).
if (!getenv('FC_VERBOSE')) {
    ini_set('error_log', '/dev/null');
}

$GLOBALS['fc_failures'] = 0;
$GLOBALS['fc_passes'] = 0;

function fc_check($label, $actual, $expected)
{
    if ($actual !== $expected) {
        $GLOBALS['fc_failures']++;
        fwrite(STDERR, "FAIL {$label}: got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . "\n");
        return;
    }
    $GLOBALS['fc_passes']++;
}

function fc_section($name)
{
    echo "== {$name}\n";
}

require_once __DIR__ . '/stubs/hooks.php';
require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/wp-content.php';
require_once __DIR__ . '/stubs/wp-admin.php';
require_once __DIR__ . '/../../class/hostmanager-auth.php';
require_once __DIR__ . '/../../class/cache/bootstrap.php';
require_once __DIR__ . '/../../class/cloudflare-manager.php';
require_once __DIR__ . '/../../class/wp-rocket-policy.php';
require_once __DIR__ . '/stubs/fakes.php';

foreach (glob(__DIR__ . '/test-*.php') as $suite) {
    require $suite;
}

echo "\n{$GLOBALS['fc_passes']} OK, {$GLOBALS['fc_failures']} FAIL\n";
exit($GLOBALS['fc_failures'] ? 1 : 0);
