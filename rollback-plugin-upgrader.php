<?php

/**
 *
 * Class that extends the WP Core Plugin_Upgrader found in core to do updates to a precise verison.
 *
 * @copyright  : http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since      : 1.0.0
 */


/**
 * Class WP_Custom_Plugin_Upgrader
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Custom_Plugin_Upgrader extends Plugin_Upgrader
{


    /**
     * Plugin rollback.
     *
     * @param       $plugin
     * @param array $args
     *
     * @return array|bool|\WP_Error
     */
    public function rollback($plugin, $args = array())
    {

        $defaults    = array(
            'clear_update_cache' => true,
        );

        $this->init();
        $this->upgrade_strings();

        // TODO: Add final check to make sure plugin exists
        if (0) {
            $this->skin->before();
            $this->skin->set_result(false);
            $this->skin->error('up_to_date');
            $this->skin->after();

            return false;
        }


        $plugin_slug = $this->skin->options['plugin'];

        $plugin_version = $this->skin->options['version'];

        $download_endpoint = 'https://downloads.wordpress.org/plugin/';

        $url = $download_endpoint . $plugin_slug . '.' . $plugin_version . '.zip';

        add_filter('upgrader_pre_install', array($this, 'deactivate_plugin_before_upgrade'), 10, 2);
        add_filter('upgrader_clear_destination', array($this, 'delete_old_plugin'), 10, 4);

        $this->run(array(
            'package'           => $url,
            'destination'       => WP_PLUGIN_DIR,
            'clear_destination' => true,
            'clear_working'     => true,
            'hook_extra'        => array(
                'plugin' => $plugin,
                'type'   => 'plugin',
                'action' => 'update',
            ),
        ));

        // Cleanup our hooks, in case something else does a upgrade on this connection.
        remove_filter('upgrader_pre_install', array($this, 'deactivate_plugin_before_upgrade'));
        remove_filter('upgrader_clear_destination', array($this, 'delete_old_plugin'));

        if (!$this->result || is_wp_error($this->result)) {
            return $this->result;
        }

        // Force refresh of plugin update information.
        wp_clean_plugins_cache(true);

        return true;
    }

    /*
    public function bulkRollback($plugins, $args = array())
    {
        $defaults    = array(
            'clear_update_cache' => true,
        );
        $parsed_args = wp_parse_args($args, $defaults);

        $this->init();
        $this->bulk = true;
        $this->upgrade_strings();

        $current = get_site_transient('update_plugins');

        // TODO: Add final check to make sure plugin exists
        if (0) {
            $this->skin->before();
            $this->skin->set_result(false);
            $this->skin->error('up_to_date');
            $this->skin->after();

            return false;
        }

        //$this->skin->header();

        // Connect to the filesystem first.
        $res = $this->fs_connect(array(WP_CONTENT_DIR, WP_PLUGIN_DIR));
        if (!$res) {
            $this->skin->footer();
            return false;
        }

        //$this->skin->bulk_header();

        /*
         * Only start maintenance mode if:
         * - running Multisite and there are one or more plugins specified, OR
         * - a plugin with an update available is currently active.
         * @todo For multisite, maintenance mode should only kick in for individual sites if at all possible.
         
        $maintenance = (is_multisite() && !empty($plugins));
        foreach ($plugins as $plugin) {
            $maintenance = $maintenance || (is_plugin_active($plugin) && isset($current->response[$plugin]));
        }

        if ($maintenance) {
            $this->maintenance_mode(true);
        }

        $results = array();

        $this->update_count   = count($plugins);
        $this->update_current = 0;
        foreach ($plugins as $plugin => $pluginInfo) {
            $this->update_current++;

            $plugin_slug = $pluginInfo['slug'];

            $plugin_version = $pluginInfo['version'];

            if ($plugin_version == "latest") {
                if (!isset($current->response[$plugin])) {
                    $this->skin->set_result('up_to_date');
                    $this->skin->before();
                    $this->skin->feedback('up_to_date');
                    $this->skin->after();
                    $results[$plugin] = true;
                    continue;
                }

                // Get the URL to the zip file.
                $r = $current->response[$plugin];

                $this->skin->plugin_active = is_plugin_active($plugin);

                $result = $this->run(
                    array(
                        'package'           => $r->package,
                        'destination'       => WP_PLUGIN_DIR,
                        'clear_destination' => true,
                        'clear_working'     => true,
                        'is_multi'          => true,
                        'hook_extra'        => array(
                            'plugin' => $plugin,
                        ),
                    )
                );

                $results[$plugin] = $this->result;
            } else {
                $download_endpoint = 'https://downloads.wordpress.org/plugin/';

                $url = $download_endpoint . $plugin_slug . '.' . $plugin_version . '.zip';

                $result = $this->run(array(
                    'package'           => $url,
                    'destination'       => WP_PLUGIN_DIR,
                    'clear_destination' => true,
                    'clear_working'     => true,
                    'is_multi'          => true,
                    'hook_extra'        => array(
                        'plugin' => $plugin,
                        'type'   => 'plugin',
                        'action' => 'update',
                    ),
                ));

                $results[$plugin] = $this->result;
            }

            // Prevent credentials auth screen from displaying multiple times.
            if (false === $result) {
                break;
            }
        }

        $this->maintenance_mode(false);

        // Force refresh of plugin update information.
        wp_clean_plugins_cache($parsed_args['clear_update_cache']);

        /** This action is documented in wp-admin/includes/class-wp-upgrader.php */
    /*
        do_action(
            'upgrader_process_complete',
            $this,
            array(
                'action'  => 'update',
                'type'    => 'plugin',
                'bulk'    => true,
                'plugins' => $plugins,
            )
        );
        

        $this->skin->bulk_footer();

        $this->skin->footer();
        

        // Cleanup our hooks, in case something else does a upgrade on this connection.
        remove_filter('upgrader_clear_destination', array($this, 'delete_old_plugin'));

        // Ensure any future auto-update failures trigger a failure email by removing
        // the last failure notification from the list when plugins update successfully.
        $past_failure_emails = get_option('auto_plugin_theme_update_emails', array());

        foreach ($results as $plugin => $result) {
            // Maintain last failure notification when plugins failed to update manually.
            if (!$result || is_wp_error($result) || !isset($past_failure_emails[$plugin])) {
                continue;
            }

            unset($past_failure_emails[$plugin]);
        }


        update_option('auto_plugin_theme_update_emails', $past_failure_emails);

        // Force refresh of plugin update information.
        wp_clean_plugins_cache($parsed_args['clear_update_cache']);

        return $results;
    }
    */
}
