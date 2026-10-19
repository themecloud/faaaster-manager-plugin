<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Names of the components a content uses: blocks, shortcodes, Elementor
 * widgets. Shared by the content conditions of the runtime, the audit
 * snapshot and the inventory scan, so a component the audit reports on a page
 * is a component the condition sees there.
 */
class FaaasterPerformanceComponents
{
    const SHORTCODE = '/\[([A-Za-z0-9_-]{1,60})(?![A-Za-z0-9_-])/';
    const ELEMENTOR_WIDGET = '/"widgetType"\s*:\s*"([a-z0-9_-]{1,60})"/';

    /**
     * Every block a serialized content opens, as has_block() matches it: a
     * name without namespace is a core block. One pass over the content.
     */
    public static function serialized_blocks($content)
    {
        if (!is_string($content) || strpos($content, '<!-- wp:') === false) {
            return array();
        }
        if (!preg_match_all('/<!-- wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?) /', $content, $matches)) {
            return array();
        }
        $names = array();
        foreach ($matches[1] as $name) {
            $names[strpos($name, '/') === false ? 'core/' . $name : $name] = true;
        }
        return $names;
    }

    /** Unique block names of a content, inner blocks included (parse_blocks). */
    public static function parsed_blocks($content, $max)
    {
        if (!is_string($content) || strpos($content, '<!-- wp:') === false || !function_exists('parse_blocks')) {
            return array();
        }
        $names = array();
        self::collect_blocks((array) parse_blocks($content), $names, $max);
        return array_keys($names);
    }

    private static function collect_blocks(array $blocks, array &$names, $max)
    {
        foreach ($blocks as $block) {
            if (count($names) >= $max) {
                return;
            }
            if (!empty($block['blockName']) && is_string($block['blockName'])) {
                $names[$block['blockName']] = true;
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collect_blocks($block['innerBlocks'], $names, $max);
            }
        }
    }

    /**
     * Registered shortcode tags written in a content, nested ones included.
     * An opening `[tag` is enough: a false positive keeps a rule withheld,
     * never applied where the component is.
     *
     * @param array $registered tag => true
     */
    public static function shortcodes($content, array $registered)
    {
        if (!$registered || !is_string($content) || strpos($content, '[') === false) {
            return array();
        }
        if (!preg_match_all(self::SHORTCODE, $content, $matches)) {
            return array();
        }
        $tags = array();
        foreach ($matches[1] as $tag) {
            if (isset($registered[$tag])) {
                $tags[$tag] = true;
            }
        }
        return $tags;
    }

    /** The tags WordPress registers, as a set. */
    public static function registered_shortcodes()
    {
        $tags = isset($GLOBALS['shortcode_tags']) && is_array($GLOBALS['shortcode_tags'])
            ? array_keys($GLOBALS['shortcode_tags'])
            : array();
        return $tags ? array_fill_keys(array_map('strval', $tags), true) : array();
    }

    /** Elementor widget types of a page's `_elementor_data`, as a set. */
    public static function elementor_widgets($data)
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        if (!is_string($data) || strpos($data, 'widgetType') === false) {
            return array();
        }
        if (!preg_match_all(self::ELEMENTOR_WIDGET, $data, $matches)) {
            return array();
        }
        return array_fill_keys($matches[1], true);
    }
}
