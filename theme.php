<?php

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/update.php';

class HostManagerQuietSkinTheme extends \WP_Upgrader_Skin
{
    public function feedback($string, ...$args)
    { /* no output */
    }
}

class ThemeUpgrade
{

    public function theme_upgrade($request)
    {
        $param = $request->get_query_params();

        try {
            if (!isset($param['theme'])) {
                return new WP_REST_Response([
                    "code" => "no_theme_given",
                    "message" => "Need to set theme param",
                    "data" => ["status" => 400]
                ], 400);
            }

            $theme = $param['theme'];
            wp_update_themes(); // Force check for theme updates

            // For latest version, just use the Theme_Upgrader
            if (!isset($param['version']) || $param['version'] === "latest") {
                return $this->update_latest($theme);
            } else {
                // For specific version, more custom logic might be required
                // This example assumes you have a method for handling custom version updates
                $version = $param['version'];
                return $this->update_custom_version($theme, $version);
            }
        } catch (Exception $e) {
            return new WP_REST_Response([
                "code" => "unknown_error",
                "message" => $e->getMessage(),
                "data" => ["status" => 500]
            ], 500);
        }
    }

    private function update_latest($theme)
    {
        $upgrader = new Theme_Upgrader(new HostManagerQuietSkinTheme());
        $result = $upgrader->upgrade($theme);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                "code" => $result->get_error_code(),
                "message" => $result->get_error_message(),
                "data" => ["status" => 500]
            ], 500);
        }

        return new WP_REST_Response([
            "code" => "ok",
            "message" => "Successfully updated theme."
        ], 200);
    }

    private function update_custom_version($theme, $version)
    {
        // Custom version update logic here
        // This is a placeholder function. Implement your logic for updating to a specific version.
        return new WP_REST_Response([
            "code" => "ok",
            "message" => "Custom version update feature not implemented."
        ], 200);
    }

    // Toggle theme
    public function restToggle($request)
    {
        $param = $request->get_query_params();

        try {
            if (!isset($param['theme'])) {
                return new WP_REST_Response([
                    "code" => "no_theme_given",
                    "message" => "Need to set theme param",
                    "data" => ["status" => 400]
                ], 400);
            }

            $theme = $param['theme'];

            if (!array_key_exists($theme, wp_get_themes())) {
                return new WP_REST_Response([
                    "code" => "theme_not_found",
                    "message" => "The specified theme does not exist.",
                    "data" => ["status" => 404]
                ], 404);
            }

            switch_theme($theme);
            return new WP_REST_Response([
                "code" => "success",
                "message" => "Theme switched successfully.",
                "data" => ["status" => 200]
            ], 200);
        } catch (Exception $e) {
            return new WP_REST_Response([
                "code" => "unknown_error",
                "message" => $e->getMessage(),
                "data" => ["status" => 500]
            ], 500);
        }
    }

    // Reinstall themes
    public function restInstall($request)
    {
        $param = $request->get_query_params();

        try {
            if (!isset($param['theme'])) {
                return new WP_REST_Response([
                    "code" => "no_theme_given",
                    "message" => "Need to set theme param",
                    "data" => ["status" => 500]
                ], 500);
            }

            $theme = $param['theme'];

            // Assume the theme is from the WordPress.org repository for simplicity
            // For custom themes, you might need to adjust this process
            $upgrader = new Theme_Upgrader(new HostManagerQuietSkinTheme());
            $result = $upgrader->install("https://downloads.wordpress.org/theme/{$theme}.latest-stable.zip");

            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    "code" => $result->get_error_code(),
                    "message" => $result->get_error_message(),
                    "data" => ["status" => 500]
                ], 500);
            }

            return new WP_REST_Response([
                "code" => "success",
                "message" => "Theme installed successfully.",
                "data" => ["status" => 200]
            ], 200);
        } catch (Exception $e) {
            return new WP_REST_Response([
                "code" => "unknown_error",
                "message" => $e->getMessage(),
                "data" => ["status" => 500]
            ], 500);
        }
    }
}


