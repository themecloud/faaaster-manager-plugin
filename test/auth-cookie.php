<?php

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

exit($failures === 0 ? 0 : 1);
