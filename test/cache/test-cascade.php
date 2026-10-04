<?php

fc_section('cascade');

function fc_urls(array $urls)
{
    $paths = array();
    foreach ($urls as $url) {
        $paths[] = parse_url($url, PHP_URL_PATH);
    }
    sort($paths);
    return $paths;
}

// Article : accueil (liste des articles), catégories, étiquettes, flux, pages 2-3.
list($cache) = faaaster_test_boot();
ft_term(1, 'category', 'news');
ft_term(2, 'post_tag', 'wp');
$post = ft_post(array('ID' => 10, 'post_name' => 'hello'));
$GLOBALS['ft']['object_terms'][10] = array('category' => array(1), 'post_tag' => array(2));
fc_check('post defaults (show_on_front=posts)', fc_urls($cache->cascade()->urls_for_post($post)), array(
    '/', '/category/news/', '/category/news/feed/', '/category/news/page/2/', '/category/news/page/3/',
    '/feed/', '/hello/', '/page/2/', '/page/3/', '/tag/wp/', '/tag/wp/feed/', '/tag/wp/page/2/', '/tag/wp/page/3/',
));

// Page d'accueil statique + page des articles.
list($cache) = faaaster_test_boot();
update_option('show_on_front', 'page');
update_option('page_for_posts', 5);
ft_post(array('ID' => 5, 'post_type' => 'page', 'post_name' => 'blog'));
$post = ft_post(array('ID' => 11, 'post_name' => 'solo'));
fc_check('post with static front page → posts page archive', fc_urls($cache->cascade()->urls_for_post($post)), array(
    '/', '/blog/', '/blog/page/2/', '/blog/page/3/', '/feed/', '/solo/',
));

// Ancien plugin : page_for_posts = 0 → aucune archive (corrigé : rien d'inventé).
list($cache) = faaaster_test_boot();
update_option('show_on_front', 'page');
update_option('page_for_posts', 0);
fc_check('no posts page → no archive', FaaasterCacheCascade::archive_url('post'), null);

// Produit WooCommerce : boutique + catégories produit.
list($cache) = faaaster_test_boot();
$GLOBALS['ft']['shop_page_id'] = 7;
ft_post(array('ID' => 7, 'post_type' => 'page', 'post_name' => 'shop'));
ft_term(3, 'product_cat', 'shoes');
$product = ft_post(array('ID' => 12, 'post_type' => 'product', 'post_name' => 'sneaker'));
$GLOBALS['ft']['object_terms'][12] = array('product_cat' => array(3));
fc_check('product defaults → shop + product_cat', fc_urls($cache->cascade()->urls_for_post($product)), array(
    '/', '/page/2/', '/page/3/', '/product_cat/shoes/', '/product_cat/shoes/page/2/', '/product_cat/shoes/page/3/',
    '/shop/', '/shop/page/2/', '/shop/page/3/', '/sneaker/',
));

// Page : seulement elle-même.
list($cache) = faaaster_test_boot();
$page = ft_post(array('ID' => 13, 'post_type' => 'page', 'post_name' => 'about'));
fc_check('page → itself only', fc_urls($cache->cascade()->urls_for_post($page)), array('/about/'));

// Type personnalisé : son archive.
list($cache) = faaaster_test_boot();
$book = ft_post(array('ID' => 14, 'post_type' => 'book', 'post_name' => 'dune'));
fc_check('custom type → its archive', fc_urls($cache->cascade()->urls_for_post($book)), array('/books/', '/dune/'));

// Réglages : surcharge par type + chemins toujours purgés.
list($cache) = faaaster_test_boot();
$cache->settings()->update_section('purge', array(
    'post_types' => array('page' => array('enabled' => 1, 'homepage' => 1, 'custom_paths' => array('/sitemap-html/'))),
    'always_paths' => array('/promo/'),
));
$page = ft_post(array('ID' => 15, 'post_type' => 'page', 'post_name' => 'team'));
fc_check('settings override + always paths', fc_urls($cache->cascade()->urls_for_post($page)), array('/', '/promo/', '/sitemap-html/', '/team/'));

// Cascade désactivée pour le type : seulement le contenu (+ chemins toujours purgés).
list($cache) = faaaster_test_boot();
$cache->settings()->update_section('purge', array('post_types' => array('post' => array('enabled' => 0))));
$post = ft_post(array('ID' => 16, 'post_name' => 'quiet'));
fc_check('cascade disabled → permalink only', fc_urls($cache->cascade()->urls_for_post($post)), array('/quiet/'));

// Auteur + dates (option).
list($cache) = faaaster_test_boot();
$cache->settings()->update_section('purge', array('post_types' => array('post' => array('enabled' => 1, 'author' => 1, 'date' => 1))));
$post = ft_post(array('ID' => 17, 'post_name' => 'dated', 'post_date' => '2026-03-15 09:00:00'));
fc_check('author + date archives', fc_urls($cache->cascade()->urls_for_post($post)), array('/2026/', '/2026/03/', '/author/alice/', '/dated/'));

// Taxonomie non visible ignorée.
list($cache) = faaaster_test_boot();
ft_term(4, 'post_tag', 'hidden');
$GLOBALS['ft']['viewable_taxonomies'] = array('category');
$post = ft_post(array('ID' => 18, 'post_name' => 'p18'));
$GLOBALS['ft']['object_terms'][18] = array('post_tag' => array(4));
fc_check('non-viewable taxonomy skipped', in_array('/tag/hidden/', fc_urls($cache->cascade()->urls_for_post($post)), true), false);

fc_check('paged urls bounded to 10', count(FaaasterCacheCascade::paged_urls('https://example.com/x/', 50)), 9);
fc_check('paged urls skip query URLs', FaaasterCacheCascade::paged_urls('https://example.com/?cat=3', 3), array());
