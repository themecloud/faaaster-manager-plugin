<?php
class CoreUpgrade
{
    public function reinstall_core($request)
    {
        exec('wp core download --skip-content --force --skip-plugins --skip-themes', $output, $return_var);
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
    public function core_update(array $args = []): array
    {
        $new_args = wp_parse_args($args, [
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

        // Fetch available core updates
        if ($args['version'] && $args['locale']) {
            // WIP doesn't work for rollbacks
            var_dump($args['version'] . ">> " . $args['locale']);
            $update = find_core_update($args['version'], $args['locale']);
        } else {
            $available_updates = get_core_updates();
            if ($new_args['version'] === get_bloginfo('version') && !empty($available_updates)) {
                foreach ($available_updates as $update) {
                    if ($update->response == 'upgrade' && version_compare($update->current, $args['version'], '>')) {
                        // If an upgrade is available and newer than the current version, use it
                        $new_args['version'] = $update->current;
                        break;
                    }
                }
            }

            $update = find_core_update($new_args['version'], $new_args['locale']);
        }
        if (!$update) {
            return [
                'message' => esc_html__('Update not found!'),
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
