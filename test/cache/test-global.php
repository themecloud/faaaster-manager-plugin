<?php

fc_section('global triggers');

foreach (array(
    array('upgrader_process_complete', array(null, array('type' => 'plugin', 'action' => 'update'))),
    array('activated_plugin', array('x/x.php', false)),
    array('deactivated_plugin', array('x/x.php', false)),
    array('switch_theme', array('Twenty', null, null)),
    array('customize_save_after', array(null)),
    array('wp_update_nav_menu', array(3, array())),
    array('wp_delete_nav_menu', array(3)),
) as $case) {
    list($cache, $t) = faaaster_test_boot();
    call_user_func_array('do_action', array_merge(array($case[0]), $case[1]));
    do_action('shutdown');
    fc_check("{$case[0]} → one purge-all", $t->count('all'), 1);
}

// Plusieurs déclencheurs dans la même requête : une seule purge totale.
list($cache, $t) = faaaster_test_boot();
do_action('upgrader_process_complete', null, array('type' => 'plugin'));
do_action('activated_plugin', 'x/x.php', false);
do_action('shutdown');
fc_check('several triggers → still one purge-all', $t->count('all'), 1);

// Options surveillées vs non surveillées.
list($cache, $t) = faaaster_test_boot();
do_action('updated_option', 'blogname', 'a', 'b');
do_action('shutdown');
fc_check('blogname change → purge-all', $t->count('all'), 1);
list($cache, $t) = faaaster_test_boot();
do_action('updated_option', 'widget_text', array(), array(1));
do_action('shutdown');
fc_check('widget_* prefix → purge-all', $t->count('all'), 1);
list($cache, $t) = faaaster_test_boot();
do_action('updated_option', 'some_plugin_cache_counter', 1, 2);
do_action('shutdown');
fc_check('unrelated option → nothing', count($t->calls), 0);

// Écriture d'un theme_mod pendant la visite d'un anonyme : pas de purge totale.
list($cache, $t) = faaaster_test_boot();
$_SERVER['REQUEST_METHOD'] = 'GET';
do_action('updated_option', 'theme_mods_astra', array(), array(1));
do_action('shutdown');
fc_check('anonymous front GET option write suppressed', count($t->calls), 0);
list($cache, $t) = faaaster_test_boot();
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['ft']['logged_in'] = true;
do_action('updated_option', 'theme_mods_astra', array(), array(1));
do_action('shutdown');
fc_check('logged-in GET option write purges', $t->count('all'), 1);
list($cache, $t) = faaaster_test_boot();
$_SERVER['REQUEST_METHOD'] = 'POST';
do_action('added_option', 'theme_mods_astra', array(1));
do_action('shutdown');
fc_check('POST added_option purges', $t->count('all'), 1);
unset($_SERVER['REQUEST_METHOD']);

// Garde-fou anti-tempête : inactif sans APCu (cas du CLI de test), jamais sur une purge forcée.
fc_check('storm guard inactive without APCu', FaaasterCachePurgeQueue::storm_suppressed(), false);
