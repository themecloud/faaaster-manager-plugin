<?php


class PluginUpgrade
{

    public function plugin_upgrade($request)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $param = $request->get_query_params();

        try {

            if (!isset($param['plugin'])) {
                $data_for_response = array(
                    "code"    => "no_plugin_given",
                    "message" => "Need to set plugin param",
                    "data"    => array("status" => 500)
                );

                return new WP_REST_Response($data_for_response, 500);
            }
            $plugin = $param['plugin'];

            wp_update_plugins();

            $current = get_site_transient('update_plugins');


            if (!isset($param['version']) || $param['version'] == "latest") {

                $pluginUpdates = get_plugin_updates();

                $foundPlugin = false;

                // no plugins needs to be updated
                if (empty($pluginUpdates)) {
                    $data_for_response = array(
                        "code"    => "already_updated",
                        "message" => "All plugins are on their latest version.",
                        "data"    => array("status" => 500)
                    );

                    return new WP_REST_Response($data_for_response, 500);
                }

                // get the full path
                foreach ($pluginUpdates as $slug => $pluginName) {
                    if ($plugin == $pluginName->update->slug) {
                        $plugin = $pluginName->update->plugin;
                        $foundPlugin = true;
                    };
                }

                // some plugins needs to be updated, but not this one
                if (!$foundPlugin) {
                    $data_for_response = array(
                        "code"    => "already_updated",
                        "message" => "This plugin is already at the latest version.",
                        "data"    => array("status" => 500)
                    );

                    return new WP_REST_Response($data_for_response, 500);
                }


                $result = self::updateLatest($plugin);
            } else {
                $version = $param['version'];

                $pluginList = get_plugins();
                $pluginPath = "";

                foreach ($pluginList as $pluginFile => $pluginValue) {
                    if ($pluginValue['TextDomain'] == $plugin) {
                        $pluginPath = $pluginFile;
                    }
                }

                if ($pluginPath == "") {
                    $data_for_response = array(
                        "code"    => "plugin_not_found",
                        "message" => "Plugin not found.",
                        "data"    => array("status" => 500)
                    );

                    return new WP_REST_Response($data_for_response, 500);
                }


                $result = self::updateCustomVersion($plugin, $pluginPath, $version);
            }


            if (is_wp_error($result)) {
                $data_for_response = array(
                    "code"    => $result->get_error_code(),
                    "message" => $result->get_error_message(),
                    "data"    => array("status" => 500)
                );

                return new WP_REST_Response($data_for_response, 500);
            }

            if (!$result) {
                $data_for_response = array(
                    "code"    => "unable_to_upgrade_plugin",
                    "message" => "No result from Plugin Upgrade.",
                    "data"    => array("status" => 500, "plugin name" => $plugin, "current" => $current, "plugins" => get_plugins()),
                );

                return new WP_REST_Response($data_for_response, 500);
            }

            $data_for_response = array(
                "code" => "ok",
                "message" => "Succesfully updated plugin."
            );

            return new WP_REST_Response($data_for_response, 200);
        } catch (Exception $e) {
            $data_for_response = array(
                "code"    => "unknown_error",
                "message" => $e->getMessage(),
                "data"    => array("status" => 500)
            );

            return new WP_REST_Response($data_for_response, 500);
        }
    }

    private static function updateLatest($plugin)
    {

        $nonce = 'upgrade-plugin_' . $plugin;
        $url = 'update.php?action=upgrade-plugin&plugin=' . urlencode($plugin);

        $skin     = new Automatic_Upgrader_Skin(compact('nonce', 'url', 'plugin'));
        $upgrader = new Plugin_Upgrader($skin);

        $result = $upgrader->upgrade($plugin);

        return $result;
    }

    private static function updateCustomVersion($plugin, $pluginPath, $version)
    {

        include_once 'rollback-plugin-upgrader.php';

        $nonce = 'upgrade-plugin_' . $plugin;
        $url = 'index.php?page=hostmanager&plugin_file=' . $pluginPath . 'action=upgrade-plugin';


        $skin     = new Automatic_Upgrader_Skin(compact('nonce', 'url', 'plugin', 'version'));
        $upgrader = new WP_Custom_Plugin_Upgrader($skin);

        $result = $upgrader->rollback($pluginPath);

        return $result;
    }
}
