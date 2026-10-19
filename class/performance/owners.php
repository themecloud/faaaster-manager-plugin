<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which extension declares a shortcode, a block, a widget or an Elementor
 * widget, in the control plane's asset origin format: `plugin:<slug>`,
 * `mu-plugin:<slug or file>`, `theme:<slug>`, `wordpress`. Read by reflection
 * of the PHP file that declares the callback or the class; an entry whose
 * origin cannot be read is left out. Never fatal: every reflection is guarded.
 */
class FaaasterPerformanceOwners
{
    const ELEMENTOR_WIDGET = '/^[a-z0-9_-]{1,60}$/';

    /** directories => kind, the most specific first */
    private $roots = array();
    /** URL paths => kind, for block assets */
    private $url_roots = array();

    public function __construct()
    {
        $roots = array();
        if (defined('WPMU_PLUGIN_DIR')) {
            $roots[] = array(WPMU_PLUGIN_DIR, 'mu-plugin');
        }
        if (defined('WP_PLUGIN_DIR')) {
            $roots[] = array(WP_PLUGIN_DIR, 'plugin');
        }
        if (function_exists('get_theme_root')) {
            $roots[] = array(get_theme_root(), 'theme');
        }
        if (defined('ABSPATH')) {
            $roots[] = array(ABSPATH . (defined('WPINC') ? WPINC : 'wp-includes'), 'wordpress');
            $roots[] = array(ABSPATH . 'wp-admin', 'wordpress');
        }
        foreach ($roots as $root) {
            foreach (array_unique(array((string) $root[0], (string) realpath((string) $root[0]))) as $path) {
                $path = self::normalize($path);
                if ($path !== '') {
                    $this->roots[$path] = $root[1];
                }
            }
        }
        uksort($this->roots, function ($left, $right) {
            return strlen($right) - strlen($left);
        });
        $urls = array(
            'mu-plugin' => defined('WPMU_PLUGIN_URL') ? WPMU_PLUGIN_URL : '/wp-content/mu-plugins',
            'plugin' => function_exists('plugins_url') ? plugins_url() : '/wp-content/plugins',
            'theme' => function_exists('get_theme_root_uri') ? get_theme_root_uri() : '/wp-content/themes',
        );
        foreach ($urls as $kind => $url) {
            $path = wp_parse_url((string) $url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $this->url_roots[rtrim($path, '/')] = $kind;
            }
        }
        $this->url_roots['/wp-includes'] = 'wordpress';
    }

    /**
     * Owners of the registered shortcodes, blocks, widgets and, when Elementor
     * is active, Elementor widgets; each map bounded like the registry lists.
     */
    public function registry($shortcodes, $blocks, $widgets, $elementor)
    {
        $owners = array('shortcodes' => array(), 'blocks' => array(), 'widgets' => array(), 'elementorWidgets' => array());
        foreach (array_slice((array) $shortcodes, 0, 300, true) as $tag => $callback) {
            $origin = $this->of_callable($callback);
            if ($origin !== null) {
                $owners['shortcodes'][(string) $tag] = $origin;
            }
        }
        foreach (array_slice((array) $blocks, 0, 600, true) as $name => $type) {
            $origin = $this->of_block((string) $name, $type);
            if ($origin !== null) {
                $owners['blocks'][(string) $name] = $origin;
            }
        }
        foreach (array_slice((array) $widgets, 0, 200, true) as $id_base => $widget) {
            $origin = $this->of_class($widget);
            if ($origin !== null) {
                $owners['widgets'][(string) $id_base] = $origin;
            }
        }
        if ($elementor) {
            $count = 0;
            foreach ($this->elementor_widget_types() as $name => $widget) {
                if ($count >= 200) {
                    break;
                }
                if (!preg_match(self::ELEMENTOR_WIDGET, (string) $name)) {
                    continue;
                }
                $origin = $this->of_class($widget);
                if ($origin !== null) {
                    $owners['elementorWidgets'][(string) $name] = $origin;
                    $count++;
                }
            }
        }
        return $owners;
    }

    /** Origin of a PHP file, or null outside the known directories. */
    public function of_file($file)
    {
        if (!is_string($file) || $file === '') {
            return null;
        }
        $file = self::normalize($file);
        foreach ($this->roots as $root => $kind) {
            if (strpos($file, $root . '/') !== 0) {
                continue;
            }
            if ($kind === 'wordpress') {
                return 'wordpress';
            }
            $relative = substr($file, strlen($root) + 1);
            $segment = explode('/', $relative, 2)[0];
            $nested = strpos($relative, '/') !== false;
            if ($segment === '' || ($kind === 'theme' && !$nested)) {
                return null;
            }
            if ($kind === 'plugin') {
                // a plugin of one file is known by its name, as in `plugins`
                return 'plugin:' . ($nested ? $segment : basename($segment, '.php'));
            }
            return $kind . ':' . $segment;
        }
        return null;
    }

