<?php

/**
 * Adaptateur d'intégration : purge l'hébergement quand une extension (page
 * builder, optimiseur, cache de pages) régénère ou vide ce que le HTML en cache
 * référence. Hooks vérifiés dans les sources : docs/cache/hooks-verified.md.
 * Un adaptateur « pending » est seulement détecté (aucun hook) : le filet global
 * le couvre en attendant la vérification de ses sources.
 */
abstract class FaaasterCacheIntegration
{
    /** @var FaaasterCache */
    protected $cache;

    public function __construct(FaaasterCache $cache)
    {
        $this->cache = $cache;
    }

    /** Identifiant court, aussi suffixe du kill switch FAAASTER_CACHE_DISABLE_<ID>. */
    abstract public function id();

    abstract public function label();

    /** L'extension est-elle chargée ? */
    abstract public function detected();

    /** 'verified' (hooks vérifiés et branchés) ou 'pending' (détection seule). */
    public function status()
    {
        return 'verified';
    }

    /** Branche les hooks (seulement si verified, détecté et non désactivé). */
    public function register()
    {
    }

    public function kill_constant()
    {
        return 'FAAASTER_CACHE_DISABLE_' . strtoupper($this->id());
    }

    public function disabled()
    {
        $c = $this->kill_constant();
        return defined($c) && constant($c);
    }

    protected function queue()
    {
        return $this->cache->queue();
    }

    protected function purge_all($source)
    {
        $this->queue()->enqueue_all($this->id() . ':' . $source);
    }

    protected function purge_url($url, $source)
    {
        if (is_string($url) && $url !== '') {
            $this->queue()->enqueue_url($url, $this->id() . ':' . $source);
        }
    }

    /** Cascade complète d'un contenu publié. */
    protected function purge_post($post_id, $source)
    {
        $post = get_post((int) $post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }
        foreach ($this->cache->cascade()->urls_for_post($post) as $url) {
            $this->purge_url($url, $source);
        }
    }

    protected function hook($hook, $method, $priority = 10, $args = 1)
    {
        $this->cache->hook($hook, array($this, $method), $priority, $args);
    }
}
