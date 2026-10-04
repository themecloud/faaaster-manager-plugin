<?php

/**
 * Stubs WordPress « contenu » : articles, termes, auteurs, commentaires.
 * Permaliens simulés : /<slug>/, pages hiérarchiques /<parent>/<enfant>/,
 * termes /category/<slug>/ ou /<taxonomie>/<slug>/, auteurs /author/<nicename>/.
 */
class WP_Post
{
    public $ID = 0;
    public $post_type = 'post';
    public $post_status = 'publish';
    public $post_name = '';
    public $post_parent = 0;
    public $post_author = 1;
    public $post_date = '2026-10-04 10:00:00';

    public function __construct(array $props = array())
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class WP_Term
{
    public $term_id = 0;
    public $term_taxonomy_id = 0;
    public $taxonomy = 'category';
    public $slug = '';

    public function __construct(array $props = array())
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

function faaaster_test_reset_content()
{
    $GLOBALS['ft'] = array(
        'posts' => array(),
        'terms' => array(),
        'object_terms' => array(),
        'users' => array(1 => 'alice'),
        'user_posts' => array(1 => 3),
        'comments' => array(),
        'comment_counts' => array(),
        'children' => array(),
        'shop_page_id' => -1,
        'cpt_archives' => array('book' => '/books/'),
        'viewable_types' => array('post', 'page', 'product', 'book', 'attachment'),
        'hierarchical_types' => array('page'),
        'taxonomies' => array('post' => array('category', 'post_tag'), 'product' => array('product_cat', 'product_tag'), 'book' => array('genre')),
        'viewable_taxonomies' => array('category', 'post_tag', 'product_cat', 'product_tag', 'genre'),
        'logged_in' => false,
        'is_admin' => false,
    );
}
faaaster_test_reset_content();

function ft_post(array $props)
{
    $post = new WP_Post($props);
    $GLOBALS['ft']['posts'][$post->ID] = $post;
    return $post;
}

function ft_term($id, $taxonomy, $slug)
{
    $term = new WP_Term(array('term_id' => $id, 'term_taxonomy_id' => 1000 + $id, 'taxonomy' => $taxonomy, 'slug' => $slug));
    $GLOBALS['ft']['terms'][$id] = $term;
    return $term;
}

function wp_after_insert_post()
{
}

function get_post($post = null)
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    return isset($GLOBALS['ft']['posts'][(int) $post]) ? $GLOBALS['ft']['posts'][(int) $post] : null;
}

function get_permalink($post = 0)
{
    $post = get_post($post);
    if (!$post) {
        return false;
    }
    $path = $post->post_name;
    $parent = (int) $post->post_parent;
    while ($parent && isset($GLOBALS['ft']['posts'][$parent])) {
        $path = $GLOBALS['ft']['posts'][$parent]->post_name . '/' . $path;
        $parent = (int) $GLOBALS['ft']['posts'][$parent]->post_parent;
    }
    return home_url('/' . $path . '/');
}

function get_post_type_archive_link($type)
{
    return isset($GLOBALS['ft']['cpt_archives'][$type]) ? home_url($GLOBALS['ft']['cpt_archives'][$type]) : false;
}

function wc_get_page_id($page)
{
    return $GLOBALS['ft']['shop_page_id'];
}

function post_type_supports($type, $feature)
{
    return $feature === 'author' && $type !== 'page';
}

function is_post_type_viewable($type)
{
    return in_array($type, $GLOBALS['ft']['viewable_types'], true);
}

function is_post_type_hierarchical($type)
{
    return in_array($type, $GLOBALS['ft']['hierarchical_types'], true);
}

function is_taxonomy_viewable($taxonomy)
{
    return in_array($taxonomy, $GLOBALS['ft']['viewable_taxonomies'], true);
}

function get_object_taxonomies($type, $output = 'names')
{
    $names = isset($GLOBALS['ft']['taxonomies'][$type]) ? $GLOBALS['ft']['taxonomies'][$type] : array();
    if ($output !== 'objects') {
        return $names;
    }
    $objects = array();
    foreach ($names as $name) {
        $objects[$name] = (object) array('name' => $name, 'labels' => (object) array('name' => ucfirst($name)));
    }
    return $objects;
}

function get_the_terms($post_id, $taxonomy)
{
    if (empty($GLOBALS['ft']['object_terms'][$post_id][$taxonomy])) {
        return false;
    }
    $terms = array();
    foreach ($GLOBALS['ft']['object_terms'][$post_id][$taxonomy] as $id) {
        $terms[] = $GLOBALS['ft']['terms'][$id];
    }
    return $terms;
}

function get_term_link($term, $taxonomy = '')
{
    if (!($term instanceof WP_Term)) {
        $term = isset($GLOBALS['ft']['terms'][(int) $term]) ? $GLOBALS['ft']['terms'][(int) $term] : null;
    }
    if (!$term) {
        return new WP_Error('invalid_term', 'missing');
    }
    $base = $term->taxonomy === 'category' ? 'category' : ($term->taxonomy === 'post_tag' ? 'tag' : $term->taxonomy);
    return home_url('/' . $base . '/' . $term->slug . '/');
}

function get_term_by($field, $value, $taxonomy = '')
{
    foreach ($GLOBALS['ft']['terms'] as $term) {
        if ($field === 'term_taxonomy_id' && (int) $term->term_taxonomy_id === (int) $value) {
            return $term;
        }
    }
    return false;
}

function get_term_feed_link($term_id, $taxonomy = '')
{
    return trailingslashit(get_term_link($term_id, $taxonomy)) . 'feed/';
}

function get_author_posts_url($author_id, $nicename = '')
{
    $nicename = $nicename !== '' ? $nicename : (isset($GLOBALS['ft']['users'][$author_id]) ? $GLOBALS['ft']['users'][$author_id] : 'user' . $author_id);
    return home_url('/author/' . $nicename . '/');
}

function get_author_feed_link($author_id)
{
    return trailingslashit(get_author_posts_url($author_id)) . 'feed/';
}

function get_feed_link($feed = '')
{
    return home_url('/feed/');
}

function get_year_link($year)
{
    return home_url('/' . $year . '/');
}

function get_month_link($year, $month)
{
    return home_url(sprintf('/%d/%02d/', $year, $month));
}

function trailingslashit($value)
{
    return rtrim($value, '/\\') . '/';
}

function user_trailingslashit($url, $type = '')
{
    return trailingslashit($url);
}

function wp_is_post_revision($post)
{
    $post = get_post($post);
    return $post && $post->post_type === 'revision';
}

function wp_is_post_autosave($post)
{
    return false;
}

function get_children($args = array())
{
    $parent = isset($args['post_parent']) ? (int) $args['post_parent'] : 0;
    return !empty($GLOBALS['ft']['children'][$parent]) ? array($GLOBALS['ft']['children'][$parent]) : array();
}

function get_comment($id)
{
    return isset($GLOBALS['ft']['comments'][$id]) ? $GLOBALS['ft']['comments'][$id] : null;
}

function get_comments_number($post_id)
{
    return isset($GLOBALS['ft']['comment_counts'][$post_id]) ? $GLOBALS['ft']['comment_counts'][$post_id] : 0;
}

function count_user_posts($user_id)
{
    return isset($GLOBALS['ft']['user_posts'][$user_id]) ? $GLOBALS['ft']['user_posts'][$user_id] : 0;
}

function is_admin()
{
    return $GLOBALS['ft']['is_admin'];
}

function wp_doing_ajax()
{
    return false;
}

function is_user_logged_in()
{
    return $GLOBALS['ft']['logged_in'];
}
