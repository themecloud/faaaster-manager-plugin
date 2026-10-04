<?php

/**
 * Purges déclenchées par le contenu : articles, termes, commentaires, médias,
 * auteurs. Signatures vérifiées : docs/cache/hooks-verified.md (section A).
 */
class FaaasterCacheContentListener
{
    /** Types dont une modification change le rendu de tout le site. */
    const GLOBAL_POST_TYPES = array(
        'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation',
        'wp_block', 'wp_font_family', 'wp_font_face', 'elementor_library',
    );

    private $cache;
    private $cascade;
    /** URL calculées sur l'état « avant » (post_updated), par post. */
    private $before = array();
    /** URL des termes retirés (diff set_object_terms), par post. */
    private $removed_terms = array();
    /** Lien d'un terme avant modification / suppression. */
    private $term_before = array();

    public function __construct(FaaasterCache $cache, FaaasterCacheCascade $cascade)
    {
        $this->cache = $cache;
        $this->cascade = $cascade;
    }

    public function register()
    {
        $c = $this->cache;
        $c->hook('post_updated', array($this, 'on_post_updated'), 10, 3);
        $c->hook('set_object_terms', array($this, 'on_set_object_terms'), 10, 6);
        if (function_exists('wp_after_insert_post')) {
            $c->hook('wp_after_insert_post', array($this, 'on_after_insert_post'), 99, 4);
        } else {
            // WordPress < 5.6 : pas de wp_after_insert_post.
            $c->hook('save_post', array($this, 'on_save_post_legacy'), 99, 3);
        }
        $c->hook('before_delete_post', array($this, 'on_before_delete_post'), 10, 2);
        $c->hook('edit_attachment', array($this, 'on_edit_attachment'), 10, 1);

        $c->hook('wp_insert_comment', array($this, 'on_insert_comment'), 10, 2);
        $c->hook('transition_comment_status', array($this, 'on_comment_status'), 10, 3);
        $c->hook('edit_comment', array($this, 'on_edit_comment'), 10, 1);
        $c->hook('deleted_comment', array($this, 'on_deleted_comment'), 10, 2);

        $c->hook('edit_terms', array($this, 'on_edit_terms'), 10, 2);
        $c->hook('edited_term', array($this, 'on_edited_term'), 10, 3);
        $c->hook('pre_delete_term', array($this, 'on_pre_delete_term'), 10, 2);
        $c->hook('delete_term', array($this, 'on_delete_term'), 10, 3);

        $c->hook('profile_update', array($this, 'on_profile_update'), 10, 2);
        $c->hook('updated_option', array($this, 'on_updated_option'), 10, 1);
    }

    // ---- Articles ----------------------------------------------------------

    public function on_post_updated($post_id, $post_after, $post_before)
    {
        if ($this->is_purgeable_published($post_before)) {
            $this->before[$post_id] = $this->cascade->urls_for_post($post_before);
        }
    }

    /** Diff des termes : set_object_terms est émis même sans changement. */
    public function on_set_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
    {
        if (!is_taxonomy_viewable($taxonomy)) {
            return;
        }
        $removed = array_diff(array_map('intval', (array) $old_tt_ids), array_map('intval', (array) $tt_ids));
        foreach ($removed as $tt_id) {
            $term = get_term_by('term_taxonomy_id', $tt_id, $taxonomy);
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $link = get_term_link($term);
            if ($link && !is_wp_error($link)) {
                foreach ($this->cascade->urls_for_term_link($link, $taxonomy) as $url) {
                    $this->removed_terms[$object_id][] = $url;
                }
            }
        }
    }

    public function on_after_insert_post($post_id, $post, $update, $post_before)
    {
        $this->handle_post_change($post_id, $post, $post_before);
    }

    public function on_save_post_legacy($post_id, $post, $update)
    {
        $this->handle_post_change($post_id, $post, null);
    }

