<?php

/**
 * WP Rocket 3.23.5.1 (hooks-verified.md, D). Correspondance calquée sur l'addon
 * Varnish de WP Rocket, contrat maintenu par WP Media. L'intégration
 * nginx-helper native reste éteinte ($nginx_helper n'est jamais défini) : plus
 * de purge totale à chaque job RUCSS.
 */
class FaaasterCacheIntegrationWpRocket extends FaaasterCacheIntegration
{
    /** Purges ciblées après RUCSS : au plus N par requête (le CSS est inliné, un HTML périmé reste valide). */
    const RUCSS_MAX_PER_REQUEST = 20;

    private $rucss_count = 0;

    public function id()
    {
        return 'wp_rocket';
    }

    public function label()
    {
        return 'WP Rocket';
    }

    public function detected()
    {
        return defined('WP_ROCKET_VERSION');
    }

    public function register()
    {
        $this->hook('before_rocket_clean_domain', 'on_clean_domain', 10, 3);
        $this->hook('before_rocket_clean_home', 'on_clean_home', 10, 2);
        $this->hook('before_rocket_clean_file', 'on_clean_file', 10, 1);
        $this->hook('rocket_rucss_after_clearing_usedcss', 'on_clean_file', 10, 1);
        $saas = defined('WP_ROCKET_VERSION') && version_compare(WP_ROCKET_VERSION, '3.16', '>=')
            ? 'rocket_saas_complete_job_status'
            : 'rocket_rucss_complete_job_status';
        $this->hook($saas, 'on_job_complete', 10, 1);
    }

    public function on_clean_domain($root = '', $lang = '', $url = '')
    {
        $this->purge_all('clean_domain');
    }

    public function on_clean_home($root = '', $lang = '')
    {
        $home = function_exists('get_rocket_i18n_home_url') ? get_rocket_i18n_home_url($lang) : home_url('/');
        $this->purge_url($home, 'clean_home');
        foreach (FaaasterCacheCascade::paged_urls($home, 3) as $url) {
            $this->purge_url($url, 'clean_home');
        }
    }

    public function on_clean_file($url)
    {
        if (!is_string($url) || strpos($url, '*') !== false || preg_match('#/index[^/]*\.html$#', $url)) {
            return;
        }
        $this->purge_url($url, 'clean_file');
    }

    public function on_job_complete($url)
    {
        if ($this->rucss_count >= self::RUCSS_MAX_PER_REQUEST) {
            return;
        }
        $this->rucss_count++;
        $this->purge_url($url, 'rucss_job');
    }
}

/**
 * WooCommerce 11.1.2 (hooks-verified.md, B). Le stock change hors save_post
 * (commandes, remboursements) : quantité → page du produit ; statut de stock →
 * cascade (boutique, catégories). Réglages de la boutique → purge totale.
 */
class FaaasterCacheIntegrationWooCommerce extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'woocommerce';
    }

    public function label()
    {
        return 'WooCommerce';
    }

    public function detected()
    {
        return defined('WC_VERSION');
    }

    public function register()
    {
        $this->hook('woocommerce_product_set_stock', 'on_stock', 10, 1);
        $this->hook('woocommerce_variation_set_stock', 'on_stock', 10, 1);
        $this->hook('woocommerce_product_set_stock_status', 'on_stock_status', 10, 3);
        $this->hook('woocommerce_variation_set_stock_status', 'on_stock_status', 10, 3);
        $this->hook('woocommerce_settings_saved', 'on_settings_saved', 10, 0);
    }

    public function on_stock($product)
    {
        $id = self::product_page_id($product);
        if ($id) {
            $url = get_permalink($id);
            if ($url) {
                $this->purge_url($url, 'stock:' . $id);
            }
        }
    }

    public function on_stock_status($product_id, $status = '', $product = null)
    {
        $id = $product ? self::product_page_id($product) : (int) $product_id;
        if ($id) {
            $this->purge_post($id, 'stock_status:' . $id);
        }
    }

    public function on_settings_saved()
    {
        $this->purge_all('settings_saved');
    }

    /** Une variation n'a pas de page : on purge son produit parent. */
    private static function product_page_id($product)
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return 0;
        }
        if (method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0) {
            return (int) $product->get_parent_id();
        }
        return (int) $product->get_id();
    }
}

