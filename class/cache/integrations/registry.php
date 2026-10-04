<?php

/**
 * Registre des intégrations : détecte les extensions et thèmes chargés et
 * branche les adaptateurs vérifiés. Exécuté sur after_setup_theme (prio 1) :
 * extensions ET thème (Divi, Avada, Enfold, Bricks sont des thèmes) sont chargés.
 * Kill switches : FAAASTER_CACHE_INTEGRATIONS_DISABLED, FAAASTER_CACHE_DISABLE_<ID>.
 */
class FaaasterCacheIntegrationRegistry
{
    private $cache;
    /** @var FaaasterCacheIntegration[] */
    private $integrations = array();
    private $registered = false;

    public function __construct(FaaasterCache $cache)
    {
        $this->cache = $cache;
        $this->integrations = array(
            new FaaasterCacheIntegrationWpRocket($cache),
            new FaaasterCacheIntegrationWooCommerce($cache),
            new FaaasterCacheIntegrationElementor($cache),
            new FaaasterCacheIntegrationBeaverBuilder($cache),
            new FaaasterCacheIntegrationAutoptimize($cache),
            new FaaasterCacheIntegrationAssetCleanUp($cache),
            new FaaasterCacheIntegrationWpSuperCache($cache),
            new FaaasterCacheIntegrationW3TotalCache($cache),
            new FaaasterCacheIntegrationWpFastestCache($cache),
            new FaaasterCacheIntegrationLiteSpeed($cache),
            // Sources payantes non vérifiées : détection seule.
            new FaaasterCacheIntegrationPending($cache, 'elementor_pro', 'Elementor Pro', array('elementor-pro/elementor-pro.php')),
            new FaaasterCacheIntegrationPending($cache, 'divi', 'Divi', array('divi-builder/divi-builder.php'), array('Divi', 'Extra')),
            new FaaasterCacheIntegrationPending($cache, 'bricks', 'Bricks', array(), array('bricks')),
            new FaaasterCacheIntegrationPending($cache, 'oxygen', 'Oxygen', array('oxygen/functions.php')),
            new FaaasterCacheIntegrationPending($cache, 'breakdance', 'Breakdance', array('breakdance/plugin.php')),
            new FaaasterCacheIntegrationPending($cache, 'wpbakery', 'WPBakery', array('js_composer/js_composer.php')),
            new FaaasterCacheIntegrationPending($cache, 'avada', 'Avada', array('fusion-builder/fusion-builder.php'), array('Avada')),
            new FaaasterCacheIntegrationPending($cache, 'enfold', 'Enfold', array(), array('enfold')),
        );
    }

    public function register()
    {
        if (defined('FAAASTER_CACHE_INTEGRATIONS_DISABLED') && constant('FAAASTER_CACHE_INTEGRATIONS_DISABLED')) {
            return;
        }
        $this->cache->hook('after_setup_theme', array($this, 'load'), 1, 0);
    }

    public function load()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        foreach ($this->integrations as $integration) {
            if ($integration->status() === 'verified' && !$integration->disabled() && $integration->detected()) {
                $integration->register();
            }
        }
    }

    /** État pour l'interface et site_state. */
    public function status()
    {
        $rows = array();
        foreach ($this->integrations as $integration) {
            $detected = $integration->detected();
            $rows[] = array(
                'id' => $integration->id(),
                'label' => $integration->label(),
                'status' => $integration->status(),
                'detected' => $detected,
                'active' => $detected && $integration->status() === 'verified' && !$integration->disabled()
                    && !(defined('FAAASTER_CACHE_INTEGRATIONS_DISABLED') && constant('FAAASTER_CACHE_INTEGRATIONS_DISABLED')),
                'kill_constant' => $integration->kill_constant(),
            );
        }
        return $rows;
    }

    /** Cache de pages tiers en doublon de nginx (lecture seule). */
    public static function third_party_page_cache()
    {
        $dropin = defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR . '/advanced-cache.php');
        return array(
            'wp_cache' => defined('WP_CACHE') && WP_CACHE,
            'advanced_cache_dropin' => $dropin,
        );
    }
}
