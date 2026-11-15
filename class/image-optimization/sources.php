<?php

/** Local media resolver and public URL contract. Never performs HTTP requests. */
class FaaasterImageSources
{
    const WIDTHS = FaaasterImageOptimization::WIDTHS;
    const MAX_PATH_LENGTH = 1024;
    const PREFIX = '/_faaaster/images/v1/webp/q80/';
    private $uploads;
    private $root;
    private $widths;
    private $resolved = array();

    public function __construct(array $uploads)
    {
        $this->uploads = $uploads;
        $this->root = !empty($uploads['basedir']) ? realpath($uploads['basedir']) : false;
        $this->widths = self::WIDTHS;
        foreach (wp_get_registered_image_subsizes() as $size) {
            $width = isset($size['width']) ? $size['width'] : 0;
            if (is_int($width) && $width > 0 && $width <= 2560) {
                $this->widths[] = $width;
            }
        }
        $this->widths = array_values(array_unique($this->widths));
        sort($this->widths, SORT_NUMERIC);
    }

    public function widths()
    {
        return $this->widths;
    }

    public function available()
    {
        $base = wp_parse_url(isset($this->uploads['baseurl']) ? $this->uploads['baseurl'] : '');
        return $this->root && $base && isset($base['path'], $base['host'], $base['scheme'])
            && in_array(strtolower($base['scheme']), array('http', 'https'), true)
            && preg_match('~^/wp-content/uploads(?:/|$)~', $base['path']);
    }