/**
 * Optimiseurs dont le vidage supprime des CSS/JS combinés encore référencés par
 * le HTML en cache (même scénario que les CSS Elementor) : purge totale.
 */
class FaaasterCacheIntegrationAutoptimize extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'autoptimize';
    }

    public function label()
    {
        return 'Autoptimize';
    }

    public function detected()
    {
        return defined('AUTOPTIMIZE_PLUGIN_VERSION');
    }

    public function register()
    {
        // Émis à shutdown prio 11, avant le vidage de la file (100000).
        $this->hook('autoptimize_action_cachepurged', 'on_purged', 10, 0);
    }

    public function on_purged()
    {
        $this->purge_all('cachepurged');
    }
}

class FaaasterCacheIntegrationAssetCleanUp extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'asset_cleanup';
    }

    public function label()
    {
        return 'Asset CleanUp';
    }

    public function detected()
    {
        return defined('WPACU_PLUGIN_VERSION');
    }

    public function register()
    {
        $this->hook('wpacu_clear_cache_after', 'on_cleared', 10, 0);
    }

    public function on_cleared()
    {
        $this->purge_all('clear_cache');
    }
}

/**
 * Caches de pages qui doublonnent nginx : on purge l'hébergement quand ils se
 * vident (l'onglet Intégrations signale le doublon, à désactiver).
 */
class FaaasterCacheIntegrationWpSuperCache extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'wp_super_cache';
    }

    public function label()
    {
        return 'WP Super Cache';
    }

    public function detected()
    {
        return defined('WPCACHEHOME');
    }

    public function register()
    {
        $this->hook('wp_cache_cleared', 'on_cleared', 10, 0);
    }

    public function on_cleared()
    {
        $this->purge_all('cleared');
    }
}

class FaaasterCacheIntegrationW3TotalCache extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'w3_total_cache';
    }

    public function label()
    {
        return 'W3 Total Cache';
    }

    public function detected()
    {
        return defined('W3TC_VERSION');
    }

    public function register()
    {
        $this->hook('w3tc_flush_all', 'on_flush_all', 10, 0);
        $this->hook('w3tc_flush_posts', 'on_flush_all', 10, 0);
        $this->hook('w3tc_flush_after_minify', 'on_flush_all', 10, 0);
        $this->hook('w3tc_flush_post', 'on_flush_post', 10, 1);
        $this->hook('w3tc_flush_url', 'on_flush_url', 10, 1);
    }

    public function on_flush_all()
    {
        $this->purge_all('flush');
    }

    public function on_flush_post($post_id)
    {
        $this->purge_post($post_id, 'flush_post:' . (int) $post_id);
    }

    public function on_flush_url($url)
    {
        $this->purge_url($url, 'flush_url');
    }
}

class FaaasterCacheIntegrationWpFastestCache extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'wp_fastest_cache';
    }

    public function label()
    {
        return 'WP Fastest Cache';
    }

    public function detected()
    {
        return class_exists('WpFastestCache', false);
    }

    public function register()
    {
        $this->hook('wpfc_delete_cache', 'on_deleted', 10, 0);
    }

    public function on_deleted()
    {
        $this->purge_all('delete_cache');
    }
}

class FaaasterCacheIntegrationLiteSpeed extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'litespeed';
    }

    public function label()
    {
        return 'LiteSpeed Cache';
    }

    public function detected()
    {
        return defined('LSCWP_V');
    }

    public function register()
    {
        $this->hook('litespeed_purged_all', 'on_purged_all', 10, 0);
        $this->hook('litespeed_purged_all_cssjs', 'on_purged_all', 10, 0);
        $this->hook('litespeed_purged_post', 'on_purged_post', 10, 1);
        $this->hook('litespeed_purged_link', 'on_purged_link', 10, 1);
    }

    public function on_purged_all()
    {
        $this->purge_all('purged_all');
    }

    public function on_purged_post($post_id)
    {
        $this->purge_post($post_id, 'purged_post:' . (int) $post_id);
    }

    public function on_purged_link($url)
    {
        $this->purge_url($url, 'purged_link');
    }
}
