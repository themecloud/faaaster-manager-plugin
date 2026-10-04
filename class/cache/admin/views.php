<?php

/**
 * Rendu de la page « Cache Faaaster » avec les primitives du DS Calm Grid
 * (mêmes classes et balisage que Next). Toute valeur est échappée ; aucune
 * classe WordPress (.button, .form-table, .notice) dans la page.
 */
class FaaasterCacheAdminViews
{
    private $cache;

    public function __construct(FaaasterCache $cache)
    {
        $this->cache = $cache;
    }

    public function page($tab, $notice)
    {
        $tabs = array(
            'rules' => array('list-tree', __('Purge rules', 'faaaster-manager-plugin')),
            'ttl' => array('gauge', __('Cache durations', 'faaaster-manager-plugin')),
            'tools' => array('settings', __('Tools', 'faaaster-manager-plugin')),
            'events' => array('scroll-text', __('Purge log', 'faaaster-manager-plugin')),
            'integrations' => array('plug', __('Integrations', 'faaaster-manager-plugin')),
        );
        echo '<div class="wrap fstr-ds-wrap"><div class="fstr-ds">';
        echo '<div class="page-h"><div><div class="eyebrow">Faaaster</div><h1>' . esc_html__('Page cache', 'faaaster-manager-plugin') . '</h1>';
        echo '<div class="sub">' . esc_html__('Your pages are served from the server cache and, when enabled, from the Cloudflare edge. They are purged automatically when your content changes.', 'faaaster-manager-plugin') . '</div></div></div>';

        if ($notice) {
            $this->alert($notice['type'] === 'ok' ? 'info' : 'danger', $notice['message']);
        }

        // Onglets et purge totale sur la même ligne (.toolbar du DS).
        echo '<div class="toolbar"><div class="seg fstr-tabs" role="tablist">';
        foreach ($tabs as $id => $def) {
            printf(
                '<button type="button" role="tab" class="%s" data-href="%s" aria-selected="%s">%s%s</button>',
                $id === $tab ? 'active' : '',
                esc_url(FaaasterCacheAdmin::page_url($id)),
                $id === $tab ? 'true' : 'false',
                faaaster_ds_icon($def[0]),
                esc_html($def[1])
            );
        }
        echo '</div>';
        $this->form_open('purge_all', $tab, 'class="fstr-push" data-confirm="' . esc_attr__('Purge the whole page cache? Pages will be rebuilt on their next visit.', 'faaaster-manager-plugin') . '"');
        echo '<button type="submit" class="btn btn-primary">' . faaaster_ds_icon('refresh-cw') . esc_html__('Purge entire cache', 'faaaster-manager-plugin') . '</button></form>';
        echo '</div>';

        $method = 'tab_' . $tab;
        $this->$method();
        echo '</div></div>';
    }

    // ---- Onglet : règles de purge ------------------------------------------

