<?php

/**
 * Elementor 4.3.3 (hooks-verified.md, C) : sauvegarder dans l'éditeur émet les
 * hooks WP de post AVANT la suppression du CSS et after_save ; on purge donc
 * aussi sur after_save. clear_cache = régénération de tous les CSS générés.
 */
class FaaasterCacheIntegrationElementor extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'elementor';
    }

    public function label()
    {
        return 'Elementor';
    }

    public function detected()
    {
        return defined('ELEMENTOR_VERSION');
    }

    public function register()
    {
        $this->hook('elementor/core/files/clear_cache', 'on_clear_cache', 10, 0);
        $this->hook('elementor/document/after_save', 'on_after_save', 10, 2);
    }

    public function on_clear_cache()
    {
        $this->purge_all('files_clear_cache');
    }

    public function on_after_save($document, $data = array())
    {
        if (!is_object($document) || !method_exists($document, 'get_main_id')) {
            return;
        }
        $post_id = (int) $document->get_main_id();
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        // Gabarit (en-tête, pied de page, kit…) : peut s'afficher partout.
        if ($post->post_type === 'elementor_library') {
            $this->purge_all('template:' . $post_id);
            return;
        }
        $this->purge_post($post_id, 'document:' . $post_id);
    }
}

/** Beaver Builder Lite 2.11.0.6 (hooks-verified.md, G). */
class FaaasterCacheIntegrationBeaverBuilder extends FaaasterCacheIntegration
{
    public function id()
    {
        return 'beaver_builder';
    }

    public function label()
    {
        return 'Beaver Builder';
    }

    public function detected()
    {
        return defined('FL_BUILDER_VERSION');
    }

    public function register()
    {
        $this->hook('fl_builder_cache_cleared', 'on_cache_cleared', 10, 0);
        $this->hook('fl_builder_after_save_layout', 'on_save_layout', 10, 2);
        $this->hook('fl_builder_after_save_user_template', 'on_save_template', 10, 1);
    }

    public function on_cache_cleared()
    {
        $this->purge_all('cache_cleared');
    }

    /** Émis après la régénération des assets du layout. */
    public function on_save_layout($post_id, $publish = true)
    {
        if ($publish) {
            $this->purge_post($post_id, 'layout:' . $post_id);
        }
    }

    public function on_save_template($post_id)
    {
        $this->purge_all('template:' . $post_id);
    }
}

/**
 * Constructeurs dont les sources (payantes) n'ont pas encore été vérifiées :
 * détection seule, le filet global (mises à jour, thème…) les couvre.
 * Détection par la présence de l'extension ou du thème parent.
 */
class FaaasterCacheIntegrationPending extends FaaasterCacheIntegration
{
    private $id;
    private $label;
    private $plugin_files;
    private $templates;

    public function __construct(FaaasterCache $cache, $id, $label, array $plugin_files, array $templates = array())
    {
        parent::__construct($cache);
        $this->id = $id;
        $this->label = $label;
        $this->plugin_files = $plugin_files;
        $this->templates = $templates;
    }

    public function id()
    {
        return $this->id;
    }

    public function label()
    {
        return $this->label;
    }

    public function status()
    {
        return 'pending';
    }

    public function detected()
    {
        $active = (array) get_option('active_plugins', array());
        foreach ($this->plugin_files as $file) {
            if (in_array($file, $active, true)) {
                return true;
            }
        }
        if ($this->templates && function_exists('get_template')) {
            return in_array(strtolower((string) get_template()), array_map('strtolower', $this->templates), true);
        }
        return false;
    }
}
