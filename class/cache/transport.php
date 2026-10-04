<?php

/**
 * Transport vers les routes de purge nginx du pod (wp-builder http.conf:581-606).
 */
interface FaaasterCacheTransportInterface
{
    /**
     * 200 = purgé, 412 = absent du cache, 403 = garde loopback refusée.
     * @return array ['status' => int, 'ms' => int, 'error' => string|null]
     */
    public function purge_path($host, $path);

    /** @return array ['status' => int, 'ms' => int, 'error' => string|null] */
    public function purge_all($host);
}

class FaaasterCacheTransport implements FaaasterCacheTransportInterface
{
    const BASE = 'http://127.0.0.1';

    private $handle = null;

    public function purge_path($host, $path)
    {
        return $this->get(self::BASE . '/purge' . $path, $host, 2000);
    }

    public function purge_all($host)
    {
        return $this->get(self::BASE . '/purge-all', $host, 10000);
    }

    private function get($url, $host, $timeout_ms)
    {
        $start = microtime(true);
        if (!function_exists('curl_init')) {
            return array('status' => 0, 'ms' => 0, 'error' => 'curl unavailable');
        }
        if ($this->handle === null) {
            $this->handle = curl_init();
        }
        $ch = $this->handle;
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS => $timeout_ms,
            // La porte privé/essai laisse passer ce cookie (nginx-snippets/private) :
            // sans lui, une purge sur un site privé recevrait la redirection de la porte.
            CURLOPT_COOKIE => 'trial_bypass=true',
            CURLOPT_HTTPHEADER => array(
                'Host: ' . $host,
                'User-Agent: Faaaster-Cache-Purger/1.0',
            ),
        ));
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;

        return array(
            'status' => $status,
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'error' => $error,
        );
    }

    public function __destruct()
    {
        if ($this->handle !== null && PHP_VERSION_ID < 80000) {
            curl_close($this->handle);
        }
        $this->handle = null;
    }
}