    private function tab_rules()
    {
        $purge = (array) $this->cache->settings()->get('purge');
        $this->form_open('save_rules', 'rules');
        $this->section(__('Automatic purge, by content type', 'faaaster-manager-plugin'), __('When a content item is published, updated or removed, it is always purged. These rules add the pages that list it.', 'faaaster-manager-plugin'));

        foreach ($this->public_types() as $type => $object) {
            $rules = $this->cache->cascade()->rules_for($type);
            $name = 'purge[post_types][' . $type . ']';
            // Comme Card padded={false} dans Next : les set-row sont directement dans la carte.
            echo '<div class="card"><div class="card-h">' . faaaster_ds_icon('list-tree') . esc_html($object->labels->name) . '<span class="right"><span class="code">' . esc_html($type) . '</span></span></div>';
            $this->switch_row($name . '[enabled]', !empty($rules['enabled']), __('Purge related pages', 'faaaster-manager-plugin'), __('Off: only the content itself is purged.', 'faaaster-manager-plugin'));
            $this->switch_row($name . '[homepage]', !empty($rules['homepage']), __('Home page', 'faaaster-manager-plugin'), __('Turn on if your home page lists this content.', 'faaaster-manager-plugin'));
            $archive = FaaasterCacheCascade::archive_url($type);
            $this->switch_row($name . '[archive]', !empty($rules['archive']), __('Listing page', 'faaaster-manager-plugin'), $archive ? $archive : __('This content type has no listing page.', 'faaaster-manager-plugin'));
            if (post_type_supports($type, 'author')) {
                $this->switch_row($name . '[author]', !empty($rules['author']), __('Author page', 'faaaster-manager-plugin'), '');
            }
            if ($type === 'post') {
                $this->switch_row($name . '[date]', !empty($rules['date']), __('Date archives', 'faaaster-manager-plugin'), __('Year and month pages.', 'faaaster-manager-plugin'));
            }
            $this->switch_row($name . '[feeds]', !empty($rules['feeds']), __('RSS feeds', 'faaaster-manager-plugin'), '');

            $taxonomies = array();
            foreach (get_object_taxonomies($type, 'objects') as $tax) {
                if (is_taxonomy_viewable($tax->name)) {
                    $taxonomies[$tax->name] = $tax;
                }
            }
            if ($taxonomies) {
                echo '<div class="set-row"><div><div class="ti">' . esc_html__('Category and tag pages', 'faaaster-manager-plugin') . '</div><div class="desc">' . esc_html__('Pages of the terms assigned to the content.', 'faaaster-manager-plugin') . '</div></div><div class="ctl fstr-wrap">';
                foreach ($taxonomies as $tax) {
                    $this->switch_inline($name . '[taxonomies][]', $tax->name, in_array($tax->name, (array) $rules['taxonomies'], true), $tax->labels->name);
                }
                echo '</div></div>';
            }
            echo '<div class="set-row"><div><div class="ti">' . esc_html__('Following pages of listings', 'faaaster-manager-plugin') . '</div><div class="desc">' . esc_html__('Number of pages purged (/page/2/, /page/3/…). 0 or 1: first page only.', 'faaaster-manager-plugin') . '</div></div>';
            printf('<div class="ctl"><input class="txt fstr-num" type="number" min="0" max="10" name="%s" value="%d"></div></div>', esc_attr($name . '[paged]'), (int) $rules['paged']);
            echo '<div class="card-b"><div class="field"><label>' . esc_html__('Extra pages to purge (one path per line)', 'faaaster-manager-plugin') . '</label>';
            printf('<textarea class="txt" rows="2" name="%s" placeholder="/page/">%s</textarea></div></div>', esc_attr($name . '[custom_paths]'), esc_textarea(implode("\n", (array) $rules['custom_paths'])));
            echo '</div>';
        }

        $this->section(__('For every change', 'faaaster-manager-plugin'), '');
        echo '<div class="card">';
        $this->switch_row('purge[comments]', !empty($purge['comments']), __('Approved comments', 'faaaster-manager-plugin'), __('Purge the commented content when a comment is approved, edited or removed.', 'faaaster-manager-plugin'));
        echo '<div class="set-row"><div><div class="ti">' . esc_html__('Full purge threshold', 'faaaster-manager-plugin') . '</div><div class="desc">' . esc_html__('Beyond this number of addresses in one change, the whole cache is purged instead.', 'faaaster-manager-plugin') . '</div></div>';
        printf('<div class="ctl"><input class="txt fstr-num" type="number" min="10" max="500" name="purge[escalate_threshold]" value="%d"></div></div>', (int) $purge['escalate_threshold']);
        echo '<div class="card-b"><div class="field"><label>' . esc_html__('Pages always purged (one path per line)', 'faaaster-manager-plugin') . '</label>';
        printf('<textarea class="txt" rows="3" name="purge[always_paths]" placeholder="/">%s</textarea><div class="help">%s</div></div></div>', esc_textarea(implode("\n", (array) $purge['always_paths'])), esc_html__('Up to 20 paths starting with /, without wildcard.', 'faaaster-manager-plugin'));
        echo '</div>';
        $this->submit();
        echo '</form>';
    }

    // ---- Onglet : durées ---------------------------------------------------

