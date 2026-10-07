<?php

// Headers of the userinfo call: the pod proves which site is asking
// (X-Faaaster-App / X-Faaaster-Branch / X-Faaaster-Proof = HMAC-SHA256 of the
// jwt with the site's WP_API_KEY). Run: php test/sso-userinfo-headers.php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/stubs/');
}
if (!defined('FAAASTER_API_BASE')) {
    define('FAAASTER_API_BASE', 'https://app.faaaster.io');
}
if (!defined('FAAASTER_MANAGER_VERSION')) {
    define('FAAASTER_MANAGER_VERSION', 'test');
}

require_once __DIR__ . '/../class/loginSSO.php';

$failures = 0;
function check_sso($label, $actual, $expected)
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n");
        return;
    }
    echo "OK {$label}\n";
}

$jwt = 'a.b.c';

// Same fixture as Next (__tests__/lib/sso-auth.ts): HMAC-SHA256("unit-test-key", "a.b.c").
$expectedProof = 'e42e1821f1569132443bcf76b5be37be6eb75d2ef6693e751eb973c33b27e70d';
check_sso('cross-repo fixture', hash_hmac('sha256', $jwt, 'unit-test-key'), $expectedProof);

$headers = LoginSSO::userinfoHeaders($jwt, 'app-a', 'default', 'unit-test-key');
check_sso('bearer first', $headers[0], 'Authorization: Bearer a.b.c');
check_sso('names the application', in_array('X-Faaaster-App: app-a', $headers, true), true);
check_sso('names the branch', in_array('X-Faaaster-Branch: default', $headers, true), true);
check_sso('proof = HMAC of the jwt with the site key', in_array('X-Faaaster-Proof: ' . $expectedProof, $headers, true), true);
check_sso('the key itself is never sent', strpos(implode("\n", $headers), 'unit-test-key'), false);

// A legacy opaque token (no dots) goes to Symfony: no proof.
$legacy = LoginSSO::userinfoHeaders('opaque-v0-token', 'app-a', 'default', 'unit-test-key');
check_sso('no proof for a legacy token', count($legacy), 3);

// A pod without identity constants sends no proof rather than a wrong one.
$bare = LoginSSO::userinfoHeaders($jwt, '', 'default', 'unit-test-key');
check_sso('no proof without APP_ID', count($bare), 3);
$noKey = LoginSSO::userinfoHeaders($jwt, 'app-a', 'default', '');
check_sso('no proof without WP_API_KEY', count($noKey), 3);

if ($failures) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all green\n";
