<?php

// site_state tells a failed WordPress.org check from "no update": a failed
// call leaves the transient WordPress marked before calling, which holds no
// answer. Run: php test/site-state-update-checks.php

require_once __DIR__ . '/../class/site-state.php';

$failures = 0;
$check = function ($label, $actual, $expected) use (&$failures) {
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: {$actual}, expected {$expected}\n");
    }
};

// What wp_update_plugins / wp_update_themes leave behind.
$marker = new stdClass();
$marker->last_checked = time();
$answered = new stdClass();
$answered->last_checked = time();
$answered->checked = array('twentytwentythree' => '1.6');
$answered->response = array();
$no_plugin = new stdClass();
$no_plugin->last_checked = time();
$no_plugin->checked = array();

$check('transient deleted', SiteState::update_check_state(false, 'checked'), 'failed');
$check('marker only', SiteState::update_check_state($marker, 'checked'), 'failed');
$check('answered, nothing to update', SiteState::update_check_state($answered, 'checked'), 'ok');
$check('answered, no plugin installed', SiteState::update_check_state($no_plugin, 'checked'), 'ok');

// What wp_version_check leaves behind.
$core_marker = new stdClass();
$core_marker->updates = array();
$core_marker->version_checked = '7.1.4';
$core_marker->last_checked = time();
$core_answered = clone $core_marker;
$core_answered->updates = array((object) array('response' => 'latest', 'current' => '7.1.4'));

$check('core marker only', SiteState::update_check_state($core_marker, 'updates'), 'failed');
$check('core answered', SiteState::update_check_state($core_answered, 'updates'), 'ok');

if ($failures) {
    exit(1);
}
echo "OK site_state tells a failed update check from no update\n";