    private function tab_ttl()
    {
        $ttl = (array) $this->cache->settings()->get('ttl');
        $rules = (array) $ttl['rules'];
        $nginx = FaaasterCacheNginxConf::ttl_for_status(200);

        $this->form_open('save_ttl', 'ttl');
        $this->section(__('Default duration', 'faaaster-manager-plugin'), '');
        echo '<div class="card"><div class="card-b"><table class="kv"><tbody>';
        printf('<tr><td class="k">%s</td><td class="v">%s</td></tr>', esc_html__('Server cache (all pages)', 'faaaster-manager-plugin'), $nginx !== null ? esc_html(self::human($nginx)) : esc_html__('unknown', 'faaaster-manager-plugin'));
        printf('<tr><td class="k">%s</td><td class="v">%s <a class="link" href="https://help.faaaster.io/" target="_blank" rel="noopener">%s%s</a></td></tr>', esc_html__('Where to change it', 'faaaster-manager-plugin'), esc_html__('In your site files, folder conf (cache-ttl.user.conf).', 'faaaster-manager-plugin'), esc_html__('Help center', 'faaaster-manager-plugin'), faaaster_ds_icon('external-link', 13));
        echo '</tbody></table></div></div>';

        $this->section(__('Duration by page type', 'faaaster-manager-plugin'), __('Leave empty to use the default duration. 0 keeps the page out of the cache.', 'faaaster-manager-plugin'));
        echo '<div class="card"><table class="table"><thead><tr><th>' . esc_html__('Page type', 'faaaster-manager-plugin') . '</th><th class="fstr-end">' . esc_html__('Duration', 'faaaster-manager-plugin') . '</th></tr></thead><tbody>';
        foreach ($this->ttl_contexts() as $context => $label) {
            $value = '';
            $unit = 'h';
            if (array_key_exists($context, $rules)) {
                list($value, $unit) = self::split_duration((int) $rules[$context]);
            }
            printf('<tr class="row-static"><td>%s <span class="code">%s</span></td><td class="fstr-end"><div class="fstr-dur">', esc_html($label), esc_html($context));
            printf('<input class="txt fstr-num" type="number" min="0" step="any" name="ttl[rules][%1$s][value]" value="%2$s" placeholder="%3$s">', esc_attr($context), esc_attr($value), esc_attr__('default', 'faaaster-manager-plugin'));
            printf('<select class="txt" name="ttl[rules][%s][unit]">', esc_attr($context));
            foreach (array('s' => __('seconds', 'faaaster-manager-plugin'), 'm' => __('minutes', 'faaaster-manager-plugin'), 'h' => __('hours', 'faaaster-manager-plugin'), 'd' => __('days', 'faaaster-manager-plugin')) as $u => $u_label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($u), selected($unit, $u, false), esc_html($u_label));
            }
            echo '</select></div></td></tr>';
        }
        echo '</tbody></table></div>';

