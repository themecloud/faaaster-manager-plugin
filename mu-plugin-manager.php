<?php

class MUPluginManager
{
    public function checkInstalled()
    {
        $mu_dir = (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) ? WPMU_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';
        $mu_dir = untrailingslashit($mu_dir);

        $mu_plugin = $mu_dir . "/benchmark-analysis.php";
        return file_exists($mu_plugin);
    }

    public function checkFolderExist()
    {
        $mu_dir = (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) ? WPMU_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';
        $mu_dir = untrailingslashit($mu_dir);

        // need to change on release

        $mu_plugin = $mu_dir . "/which-plugin-0.0.1";
        return file_exists($mu_plugin);
    }

    public function installPlugin()
    {
        $mu_dir = (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) ? WPMU_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';
        $mu_dir = untrailingslashit($mu_dir);

        $ch = curl_init();

        // just replace this zip file on bucket or change this URL on release
        $source = "https://storage.googleapis.com/themecloud-dev-manager/which-plugin-0.0.1.zip";
        curl_setopt($ch, CURLOPT_URL, $source);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $data = curl_exec($ch);
        curl_close($ch);

        $destination = "benchmark-analysis.zip";
        $file = fopen($destination, "w+");
        fputs($file, $data);
        fclose($file);

        $zip = new ZipArchive;
        $res = $zip->open($destination);

        if ($res === TRUE) {
            // directory is which-plugin-(version)
            $zip->extractTo($mu_dir);
            $zip->close();

            unlink($destination);
        } else {
            return "Failed to open zip archive: " . $res;
        }


        return true;
    }


    public function togglePlugin()
    {
        $mu_dir = (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) ? WPMU_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';
        $mu_dir = untrailingslashit($mu_dir);

        $source = plugin_dir_path(__FILE__) . "template/benchmark-analysis.php";
        $dest = $mu_dir . '/benchmark-analysis.php';

        if (!$this->checkFolderExist()) {
            $res = $this->installPlugin();

            if ($res != true) {
                return $res;
            }
        }

        if (!$this->checkInstalled()) {
            if (!copy($source, $dest)) {
                return "Can't copy the files.";
            }

            return "Successfully installed.";
        } else {
            if (!unlink($dest)) {
                return "Cannot remove file.";
            }

            return "Successfully uninstalled.";
        }

        return;
    }
}
