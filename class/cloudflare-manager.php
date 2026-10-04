<?php

class FaaasterCloudflare
{
    private $app_id;
    private $branch;
    private $wp_api_key;
    private $cfcache_enabled;
    private $api_base;
    private $purgedAll = false;
    private $urlsToPurge = [];
    private $shutdownRegistered = false;

    public function __construct($app_id, $branch, $wp_api_key, $cfcache_enabled, $api_base)
    {
        $this->app_id = $app_id;
        $this->branch = $branch;
        $this->wp_api_key = $wp_api_key;
        $this->cfcache_enabled = $cfcache_enabled;
        $this->api_base = $api_base;
    }

    public function isEnabled()
    {
        return $this->app_id && $this->wp_api_key && $this->branch && $this->cfcache_enabled == "true";
    }

    /**
     * Client du module cache : purge totale, UN appel, résultat réel.
     * @return array ['status' => int, 'ms' => int, 'error' => string|null]
     */
    public function purgeEverything()
    {
        return $this->post(array('scope' => 'everything'));
    }

    /**
     * Client du module cache : URL par lots de 30 (limite de l'API Cloudflare).
     * @return array ['batches' => int, 'statuses' => int[], 'ok' => bool]
     */
    public function purgeUrlList(array $urls)
    {
        $statuses = array();
        $ok = true;
        foreach (array_chunk(array_values(array_unique($urls)), 30) as $chunk) {
            $r = $this->post(array('scope' => 'urls', 'urls' => $chunk));
            $statuses[] = $r['status'];
            if ($r['status'] !== 200) {
                $ok = false;
            }
        }
        return array('batches' => count($statuses), 'statuses' => $statuses, 'ok' => $ok);
    }

    private function post(array $body)
    {
        $start = microtime(true);
        $response = wp_remote_post($this->getEndpointUrl(), array(
            'body' => json_encode($body),
            'headers' => $this->getAuthHeaders(),
            'timeout' => 3,
        ));
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (is_wp_error($response)) {
            error_log('[faaaster-cache] cloudflare ' . $body['scope'] . ' error: ' . $response->get_error_message());
            return array('status' => 0, 'ms' => $ms, 'error' => $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            error_log('[faaaster-cache] cloudflare ' . $body['scope'] . ' failed (' . $status . ')');
        }
        return array('status' => $status, 'ms' => $ms, 'error' => null);
    }

    /**
     * Câblage historique sur les actions du fork Nginx Helper : utilisé seulement
     * quand le module cache est inactif (ou que le fork n'a pas été repris en main).
     */
    public function init()
    {
        if ($this->app_id && $this->wp_api_key && $this->branch && $this->cfcache_enabled == "true") {
            add_action('rt_nginx_helper_after_fastcgi_purge_all', [$this, 'purgeAll'], PHP_INT_MAX);
            add_action('rt_nginx_helper_fastcgi_purge_url', [$this, 'purgeUrls'], PHP_INT_MAX, 1);
        }
    }

    private function getEndpointUrl()
    {
        return $this->api_base . "/api/applications/" . $this->app_id . "/instances/" . $this->branch . "/cloudflare";
    }

    private function getAuthHeaders()
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->wp_api_key,
        ];
    }

    /**
     * Purge all Cloudflare cache.
     * Sets a flag so that any subsequent per-URL purges in the same
     * PHP request are skipped (they would be redundant).
     */
    public function purgeAll()
    {
        $this->purgedAll = true;
        $this->urlsToPurge = [];

        $response = wp_remote_post($this->getEndpointUrl(), [
            'body' => json_encode(['scope' => 'everything']),
            'headers' => $this->getAuthHeaders(),
        ]);

        // if (is_wp_error($response)) {
        //     error_log("[FaaasterCloudflare] purgeAll error: " . $response->get_error_message());
        // } else {
        //     $response_code = wp_remote_retrieve_response_code($response);
        //     if ($response_code !== 200) {
        //         error_log("[FaaasterCloudflare] purgeAll failed (" . $response_code . "): " . wp_remote_retrieve_body($response));
        //     }
        // }
    }

    /**
     * Queue a URL for Cloudflare cache purging.
     * URLs are collected and sent in a single batched request at shutdown
     * instead of one HTTP call per URL.
     * Skipped entirely if purgeAll() was already called in this request.
     */
    public function purgeUrls($url)
    {
        if ($this->purgedAll) {
            // error_log("[FaaasterCloudflare]purgeUrls SKIPPED (purgeAll already done): " . $url);
            return;
        }

        $this->urlsToPurge[] = $url;
        // error_log("[FaaasterCloudflare] purgeUrls queued (" . count($this->urlsToPurge) . "): " . $url);

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            add_action('shutdown', [$this, 'flushPurgeUrls']);
        }
    }

    /**
     * Send all queued URL purges in batched requests (max 30 URLs per
     * Cloudflare API call). Called automatically at PHP shutdown.
     */
    public function flushPurgeUrls()
    {
        if ($this->purgedAll || empty($this->urlsToPurge)) {
            return;
        }

        $urls = array_values(array_unique($this->urlsToPurge));
        $this->urlsToPurge = [];

        // error_log("[FaaasterCloudflare] flushPurgeUrls -> " . count($urls) . " unique URLs to purge");

        $chunks = array_chunk($urls, 30);
        foreach ($chunks as $i => $chunk) {
            // error_log("[FaaasterCloudflare] flushPurgeUrls -> POST " . $this->getEndpointUrl() . " scope=urls, chunk " . ($i + 1) . "/" . count($chunks) . ", " . count($chunk) . " URLs: " . implode(', ', $chunk));

            $response = wp_remote_post($this->getEndpointUrl(), [
                'body' => json_encode(['scope' => 'urls', 'urls' => $chunk]),
                'headers' => $this->getAuthHeaders(),
            ]);

            // if (is_wp_error($response)) {
            //     error_log("[FaaasterCloudflare] flushPurgeUrls error: " . $response->get_error_message());
            // } else {
            //     $response_code = wp_remote_retrieve_response_code($response);
            //     if ($response_code !== 200) {
            //         error_log("[FaaasterCloudflare] flushPurgeUrls failed (" . $response_code . "): " . wp_remote_retrieve_body($response));
            //     }
            // }
        }
    }
}
