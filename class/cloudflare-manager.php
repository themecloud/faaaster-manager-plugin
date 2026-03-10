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

    /**
     * Initialize Cloudflare hooks and filters
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

        error_log("[FaaasterCloudflare] purgeAll -> POST " . $this->getEndpointUrl() . " scope=everything");

        $response = wp_remote_post($this->getEndpointUrl(), [
            'body' => json_encode(['scope' => 'everything']),
            'headers' => $this->getAuthHeaders(),
        ]);

        if (is_wp_error($response)) {
            error_log("[FaaasterCloudflare] purgeAll error: " . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            error_log("[FaaasterCloudflare] purgeAll response: " . $response_code);
            if ($response_code !== 200) {
                error_log("[FaaasterCloudflare] purgeAll body: " . wp_remote_retrieve_body($response));
            }
        }
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
            error_log("[FaaasterCloudflare] purgeUrls SKIPPED (purgeAll already done): " . $url);
            return;
        }

        $this->urlsToPurge[] = $url;
        error_log("[FaaasterCloudflare] purgeUrls queued (" . count($this->urlsToPurge) . "): " . $url);

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

        error_log("[FaaasterCloudflare] flushPurgeUrls -> " . count($urls) . " unique URLs to purge");

        $chunks = array_chunk($urls, 30);
        foreach ($chunks as $i => $chunk) {
            error_log("[FaaasterCloudflare] flushPurgeUrls -> POST " . $this->getEndpointUrl() . " scope=urls, chunk " . ($i + 1) . "/" . count($chunks) . ", " . count($chunk) . " URLs: " . implode(', ', $chunk));

            $response = wp_remote_post($this->getEndpointUrl(), [
                'body' => json_encode(['scope' => 'urls', 'urls' => $chunk]),
                'headers' => $this->getAuthHeaders(),
            ]);

            if (is_wp_error($response)) {
                error_log("[FaaasterCloudflare] flushPurgeUrls error: " . $response->get_error_message());
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                error_log("[FaaasterCloudflare] flushPurgeUrls response: " . $response_code);
                if ($response_code !== 200) {
                    error_log("[FaaasterCloudflare] flushPurgeUrls body: " . wp_remote_retrieve_body($response));
                }
            }
        }
    }
}
