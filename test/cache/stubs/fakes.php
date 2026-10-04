<?php

/** Transport enregistreur : aucune requête réseau. */
class FaaasterTestTransport implements FaaasterCacheTransportInterface
{
    public $calls = array();
    public $path_status = 200;
    public $all_status = 200;
    public $delay_ms = 0;

    public function purge_path($host, $path)
    {
        $this->calls[] = array('path', $host, $path);
        if ($this->delay_ms) {
            usleep($this->delay_ms * 1000);
        }
        return array('status' => $this->path_status, 'ms' => $this->delay_ms, 'error' => null);
    }

    public function purge_all($host)
    {
        $this->calls[] = array('all', $host, null);
        return array('status' => $this->all_status, 'ms' => 1, 'error' => null);
    }

    /** Chemins purgés (ordre d'appel). */
    public function paths()
    {
        $paths = array();
        foreach ($this->calls as $call) {
            if ($call[0] === 'path') {
                $paths[] = $call[2];
            }
        }
        sort($paths);
        return $paths;
    }

    public function count($kind)
    {
        $n = 0;
        foreach ($this->calls as $call) {
            if ($call[0] === $kind) {
                $n++;
            }
        }
        return $n;
    }
}

/** Client Cloudflare enregistreur. */
class FaaasterTestCloudflare
{
    public $everything = 0;
    public $url_batches = array();

    public function purgeEverything()
    {
        $this->everything++;
        return array('status' => 200, 'ms' => 1, 'error' => null);
    }

    public function purgeUrlList(array $urls)
    {
        $this->url_batches[] = $urls;
        return array('batches' => 1, 'statuses' => array(200), 'ok' => true);
    }
}

/**
 * Démarre un module isolé pour un test.
 * @return array [FaaasterCache, FaaasterTestTransport, FaaasterTestCloudflare]
 */
function faaaster_test_boot(array $overrides = array())
{
    faaaster_test_reset_hooks();
    faaaster_test_reset_wp();
    faaaster_test_reset_content();
    FaaasterCache::reset();
    FaaasterCacheTakeover::reset();
    unset($GLOBALS['nginx_purger'], $GLOBALS['nginx_helper']);
    $transport = new FaaasterTestTransport();
    $cloudflare = new FaaasterTestCloudflare();
    $cache = FaaasterCache::boot(array_merge(array(
        'transport' => $transport,
        'cloudflare' => $cloudflare,
        'cf_enabled' => true,
        'hostmanager' => false,
        'site_provider' => function () {
            return array('home_url' => home_url('/'), 'hosts' => array('example.com'));
        },
    ), $overrides));
    return array($cache, $transport, $cloudflare);
}
