<?php

fc_section('content listener');

// Enregistrement : un seul calcul sur wp_after_insert_post (pas de double purge).
list($cache, $t) = faaaster_test_boot();
fc_check('wp_after_insert_post hooked at 99', has_action('wp_after_insert_post') !== false, true);
fc_check('no transition_post_status hook (fork double purge)', has_action('transition_post_status'), false);

// Publication d'un article : cascade complète, une fois.
list($cache, $t) = faaaster_test_boot();
ft_term(1, 'category', 'news');
$post = ft_post(array('ID' => 20, 'post_name' => 'launch'));
$GLOBALS['ft']['object_terms'][20] = array('category' => array(1));
$before = new WP_Post(array('ID' => 20, 'post_name' => 'launch', 'post_status' => 'draft'));
do_action('wp_after_insert_post', 20, $post, true, $before);
do_action('shutdown');
fc_check('publish: post + home + category purged', in_array('/launch/', $t->paths(), true) && in_array('/', $t->paths(), true) && in_array('/category/news/', $t->paths(), true), true);

// Changement de slug : ancien ET nouveau permalien.
list($cache, $t) = faaaster_test_boot();
$before = new WP_Post(array('ID' => 21, 'post_name' => 'old-slug'));
$after = ft_post(array('ID' => 21, 'post_name' => 'new-slug'));
do_action('post_updated', 21, $after, $before);
do_action('wp_after_insert_post', 21, $after, true, $before);
do_action('shutdown');
fc_check('slug change purges old and new', in_array('/old-slug/', $t->paths(), true) && in_array('/new-slug/', $t->paths(), true), true);

// Dépublication : ancien permalien purgé.
list($cache, $t) = faaaster_test_boot();
$before = new WP_Post(array('ID' => 22, 'post_name' => 'gone'));
$after = ft_post(array('ID' => 22, 'post_name' => 'gone', 'post_status' => 'draft'));
do_action('post_updated', 22, $after, $before);
do_action('wp_after_insert_post', 22, $after, true, $before);
do_action('shutdown');
fc_check('unpublish purges the old URL', in_array('/gone/', $t->paths(), true), true);

// Corbeille : URL calculée sur l'état d'avant (sans __trashed).
list($cache, $t) = faaaster_test_boot();
$before = new WP_Post(array('ID' => 23, 'post_name' => 'bin'));
$after = ft_post(array('ID' => 23, 'post_name' => 'bin__trashed', 'post_status' => 'trash'));
do_action('post_updated', 23, $after, $before);
do_action('wp_after_insert_post', 23, $after, true, $before);
do_action('shutdown');
fc_check('trash purges the pre-trash URL', in_array('/bin/', $t->paths(), true) && !in_array('/bin__trashed/', $t->paths(), true), true);

// Brouillon modifié : aucune purge.
list($cache, $t) = faaaster_test_boot();
$before = new WP_Post(array('ID' => 24, 'post_status' => 'draft', 'post_name' => 'wip'));
$after = ft_post(array('ID' => 24, 'post_status' => 'draft', 'post_name' => 'wip'));
do_action('wp_after_insert_post', 24, $after, true, $before);
do_action('shutdown');
fc_check('draft edit purges nothing', count($t->calls), 0);

// Publication programmée (cron, wp_publish_post) : future → publish.
list($cache, $t) = faaaster_test_boot();
$before = new WP_Post(array('ID' => 25, 'post_status' => 'future', 'post_name' => 'scheduled'));
$after = ft_post(array('ID' => 25, 'post_name' => 'scheduled'));
do_action('wp_after_insert_post', 25, $after, true, $before);
do_action('shutdown');
fc_check('scheduled publish purges', in_array('/scheduled/', $t->paths(), true), true);

