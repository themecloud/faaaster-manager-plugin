<?php

fc_section('integrations');

class FaaasterTestWcProduct
{
    private $id;
    private $parent;

    public function __construct($id, $parent = 0)
    {
        $this->id = $id;
        $this->parent = $parent;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_parent_id()
    {
        return $this->parent;
    }
}

class FaaasterTestElementorDocument
{
    private $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function get_main_id()
    {
        return $this->id;
    }
}

function fc_integration($class)
{
    list($cache, $t, $cf) = faaaster_test_boot();
    $integration = new $class($cache);
    $integration->register();
    return array($cache, $t, $cf, $integration);
}

// Elementor : clear_cache → purge totale ; after_save d'une page → cascade ; gabarit → purge totale.
list($cache, $t) = fc_integration('FaaasterCacheIntegrationElementor');
do_action('elementor/core/files/clear_cache');
do_action('shutdown');
fc_check('elementor clear_cache → purge-all', $t->count('all'), 1);
list($cache, $t) = fc_integration('FaaasterCacheIntegrationElementor');
ft_post(array('ID' => 70, 'post_type' => 'page', 'post_name' => 'landing'));
do_action('elementor/document/after_save', new FaaasterTestElementorDocument(70), array());
do_action('shutdown');
fc_check('elementor after_save page → its URL', $t->paths(), array('/landing/'));
list($cache, $t) = fc_integration('FaaasterCacheIntegrationElementor');
ft_post(array('ID' => 71, 'post_type' => 'elementor_library', 'post_name' => 'header'));
do_action('elementor/document/after_save', new FaaasterTestElementorDocument(71), array());
do_action('shutdown');
fc_check('elementor after_save template → purge-all', $t->count('all'), 1);

// Beaver Builder.
list($cache, $t) = fc_integration('FaaasterCacheIntegrationBeaverBuilder');
do_action('fl_builder_cache_cleared');
do_action('shutdown');
fc_check('beaver cache cleared → purge-all', $t->count('all'), 1);
list($cache, $t) = fc_integration('FaaasterCacheIntegrationBeaverBuilder');
ft_post(array('ID' => 72, 'post_type' => 'page', 'post_name' => 'bb'));
do_action('fl_builder_after_save_layout', 72, true, array(), array());
do_action('shutdown');
fc_check('beaver save layout (publish) → page', $t->paths(), array('/bb/'));
list($cache, $t) = fc_integration('FaaasterCacheIntegrationBeaverBuilder');
ft_post(array('ID' => 72, 'post_type' => 'page', 'post_name' => 'bb'));
do_action('fl_builder_after_save_layout', 72, false, array(), array());
do_action('shutdown');
fc_check('beaver save draft layout → nothing', count($t->calls), 0);

// WooCommerce : quantité → produit ; variation → parent ; statut → cascade ; réglages → tout.
list($cache, $t) = fc_integration('FaaasterCacheIntegrationWooCommerce');
ft_post(array('ID' => 80, 'post_type' => 'product', 'post_name' => 'tee'));
do_action('woocommerce_product_set_stock', new FaaasterTestWcProduct(80));
do_action('woocommerce_variation_set_stock', new FaaasterTestWcProduct(81, 80));
do_action('shutdown');
fc_check('stock change → product page only (variation → parent)', $t->paths(), array('/tee/'));
list($cache, $t) = fc_integration('FaaasterCacheIntegrationWooCommerce');
$GLOBALS['ft']['shop_page_id'] = 7;
ft_post(array('ID' => 7, 'post_type' => 'page', 'post_name' => 'shop'));
ft_post(array('ID' => 80, 'post_type' => 'product', 'post_name' => 'tee'));
do_action('woocommerce_product_set_stock_status', 80, 'outofstock', new FaaasterTestWcProduct(80));
do_action('shutdown');
fc_check('stock status → cascade (shop)', in_array('/shop/', $t->paths(), true) && in_array('/tee/', $t->paths(), true), true);
list($cache, $t) = fc_integration('FaaasterCacheIntegrationWooCommerce');
do_action('woocommerce_settings_saved');
do_action('shutdown');
fc_check('woocommerce settings saved → purge-all', $t->count('all'), 1);

// Optimiseurs et caches tiers : purge totale à leur vidage.
foreach (array(
    array('FaaasterCacheIntegrationAutoptimize', 'autoptimize_action_cachepurged'),
    array('FaaasterCacheIntegrationAssetCleanUp', 'wpacu_clear_cache_after'),
    array('FaaasterCacheIntegrationWpSuperCache', 'wp_cache_cleared'),
    array('FaaasterCacheIntegrationW3TotalCache', 'w3tc_flush_all'),
    array('FaaasterCacheIntegrationW3TotalCache', 'w3tc_flush_after_minify'),
    array('FaaasterCacheIntegrationWpFastestCache', 'wpfc_delete_cache'),
    array('FaaasterCacheIntegrationLiteSpeed', 'litespeed_purged_all'),
    array('FaaasterCacheIntegrationLiteSpeed', 'litespeed_purged_all_cssjs'),
) as $case) {
    list($cache, $t) = fc_integration($case[0]);
    do_action($case[1]);
    do_action('shutdown');
    fc_check("{$case[1]} → purge-all", $t->count('all'), 1);
}
list($cache, $t) = fc_integration('FaaasterCacheIntegrationLiteSpeed');
do_action('litespeed_purged_link', 'https://example.com/promo/');
do_action('shutdown');
fc_check('litespeed purged link → that URL', $t->paths(), array('/promo/'));
list($cache, $t) = fc_integration('FaaasterCacheIntegrationW3TotalCache');
ft_post(array('ID' => 90, 'post_name' => 'w3'));
do_action('w3tc_flush_post', 90, false, null);
do_action('shutdown');
fc_check('w3tc flush post → cascade', in_array('/w3/', $t->paths(), true), true);

// Registre : kill switch global, détection seule pour les sources non vérifiées.
list($cache, $t) = faaaster_test_boot();
update_option('active_plugins', array('js_composer/js_composer.php'));
$status = array();
foreach ($cache->integrations()->status() as $row) {
    $status[$row['id']] = $row;
}
fc_check('pending builder detected (WPBakery)', $status['wpbakery']['detected'], true);
fc_check('pending builder never active', $status['wpbakery']['active'], false);
fc_check('pending status reported', $status['wpbakery']['status'], 'pending');
fc_check('verified but absent → inactive', $status['elementor']['active'], false);
fc_check('kill constant name', $status['wp_rocket']['kill_constant'], 'FAAASTER_CACHE_DISABLE_WP_ROCKET');
fc_check('registry loads on after_setup_theme', has_action('after_setup_theme') !== false, true);

// Requête hostmanager : aucun adaptateur branché.
list($cache, $t) = faaaster_test_boot(array('hostmanager' => true));
fc_check('hostmanager request: registry not hooked', has_action('after_setup_theme'), false);
