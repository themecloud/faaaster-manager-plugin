<?php

/**
 * Fixture minimale du fork faaaster-cache-manager 3.2.10 : mêmes classes et
 * même enregistrement de hooks que includes/class-nginx-helper.php:157-241.
 */
if (!class_exists('Nginx_Helper', false)) {
    class Nginx_Helper
    {
    }

    class Nginx_Helper_Admin
    {
        public function __call($name, $args)
        {
        }
    }

    abstract class Purger
    {
        public function __call($name, $args)
        {
        }
    }

    class FastCGI_Purger extends Purger
    {
        public $purged_all = 0;

        public function purge_all($force = false)
        {
            $this->purged_all++;
        }
    }
}

/** Charge le fork comme le ferait faaaster-cache-manager.php. */
function faaaster_test_load_fork()
{
    $GLOBALS['nginx_helper'] = new Nginx_Helper();
    $admin = $GLOBALS['nginx_helper_admin'] = new Nginx_Helper_Admin();
    $purger = $GLOBALS['nginx_purger'] = new FastCGI_Purger();

    add_action('admin_enqueue_scripts', array($admin, 'enqueue_styles'));
    add_action('admin_enqueue_scripts', array($admin, 'enqueue_scripts'));
    add_action('admin_menu', array($admin, 'nginx_helper_admin_menu'));
    add_filter('plugin_action_links_nginx-helper/nginx-helper.php', array($admin, 'nginx_helper_settings_link'));
    add_action('admin_bar_menu', array($admin, 'nginx_helper_toolbar_purge_link'), 100);
    add_action('wp_ajax_rt_get_feeds', array($admin, 'nginx_helper_get_feeds'));
    add_action('shutdown', array($admin, 'add_timestamps'), 99999);
    add_action('add_init', array($admin, 'update_map'));
    add_action('wp_insert_comment', array($purger, 'purge_post_on_comment'), 200, 2);
    add_action('transition_comment_status', array($purger, 'purge_post_on_comment_change'), 200, 3);
    add_action('transition_post_status', array($admin, 'set_future_post_option_on_future_status'), 20, 3);
    add_action('delete_post', array($admin, 'unset_future_post_option_on_delete'), 20, 1);
    add_action('edit_attachment', array($purger, 'purge_image_on_edit'), 100, 1);
    add_action('wpmu_new_blog', array($admin, 'update_new_blog_options'), 10, 1);
    add_action('transition_post_status', array($purger, 'purge_on_post_moved_to_trash'), 20, 3);
    add_action('edit_term', array($purger, 'purge_on_term_taxonomy_edited'), 20, 3);
    add_action('delete_term', array($purger, 'purge_on_term_taxonomy_edited'), 20, 3);
    add_action('check_ajax_referer', array($purger, 'purge_on_check_ajax_referer'), 20);
    add_action('admin_bar_init', array($admin, 'purge_all'));
    add_action('wp_after_insert_post', array($purger, 'purge_wp_after_insert_post'), 20, 4);
    add_action('elementor/document/after_save', array($purger, 'purge_elementor'));
    add_action('elementor/document/before_save', array($purger, 'init_elementor'), 10, 2);
    add_action('elementor/core/files/clear_cache', array($purger, 'purge_on_elementor_files_cleared'));
    add_action('rt_nginx_helper_purge_all', array($purger, 'purge_all'));
    return $purger;
}

/** Nombre de callbacks portés par un objet du fork. */
function faaaster_test_fork_callbacks()
{
    $n = 0;
    foreach ($GLOBALS['wp_filter'] as $hook) {
        foreach ($hook->callbacks as $items) {
            foreach ($items as $item) {
                if (is_array($item['function']) && is_object($item['function'][0]) && FaaasterCacheTakeover::is_fork_object($item['function'][0])) {
                    $n++;
                }
            }
        }
    }
    return $n;
}
