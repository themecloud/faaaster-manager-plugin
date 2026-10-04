<?php

/**
 * File de purge de la requête : dédoublonne les URL, laisse une purge totale
 * remplacer les URL, bascule en purge totale au-delà d'un seuil, puis vide la
 * file une seule fois en fin de requête (ou tout de suite en mode forcé).
 */
class FaaasterCachePurgeQueue
{
    /** Budget de temps des purges nginx par URL avant bascule en purge totale. */
    const NGINX_BUDGET_MS = 3000;
    const SHUTDOWN_PRIORITY = 100000;
    const PAGESPEED_FLUSH = '/tmp/pagespeed/cache.flush';

    private $transport;
    private $cloudflare;
    private $cf_enabled;
    private $events;
    private $settings;
    private $site_provider;
    private $site = null;

    private $urls = array();
    private $cf_urls = array();
    private $all = false;
    private $sources = array();
    private $shutdown_registered = false;
    private $shutdown_done = false;

    /**
     * @param FaaasterCacheTransportInterface $transport
     * @param object|null $cloudflare client exposant purgeEverything() / purgeUrlList()
     * @param callable $site_provider renvoie ['home_url' => string, 'hosts' => string[]]
     */
    public function __construct($transport, $cloudflare, $cf_enabled, FaaasterCacheEvents $events, FaaasterCacheSettings $settings, $site_provider)
    {
        $this->transport = $transport;
        $this->cloudflare = $cloudflare;
        $this->cf_enabled = (bool) $cf_enabled;
        $this->events = $events;
        $this->settings = $settings;
        $this->site_provider = $site_provider;
    }

    public function enqueue_url($url, $source = '')
    {
        $parsed = FaaasterCacheUrl::parse($url);
        if ($parsed === null) {
            return false;
        }
        $site = $this->site();
        $this->note_source($source);

        if ($this->cf_enabled && FaaasterCacheUrl::host_allowed($parsed['host'], $site['hosts'])) {
            $this->cf_urls[FaaasterCacheUrl::key($parsed)] = $parsed['url'];
        }
        if (FaaasterCacheUrl::purge_paths($parsed, $site['home_path']) !== array()) {
            $this->urls[FaaasterCacheUrl::key($parsed)] = $parsed;
        }
        if (count($this->urls) > (int) $this->settings->get('purge', 'escalate_threshold', 100)) {
            $this->all = true;
        }
        $this->schedule();
        return true;
    }

    /**
     * @param bool $forced true = purge synchrone immédiate (action manuelle)
     * @return array|null rapport si forcé
     */
    public function enqueue_all($source = '', $forced = false)
    {
        $this->all = true;
        $this->note_source($source);
        if ($forced) {
            return $this->flush(true);
        }
        $this->schedule();
        return null;
    }

    public function has_pending()
    {
        return $this->all || !empty($this->urls) || !empty($this->cf_urls);
    }

    public function pending_count()
    {
        return count($this->urls);
    }

    /** Fin de requête : rend la réponse au visiteur, puis purge. */
    public function on_shutdown()
    {
        $this->shutdown_done = true;
        if (!$this->has_pending()) {
            return;
        }
        if (function_exists('fastcgi_finish_request') && !(defined('WP_CLI') && WP_CLI)) {
            fastcgi_finish_request();
        }
        $this->flush(false);
    }

