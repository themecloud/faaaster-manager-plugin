<?php

/**
 * Calcul des URL à purger pour un contenu (purge en cascade par type).
 *
 * L'état calculé est celui de l'objet passé : avec $post_before (instantané
 * pris avant la mise à jour), on obtient l'ancien permalien, l'ancien auteur et
 * les anciennes dates. Les termes viennent de la base (donc les nouveaux) ; les
 * termes retirés sont fournis à part par le listener (diff de set_object_terms).
 */
class FaaasterCacheCascade
{
    private $settings;

    public function __construct(FaaasterCacheSettings $settings)
    {
        $this->settings = $settings;
    }

    /** Règles par défaut d'un type de contenu. */
    public static function default_rules($post_type)
    {
        $none = array(
            'enabled' => true,
            'homepage' => false,
            'archive' => false,
            'author' => false,
            'date' => false,
            'feeds' => false,
            'paged' => 0,
            'taxonomies' => array(),
            'custom_paths' => array(),
        );
        if ($post_type === 'post') {
            return array_merge($none, array(
                'homepage' => true,
                'archive' => true,
                'feeds' => true,
                'paged' => 3,
                'taxonomies' => array('category', 'post_tag'),
            ));
        }
        if ($post_type === 'product') {
            return array_merge($none, array(
                'homepage' => true,
                'archive' => true,
                'paged' => 3,
                'taxonomies' => array('product_cat', 'product_tag'),
            ));
        }
        if ($post_type === 'page') {
            return $none;
        }
        // Autre type public : son archive, s'il en a une.
        return array_merge($none, array('archive' => true));
    }

    /** Règles effectives : surcharge des réglages, sinon défaut. */
    public function rules_for($post_type)
    {
        $custom = $this->settings->get('purge', 'post_types', array());
        if (isset($custom[$post_type]) && is_array($custom[$post_type])) {
            return array_merge(self::default_rules($post_type), $custom[$post_type]);
        }
        return self::default_rules($post_type);
    }

    /**
     * @param WP_Post $post état à purger (actuel ou instantané « avant »)
     * @return string[] URL absolues, dédoublonnées
     */
    public function urls_for_post($post)
    {
        $urls = array();
        $type = $post->post_type;
        $rules = $this->rules_for($type);

        $permalink = get_permalink($post);
        if ($permalink) {
            $urls[] = $permalink;
        }

        if (!empty($rules['enabled'])) {
            $listing = array(); // URL d'archives, qui reçoivent aussi leurs pages suivantes

            if (!empty($rules['homepage'])) {
                $urls[] = home_url('/');
                if (get_option('show_on_front') !== 'page') {
                    $listing[] = home_url('/');
                }
            }
            if (!empty($rules['archive'])) {
                $archive = self::archive_url($type);
                if ($archive) {
                    $urls[] = $archive;
                    $listing[] = $archive;
                }
            }
            if (!empty($rules['author']) && post_type_supports($type, 'author') && $post->post_author) {
                $author = get_author_posts_url((int) $post->post_author);
                $urls[] = $author;
                $listing[] = $author;
                if (!empty($rules['feeds']) && function_exists('get_author_feed_link')) {
                    $urls[] = get_author_feed_link((int) $post->post_author);
                }
            }
            if (!empty($rules['date']) && $type === 'post' && !empty($post->post_date)) {
                $time = strtotime($post->post_date);
                if ($time) {
                    $urls[] = get_year_link((int) date('Y', $time));
                    $urls[] = get_month_link((int) date('Y', $time), (int) date('m', $time));
                }
            }
            foreach ($this->term_links($post, isset($rules['taxonomies']) ? (array) $rules['taxonomies'] : array(), !empty($rules['feeds'])) as $link) {
                $urls[] = $link['url'];
                if ($link['listing']) {
                    $listing[] = $link['url'];
                }
            }
            if (!empty($rules['feeds']) && $type === 'post' && function_exists('get_feed_link')) {
                $urls[] = get_feed_link();
            }
            $paged = isset($rules['paged']) ? (int) $rules['paged'] : 0;
            foreach ($listing as $url) {
                foreach (self::paged_urls($url, $paged) as $page) {
                    $urls[] = $page;
                }
            }
            foreach (isset($rules['custom_paths']) ? (array) $rules['custom_paths'] : array() as $path) {
                $urls[] = home_url($path);
            }
        }

        foreach ((array) $this->settings->get('purge', 'always_paths', array()) as $path) {
            $urls[] = home_url($path);
        }

        $urls = array_values(array_unique(array_filter($urls)));
        return (array) apply_filters('faaaster_cache_cascade_urls', $urls, $post);
    }

    /** URL d'un terme et de ses pages suivantes (édition ou suppression de terme). */
    public function urls_for_term_link($url, $taxonomy)
    {
        $paged = 0;
        $types = array_unique(array_merge(
            array('post', 'product'),
            array_keys((array) $this->settings->get('purge', 'post_types', array()))
        ));
        foreach ($types as $type) {
            $rules = $this->rules_for($type);
            if (!empty($rules['enabled']) && in_array($taxonomy, (array) $rules['taxonomies'], true)) {
                $paged = max($paged, (int) $rules['paged']);
            }
        }
        return array_merge(array($url), self::paged_urls($url, $paged));
    }

    public static function archive_url($post_type)
    {
        if ($post_type === 'post') {
            if (get_option('show_on_front') === 'page') {
                $page_for_posts = (int) get_option('page_for_posts');
                return $page_for_posts ? get_permalink($page_for_posts) : null;
            }
            return home_url('/');
        }
        if ($post_type === 'product' && function_exists('wc_get_page_id')) {
            $shop = (int) wc_get_page_id('shop');
            return $shop > 0 ? get_permalink($shop) : null;
        }
        $link = get_post_type_archive_link($post_type);
        return $link ? $link : null;
    }

    /** /page/2/ … /page/N/ d'une URL d'archive. */
    public static function paged_urls($url, $count)
    {
        $count = max(0, min(10, (int) $count));
        if ($count < 2 || strpos($url, '?') !== false) {
            return array();
        }
        global $wp_rewrite;
        $base = (isset($wp_rewrite) && !empty($wp_rewrite->pagination_base)) ? $wp_rewrite->pagination_base : 'page';
        $urls = array();
        for ($n = 2; $n <= $count; $n++) {
            $urls[] = user_trailingslashit(trailingslashit($url) . $base . '/' . $n, 'paged');
        }
        return $urls;
    }

    /** @return array[] ['url' => string, 'listing' => bool] */
    private function term_links($post, array $taxonomies, $with_feeds)
    {
        $links = array();
        $available = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            if (!in_array($taxonomy, $available, true) || !is_taxonomy_viewable($taxonomy)) {
                continue;
            }
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (is_wp_error($link) || !$link) {
                    continue;
                }
                $links[] = array('url' => $link, 'listing' => true);
                if ($with_feeds && function_exists('get_term_feed_link')) {
                    $feed = get_term_feed_link($term->term_id, $taxonomy);
                    if ($feed) {
                        $links[] = array('url' => $feed, 'listing' => false);
                    }
                }
            }
        }
        return $links;
    }
}
