<?php

/**
 * API de hooks minimale, à la forme de WP_Hook ($wp_filter[$hook]->callbacks
 * [$priority][$id] = ['function' => ..., 'accepted_args' => n]) pour que le code
 * de reprise en main du fork tourne à l'identique.
 */
class Faaaster_Test_Hook
{
    public $callbacks = array();
}

$GLOBALS['wp_filter'] = array();
$GLOBALS['wp_actions'] = array();
$GLOBALS['wp_current_filter'] = array();

function _faaaster_test_hook_id($callback)
{
    if (is_string($callback)) {
        return $callback;
    }
    if (is_object($callback)) {
        return spl_object_hash($callback);
    }
    if (is_array($callback)) {
        return (is_object($callback[0]) ? spl_object_hash($callback[0]) : $callback[0]) . '::' . $callback[1];
    }
    return md5(serialize($callback));
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    if (!isset($GLOBALS['wp_filter'][$hook])) {
        $GLOBALS['wp_filter'][$hook] = new Faaaster_Test_Hook();
    }
    $GLOBALS['wp_filter'][$hook]->callbacks[$priority][_faaaster_test_hook_id($callback)] = array(
        'function' => $callback,
        'accepted_args' => $accepted_args,
    );
    ksort($GLOBALS['wp_filter'][$hook]->callbacks);
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function remove_filter($hook, $callback, $priority = 10)
{
    $id = _faaaster_test_hook_id($callback);
    if (isset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][$id])) {
        unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][$id]);
        if (empty($GLOBALS['wp_filter'][$hook]->callbacks[$priority])) {
            unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority]);
        }
        return true;
    }
    return false;
}

function remove_action($hook, $callback, $priority = 10)
{
    return remove_filter($hook, $callback, $priority);
}

function has_filter($hook, $callback = false)
{
    if (!isset($GLOBALS['wp_filter'][$hook])) {
        return false;
    }
    if ($callback === false) {
        return !empty($GLOBALS['wp_filter'][$hook]->callbacks);
    }
    $id = _faaaster_test_hook_id($callback);
    foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $items) {
        if (isset($items[$id])) {
            return $priority;
        }
    }
    return false;
}

function has_action($hook, $callback = false)
{
    return has_filter($hook, $callback);
}

function apply_filters($hook, $value)
{
    $args = func_get_args();
    array_shift($args);
    $GLOBALS['wp_current_filter'][] = $hook;
    if (isset($GLOBALS['wp_filter'][$hook])) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $items) {
            foreach ($items as $item) {
                $args[0] = $value;
                $value = call_user_func_array($item['function'], array_slice($args, 0, $item['accepted_args']));
            }
        }
    }
    array_pop($GLOBALS['wp_current_filter']);
    return $value;
}

function do_action($hook)
{
    $args = func_get_args();
    array_shift($args);
    $GLOBALS['wp_actions'][$hook] = isset($GLOBALS['wp_actions'][$hook]) ? $GLOBALS['wp_actions'][$hook] + 1 : 1;
    $GLOBALS['wp_current_filter'][] = $hook;
    if (isset($GLOBALS['wp_filter'][$hook])) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $items) {
            foreach ($items as $item) {
                call_user_func_array($item['function'], array_slice($args, 0, $item['accepted_args']));
            }
        }
    }
    array_pop($GLOBALS['wp_current_filter']);
}

function did_action($hook)
{
    return isset($GLOBALS['wp_actions'][$hook]) ? $GLOBALS['wp_actions'][$hook] : 0;
}

function current_filter()
{
    return end($GLOBALS['wp_current_filter']);
}

function faaaster_test_reset_hooks()
{
    $GLOBALS['wp_filter'] = array();
    $GLOBALS['wp_actions'] = array();
    $GLOBALS['wp_current_filter'] = array();
}
