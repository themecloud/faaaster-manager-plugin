<?php
class CoreUpgrade
{
    public function core_update(array $args = []): array
    {
        $args = wp_parse_args($args, [
            'locale'  => get_locale(),
            'version' => get_bloginfo('version')
        ]);
       
        //$args['locale']="fr_FR";
        // error_log("version ".$args['version']);
        // error_log("locale ".$args['locale']);
        if (!function_exists('find_core_update')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('show_message')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $update = find_core_update($args['version'], $args['locale']);        
        if (!$update) {
            return [
                'message' => esc_html('Update not found!'),
                'status'  => false,
            ];
        }

        /*
     * Allow relaxed file ownership writes for User-initiated upgrades when the API specifies
    * that it's safe to do so. This only happens when there are no new files to create.
    */
        $allow_relaxed_file_ownership = isset($update->new_files) && !$update->new_files;

        if (!class_exists('WP_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        if (!class_exists('Core_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
        }

        if (!class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Core_Upgrader($skin);
        $result   = $upgrader->upgrade($update, [
            'allow_relaxed_file_ownership' => $allow_relaxed_file_ownership,
        ]);

        if (is_wp_error($result)) {
            if ($result->get_error_data() && is_string($result->get_error_data())) {
                $error_message = $result->get_error_message() . ': ' . $result->get_error_data();
            } else {
                $error_message = $result->get_error_message();
            }

            if ('up_to_date' !== $result->get_error_code() && 'locked' !== $result->get_error_code()) {
                $error_message = __('Installation failed.');
            }
        }

        $message = isset($error_message) ? trim($error_message) : '';

        return [
            'message' => empty($message) ? esc_html('Success!') : $message,
            'status'  => empty($message),
        ];
    }
}
