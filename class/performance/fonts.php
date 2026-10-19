<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Font stylesheets served with the faces the pages render only. A face is
 * kept when its family (case-insensitive, without quotes), its style and its
 * weight (a value or a range, overlapping a kept one) match a kept face. A
 * face the parser cannot read is always kept. On any failure the stylesheet
 * is left untouched. Files are read and written by the REST handlers only.
 */
class FaaasterPerformanceFontFaces
{
    const MAX_FILE_BYTES = 2097152;
    const DIRECTORY = 'faaaster-performance/fonts';

    /**
     * @param array $keep compiled kept faces: family, weight [min, max], style
     * @return array|null css and removed count, null when the CSS cannot be read
     */
    public static function filter($css, array $keep)
    {
        if (!is_string($css)) {
            return null;
        }
        if (stripos($css, '@font-face') === false) {
            return array('css' => $css, 'removed' => 0);
        }
        // comments first: a face in a comment is no face, and a comment
        // naming @font-face must not start a block
        if (!preg_match_all('~/\*.*?\*/|@font-face\s*\{~is', $css, $matches, PREG_OFFSET_CAPTURE)) {
            return array('css' => $css, 'removed' => 0);
        }
        $out = '';
        $offset = 0;
        $removed = 0;
        foreach ($matches[0] as $match) {
            list($text, $start) = $match;
            if ($text[0] === '/' || $start < $offset) {
                continue;
            }
            $end = self::block_end($css, $start + strlen($text));
            if ($end === null) {
                return null;
            }
            $face = self::face_of(substr($css, $start, $end - $start));
            if ($face === null || self::kept($face, $keep)) {
                continue;
            }
            $out .= substr($css, $offset, $start - $offset);
            $offset = $end;
            $removed++;
        }
        return array('css' => $removed ? $out . substr($css, $offset) : $css, 'removed' => $removed);
    }