    /**
     * Vide la file : nginx, puis pagespeed, puis Cloudflare, puis journal.
     *
     * @return array ['mode', 'ok', 'nginx' => [...], 'cloudflare' => [...]|null]
     */
    public function flush($forced = false)
    {
        $report = array('mode' => 'none', 'ok' => true, 'nginx' => array(), 'cloudflare' => null);
        if (!$this->has_pending()) {
            return $report;
        }
        $site = $this->site();
        $sample = array_values($this->cf_urls ? $this->cf_urls : array_map(function ($p) {
            return $p['url'];
        }, $this->urls));

        if ($this->all) {
            $report['mode'] = 'all';
            $report['nginx'] = $this->purge_all_nginx($site['home_host']);
        } else {
            $report['mode'] = 'urls';
            $report['nginx'] = $this->purge_urls_nginx($site);
            if ($report['nginx']['escalated']) {
                $report['mode'] = 'all';
                $this->all = true;
            }
        }
        $report['ok'] = !empty($report['nginx']['ok']);

        if ($this->cf_enabled && $this->cloudflare) {
            $report['cloudflare'] = $this->all
                ? $this->cloudflare->purgeEverything()
                : ($this->cf_urls ? $this->cloudflare->purgeUrlList(array_values($this->cf_urls)) : null);
        }

        $this->events->record(array(
            'type' => $report['mode'],
            'source' => implode(',', array_slice(array_keys($this->sources), 0, 5)),
            'forced' => $forced,
            'count' => $this->all ? 0 : count($this->urls),
            'urls' => $this->all ? array() : $sample,
            'result' => array(
                'nginx' => $report['nginx'],
                'cloudflare' => $report['cloudflare'],
            ),
        ));
        $this->events->persist();

        $this->urls = array();
        $this->cf_urls = array();
        $this->all = false;
        $this->sources = array();

        if (!$report['ok']) {
            FaaasterCache::log('purge failed', array('mode' => $report['mode'], 'nginx' => $report['nginx']));
        }
        return $report;
    }

    private function purge_all_nginx($host)
    {
        $r = $this->transport->purge_all($host);
        if (is_dir(dirname(self::PAGESPEED_FLUSH))) {
            @touch(self::PAGESPEED_FLUSH);
        }
        return array(
            'mode' => 'all',
            'status' => $r['status'],
            'ms' => $r['ms'],
            'error' => $r['error'],
            'ok' => $r['status'] >= 200 && $r['status'] < 300,
            'escalated' => false,
        );
    }

    private function purge_urls_nginx(array $site)
    {
        $start = microtime(true);
        $statuses = array();
        $ok = true;
        foreach ($this->urls as $parsed) {
            foreach (FaaasterCacheUrl::purge_paths($parsed, $site['home_path']) as $path) {
                $r = $this->transport->purge_path($parsed['host'], $path);
                $statuses[$r['status']] = isset($statuses[$r['status']]) ? $statuses[$r['status']] + 1 : 1;
                if (!self::purge_status_ok($r['status'])) {
                    $ok = false;
                }
                if ((microtime(true) - $start) * 1000 > self::NGINX_BUDGET_MS) {
                    $all = $this->purge_all_nginx($site['home_host']);
                    $all['escalated'] = true;
                    $all['statuses'] = $statuses;
                    return $all;
                }
            }
        }
        return array(
            'mode' => 'urls',
            'statuses' => $statuses,
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'ok' => $ok,
            'escalated' => false,
        );
    }

    /**
     * Statuts de /purge/<path> (ngx_cache_purge 2.5) : 200 = purgé,
     * 412 = clé absente du cache (mesuré sur www.faaaster.io le 04/10/2026),
     * 404 = toléré. 403 = garde allow 127.0.0.1 non satisfaite, 0 = transport.
     */
    public static function purge_status_ok($status)
    {
        return $status === 200 || $status === 412 || $status === 404;
    }

    /** Programme le vidage en fin de requête ; après le shutdown, vide tout de suite. */
    private function schedule()
    {
        if ($this->shutdown_done) {
            $this->flush(false);
            return;
        }
        if (!$this->shutdown_registered && function_exists('add_action')) {
            $this->shutdown_registered = true;
            add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);
        }
    }

    private function note_source($source)
    {
        if ($source !== '') {
            $this->sources[$source] = true;
        }
    }

    private function site()
    {
        if ($this->site === null) {
            $site = call_user_func($this->site_provider);
            $home = FaaasterCacheUrl::parse(isset($site['home_url']) ? $site['home_url'] : '');
            $this->site = array(
                'home_url' => $home ? $home['url'] : '',
                'home_host' => $home ? $home['host'] : 'localhost',
                'home_path' => $home ? $home['path'] : '/',
                'hosts' => isset($site['hosts']) ? array_values(array_unique(array_map('strtolower', $site['hosts']))) : array(),
            );
        }
        return $this->site;
    }
}
