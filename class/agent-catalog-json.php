<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JSON-safe projection of a value destined to a cached discovery catalogue.
 *
 * The REST route index copies each endpoint's arg schema as plugins declared it,
 * and a plugin may put a PHP callable where JSON Schema expects data — vécu le
 * 07/10/2026 (inst133260) : Elementor Pro 4.3.1 declares
 * `mentioned_usernames.items.sanitize_callback` as a Closure on its
 * `/elementor/v1/notes` routes. `update_option()` serializes the catalogue and
 * PHP refuses to serialize a Closure: fatal, nginx 502, the agent's enable call
 * fails after the read user was created.
 *
 * Kept: null, bool, int, float, string, and arrays of kept values (recursively).
 * Dropped: closures, objects, resources, and anything nested deeper than
 * FAAASTER_AGENT_JSON_MAX_DEPTH. A dropped array entry disappears from its
 * parent; a dropped root yields null.
 */
const FAAASTER_AGENT_JSON_MAX_DEPTH = 16;

/** Sentinel returned by faaaster_agent_json_safe_inner() for a dropped value. */
const FAAASTER_AGENT_JSON_DROP = "\0faaaster-agent-json-drop\0";

function faaaster_agent_json_safe($value)
{
    $safe = faaaster_agent_json_safe_inner($value, 0);
    return $safe === FAAASTER_AGENT_JSON_DROP ? null : $safe;
}

function faaaster_agent_json_safe_inner($value, $depth)
{
    if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
        return $value;
    }
    if (is_float($value)) {
        // NAN / INF are not JSON either (wp_json_encode fails on them).
        return is_finite($value) ? $value : FAAASTER_AGENT_JSON_DROP;
    }
    if (!is_array($value)) {
        // Closure, object, resource: never data.
        return FAAASTER_AGENT_JSON_DROP;
    }
    if ($depth >= FAAASTER_AGENT_JSON_MAX_DEPTH) {
        return FAAASTER_AGENT_JSON_DROP;
    }
    $out = array();
    foreach ($value as $key => $item) {
        $safe = faaaster_agent_json_safe_inner($item, $depth + 1);
        if ($safe === FAAASTER_AGENT_JSON_DROP) {
            continue;
        }
        $out[$key] = $safe;
    }
    return $out;
}

/**
 * Run one catalogue builder without letting its failure escape as a fatal.
 * Returns array('catalog' => array|null, 'error' => string|null). A failure
 * leaves the previously cached catalogue untouched (the builder writes last),
 * and is logged so the pod's error log names the offending plugin frame.
 */
function faaaster_agent_try_build_catalog($label, $builder)
{
    try {
        return array('catalog' => call_user_func($builder), 'error' => null);
    } catch (Throwable $e) {
        $message = $label . ' catalogue not rebuilt: ' . get_class($e) . ': ' . $e->getMessage();
        error_log('[faaaster-agent] ' . $message . ' at ' . $e->getFile() . ':' . $e->getLine());
        return array('catalog' => null, 'error' => $message);
    }
}
