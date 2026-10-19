<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/components.php';
require_once __DIR__ . '/fonts.php';
require_once __DIR__ . '/owners.php';
require_once __DIR__ . '/conditionals.php';
require_once __DIR__ . '/runtime.php';
require_once __DIR__ . '/rest.php';

function faaaster_performance_optimizer_boot()
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;
    $settings = new FaaasterPerformanceSettings();
    $runtime = new FaaasterPerformanceRuntime($settings);
    $rest = new FaaasterPerformanceRest($settings);
    $runtime->register();
    add_action('rest_api_init', array($rest, 'register_routes'));
}

faaaster_performance_optimizer_boot();