    /** Returns null for unsupported media; all paths are checked against the real uploads root. */
    public function resolve($url)
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        if (isset($this->resolved[$url])) {
            return $this->resolved[$url];
        }
        $located = $this->locate($url, false);
        if (!$located) {
            return null;
        }
        list($decoded, $file, $base) = $located;
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, array('jpg', 'jpeg', 'png', 'webp'), true)) {
            return null;
        }
        $dimensions = wp_getimagesize($file);
        $expected = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
        if (!$dimensions || empty($dimensions[0]) || empty($dimensions[1]) || $dimensions['mime'] !== $expected[$extension]
            || self::animated($file, $extension)) {
            return null;
        }
        $mtime = filemtime($file);
        $size = filesize($file);
        if (!preg_match('/^[1-9][0-9]{0,11}$/', (string) $mtime) || !preg_match('/^[1-9][0-9]{0,11}$/', (string) $size)) {
            return null;
        }
        $path = rtrim($base['path'], '/') . '/' . implode('/', array_map('rawurlencode', $decoded));
        // Emitted raw inside url("…"), CSS attribute selectors and srcset: URL-safe bytes only
        // (rawurlencode guarantees it for the file, not for the uploads base path).
        if (!preg_match('~^/[A-Za-z0-9/._\~%-]+$~D', $path)) {
            return null;
        }
        // Reserve the longest emitted width segment so every candidate is signable by px2.
        if (strlen(self::PREFIX . 'w2560/m' . $mtime . '-s' . $size . $path) > self::MAX_PATH_LENGTH) {
            return null;
        }
        return $this->resolved[$url] = array(
            'path' => $path,
            'width' => (int) $dimensions[0], 'height' => (int) $dimensions[1],
            'version' => 'm' . $mtime . '-s' . $size,
        );
    }

    /**
     * A stylesheet linked by the page, as a local file of the real uploads directory (where page
     * builders write their generated CSS), or null. Its "?ver=" query is ignored. Read-only use.
     */
    public function stylesheet($url)
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $located = $this->locate($url, true);
        return $located && strtolower(pathinfo($located[1], PATHINFO_EXTENSION)) === 'css' ? $located[1] : null;
    }

    /** Public URL to a readable file inside the real uploads directory: decoded segments, file, base URL parts. */
    private function locate($url, $allow_query)
    {
        if (!$this->available() || strpos($url, self::PREFIX) !== false || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }
        $parts = wp_parse_url($url);
        $base = wp_parse_url($this->uploads['baseurl']);
        if (!$parts || (!$allow_query && isset($parts['query'])) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return null;
        }
        // Only the actual uploads origin is accepted, never an arbitrary request Host.
        if (isset($parts['host']) && (strtolower($parts['host']) !== strtolower($base['host'])
            || (isset($parts['port']) ? $parts['port'] : null) !== (isset($base['port']) ? $base['port'] : null))) {
            return null;
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        $prefix = rtrim($base['path'], '/') . '/';
        if (strpos($path, $prefix) !== 0 || preg_match('/%(?:2f|5c|00)/i', $path) || preg_match('/%(?![0-9a-f]{2})/i', $path)) {
            return null;
        }
        $decoded = self::decoded_segments(substr($path, strlen($prefix)));
        if ($decoded === null) {
            return null;
        }
        $file = realpath($this->root . '/' . implode('/', $decoded));
        if (!$file || strpos($file, $this->root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file) || !is_readable($file)) {
            return null;
        }
        return array($decoded, $file, $base);
    }

    private static function decoded_segments($path)
    {
        $decoded = array();
        foreach (explode('/', $path) as $segment) {
            $value = rawurldecode($segment);
            if ($value === '' || $value === '.' || $value === '..' || preg_match('/[\x00-\x1f\x7f\\\\\/]/', $value)
                || strpos($value, '%') !== false || !preg_match('//u', $value)) {
                return null;
            }
            $decoded[] = $value;
        }
        return $decoded;
    }

    /**
     * Uploads path of an observed background, original file or variant alike, in the encoding
     * resolve() emits. No filesystem I/O: one identity for a file before and after it is adapted.
     */
    public static function upload_path($url)
    {
        if (!is_string($url)) { return null; }
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        if (isset($parts['host']) && (strtolower($parts['host']) !== strtolower($home['host'])
            || ($parts['port'] ?? null) !== ($home['port'] ?? null))) { return null; }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) { return null; }
        $path = $parts['path'] ?? '';
        if (strlen($path) > self::MAX_PATH_LENGTH || preg_match('/%(?:2f|5c|00)/i', $path) || preg_match('/%(?![0-9a-f]{2})/i', $path)) { return null; }
        if (preg_match('~^' . preg_quote(self::PREFIX, '~') . 'w(?:0|[1-9][0-9]{0,3})/m[1-9][0-9]{0,11}-s[1-9][0-9]{0,11}(/wp-content/uploads/.+)\z~', $path, $match)) {
            $path = $match[1];
        }
        if (strpos($path, '/wp-content/uploads/') !== 0) { return null; }
        $decoded = self::decoded_segments(substr($path, 1));
        return $decoded === null ? null : '/' . implode('/', array_map('rawurlencode', $decoded));
    }

    /** Normalize an observed variant to its full-size source identity, without filesystem I/O. */
    public static function variant_key($url)
    {
        if (!is_string($url)) { return null; }
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || (isset($parts['query']) || isset($parts['fragment'])) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        if (isset($parts['host']) && (strtolower($parts['host']) !== strtolower($home['host'])
            || ($parts['port'] ?? null) !== ($home['port'] ?? null))) { return null; }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) { return null; }
        $path = $parts['path'] ?? '';
        if (strlen($path) > self::MAX_PATH_LENGTH || !preg_match('~^' . preg_quote(self::PREFIX, '~')
            . 'w(?:0|[1-9][0-9]{0,3})/(m[1-9][0-9]{0,11}-s[1-9][0-9]{0,11})(/wp-content/uploads/.+)\z~', $path, $match)) { return null; }
        return self::PREFIX . 'w0/' . $match[1] . $match[2];
    }

    private static function animated($file, $extension)
    {
        if ($extension !== 'png' && $extension !== 'webp') {
            return false;
        }
        $stream = fopen($file, 'rb');
        if (!$stream) {
            return true;
        }
        $header = fread($stream, 32);
        if ($extension === 'webp') {
            fclose($stream);
            return substr($header, 12, 4) === 'VP8X' && isset($header[20]) && (ord($header[20]) & 2) !== 0;
        }
        fseek($stream, 8);
        while (!feof($stream)) {
            $chunk = fread($stream, 8);
            if (strlen($chunk) !== 8) {
                break;
            }
            $length = unpack('Nlength', substr($chunk, 0, 4))['length'];
            $type = substr($chunk, 4, 4);
            if ($type === 'acTL') {
                fclose($stream);
                return true;
            }
            if ($type === 'IDAT' || $type === 'IEND' || $length > filesize($file) || fseek($stream, $length + 4, SEEK_CUR) !== 0) {
                break;
            }
        }
        fclose($stream);
        return false;
    }

    public function url(array $source, $width = 0)
    {
        $width = (int) $width;
        if ($width !== 0 && (!in_array($width, $this->widths, true) || $width > $source['width'])) {
            return null;
        }
        $url = self::PREFIX . 'w' . $width . '/' . $source['version'] . $source['path'];
        return strlen($url) <= self::MAX_PATH_LENGTH ? $url : null;
    }

    /** Parse all-or-nothing: unsupported/custom srcset syntax must stay untouched. */
    public function candidates($srcset, $allow_unsupported = false)
    {
        if (!is_string($srcset) || trim($srcset) === '') {
            return array();
        }
        $items = array();
        $kind = null;
        foreach (explode(',', $srcset) as $candidate) {
            $pattern = $allow_unsupported
                ? '/^\s*(\S+?)(?:\s+([1-9][0-9]*w|(?:[0-9]+(?:\.[0-9]+)?)x))?\s*$/'
                : '/^\s*(\S+)\s+([1-9][0-9]*w|(?:[0-9]+(?:\.[0-9]+)?)x)\s*$/';
            if (!preg_match($pattern, $candidate, $match)) {
                return null;
            }
            $descriptor = isset($match[2]) ? $match[2] : '';
            $type = $descriptor === '' ? 'x' : substr($descriptor, -1);
            if (($kind !== null && $kind !== $type) || ($descriptor !== '' && (float) $descriptor <= 0)) {
                return null;
            }
            $source = $this->resolve($match[1]);
            if (!$source || ($type === 'w' && (int) $descriptor !== $source['width'])) {
                if (!$allow_unsupported) { return null; }
                $source = null;
            }
            $kind = $type;
            $items[] = array('source' => $source, 'descriptor' => $descriptor, 'url' => $match[1]);
        }
        return $items;
    }

    /** Lazy sets keep authored candidates/descriptors; unsupported sources remain original. */
    public function convert_candidates($srcset)
    {
        if (is_string($srcset) && preg_match('/(?:^|,)\s*data:/i', $srcset)) { return null; }
        $candidates = $this->candidates($srcset, true);
        if ($candidates === null) { return null; }
        $changed = false;
        $out = array();
        foreach ($candidates as $candidate) {
            $url = $candidate['source'] ? $this->url($candidate['source']) : $candidate['url'];
            $changed = $changed || $url !== $candidate['url'];
            $out[] = $url . ($candidate['descriptor'] !== '' ? ' ' . $candidate['descriptor'] : '');
        }
        return $changed ? implode(', ', $out) : $srcset;
    }

    public function srcset(array $source, array $candidates, $expand = true)
    {
        if ($candidates && substr($candidates[0]['descriptor'], -1) === 'x') {
            return implode(', ', array_map(function ($candidate) {
                return $this->url($candidate['source']) . ' ' . $candidate['descriptor'];
            }, $candidates));
        }
        $out = array();
        foreach ($candidates as $candidate) {
            $item = $candidate['source'];
            if (abs($item['height'] - $item['width'] * $source['height'] / $source['width']) > 1) {
                // A mixed-ratio set is authored art direction. Do not interpolate new crops.
                return implode(', ', array_map(function ($candidate) {
                    return $this->url($candidate['source']) . ' ' . $candidate['descriptor'];
                }, $candidates));
            }
        }
        if (!$expand) {
            $existing = array();
            foreach ($candidates as $candidate) {
                $existing[$candidate['source']['width']] = $this->url($candidate['source']) . ' ' . $candidate['descriptor'];
            }
            if ($existing && $source['width'] > max(array_keys($existing))) {
                $existing[$source['width']] = $this->url($source) . ' ' . $source['width'] . 'w';
            }
            ksort($existing, SORT_NUMERIC);
            return implode(', ', $existing);
        }
        // Every file here has the src ratio (checked above): the sizes WordPress made of one image.
        $natives = array($source['width'] => $source);
        foreach ($candidates as $candidate) {
            $natives[$candidate['source']['width']] = $candidate['source'];
        }
        ksort($natives, SORT_NUMERIC);
        foreach ($natives as $width => $item) {
            // Existing native candidates retain their own crop/source, at their natural width
            // (w0 has no bucket limit: a full size above 2560 stays available).
            $out[$width] = $this->url($item) . ' ' . $width . 'w';
        }
        // A bucket is added unless a candidate less than 20 % wider is already there (5c < 6w; exactly
        // 20 %, as 768 for 640, is far enough): the browser takes that candidate instead. Widest
        // first, so that of two close buckets the wider one stays and covers the other.
        $kept = null;
        foreach (array_reverse($this->widths) as $width) {
            // Resized from the narrowest file that covers the bucket, not from the full size: when the
            // image service does not answer in time the site serves the file named here, unresized.
            foreach ($natives as $native_width => $item) {
                if ($native_width >= $width) {
                    if (min($native_width, $kept === null ? $native_width : $kept) * 5 >= $width * 6) {
                        $out[$width] = $this->url($item, $width) . ' ' . $width . 'w';
                        $kept = $width;
                    }
                    break;
                }
            }
        }
        ksort($out, SORT_NUMERIC);
        return implode(', ', $out);
    }
}
