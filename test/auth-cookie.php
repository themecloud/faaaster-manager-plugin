<?php

// Keep output buffered so refresh() can emit real cookies in CLI tests.
ob_start();

require_once __DIR__ . '/../class/auth-cookie.php';

$failures = 0;
function check_auth_cookie($label, $actual, $expected)
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: got [{$actual}], expected [{$expected}]\n");
        return;
    }
    echo "OK {$label}\n";
}

$token = FaaasterAuthCookieManager::buildToken(
    'unit-test-key',
    42,
    'editor',
    1700000300
);
check_auth_cookie(
    'v1 contract and SHA-256 fixture',
    $token,
    'v1.42.editor.1700000300.5d55e23683b2f7fd6a9434d9cd7ce44e7c0991501b0ca56358d615405d6fbfd6'
);

$admin = FaaasterAuthCookieManager::buildToken(
    'unit-test-key',
    7,
    'admin',
    1700000300
);
check_auth_cookie('admin level carried', explode('.', $admin)[2], 'admin');
check_auth_cookie('MAC is 64 lowercase hex chars', preg_match('/^[a-f0-9]{64}$/', explode('.', $admin)[4]), 1);

$hooks = array();
$loggedIn = true;
$canEdit = true;
$userReads = 0;
define('WP_API_KEY', 'unit-test-key');
function add_action($hook, $callback) { global $hooks; $hooks[$hook] = $callback; }
function add_filter($hook, $callback) { add_action($hook, $callback); }
function is_user_logged_in() { global $loggedIn; return $loggedIn; }
function current_user_can($capability) { global $canEdit; return $canEdit; }
function get_current_user_id() { global $userReads; $userReads++; return 42; }
function is_wp_error($response) { return $response instanceof Exception; }

$manager = new FaaasterAuthCookieManager();
$manager->init();
foreach (array('send_headers', 'admin_init') as $hook) {
    $before = $userReads;
    call_user_func($hooks[$hook]);
    check_auth_cookie("{$hook} renews authenticated editor proof", $userReads, $before + 1);
}
// admin_init is shared by wp-admin pages and admin-ajax.php (Heartbeat).
$loggedIn = false;
$before = $userReads;
call_user_func($hooks['admin_init']);
check_auth_cookie('anonymous AJAX does not issue proof', $userReads, $before);
$loggedIn = true;
$canEdit = false;
call_user_func($hooks['admin_init']);
check_auth_cookie('subscriber does not receive editor proof', $userReads, $before);
$canEdit = true;

foreach (array(200, 201, 401, 403, 500) as $status) {
    $response = new class($status) {
        private $status;
        public function __construct($status) { $this->status = $status; }
        public function get_status() { return $this->status; }
    };
    $before = $userReads;
    $returned = call_user_func($hooks['rest_post_dispatch'], $response);
    check_auth_cookie("REST {$status} preserves response", $returned === $response, true);
    check_auth_cookie("REST {$status} proof eligibility", $userReads, $before + ($status < 400 ? 1 : 0));
}
$error = new Exception('rejected authentication');
$before = $userReads;
check_auth_cookie('REST error preserved', call_user_func($hooks['rest_post_dispatch'], $error) === $error, true);
check_auth_cookie('REST error does not issue proof', $userReads, $before);
$loggedIn = false;
$response = new class { public function get_status() { return 200; } };
call_user_func($hooks['rest_post_dispatch'], $response);
check_auth_cookie('public REST success does not issue proof', $userReads, $before);

ob_end_flush();
exit($failures === 0 ? 0 : 1);