    /** Origin of the file declaring a callback, or null. */
    public function of_callable($callback)
    {
        $reflection = self::callable_reflection($callback);
        if ($reflection === null) {
            return null;
        }
        try {
            return $this->of_file($reflection->getFileName());
        } catch (Throwable $error) {
            return null;
        }
    }

    /** Origin of the file declaring an object's class, or null. */
    public function of_class($object)
    {
        if (!is_object($object)) {
            return null;
        }
        try {
            return $this->of_file((new ReflectionClass($object))->getFileName());
        } catch (Throwable $error) {
            return null;
        }
    }

    /**
     * A block: core/ is WordPress; else its render callback, else the files
     * of its scripts and styles. block.json `render` templates are wrapped by
     * WordPress in a closure of its own: the template's path, bound to it,
     * names the extension.
     */
    private function of_block($name, $type)
    {
        if (strpos($name, 'core/') === 0) {
            return 'wordpress';
        }
        if (!is_object($type)) {
            return null;
        }
        $vars = get_object_vars($type);
        $reflection = isset($vars['render_callback']) ? self::callable_reflection($vars['render_callback']) : null;
        if ($reflection !== null) {
            try {
                $origin = $this->of_file($reflection->getFileName());
                if ($origin !== null && $origin !== 'wordpress') {
                    return $origin;
                }
                if ($reflection instanceof ReflectionFunction) {
                    foreach ($reflection->getStaticVariables() as $value) {
                        $origin = is_string($value) ? $this->of_file($value) : null;
                        if ($origin !== null && $origin !== 'wordpress') {
                            return $origin;
                        }
                    }
                }
            } catch (Throwable $error) {
                // the assets may still tell
            }
        }
        $handles = array();
        foreach (array('editor_script_handles', 'script_handles', 'view_script_handles', 'editor_style_handles', 'style_handles', 'view_style_handles', 'editor_script', 'script', 'view_script', 'editor_style', 'style') as $property) {
            if (isset($vars[$property])) {
                foreach ((array) $vars[$property] as $handle) {
                    if (is_string($handle) && $handle !== '') {
                        $handles[$handle] = true;
                    }
                }
            }
        }
        if (!$handles) {
            return null;
        }
        $registries = array(
            function_exists('wp_scripts') ? wp_scripts() : null,
            function_exists('wp_styles') ? wp_styles() : null,
        );
        foreach (array_keys($handles) as $handle) {
            foreach ($registries as $registry) {
                if (is_object($registry) && isset($registry->registered[$handle]->src) && is_string($registry->registered[$handle]->src)) {
                    $origin = $this->of_url($registry->registered[$handle]->src);
                    if ($origin !== null && $origin !== 'wordpress') {
                        return $origin;
                    }
                }
            }
        }
        return null;
    }

    /** Origin of an asset URL: /plugins/<slug>/, /themes/<slug>/… */
    private function of_url($src)
    {
        $path = wp_parse_url($src, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        foreach ($this->url_roots as $root => $kind) {
            if (strpos($path, $root . '/') !== 0) {
                continue;
            }
            if ($kind === 'wordpress') {
                return 'wordpress';
            }
            $parts = explode('/', substr($path, strlen($root) + 1), 2);
            return count($parts) === 2 && $parts[0] !== '' ? $kind . ':' . $parts[0] : null;
        }
        return null;
    }

    /** Elementor's widget objects by name; empty on any failure. */
    private function elementor_widget_types()
    {
        if (!class_exists('\Elementor\Plugin')) {
            return array();
        }
        try {
            $plugin = \Elementor\Plugin::instance();
            $manager = is_object($plugin) && isset($plugin->widgets_manager) ? $plugin->widgets_manager : null;
            $types = is_object($manager) && method_exists($manager, 'get_widget_types') ? $manager->get_widget_types() : array();
            return is_array($types) ? $types : array();
        } catch (Throwable $error) {
            error_log('[faaaster-performance] registry: Elementor widgets unreadable: ' . $error->getMessage());
            return array();
        }
    }

    /** The reflection of a callback's function or method, or null. */
    private static function callable_reflection($callback)
    {
        try {
            if ($callback instanceof Closure) {
                return new ReflectionFunction($callback);
            }
            if (is_string($callback)) {
                if (strpos($callback, '::') !== false) {
                    list($class, $method) = explode('::', $callback, 2);
                    return new ReflectionMethod($class, $method);
                }
                return function_exists($callback) ? new ReflectionFunction($callback) : null;
            }
            if (is_array($callback) && count($callback) === 2 && isset($callback[0], $callback[1]) && is_string($callback[1])) {
                if (!is_object($callback[0]) && !is_string($callback[0])) {
                    return null;
                }
                return new ReflectionMethod($callback[0], $callback[1]);
            }
            if (is_object($callback) && method_exists($callback, '__invoke')) {
                return new ReflectionMethod($callback, '__invoke');
            }
        } catch (Throwable $error) {
            return null;
        }
        return null;
    }

    private static function normalize($path)
    {
        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }
}
