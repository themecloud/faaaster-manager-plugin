<?php

// JSON-safe projection of the agent discovery catalogues: a plugin-authored
// schema carrying a Closure (Elementor Pro 4.3.1 Notes, inst133260 07/10/2026)
// must not reach update_option(). Run: php test/agent-catalog-json-safe.php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/stubs/');
}
ini_set('error_log', '/dev/null'); // faaaster_agent_try_build_catalog logs its failures

require_once __DIR__ . '/../class/agent-catalog-json.php';

$failures = 0;
function check_json($label, $actual, $expected)
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n");
        return;
    }
    echo "OK {$label}\n";
}

// The exact shape Elementor Pro registers on /elementor/v1/notes.
$elementorArg = array(
    'type'        => 'array',
    'description' => "List of user names that have been mentioned in the note's content.",
    'default'     => array(),
    'items'       => array(
        'type'              => 'string',
        'sanitize_callback' => function ($value) {
            return $value;
        },
    ),
);
$safe = faaaster_agent_json_safe($elementorArg);
check_json('closure dropped from items', $safe['items'], array('type' => 'string'));
check_json('siblings kept', array_keys($safe), array('type', 'description', 'default', 'items'));
check_json('result serializes', is_string(serialize($safe)), true);

// Scalars and nesting survive untouched, keys included.
$nested = array('a' => 1, 'b' => 1.5, 'c' => 'x', 'd' => null, 'e' => false, 'f' => array(1, 2, array('g' => 'h')));
check_json('scalars and nesting kept', faaaster_agent_json_safe($nested), $nested);
check_json('list keys kept', faaaster_agent_json_safe(array('on', 'off')), array('on', 'off'));

// Non-data values disappear wherever they sit.
$mixed = array('enum' => array('a', new stdClass(), 'b'), 'cb' => 'strtolower', 'obj' => new ArrayObject(), 'nan' => NAN);
check_json('objects and NAN dropped, string callables kept as strings', faaaster_agent_json_safe($mixed), array('enum' => array(0 => 'a', 2 => 'b'), 'cb' => 'strtolower'));
check_json('root closure yields null', faaaster_agent_json_safe(function () {
}), null);
check_json('root scalar kept', faaaster_agent_json_safe('s'), 's');

// Depth cap: deeper than FAAASTER_AGENT_JSON_MAX_DEPTH is dropped, not recursed forever.
$deep = 'leaf';
for ($i = 0; $i < FAAASTER_AGENT_JSON_MAX_DEPTH + 2; $i++) {
    $deep = array('n' => $deep);
}
$safeDeep = faaaster_agent_json_safe($deep);
$cursor = $safeDeep;
$levels = 0;
while (is_array($cursor) && isset($cursor['n'])) {
    $cursor = $cursor['n'];
    $levels++;
}
check_json('depth capped', $levels < FAAASTER_AGENT_JSON_MAX_DEPTH + 2, true);

// Guarded builder: a throwing builder becomes a warning, a sound one its catalogue.
$failed = faaaster_agent_try_build_catalog('REST routes', function () {
    throw new Exception("Serialization of 'Closure' is not allowed");
});
check_json('failure caught', $failed['catalog'], null);
check_json('failure named', strpos($failed['error'], "REST routes catalogue not rebuilt: Exception: Serialization of 'Closure' is not allowed"), 0);
$ok = faaaster_agent_try_build_catalog('abilities', function () {
    return array('data' => array(1), 'builtAt' => 42);
});
check_json('success passes the catalogue', $ok, array('catalog' => array('data' => array(1), 'builtAt' => 42), 'error' => null));

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all green\n";
