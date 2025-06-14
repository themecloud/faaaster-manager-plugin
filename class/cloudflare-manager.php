<?php

class FaaasterCloudflare
{
    private $app_id;
    private $branch;
    private $wp_api_key;
    private $cfcache_enabled;
    private $api_base;

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
            add_filter('script_loader_src', [$this, 'appendCacheBustQueryString'], 10, 1);
            add_filter('style_loader_src', [$this, 'appendCacheBustQueryString'], 10, 1);
            add_filter('wp_get_attachment_url', [$this, 'add_cache_bust_query_string_to_images'], 10, 2);
        }
    }

    /**
     * Purge all Cloudflare cache
     */
    public function purgeAll()
    {
        // Store timestamp
        $timestamp = time();
        update_option('faaaster_bust_timestamp', $timestamp);

        $url = $this->api_base . "/api/applications/" . $this->app_id . "/instances/" . $this->branch . "/cloudflare";
        $data = [
            'scope' => 'everything',
        ];

        // Define the request arguments
        $args = [
            'body' => json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->wp_api_key,
            ],
        ];

        // Make the API call
        $response = wp_remote_post($url, $args);

        // Check for errors and handle the response
        if (is_wp_error($response)) {
            error_log("Cloudflare purge all error: " . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                error_log("Cloudflare purge all failed with response code $response_code. Response: " . $response_body);
            }
        }
    }

    /**
     * Purge specific URLs from Cloudflare cache
     */
    public function purgeUrls($urls)
    {
        $url = $this->api_base . "/api/applications/" . $this->app_id . "/instances/" . $this->branch . "/cloudflare";
        $data = [
            'scope' => 'urls',
            'urls' => array($urls)
        ];

        // Define the request arguments
        $args = [
            'body' => json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->wp_api_key,
            ],
        ];

        // Make the API call
        $response = wp_remote_post($url, $args);

        // Check for errors and handle the response
        if (is_wp_error($response)) {
            error_log("Cloudflare purge URLs error: " . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                error_log("Cloudflare purge URLs failed with response code $response_code. Response: " . $response_body);
            }
        }
    }

    /**
     * Append cache busting query string to assets
     */
    public function appendCacheBustQueryString($src)
    {
        // Retrieve the cache busting timestamp from WP options
        $cache_bust = get_option('faaaster_bust_timestamp', time());
        // Append the cache busting query parameter to the URL
        return add_query_arg('fstrcache', $cache_bust, $src);
    }

    // Append cache busting query string to images
    public function add_cache_bust_query_string_to_images($url, $post_id)
    {
        // Retrieve the cache busting timestamp from WP options
        $cache_bust = get_option('faaaster_bust_timestamp', time());
        $query_param = 'fstrcache=' . $cache_bust;
        $url = add_query_arg($query_param, '', $url);

        return $url;
    }
}
