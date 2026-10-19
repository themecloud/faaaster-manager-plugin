<?php

if (!defined('ABSPATH')) {
    exit;
}

/** A `when` condition that cannot be evaluated: the rule does not apply. */
class FaaasterPerformanceConditionError extends RuntimeException
{
    public $name;

    public function __construct($name, $reason)
    {
        $this->name = (string) $name;
        parent::__construct('conditional ' . $this->name . ': ' . $reason);
    }
}

/**
 * The site's own conditional tags a `when` condition may call: user functions
 * `is_*` declared by an active plugin or the active theme (parent or child),
 * without required parameter. Discovered by reflection at the inventory and
 * when a rule set is applied or previewed, kept in a non-autoloaded option;
 * the runtime only calls a name of that list.
 */
class FaaasterPerformanceConditionals
{
    const OPTION = 'faaaster_performance_conditionals';
    const PATTERN = '/^is_[a-z0-9_]{1,60}$/';
    const MAX = 60;

    /** name => origin, read once per request */
    private static $allowed = null;

    /** @return array[] [{name, origin}], sorted by name */
    public static function discover()
    {
        $functions = function_exists('get_defined_functions') ? get_defined_functions() : array();
        $names = preg_grep(self::PATTERN, isset($functions['user']) ? (array) $functions['user'] : array());
        if (!$names) {
            return array();
        }
        sort($names, SORT_STRING);
        $active = self::active_origins();
        $owners = new FaaasterPerformanceOwners();
        $found = array();
        foreach ($names as $name) {
            try {
                $reflection = new ReflectionFunction($name);
                if ($reflection->isInternal() || $reflection->getNumberOfRequiredParameters() !== 0) {
                    continue;
                }
                $origin = $owners->of_file($reflection->getFileName());
            } catch (Throwable $error) {
                continue;
            }
            if ($origin === null || !isset($active[$origin])) {
                continue;
            }
            $found[] = array('name' => (string) $name, 'origin' => $origin);
            if (count($found) >= self::MAX) {
                break;
            }
        }
        return $found;
    }

    /** Discovered again and stored (inventory, apply, preview). */
    public static function refresh()
    {
        $list = self::discover();
        update_option(self::OPTION, $list, false);
        self::$allowed = self::index($list);
        return $list;
    }

    /** name => origin; discovered on first use when never stored. */
    public static function allowed()
    {
        if (self::$allowed === null) {
            $stored = get_option(self::OPTION, null);
            if (is_array($stored)) {
                self::$allowed = self::index($stored);
            } else {
                self::refresh();
            }
        }
        return self::$allowed;
    }

    /** The stored list, as the inventory reports it. */
    public static function listed()
    {
        $list = array();
        foreach (self::allowed() as $name => $origin) {
            $list[] = array('name' => $name, 'origin' => $origin);
        }
        return $list;
    }

    /** Forget the request's copy (tests, a new request). */
    public static function reset()
    {
        self::$allowed = null;
    }

    private static function index(array $list)
    {
        $allowed = array();
        foreach ($list as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            if (preg_match(self::PATTERN, $name) && count($allowed) < self::MAX) {
                $allowed[$name] = (string) ($entry['origin'] ?? '');
            }
        }
        return $allowed;
    }

    /** Origins of the active plugins (network ones included) and themes. */
    private static function active_origins()
    {
        $plugins = (array) get_option('active_plugins', array());
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $origins = array();
        foreach ($plugins as $plugin) {
            $plugin = (string) $plugin;
            $slug = strpos($plugin, '/') !== false ? dirname($plugin) : basename($plugin, '.php');
            if ($slug !== '' && $slug !== '.') {
                $origins['plugin:' . $slug] = true;
            }
        }
        foreach (array('get_stylesheet', 'get_template') as $theme) {
            if (function_exists($theme) && (string) $theme() !== '') {
                $origins['theme:' . $theme()] = true;
            }
        }
        return $origins;
    }
}