// Termes retirés pendant l'édition (diff set_object_terms ; émis même sans changement).
list($cache, $t) = faaaster_test_boot();
$old = ft_term(5, 'category', 'old-cat');
ft_term(6, 'category', 'new-cat');
$post = ft_post(array('ID' => 26, 'post_name' => 'moved'));
$GLOBALS['ft']['object_terms'][26] = array('category' => array(6));
do_action('set_object_terms', 26, array(6), array(1006), 'category', false, array(1006));
do_action('set_object_terms', 26, array(6), array(1006), 'category', false, array(1005));
do_action('wp_after_insert_post', 26, $post, true, $post);
do_action('shutdown');
fc_check('removed term purged', in_array('/category/old-cat/', $t->paths(), true), true);
fc_check('new term purged', in_array('/category/new-cat/', $t->paths(), true), true);

// Exclusions : révision, auto-draft, nav_menu_item, type non visible.
list($cache, $t) = faaaster_test_boot();
foreach (array(
    array('ID' => 27, 'post_type' => 'revision'),
    array('ID' => 28, 'post_status' => 'auto-draft'),
    array('ID' => 29, 'post_type' => 'nav_menu_item'),
    array('ID' => 30, 'post_type' => 'shop_order'),
) as $props) {
    $p = ft_post($props + array('post_name' => 'x' . $props['ID']));
    do_action('wp_after_insert_post', $p->ID, $p, true, $p);
}
do_action('shutdown');
fc_check('revision/auto-draft/menu item/private type ignored', count($t->calls), 0);

// Type global (gabarits, styles) : purge totale.
list($cache, $t) = faaaster_test_boot();
$tpl = ft_post(array('ID' => 31, 'post_type' => 'wp_template_part', 'post_name' => 'header'));
do_action('wp_after_insert_post', 31, $tpl, true, $tpl);
do_action('shutdown');
fc_check('global post type → purge-all', $t->count('all'), 1);
list($cache, $t) = faaaster_test_boot();
$tpl = ft_post(array('ID' => 32, 'post_type' => 'elementor_library', 'post_name' => 'kit'));
do_action('wp_after_insert_post', 32, $tpl, true, $tpl);
do_action('shutdown');
fc_check('elementor template → purge-all', $t->count('all'), 1);

// Page parente dont le slug change, avec enfants → purge totale.
list($cache, $t) = faaaster_test_boot();
$GLOBALS['ft']['children'][33] = 34;
$before = new WP_Post(array('ID' => 33, 'post_type' => 'page', 'post_name' => 'services'));
$after = ft_post(array('ID' => 33, 'post_type' => 'page', 'post_name' => 'offres'));
do_action('wp_after_insert_post', 33, $after, true, $before);
do_action('shutdown');
fc_check('parent page slug change with children → purge-all', $t->count('all'), 1);

// Suppression définitive (EMPTY_TRASH_DAYS = 0).
list($cache, $t) = faaaster_test_boot();
$post = ft_post(array('ID' => 35, 'post_name' => 'deleted'));
do_action('before_delete_post', 35, $post);
do_action('shutdown');
fc_check('hard delete purges the post', in_array('/deleted/', $t->paths(), true), true);

// Pièce jointe : seulement la page d'attachement, selon l'option.
list($cache, $t) = faaaster_test_boot();
ft_post(array('ID' => 36, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'photo'));
do_action('edit_attachment', 36);
do_action('shutdown');
fc_check('attachment page purged', $t->paths(), array('/photo/'));
list($cache, $t) = faaaster_test_boot();
update_option('wp_attachment_pages_enabled', 0);
ft_post(array('ID' => 36, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'photo'));
do_action('edit_attachment', 36);
do_action('shutdown');
fc_check('attachment pages disabled → nothing', count($t->calls), 0);

