<?php

class FaaasterEventManager
{
    private $app_id;
    private $branch;
    private $wp_api_key;
    private $api_base;

    public function __construct($app_id, $branch, $wp_api_key, $api_base)
    {
        $this->app_id = $app_id;
        $this->branch = $branch;
        $this->wp_api_key = $wp_api_key;
        $this->api_base = $api_base;
    }

    public function init()
    {
        if ($this->app_id && $this->wp_api_key && $this->branch) {
            add_action('upgrader_process_complete', [$this, 'updaterUpdatedAction'], 10, 2);
            add_action('activated_plugin', [$this, 'pluginActivated']);
            add_action('deactivated_plugin', [$this, 'pluginDeactivated']);
            add_action('switch_theme', [$this, 'themeDeactivationAction'], 10, 2);
        }
    }

    /**
     * Handle component updates
     */
    public function updaterUpdatedAction($upgrader_object, $options)
    {
        // Get the update action (core, plugin, or theme)
        $action = $options['action'];
        // Get the update type (update, install, or delete)
        $type = $options['type'];

        if ($action === "update") {
            $timestamp = time();
            update_option('faaaster_bust_timestamp', $timestamp);

            // Get the user information
            $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";
            // Format the date and time
            $date_time = current_time('mysql');
            // initialize components
            $components = [];

            // Check for different update types
            if ($type === 'plugin') {
                if (isset($options['bulk']) && $options['bulk'] == "true") {
                    foreach ($options['plugins'] as $each_plugin) {
                        $plugin = get_plugin_data(WP_CONTENT_DIR . "/plugins/" . $each_plugin);
                        $old_version = $upgrader_object->skin->plugin_info['Version'];
                        $name = $plugin["Name"];
                        $new_version = $plugin["Version"];
                        $plugin = $plugin["Name"] . " - " . $old_version . " >> " . $plugin["Version"];
                        $components[] = $plugin;
                    }
                } else {
                    $plugin = get_plugin_data(WP_CONTENT_DIR . "/plugins/" . $options['plugin']);
                    $old_version = $upgrader_object->skin->plugin_info['Version'];
                    $name = $plugin["Name"];
                    $new_version = $plugin["Version"];
                    $plugin = $plugin["Name"] . " - " . $old_version . " >> " . $plugin["Version"];
                    $components[] = $plugin;
                }
            } elseif ($type === 'theme') {
                if (isset($options['bulk']) && $options['bulk'] == "true") {
                    foreach ($options['themes'] as $each_theme) {
                        $theme = wp_get_theme($each_theme);
                        $old_version = get_transient('theme_' . $each_theme . '_old_version');
                        $name = $theme["Name"];
                        $new_version = $theme["Version"];
                        $theme = $old_version ? $theme["Name"] . " - " . $old_version . " >> " . $theme["Version"] :  $theme["Name"] . " - " . $theme["Version"];
                        $components[] = $theme;
                    }
                } else {
                    $theme =  wp_get_theme($options['theme']);
                    $old_version = get_transient('theme_' . $options['theme'] . '_old_version');
                    $name = $theme["Name"];
                    $new_version = $theme["Version"];
                    $theme = $old_version ? $theme["Name"] . " - " . $old_version . " >> " . $theme["Version"] :  $theme["Name"] . " - " . $theme["Version"];
                    $components[] = $theme;
                }
            } elseif ($type === 'core' || $type === 'translation') {
                return;
            }

            $this->sendWebhookEvent("upgrader", [
                'action' => $action,
                'type' => $type,
                'components' => $components,
                'name' => $name,
                'old_version' => $old_version,
                'new_version' => $new_version,
                'user' => $user->user_email,
                'date' => $date_time,
            ]);
        }
    }

    /**
     * Handle plugin activation
     */
    public function pluginActivated($plugin)
    {
        $this->pluginActivateAction($plugin, 'activate');
    }

    /**
     * Handle plugin deactivation
     */
    public function pluginDeactivated($plugin)
    {
        $this->pluginActivateAction($plugin, 'deactivate');
    }

    /**
     * Handle plugin activation/deactivation
     */
    private function pluginActivateAction($plugin, $action)
    {
        // Get the user information
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";
        // Format the date and time
        $date_time = current_time('mysql');
        $plugin_data = get_plugin_data(WP_CONTENT_DIR . "/plugins/" . $plugin);
        $components = $plugin_data["Name"] . " - " . $plugin_data["Version"];

        $this->sendWebhookEvent($action, [
            'type' => "plugin",
            'components' => $components,
            'name' => $plugin_data["Name"],
            'version' => $plugin_data["Version"],
            'user' => $user->user_email,
            'date' => $date_time,
        ]);
    }

    /**
     * Handle theme switching
     */
    public function themeDeactivationAction($new_theme, $old_theme)
    {
        // Get the user information
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : "";
        // Format the date and time
        $date_time = current_time('mysql');

        $this->sendWebhookEvent("switch_theme", [
            'type' => "theme",
            'components' => [
                'new' => $new_theme,
                'old' => $old_theme,
            ],
            'user' => $user->user_email,
            'date' => $date_time,
        ]);
    }

    /**
     * Send webhook event to API
     */
    private function sendWebhookEvent($event, $data)
    {
        $url = $this->api_base . "/api/webhook-event/";
        $payload = [
            'event' => $event,
            'data' => $data,
            'app_id' => $this->app_id,
            'instance' => $this->branch,
        ];

        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->wp_api_key,
            ],
        ];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            error_log("Failed to send webhook event: " . $response->get_error_message());
        }
    }
}