    private function handle_post_change($post_id, $post, $post_before)
    {
        if (!$post || $this->is_ignored($post)) {
            $this->forget($post_id);
            return;
        }
        $queue = $this->cache->queue();
        if (in_array($post->post_type, self::GLOBAL_POST_TYPES, true)) {
            $queue->enqueue_all('post_type:' . $post->post_type);
            $this->forget($post_id);
            return;
        }
        if (!is_post_type_viewable($post->post_type)) {
            $this->forget($post_id);
            return;
        }
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            $queue->enqueue_all('import');
            $this->forget($post_id);
            return;
        }

        $was_published = $post_before && $post_before->post_status === 'publish';
        $is_published = $post->post_status === 'publish';
        if (!$was_published && !$is_published) {
            $this->forget($post_id);
            return;
        }

        // Page parente dont l'URL change : les URL de toutes ses descendantes changent.
        if ($was_published && is_post_type_hierarchical($post->post_type)
            && ($post_before->post_name !== $post->post_name
                || (int) $post_before->post_parent !== (int) $post->post_parent
                || !$is_published)
            && $this->has_children($post_id)) {
            $queue->enqueue_all('hierarchy:' . $post_id);
            $this->forget($post_id);
            return;
        }

        $urls = array();
        if ($is_published) {
            $urls = $this->cascade->urls_for_post($post);
        }
        if ($was_published) {
            $before = isset($this->before[$post_id]) ? $this->before[$post_id] : $this->cascade->urls_for_post($post_before);
            $urls = array_merge($urls, $before);
        }
        if (isset($this->removed_terms[$post_id])) {
            $urls = array_merge($urls, $this->removed_terms[$post_id]);
        }
        foreach (array_unique($urls) as $url) {
            $queue->enqueue_url($url, 'post:' . $post_id);
        }
        $this->forget($post_id);
    }

    public function on_before_delete_post($post_id, $post = null)
    {
        $post = $post ? $post : get_post($post_id);
        if ($this->is_purgeable_published($post)) {
            foreach ($this->cascade->urls_for_post($post) as $url) {
                $this->cache->queue()->enqueue_url($url, 'delete:' . $post_id);
            }
        }
    }

    /** Seule la page d'attachement est en cache, jamais les fichiers d'uploads. */
    public function on_edit_attachment($post_id)
    {
        // Option apparue en 6.4 ; absente = comportement historique (pages actives).
        if (!get_option('wp_attachment_pages_enabled', 1)) {
            return;
        }
        $url = get_permalink($post_id);
        if ($url) {
            $this->cache->queue()->enqueue_url($url, 'attachment:' . $post_id);
        }
    }

    // ---- Commentaires -------------------------------------------------------

    public function on_insert_comment($comment_id, $comment)
    {
        if ($comment && (string) $comment->comment_approved === '1') {
            $this->purge_comment_post($comment);
        }
    }

    public function on_comment_status($new_status, $old_status, $comment)
    {
        if ($new_status === 'approved' || $old_status === 'approved') {
            $this->purge_comment_post($comment);
        }
    }

    public function on_edit_comment($comment_id)
    {
        $comment = get_comment($comment_id);
        if ($comment && (string) $comment->comment_approved === '1') {
            $this->purge_comment_post($comment);
        }
    }

    public function on_deleted_comment($comment_id, $comment = null)
    {
        if ($comment && (string) $comment->comment_approved === '1') {
            $this->purge_comment_post($comment);
        }
    }

    private function purge_comment_post($comment)
    {
        if (!$this->cache->settings()->get('purge', 'comments', true)) {
            return;
        }
        $post = get_post((int) $comment->comment_post_ID);
        if (!$this->is_purgeable_published($post)) {
            return;
        }
        $permalink = get_permalink($post);
        if (!$permalink) {
            return;
        }
        $queue = $this->cache->queue();
        $queue->enqueue_url($permalink, 'comment:' . $post->ID);
        if (get_option('page_comments')) {
            $per_page = max(1, (int) get_option('comments_per_page', 50));
            $pages = min(5, (int) ceil(max(1, (int) get_comments_number($post->ID)) / $per_page));
            for ($n = 1; $n <= $pages; $n++) {
                $queue->enqueue_url(user_trailingslashit(trailingslashit($permalink) . 'comment-page-' . $n), 'comment:' . $post->ID);
            }
        }
    }

    // ---- Termes --------------------------------------------------------------

    public function on_edit_terms($term_id, $taxonomy)
    {
        if (is_taxonomy_viewable($taxonomy)) {
            $link = get_term_link((int) $term_id, $taxonomy);
            $this->term_before[$term_id] = is_wp_error($link) ? null : $link;
        }
    }

    public function on_edited_term($term_id, $tt_id, $taxonomy)
    {
        if (!is_taxonomy_viewable($taxonomy)) {
            return;
        }
        $queue = $this->cache->queue();
        $old = isset($this->term_before[$term_id]) ? $this->term_before[$term_id] : null;
        unset($this->term_before[$term_id]);
        $new = get_term_link((int) $term_id, $taxonomy);
        $new = is_wp_error($new) ? null : $new;
        // Slug changé : les permaliens en %category% (et réécritures de types) changent aussi.
        if ($old && $new && $old !== $new) {
            $queue->enqueue_all('term_slug:' . $term_id);
            return;
        }
        if ($new) {
            foreach ($this->cascade->urls_for_term_link($new, $taxonomy) as $url) {
                $queue->enqueue_url($url, 'term:' . $term_id);
            }
        }
        $queue->enqueue_url(home_url('/'), 'term:' . $term_id);
    }

    public function on_pre_delete_term($term_id, $taxonomy)
    {
        $this->on_edit_terms($term_id, $taxonomy);
    }

    public function on_delete_term($term_id, $tt_id, $taxonomy)
    {
        $queue = $this->cache->queue();
        if (!empty($this->term_before[$term_id])) {
            foreach ($this->cascade->urls_for_term_link($this->term_before[$term_id], $taxonomy) as $url) {
                $queue->enqueue_url($url, 'term_delete:' . $term_id);
            }
            $queue->enqueue_url(home_url('/'), 'term_delete:' . $term_id);
        }
        unset($this->term_before[$term_id]);
    }

    // ---- Auteurs, options de contenu -----------------------------------------

    public function on_profile_update($user_id, $old_user_data = null)
    {
        if ((int) count_user_posts($user_id) === 0) {
            return;
        }
        $queue = $this->cache->queue();
        $queue->enqueue_url(get_author_posts_url($user_id), 'author:' . $user_id);
        if ($old_user_data && !empty($old_user_data->user_nicename)) {
            $queue->enqueue_url(get_author_posts_url($user_id, $old_user_data->user_nicename), 'author:' . $user_id);
        }
    }

    public function on_updated_option($option)
    {
        if ($option !== 'sticky_posts') {
            return;
        }
        $queue = $this->cache->queue();
        $queue->enqueue_url(home_url('/'), 'sticky_posts');
        $archive = FaaasterCacheCascade::archive_url('post');
        if ($archive) {
            $queue->enqueue_url($archive, 'sticky_posts');
        }
    }

    // ---- Outils ------------------------------------------------------------

    private function is_ignored($post)
    {
        if (in_array($post->post_status, array('auto-draft', 'inherit'), true)) {
            return true;
        }
        if ($post->post_type === 'nav_menu_item' || $post->post_type === 'revision') {
            return true;
        }
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return true;
        }
        return defined('DOING_AUTOSAVE') && DOING_AUTOSAVE;
    }

    private function is_purgeable_published($post)
    {
        return $post && $post->post_status === 'publish' && !$this->is_ignored($post)
            && !in_array($post->post_type, self::GLOBAL_POST_TYPES, true)
            && is_post_type_viewable($post->post_type);
    }

    private function has_children($post_id)
    {
        $children = get_children(array(
            'post_parent' => $post_id,
            'post_type' => 'any',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
        ));
        return !empty($children);
    }

    private function forget($post_id)
    {
        unset($this->before[$post_id], $this->removed_terms[$post_id]);
    }
}
