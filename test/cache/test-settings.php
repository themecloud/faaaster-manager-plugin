<?php

fc_section('settings');

faaaster_test_reset_wp();
$GLOBALS['faaaster_test']['site_options']['rt_wp_nginx_helper_options'] = array(
    'purge_url' => "/landing/\r\nhttps://example.com/promo/\n/wild/*\nnot-a-path",
);
$s = new FaaasterCacheSettings();
fc_check('fork purge_url imported once (paths only, no wildcard)', $s->get('purge', 'always_paths'), array('/landing/', '/promo/'));
fc_check('option created', is_array(get_option(FaaasterCacheSettings::OPTION)), true);

$GLOBALS['faaaster_test']['site_options']['rt_wp_nginx_helper_options'] = array('purge_url' => '/other/');
$s2 = new FaaasterCacheSettings();
fc_check('import does not run again', $s2->get('purge', 'always_paths'), array('/landing/', '/promo/'));

fc_check('defaults merged', $s->get('ttl', 'donotcachepage'), true);

$saved = $s->update_section('ttl', array(
    'rules' => array('front_page' => '3600', 'singular:post' => 999999999, 'bad key!' => 10, 'search' => ''),
    'donotcachepage' => '1',
    'nonce_cap' => '',
    'diagnostic' => 'on',
));
fc_check('ttl numeric cast', $saved['rules']['front_page'], 3600);
fc_check('ttl capped at 7 days', $saved['rules']['singular:post'], FaaasterCacheSettings::MAX_TTL);
fc_check('ttl invalid key dropped', isset($saved['rules']['bad key!']), false);
fc_check('ttl empty = default nginx (no rule)', isset($saved['rules']['search']), false);
fc_check('ttl flag off', $saved['nonce_cap'], false);

$saved = $s->update_section('purge', array(
    'post_types' => array(
        'post' => array('enabled' => '1', 'homepage' => '1', 'paged' => 99, 'taxonomies' => array('category', 'Bad Tax'), 'custom_paths' => "/a/\n/b c/"),
        'Bad Type!' => array('enabled' => 1),
    ),
    'always_paths' => '/x/',
    'escalate_threshold' => 1,
));
fc_check('paged clamped to 10', $saved['post_types']['post']['paged'], 10);
fc_check('invalid taxonomy dropped', $saved['post_types']['post']['taxonomies'], array('category'));
fc_check('custom path with space dropped', $saved['post_types']['post']['custom_paths'], array('/a/'));
fc_check('invalid post type dropped', isset($saved['post_types']['Bad Type!']), false);
fc_check('threshold floor 10', $saved['escalate_threshold'], 10);

fc_check('merge: assoc merges, lists replace', FaaasterCacheSettings::merge(
    array('a' => array('x' => 1, 'y' => 2), 'l' => array('p')),
    array('a' => array('y' => 3), 'l' => array('q', 'r'))
), array('a' => array('x' => 1, 'y' => 3), 'l' => array('q', 'r')));