// Commentaires : bon comment_post_ID, approuvés seulement, pages de commentaires.
list($cache, $t) = faaaster_test_boot();
ft_post(array('ID' => 40, 'post_name' => 'discuss'));
do_action('wp_insert_comment', 900, (object) array('comment_post_ID' => 40, 'comment_approved' => '1'));
do_action('shutdown');
fc_check('approved comment purges its post (not post #comment_id)', $t->paths(), array('/discuss/'));
list($cache, $t) = faaaster_test_boot();
ft_post(array('ID' => 40, 'post_name' => 'discuss'));
do_action('wp_insert_comment', 901, (object) array('comment_post_ID' => 40, 'comment_approved' => '0'));
do_action('shutdown');
fc_check('pending comment purges nothing', count($t->calls), 0);
list($cache, $t) = faaaster_test_boot();
ft_post(array('ID' => 40, 'post_name' => 'discuss'));
do_action('transition_comment_status', 'trash', 'approved', (object) array('comment_post_ID' => 40, 'comment_approved' => 'trash'));
do_action('shutdown');
fc_check('approved → trash purges', $t->paths(), array('/discuss/'));
list($cache, $t) = faaaster_test_boot();
update_option('page_comments', 1);
update_option('comments_per_page', 10);
$GLOBALS['ft']['comment_counts'][40] = 25;
ft_post(array('ID' => 40, 'post_name' => 'discuss'));
do_action('deleted_comment', '902', (object) array('comment_post_ID' => 40, 'comment_approved' => '1'));
do_action('shutdown');
fc_check('paged comments → comment pages purged', $t->paths(), array('/discuss/', '/discuss/comment-page-1/', '/discuss/comment-page-2/', '/discuss/comment-page-3/'));
list($cache, $t) = faaaster_test_boot();
$cache->settings()->update_section('purge', array('comments' => 0));
ft_post(array('ID' => 40, 'post_name' => 'discuss'));
do_action('wp_insert_comment', 903, (object) array('comment_post_ID' => 40, 'comment_approved' => '1'));
do_action('shutdown');
fc_check('comments setting off → nothing', count($t->calls), 0);

// Termes : édition simple vs changement de slug ; suppression.
list($cache, $t) = faaaster_test_boot();
ft_term(7, 'category', 'tips');
do_action('edit_terms', 7, 'category');
do_action('edited_term', 7, 1007, 'category');
do_action('shutdown');
fc_check('term edit → term + pages + home', $t->paths(), array('/', '/category/tips/', '/category/tips/page/2/', '/category/tips/page/3/'));
list($cache, $t) = faaaster_test_boot();
$term = ft_term(8, 'category', 'before');
do_action('edit_terms', 8, 'category');
$term->slug = 'after';
do_action('edited_term', 8, 1008, 'category');
do_action('shutdown');
fc_check('term slug change → purge-all', $t->count('all'), 1);
list($cache, $t) = faaaster_test_boot();
ft_term(9, 'category', 'doomed');
do_action('pre_delete_term', 9, 'category');
unset($GLOBALS['ft']['terms'][9]);
do_action('delete_term', 9, 1009, 'category');
do_action('shutdown');
fc_check('term delete → old link + home', in_array('/category/doomed/', $t->paths(), true) && in_array('/', $t->paths(), true), true);
list($cache, $t) = faaaster_test_boot();
do_action('edit_terms', 50, 'nav_menu');
do_action('edited_term', 50, 1050, 'nav_menu');
do_action('shutdown');
fc_check('non-viewable taxonomy (nav_menu) ignored here', count($t->calls), 0);

// Auteur renommé : ancienne et nouvelle archive.
list($cache, $t) = faaaster_test_boot();
$GLOBALS['ft']['users'][1] = 'alice-new';
do_action('profile_update', 1, (object) array('user_nicename' => 'alice'));
do_action('shutdown');
fc_check('author rename purges both archives', $t->paths(), array('/author/alice-new/', '/author/alice/'));
list($cache, $t) = faaaster_test_boot();
do_action('profile_update', 2, (object) array('user_nicename' => 'bob'));
do_action('shutdown');
fc_check('author without posts → nothing', count($t->calls), 0);

// Articles épinglés.
list($cache, $t) = faaaster_test_boot();
do_action('updated_option', 'sticky_posts', array(), array(1));
do_action('shutdown');
fc_check('sticky_posts → home', $t->paths(), array('/'));
