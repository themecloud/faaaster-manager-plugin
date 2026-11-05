<?php

// The theme_upgrade route answers JSON: its skin must print nothing, or the
// bridge reads a successful update as a broken response.
// Run: php test/theme-quiet-skin.php

$abspath = sys_get_temp_dir() . '/theme-quiet-skin-' . getmypid() . '/';
mkdir($abspath . 'wp-admin/includes', 0777, true);
foreach (['class-wp-upgrader', 'theme', 'misc', 'file', 'update'] as $file) {
    file_put_contents($abspath . "wp-admin/includes/{$file}.php", '<?php');
}
define('ABSPATH', $abspath);

// What WordPress' base skin prints around an upgrade.
class WP_Upgrader_Skin
{
    public function header()
    {
        echo '<div class="wrap"><h1></h1>';
    }

    public function footer()
    {
        echo '</div>';
    }

    public function feedback($string, ...$args)
    {
        echo "<p>{$string}</p>";
    }

    public function decrement_update_count($type)
    {
        echo '<script></script>';
    }
}

require_once __DIR__ . '/../class/theme-manager.php';

ob_start();
$skin = new HostManagerQuietSkinTheme();
$skin->header();
$skin->feedback('Downloading update');
$skin->decrement_update_count('theme');
$skin->footer();
$output = ob_get_clean();

array_map('unlink', glob($abspath . 'wp-admin/includes/*.php'));
rmdir($abspath . 'wp-admin/includes');
rmdir($abspath . 'wp-admin');
rmdir($abspath);

if ($output !== '') {
    fwrite(STDERR, "FAIL the skin printed: {$output}\n");
    exit(1);
}
echo "OK the skin prints nothing\n";