    /** Offset right after the brace closing the block opened before $from. */
    private static function block_end($css, $from)
    {
        $length = strlen($css);
        $depth = 1;
        for ($index = $from; $index < $length; $index++) {
            $char = $css[$index];
            if ($char === '"' || $char === "'") {
                for ($index++; $index < $length && $css[$index] !== $char; $index++) {
                    if ($css[$index] === '\\') {
                        $index++;
                    }
                }
            } elseif ($char === '/' && $index + 1 < $length && $css[$index + 1] === '*') {
                $close = strpos($css, '*/', $index + 2);
                if ($close === false) {
                    return null;
                }
                $index = $close + 1;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $index + 1;
                }
            }
        }
        return null;
    }

    /** family, weight [min, max] and style of a block, or null if unreadable. */
    private static function face_of($block)
    {
        $block = (string) preg_replace('~/\*.*?\*/~s', '', $block);
        if (!preg_match('/(?<![\w-])font-family\s*:\s*([^;}]+)/i', $block, $family)) {
            return null;
        }
        $family = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(array('"', "'"), '', $family[1]))));
        if ($family === '' || strpos($family, ',') !== false) {
            return null;
        }
        $weight = array(400, 400);
        if (preg_match('/(?<![\w-])font-weight\s*:\s*([^;}]+)/i', $block, $raw)) {
            $weight = self::weight_range($raw[1]);
            if ($weight === null) {
                return null;
            }
        }
        $style = 'normal';
        if (preg_match('/(?<![\w-])font-style\s*:\s*([^;}]+)/i', $block, $raw)) {
            $value = strtolower(trim($raw[1]));
            if (strpos($value, 'oblique') === 0) {
                $style = 'italic';
            } elseif ($value === 'italic' || $value === 'normal') {
                $style = $value;
            } else {
                return null;
            }
        }
        return array('family' => $family, 'weight' => $weight, 'style' => $style);
    }

    private static function weight_range($value)
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)));
        if (!$tokens || count($tokens) > 2) {
            return null;
        }
        $weights = array();
        foreach ($tokens as $token) {
            if ($token === 'normal') {
                $weights[] = 400;
            } elseif ($token === 'bold') {
                $weights[] = 700;
            } elseif (preg_match('/^\d{1,4}(\.\d+)?$/', $token)) {
                $weights[] = (int) round((float) $token);
            } else {
                return null;
            }
        }
        return array(min($weights), max($weights));
    }

    private static function kept(array $face, array $keep)
    {
        foreach ($keep as $kept) {
            if (
                $kept['family'] === $face['family']
                && $kept['style'] === $face['style']
                && max($kept['weight'][0], $face['weight'][0]) <= min($kept['weight'][1], $face['weight'][1])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Relative url() and @import of a stylesheet copied elsewhere, made
     * absolute against the original file's URL.
     */
    public static function absolute_urls($css, $base)
    {
        $parts = wp_parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $rewrite = function ($url) use ($parts) {
            return self::resolve($url, $parts);
        };
        $css = preg_replace_callback(
            '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'()]*))\s*\)/i',
            function ($match) use ($rewrite) {
                if (isset($match[3]) && $match[3] !== '') {
                    return 'url(' . $rewrite($match[3]) . ')';
                }
                if (isset($match[2]) && $match[2] !== '') {
                    return "url('" . $rewrite($match[2]) . "')";
                }
                return 'url("' . $rewrite($match[1]) . '")';
            },
            $css
        );
        if (!is_string($css)) {
            return null;
        }
        $css = preg_replace_callback(
            '/@import\s+(["\'])([^"\']*)\1/i',
            function ($match) use ($rewrite) {
                return '@import ' . $match[1] . $rewrite($match[2]) . $match[1];
            },
            $css
        );
        return is_string($css) ? $css : null;
    }

    /** A URL of the stylesheet resolved against its own location. */
    public static function resolve($url, array $base)
    {
        $url = trim((string) $url);
        if ($url === '' || $url[0] === '#' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return $base['scheme'] . ':' . $url;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        preg_match('/^([^?#]*)(.*)$/s', $url, $split);
        $path = $split[1];
        $suffix = $split[2];
        $base_path = isset($base['path']) && $base['path'] !== '' ? $base['path'] : '/';
        if ($path === '') {
            return $origin . $base_path . $suffix;
        }
        if ($path[0] !== '/') {
            $path = substr($base_path, 0, strrpos($base_path, '/') + 1) . $path;
        }
        $segments = array();
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($segments) > 1) {
                    array_pop($segments);
                }
                continue;
            }
            $segments[] = $segment;
        }
        return $origin . implode('/', $segments) . $suffix;
    }

    /**
     * Writes the filtered copy of each font_variants stylesheet and records
     * it in the compiled entry: copy URL, source file and the file's mtime.
     * Called by the REST handlers only (apply, preview): a visitor's request
     * never reads a stylesheet nor writes a file. An entry without copy
     * (not a local file, nothing to remove, failure) keeps its source.
     */
    public static function generate(array $compiled)
    {
        foreach ((array) ($compiled['fontVariants'] ?? array()) as $handle => $entries) {
            foreach ((array) $entries as $index => $entry) {
                unset($entry['copy'], $entry['file'], $entry['mtime']);
                $copy = self::write_copy((string) ($entry['src'] ?? ''), (array) ($entry['keep'] ?? array()));
                $compiled['fontVariants'][$handle][$index] = $copy ? $entry + $copy : $entry;
            }
        }
        return $compiled;
    }

    /**
     * The copy of a local stylesheet without the faces not kept, written once
     * under the uploads (name = sha1 of source, mtime and kept faces), or
     * null. Only a file of wp-content served by the site's own host is read.
     */
    private static function write_copy($src, array $keep)
    {
        $file = self::local_file($src);
        if ($file === null) {
            error_log('[faaaster-performance] font_variants: not a stylesheet of the site ' . $src);
            return null;
        }
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        $location = self::location();
        if ($mtime === false || $location === null) {
            error_log('[faaaster-performance] font_variants: no uploads directory for ' . $src);
            return null;
        }
        $name = sha1($src . '|' . $mtime . '|' . json_encode($keep)) . '.css';
        $target = $location['directory'] . '/' . $name;
        $copy = array('copy' => $location['url'] . '/' . $name, 'file' => $file, 'mtime' => (int) $mtime);
        if (strpos($copy['copy'], 'http://') === 0 && strpos($src, 'https://') === 0) {
            $copy['copy'] = 'https://' . substr($copy['copy'], 7);
        }
        if (is_file($target)) {
            // in use again: the cleanup counts its age from now
            @touch($target);
            return $copy;
        }
        // failures are logged, never printed
        $css = @file_get_contents($file);
        $filtered = is_string($css) ? self::filter($css, $keep) : null;
        if ($filtered === null) {
            error_log('[faaaster-performance] font_variants: unreadable stylesheet ' . $src);
            return null;
        }
        if ($filtered['removed'] === 0) {
            return null;
        }
        $content = self::absolute_urls($filtered['css'], $src);
        if ($content === null || !wp_mkdir_p($location['directory'])) {
            error_log('[faaaster-performance] font_variants: cannot prepare ' . $target);
            return null;
        }
        // written aside then renamed: a page never gets half a file
        $temporary = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $content) !== strlen($content) || !@rename($temporary, $target)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            error_log('[faaaster-performance] font_variants: cannot write ' . $target);
            return null;
        }
        return $copy;
    }

    /** age after which a copy no rule set references is removed */
    const CLEANUP_AGE = 86400;

    /**
     * Copies the compiled set does not reference, untouched for a day, are
     * removed (a preview's copies stay usable during its lifetime). Returns
     * the number of files removed.
     */
    public static function cleanup(array $compiled, $now = null)
    {
        $location = self::location();
        if ($location === null || !is_dir($location['directory'])) {
            return 0;
        }
        $referenced = array();
        foreach ((array) ($compiled['fontVariants'] ?? array()) as $entries) {
            foreach ((array) $entries as $entry) {
                if (!empty($entry['copy'])) {
                    $referenced[basename((string) wp_parse_url($entry['copy'], PHP_URL_PATH))] = true;
                }
            }
        }
        $limit = ($now === null ? time() : (int) $now) - self::CLEANUP_AGE;
        $removed = 0;
        foreach ((array) scandir($location['directory']) as $name) {
            $path = $location['directory'] . '/' . $name;
            if (!preg_match('/\.(css|tmp|skip)$/', (string) $name) || isset($referenced[$name]) || is_link($path) || !is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $limit) {
                continue;
            }
            if (@unlink($path)) {
                $removed++;
            } else {
                error_log('[faaaster-performance] font_variants: cannot remove ' . $path);
            }
        }
        return $removed;
    }

    /** Directory and URL of the copies, or null without uploads. */
    private static function location()
    {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : null;
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return null;
        }
        return array(
            'directory' => rtrim($uploads['basedir'], '/') . '/' . self::DIRECTORY,
            'url' => rtrim($uploads['baseurl'], '/') . '/' . self::DIRECTORY,
        );
    }

    /** The file of wp-content a stylesheet URL of the site points to, or null. */
    private static function local_file($url)
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path']) || !defined('WP_CONTENT_DIR')) {
            return null;
        }
        $host = strtolower($parts['host']);
        $own = array();
        foreach (array(home_url('/'), site_url('/')) as $site) {
            $site_host = wp_parse_url($site, PHP_URL_HOST);
            if (is_string($site_host)) {
                $own[strtolower($site_host)] = true;
            }
        }
        $content_path = wp_parse_url(content_url('/'), PHP_URL_PATH);
        $content_path = rtrim(is_string($content_path) ? $content_path : '/wp-content', '/') . '/';
        $path = rawurldecode($parts['path']);
        if (!isset($own[$host]) || strpos($path, $content_path) !== 0 || strtolower(substr($path, -4)) !== '.css') {
            return null;
        }
        $root = realpath(WP_CONTENT_DIR);
        $file = realpath(WP_CONTENT_DIR . '/' . substr($path, strlen($content_path)));
        if (
            $root === false
            || $file === false
            || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0
            || !is_file($file)
            || !is_readable($file)
            || filesize($file) > self::MAX_FILE_BYTES
        ) {
            return null;
        }
        return $file;
    }
}
