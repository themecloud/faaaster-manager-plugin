<?php

class FaaasterStaticManager
{
    /**
     * Enable Simply Static plugin
     */
    public function enableStatic()
    {
        $plugin_slug = 'simply-static';
        $plugin_path = 'simply-static/simply-static.php';

        // Check if the plugin is installed
        if (!file_exists(WP_CONTENT_DIR . "/plugins/" . $plugin_path)) {
            // Install the plugin
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

            $api = plugins_api('plugin_information', array('slug' => $plugin_slug));
            if (is_wp_error($api)) {
                return new WP_Error('plugin_error', 'Plugin information retrieval failed.');
            }

            $upgrader = new Plugin_Upgrader();
            $installed = $upgrader->install($api->download_link);

            if (is_wp_error($installed)) {
                return new WP_Error('install_failed', 'Plugin installation failed.');
            }
            // Attempt to activate the plugin
            activate_plugin($plugin_path);

            return rest_ensure_response(array('success' => true, 'message' => 'Plugin installed and activated.'));
        } else {
            if (!is_plugin_active($plugin_path)) {
                // Activate the plugin
                activate_plugin($plugin_path);

                return rest_ensure_response(array('success' => true, 'message' => 'Plugin activated.'));
            }
            // Plugin is already installed and active
            return rest_ensure_response(array('success' => true, 'message' => 'Plugin already installed and active.'));
        }
    }

    /**
     * Run static export
     */
    public function runStaticExport()
    {
        if (!class_exists('Simply_Static\Plugin')) {
            // If the class does not exist, return early
            return new WP_REST_Response('Static not enabled', 400);
        }

        // Full static export
        $simply_static = Simply_Static\Plugin::instance();
        $simply_static->run_static_export();
        return new WP_REST_Response('Static export launched', 200);
    }
}