        $this->section(__('Safeguards', 'faaaster-manager-plugin'), '');
        echo '<div class="card">';
        $this->switch_row('ttl[donotcachepage]', !empty($ttl['donotcachepage']), __('Respect “do not cache” pages', 'faaaster-manager-plugin'), __('Pages that ask not to be cached (cart, checkout, account, some forms) are never cached.', 'faaaster-manager-plugin'));
        $this->switch_row('ttl[nonce_cap]', !empty($ttl['nonce_cap']), __('Keep forms working', 'faaaster-manager-plugin'), __('Pages containing a form security token are cached for 11 hours at most, so the token never expires in the cache.', 'faaaster-manager-plugin'));
        $this->switch_row('ttl[diagnostic]', !empty($ttl['diagnostic']), __('Diagnostic header', 'faaaster-manager-plugin'), __('Adds X-Faaaster-Cache-TTL to responses to show the applied duration and rule.', 'faaaster-manager-plugin'));
        echo '</div>';
        $this->submit();
        echo '</form>';
    }

    // ---- Onglet : outils ---------------------------------------------------

    private function tab_tools()
    {
        $home = home_url('/');
        $this->section(__('Purge one address', 'faaaster-manager-plugin'), '');
        echo '<div class="card"><div class="card-b">';
        $this->form_open('purge_url', 'tools');
        echo '<div class="field"><label>' . esc_html__('Address', 'faaaster-manager-plugin') . '</label><div class="fstr-inline">';
        printf('<input class="txt" type="url" name="url" required placeholder="%s">', esc_attr($home));
        echo '<button type="submit" class="btn btn-primary">' . faaaster_ds_icon('refresh-cw') . esc_html__('Purge', 'faaaster-manager-plugin') . '</button></div></div></form>';
        echo '</div></div>';

        $this->section(__('Test an address', 'faaaster-manager-plugin'), __('Loads the page twice as an anonymous visitor and shows how the server cache answers.', 'faaaster-manager-plugin'));
        echo '<div class="card"><div class="card-b">';
        $this->form_open('test_url', 'tools');
        echo '<div class="field"><label>' . esc_html__('Address', 'faaaster-manager-plugin') . '</label><div class="fstr-inline">';
        printf('<input class="txt" type="url" name="url" required placeholder="%s">', esc_attr($home));
        echo '<button type="submit" class="btn btn-primary">' . faaaster_ds_icon('search') . esc_html__('Test', 'faaaster-manager-plugin') . '</button></div></div></form>';
        $key = 'faaaster_cache_test_' . get_current_user_id();
        $test = get_transient($key);
        if ($test) {
            delete_transient($key);
            echo '<table class="table"><thead><tr><th>#</th><th>' . esc_html__('Status', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Server cache', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Duration and rule', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Sets a cookie', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Time', 'faaaster-manager-plugin') . '</th></tr></thead><tbody>';
            foreach ($test['results'] as $i => $r) {
                if (isset($r['error'])) {
                    printf('<tr class="row-static"><td>%d</td><td colspan="5"><span class="badge bad">%s</span> %s</td></tr>', $i + 1, esc_html__('error', 'faaaster-manager-plugin'), esc_html($r['error']));
                    continue;
                }
                $cache_badge = $r['fastcgi'] === 'HIT' ? 'ok' : ($r['fastcgi'] === 'BYPASS' ? 'warn' : 'info');
                printf(
                    '<tr class="row-static"><td>%d</td><td class="mono">%d</td><td>%s</td><td class="mono">%s</td><td>%s</td><td class="mono">%d ms</td></tr>',
                    $i + 1,
                    $r['status'],
                    $r['fastcgi'] !== '' ? '<span class="badge ' . esc_attr($cache_badge) . '">' . esc_html($r['fastcgi']) . '</span>' : '—',
                    esc_html($r['ttl'] !== '' ? $r['ttl'] : '—'),
                    $r['set_cookie'] ? esc_html__('yes', 'faaaster-manager-plugin') : esc_html__('no', 'faaaster-manager-plugin'),
                    $r['ms']
                );
            }
            echo '</tbody></table><div class="help">' . esc_html($test['url']) . '</div>';
        }
        echo '</div></div>';

        $this->section(__('Health', 'faaaster-manager-plugin'), '');
        echo '<div class="card"><div class="card-b"><table class="kv"><tbody>';
        foreach ($this->health() as $row) {
            printf('<tr><td class="k">%s</td><td class="v"><span class="sdot %s"></span> %s</td></tr>', esc_html($row[0]), esc_attr($row[1]), esc_html($row[2]));
        }
        echo '</tbody></table></div></div>';
    }

    // ---- Onglet : journal --------------------------------------------------

    private function tab_events()
    {
        $events = $this->cache->events()->all();
        echo '<div class="section-h"><h2>' . esc_html__('Recent purges', 'faaaster-manager-plugin') . '</h2><span class="hint">' . esc_html(sprintf(__('%d most recent', 'faaaster-manager-plugin'), FaaasterCacheEvents::MAX)) . '</span><span class="right">';
        $this->form_open('clear_events', 'events');
        echo '<button type="submit" class="btn btn-ghost btn-sm">' . faaaster_ds_icon('trash-2') . esc_html__('Clear log', 'faaaster-manager-plugin') . '</button></form></span></div>';
        if (!$events) {
            echo '<div class="card"><div class="card-b">' . esc_html__('No purge recorded yet.', 'faaaster-manager-plugin') . '</div></div>';
            return;
        }
        echo '<div class="card"><table class="table"><thead><tr><th>' . esc_html__('When', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Purge', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Origin', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Addresses', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Result', 'faaaster-manager-plugin') . '</th></tr></thead><tbody>';
        foreach ($events as $e) {
            $nginx = isset($e['result']['nginx']) ? (array) $e['result']['nginx'] : array();
            $ok = !empty($nginx['ok']);
            $urls = '';
            foreach ((array) $e['urls'] as $url) {
                $urls .= '<div class="code">' . esc_html($url) . '</div>';
            }
            printf(
                '<tr class="row-static"><td class="mono" title="%s">%s</td><td><span class="badge %s">%s</span>%s</td><td><span class="code">%s</span><div class="help">%s</div></td><td class="wrap">%s</td><td><span class="sdot %s"></span> %s</td></tr>',
                esc_attr(gmdate('Y-m-d H:i:s', (int) $e['t']) . ' UTC'),
                esc_html(sprintf(__('%s ago', 'faaaster-manager-plugin'), human_time_diff((int) $e['t']))),
                $e['type'] === 'all' ? 'warn' : 'info',
                esc_html($e['type'] === 'all' ? __('entire cache', 'faaaster-manager-plugin') : sprintf(_n('%d address', '%d addresses', (int) $e['count'], 'faaaster-manager-plugin'), (int) $e['count'])),
                !empty($e['forced']) ? ' <span class="badge">' . esc_html__('manual', 'faaaster-manager-plugin') . '</span>' : '',
                esc_html($e['source']),
                esc_html($e['context']),
                $urls !== '' ? $urls : '—',
                $ok ? 'ok' : 'bad',
                esc_html($ok ? __('done', 'faaaster-manager-plugin') : __('failed', 'faaaster-manager-plugin'))
            );
        }
        echo '</tbody></table></div>';
    }

    // ---- Onglet : intégrations ---------------------------------------------

    private function tab_integrations()
    {
        $third = FaaasterCacheIntegrationRegistry::third_party_page_cache();
        if ($third['wp_cache'] && $third['advanced_cache_dropin']) {
            $this->alert('warn', __('Another page cache plugin is active (WP_CACHE and advanced-cache.php). It duplicates the server cache and can serve outdated pages: disable its page cache.', 'faaaster-manager-plugin'));
        }
        $this->section(__('Detected plugins and builders', 'faaaster-manager-plugin'), __('When one of them regenerates or clears its files, the server cache is purged too.', 'faaaster-manager-plugin'));
        echo '<div class="card"><table class="table"><thead><tr><th>' . esc_html__('Plugin', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('Status', 'faaaster-manager-plugin') . '</th><th>' . esc_html__('To disable it (wp-config.php)', 'faaaster-manager-plugin') . '</th></tr></thead><tbody>';
        $rows = $this->cache->integrations() ? $this->cache->integrations()->status() : array();
        $shown = 0;
        foreach ($rows as $row) {
            if (!$row['detected']) {
                continue;
            }
            $shown++;
            if ($row['active']) {
                $badge = '<span class="badge ok">' . esc_html__('connected', 'faaaster-manager-plugin') . '</span>';
            } elseif ($row['status'] === 'pending') {
                $badge = '<span class="badge warn">' . esc_html__('detected — covered by the global purge', 'faaaster-manager-plugin') . '</span>';
            } else {
                $badge = '<span class="badge">' . esc_html__('disabled', 'faaaster-manager-plugin') . '</span>';
            }
            printf('<tr class="row-static"><td>%s</td><td>%s</td><td><span class="code">%s</span></td></tr>', esc_html($row['label']), $badge, esc_html($row['kill_constant']));
        }
        if (!$shown) {
            echo '<tr class="row-static"><td colspan="3">' . esc_html__('No supported plugin detected on this site.', 'faaaster-manager-plugin') . '</td></tr>';
        }
        echo '</tbody></table></div>';

        $removed = FaaasterCacheTakeover::removed();
        if ($this->cache->context('takeover') || $removed) {
            $this->section(__('Previous cache manager', 'faaaster-manager-plugin'), __('The former Server Cache (Nginx Helper) module is still loaded: this module handles purges in its place.', 'faaaster-manager-plugin'));
            echo '<div class="card"><div class="card-b">';
            echo '<div>' . esc_html(sprintf(_n('%d action of the former module is disabled.', '%d actions of the former module are disabled.', count($removed), 'faaaster-manager-plugin'), count($removed))) . '</div>';
            if ($removed) {
                echo '<details class="fstr-details"><summary>' . esc_html__('Technical details', 'faaaster-manager-plugin') . '</summary>';
                foreach ($removed as $callback) {
                    echo '<div class="code">' . esc_html($callback) . '</div>';
                }
                echo '</details>';
            }
            echo '</div></div>';
        }
    }

    // ---- Briques -------------------------------------------------------------

    private function health()
    {
        $rows = array();
        $site = FaaasterCache::site_from_wordpress();
        $host = parse_url($site['home_url'], PHP_URL_HOST);
        $probe = wp_remote_get('http://127.0.0.1/purge/__faaaster_health__', array('headers' => array('Host' => $host), 'timeout' => 3, 'redirection' => 0));
        $status = is_wp_error($probe) ? 0 : (int) wp_remote_retrieve_response_code($probe);
        $rows[] = array(
            __('Server cache purge', 'faaaster-manager-plugin'),
            ($status === 412 || $status === 404 || $status === 200) ? 'ok' : 'bad',
            ($status === 412 || $status === 404 || $status === 200) ? __('reachable', 'faaaster-manager-plugin') : sprintf(__('not reachable (%d)', 'faaaster-manager-plugin'), $status),
        );
        $cf = defined('CFCACHE_ENABLED') && CFCACHE_ENABLED === 'true';
        $rows[] = array(__('Cloudflare edge cache', 'faaaster-manager-plugin'), $cf ? 'ok' : 'off', $cf ? __('enabled — purged with the server cache', 'faaaster-manager-plugin') : __('not enabled on this site', 'faaaster-manager-plugin'));
        $rows[] = array(__('Object cache', 'faaaster-manager-plugin'), wp_using_ext_object_cache() ? 'ok' : 'off', wp_using_ext_object_cache() ? __('persistent (APCu)', 'faaaster-manager-plugin') : __('none', 'faaaster-manager-plugin'));
        $last = null;
        foreach ($this->cache->events()->all() as $e) {
            if (empty($e['result']['nginx']['ok'])) {
                $last = $e;
                break;
            }
        }
        $rows[] = array(__('Last failed purge', 'faaaster-manager-plugin'), $last ? 'warn' : 'ok', $last ? sprintf(__('%s ago', 'faaaster-manager-plugin'), human_time_diff((int) $last['t'])) : __('no failure recorded', 'faaaster-manager-plugin'));
        return $rows;
    }

    /** Types publics réglables : ni médias, ni types globaux (toujours purge totale). */
    private function public_types()
    {
        $types = get_post_types(array('public' => true), 'objects');
        unset($types['attachment']);
        foreach (FaaasterCacheContentListener::GLOBAL_POST_TYPES as $global) {
            unset($types[$global]);
        }
        return $types;
    }

    private function ttl_contexts()
    {
        $contexts = array(
            'front_page' => __('Home page', 'faaaster-manager-plugin'),
            'home' => __('Posts page', 'faaaster-manager-plugin'),
            'singular' => __('All single pages', 'faaaster-manager-plugin'),
        );
        foreach ($this->public_types() as $type => $object) {
            $contexts['singular:' . $type] = sprintf(__('Single: %s', 'faaaster-manager-plugin'), $object->labels->singular_name);
        }
        $contexts['archive'] = __('All listing pages', 'faaaster-manager-plugin');
        foreach ($this->public_types() as $type => $object) {
            if (!empty($object->has_archive)) {
                $contexts['archive:' . $type] = sprintf(__('Listing: %s', 'faaaster-manager-plugin'), $object->labels->name);
            }
        }
        $contexts['taxonomy'] = __('All category and tag pages', 'faaaster-manager-plugin');
        foreach (get_taxonomies(array('public' => true), 'objects') as $tax) {
            if (is_taxonomy_viewable($tax->name) && $tax->name !== 'post_format') {
                $contexts['taxonomy:' . $tax->name] = sprintf(__('Terms: %s', 'faaaster-manager-plugin'), $tax->labels->name);
            }
        }
        $contexts['author'] = __('Author pages', 'faaaster-manager-plugin');
        $contexts['date'] = __('Date archives', 'faaaster-manager-plugin');
        $contexts['search'] = __('Search results', 'faaaster-manager-plugin');
        $contexts['feed'] = __('RSS feeds', 'faaaster-manager-plugin');
        $contexts['404'] = __('Page not found (404)', 'faaaster-manager-plugin');
        return $contexts;
    }

    private function form_open($action, $tab, $extra = '')
    {
        printf('<form method="post" action="%s" %s>', esc_url(admin_url('admin-post.php')), $extra);
        printf('<input type="hidden" name="action" value="%s"><input type="hidden" name="tab" value="%s">', esc_attr('faaaster_cache_' . $action), esc_attr($tab));
        wp_nonce_field(FaaasterCacheAdmin::NONCE);
    }

    private function section($title, $hint)
    {
        echo '<div class="section-h"><h2>' . esc_html($title) . '</h2>' . ($hint !== '' ? '<span class="hint">' . esc_html($hint) . '</span>' : '') . '</div>';
    }

    private function alert($kind, $message)
    {
        $icon = $kind === 'danger' ? 'circle-alert' : ($kind === 'warn' ? 'triangle-alert' : 'circle-check');
        printf('<div class="alert alert-%s" role="status">%s<div>%s</div></div>', esc_attr($kind), faaaster_ds_icon($icon), esc_html($message));
    }

    private function switch_row($name, $on, $title, $desc)
    {
        echo '<div class="set-row"><div><div class="ti">' . esc_html($title) . '</div>' . ($desc !== '' ? '<div class="desc">' . esc_html($desc) . '</div>' : '') . '</div><div class="ctl">';
        $this->switch_control($name, '1', $on, $title);
        echo '</div></div>';
    }

    private function switch_inline($name, $value, $on, $label)
    {
        echo '<label class="fstr-chip">';
        $this->switch_control($name, $value, $on, $label, true);
        echo '<span>' . esc_html($label) . '</span></label>';
    }

    /** Interrupteur DS (button.switch) + champ caché synchronisé par assets/admin.js. */
    private function switch_control($name, $value, $on, $label, $small = false)
    {
        $multi = substr($name, -2) === '[]';
        printf(
            '<input type="hidden" name="%s" value="%s"%s data-off="%s">',
            esc_attr($name),
            esc_attr($on ? $value : ($multi ? '' : '0')),
            $multi && !$on ? ' disabled' : '',
            esc_attr($multi ? '' : '0')
        );
        printf(
            '<button type="button" role="switch" class="switch%s%s" aria-checked="%s" data-state="%s" data-value="%s" aria-label="%s"></button>',
            $small ? ' switch-sm' : '',
            $on ? ' on' : '',
            $on ? 'true' : 'false',
            $on ? 'checked' : 'unchecked',
            esc_attr($value),
            esc_attr($label)
        );
    }

    private function submit()
    {
        echo '<div class="fstr-submit"><button type="submit" class="btn btn-primary">' . faaaster_ds_icon('check') . esc_html__('Save', 'faaaster-manager-plugin') . '</button></div>';
    }

    public static function human($seconds)
    {
        list($value, $unit) = self::split_duration((int) $seconds);
        switch ($unit) {
            case 'd':
                return sprintf(_n('%d day', '%d days', $value, 'faaaster-manager-plugin'), $value);
            case 'h':
                return sprintf(_n('%d hour', '%d hours', $value, 'faaaster-manager-plugin'), $value);
            case 'm':
                return sprintf(_n('%d minute', '%d minutes', $value, 'faaaster-manager-plugin'), $value);
            default:
                return sprintf(_n('%d second', '%d seconds', $value, 'faaaster-manager-plugin'), $value);
        }
    }

    /** 36000 → (10, 'h') ; 90 → (90, 's'). */
    public static function split_duration($seconds)
    {
        if ($seconds > 0 && $seconds % 86400 === 0) {
            return array($seconds / 86400, 'd');
        }
        if ($seconds > 0 && $seconds % 3600 === 0) {
            return array($seconds / 3600, 'h');
        }
        if ($seconds > 0 && $seconds % 60 === 0) {
            return array($seconds / 60, 'm');
        }
        return array($seconds, 's');
    }
}
