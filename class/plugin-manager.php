<?php


require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
include_once 'rollback-plugin-upgrader.php';


class HostManagerQuietSkin extends \WP_Upgrader_Skin
{
    public function feedback($string, ...$args)
    { /* no output */
    }
}

class PluginUpgrade
{

    // List plugins
    public function faaaster_plugin_list($request)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        $data = array(
            "code" => "ok",
            "data" =>  array("json" => json_encode($all_plugins))
        );

        return new WP_REST_Response($data, 200);
    }
    // Reinstall plugins from wp.org
    public function reinstall_plugins()
    {
        exec('wp plugin --force --skip-plugins --skip-themes install $(wp plugin list --force --skip-plugins --skip-themes --field=name | grep -v "nginx-helper") --force', $output, $return_var);
        if ($return_var !== 0) {
            // Handle error
            echo "Error executing command: " . implode("\n", $output);
            $data = array(
                "code" => "ko",
                "error" => json_encode($output),
            );
            return new WP_REST_Response($data, 200);
        } else {
            // Command executed successfully
            echo "Command executed successfully: " . implode("\n", $output);
            $data = array(
                "code" => "ok",
            );
            return new WP_REST_Response($data, 200);
        }
    }
    public function plugin_upgrade($request)
    {

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
                        // $plugin = $pluginName->update->plugin;
                        $plugin = $slug;
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

                    return new WP_REST_Response($data_for_response, 200);
                }


                $result = self::updateLatest($plugin);
            } else {
                $version = $param['version'];

                $pluginList = get_plugins();
                $pluginPath = "";

                foreach ($pluginList as $slug => $pluginName) {
                    $pluginSlug = strtok($slug, '/');
                    if ($pluginSlug == $plugin) {
                        $pluginPath = $slug;
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
        //$result = $upgrader->upgrade($plugin);
        // Do the upgrade

        $result = $upgrader->upgrade($plugin);

        return $result;
    }

    private static function updateCustomVersion($plugin, $pluginPath, $version)
    {


        $nonce = 'upgrade-plugin_' . $plugin;
        $url = 'index.php?page=hostmanager&plugin_file=' . $pluginPath . 'action=upgrade-plugin';

        $skin     = new Automatic_Upgrader_Skin(compact('nonce', 'url', 'plugin', 'version'));
        $upgrader = new WP_Custom_Plugin_Upgrader($skin);

        $result = $upgrader->rollback($pluginPath);

        return $result;
    }

    public function restToggle($request)
    {
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

            if (!isset($param['status'])) {
                $data_for_response = array(
                    "code"    => "no_status_given",
                    "message" => "Need to set status param (activate/disable)",
                    "data"    => array("status" => 500)
                );

                return new WP_REST_Response($data_for_response, 500);
            }

            $plugin = $param['plugin'];

            $status = $param['status'];

            if ($status == "activate") {
                exec('wp plugin activate --skip-plugins ' . $plugin, $output, $return_var);
                if ($return_var == 0) {
                    $data_for_response = array(
                        "code"    => "success",
                        "message" => $output[0],
                        "data"    => array("status" => 200, "res" => true)
                    );
                    return new WP_REST_Response($data_for_response, 200);
                } else {
                    $data_for_response = array(
                        "code"    => "error",
                        "message" => $output[0],
                        "data"    => array("status" => 500, "res" => false)
                    );
                    return new WP_REST_Response($data_for_response, 500);
                }
            } else if ($status == "disable") {
                exec('wp plugin deactivate --skip-plugins ' . $plugin, $output, $return_var);
                if ($return_var == 0) {
                    $data_for_response = array(
                        "code"    => "success",
                        "message" => $output[0],
                        "data"    => array("status" => 200, "res" => true)
                    );
                    return new WP_REST_Response($data_for_response, 200);
                } else {
                    $data_for_response = array(
                        "code"    => "error",
                        "message" => $output[0],
                        "data"    => array("status" => 500, "res" => false)
                    );
                    return new WP_REST_Response($data_for_response, 500);
                }
            }

            $data_for_response = array(
                "code"    => "wrong_status_param",
                "message" => "Status need to be either 'activate' or 'disable'.",
                "data"    => array("status" => 500)
            );

            return new WP_REST_Response($data_for_response, 500);
        } catch (Exception $e) {
            $data_for_response = array(
                "code"    => "unknown_error",
                "message" => $e->getMessage(),
                "data"    => array("status" => 500)
            );

            return new WP_REST_Response($data_for_response, 500);
        }
    }


    public function restInstall($request)
    {
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


            // this will download if needed and activate plugin
            $res = self::activate_plugin($plugin);

            if (!(is_bool($res))) {
                $data_for_response = array(
                    "code"    => "install_plugin_error",
                    "message" => $res,
                    "data"    => array("status" => 500)
                );

                return new WP_REST_Response($data_for_response, 500);
            }



            $data_for_response = array(
                "code"    => "success",
                "message" => "Successfully installed and activated plugin.",
                "data"    => array("status" => 200, "res" => $res)
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

    /**
     * Activates a given plugin.
     *
     * If needed it dowloads and/or installs the plugin first.
     *
     * @param string $slug The plugin's basename (containing the plugin's base directory and the bootstrap filename).
     * @return void
     */
    public function activate_plugin($plugin)
    {
        $pluginBaseName = self::is_plugin_installed($plugin);

        if ($pluginBaseName) {
            if (is_plugin_active($pluginBaseName)) {
                // Make sure the plugin is still there (files could be removed without wordpress noticing)
                $error = validate_plugin($pluginBaseName);
                if (!is_wp_error($error)) {
                    return true;
                }

                return $error;
            }
        } else {
            $error = self::install_plugin($plugin);
            if (!empty($error)) {
                return $error;
            }

            $pluginBaseName = self::is_plugin_installed($plugin);

            // Now we activate, when install has been successfull.
            if (!$pluginBaseName) {
                return 'Error: Plugin could not be installed (' . $plugin . '). '
                    . '<br>This probably means there is an error in the plugin basename, '
                    . 'or the plugin isn\'t in the wordpress repository on wordpress.org. '
                    . '<br>Please correct the problem, and/or install and activate the plugin manually.<br>'
                    . "\n";
            }
        }

        $plugin_mainfile = trailingslashit(WP_PLUGIN_DIR) . $pluginBaseName;

        $error = validate_plugin($pluginBaseName);
        if (is_wp_error($error)) {
            return 'Error: Plugin main file has not been found (' . $pluginBaseName . ').'
                . '<br/>This probably means the main file\'s name does not match the slug.'
                . '<br/>Please check the plugins listing in wp-admin.'
                . "<br>\n"
                . var_export($error->get_error_code(), true) . ': '
                . var_export($error->get_error_message(), true)
                . "\n";
        }
        $error = activate_plugin($plugin_mainfile);
        if (is_wp_error($error)) {
            return 'Error: Plugin has not been activated (' . $pluginBaseName . ').'
                . '<br/>This probably means the main file\'s name does not match the slug.'
                . '<br/>Check the plugins listing in wp-admin.'
                . "<br/>\n"
                . var_export($error->get_error_code(), true) . ': '
                . var_export($error->get_error_message(), true)
                . "\n";
        }

        return true;

        return true;
    }

    /**
     * Is plugin installed?
     *
     * Get_plugins() returns an array containing all installed plugins
     * with the plugin basename as key.
     *
     * When you pass the plugin dir to get_plugins(),
     * it will return an empty array if that plugin is not yet installed,
     *
     * When the plugin is installed it will return an array with that plugins data,
     * using the plugins main filename as key (so not the basename).
     *
     * @param  string  $plugin Plugin basename.
     * @return boolean         True when installed, otherwise false.
     */
    public function is_plugin_installed($plugin)
    {
        $plugins = get_plugins();
        foreach ($plugins as $slug => $pluginName) {
            $pluginSlug = strtok($slug, '/');
            if ($plugin == $pluginSlug) {
                return $slug;
            }
        }

        return false;
    }

    /**
     * Extraxts the plugins directory (=slug for api) from the plugin basename.
     *
     * @param string $plugin Plugin basename.
     * @return string        The directory-part of the plugin basename.
     */
    public function get_plugin_dir($plugin)
    {
        $chunks = explode('/', $plugin);
        if (!is_array($chunks)) {
            $plugin_dir = $chunks;
        } else {
            $plugin_dir = $chunks[0];
        }
        return $plugin_dir;
    }

    /**
     * Intall a given plugin.
     *
     * @param  string      $plugin Plugin basename.
     * @return null|string         Null when install was succesfull, otherwise error message.
     */
    public function install_plugin($plugin)
    {
        $api = plugins_api(
            'plugin_information',
            array(
                'slug'   => self::get_plugin_dir($plugin),
                'fields' => array(
                    'short_description' => false,
                    'requires'          => false,
                    'sections'          => false,
                    'rating'            => false,
                    'ratings'           => false,
                    'downloaded'        => false,
                    'last_updated'      => false,
                    'added'             => false,
                    'tags'              => false,
                    'compatibility'     => false,
                    'homepage'          => false,
                    'donate_link'       => false,
                ),
            )
        );

        // using HostManagerQuietSkin instead of Plugin_Installer_Skin for no output
        $skin      = new HostManagerQuietSkin(array('api' => $api));
        $upgrader  = new Plugin_Upgrader($skin);
        $error     = $upgrader->install($api->download_link);
        /*
     * Check for errors...
     * $upgrader->install() returns NULL on success,
     * otherwise a WP_Error object.
     */
        if (is_wp_error($error)) {
            return 'Error: Install process failed (' . $plugin . ').<br>'
                . "\n"
                . var_export($error->get_error_code(), true) . ': '
                . var_export($error->get_error_message(), true)
                . "\n";
        }
    }
}
