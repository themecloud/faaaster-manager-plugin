# Hooks vérifiés pour le module de purge du cache de page

Date : 2026-10-04

Méthode : sources téléchargées localement, puis `grep` sur `do_action` / `apply_filters` (appels multi-lignes compris), lecture du contexte d'appel et des docblocks. Les chemins `fichier:ligne` sont relatifs à la racine de chaque source. « depuis » reprend le `@since` du docblock. Quand ce tag est absent ou faux, la version a été établie à partir de tags historiques (miroir `WordPress/WordPress`, tags `wp-media/wp-rocket`), et la note le précise.

Verdicts :
- **VERIFIED** : le nom et les arguments correspondent ;
- **DIFFERENT** : le nom réel diffère (il est donné) ;
- **NOT FOUND** : aucun appel ou symbole n'existe.

## Sources

| Source | Version | Origine |
|---|---|---|
| WordPress core | `7.2-alpha-63166-src` (trunk, commit `dcd149a95c13`, 2026-10-03) | github.com/WordPress/wordpress-develop, `src/wp-includes` + `src/wp-admin` |
| WooCommerce | 11.1.2 | wp.org latest-stable |
| Elementor | 4.3.3 | wp.org latest-stable |
| WP Rocket | 3.23.5.1 (branche par défaut `develop`, commit `3a96b66db417`, 2026-10-02). Dernier tag de release : `v3.23.5.1` (`8e89a5917009`) | github.com/wp-media/wp-rocket |
| Autoptimize | 3.1.16 | wp.org latest-stable |
| Asset CleanUp | 1.4.0.6 | wp.org latest-stable |
| WP Super Cache | 3.1.4 (`WPSC_VERSION_ID` interne = `1.12.1`) | wp.org latest-stable |
| W3 Total Cache | 2.10.7 | wp.org latest-stable |
| WP Fastest Cache | 1.5.2 | wp.org latest-stable |
| LiteSpeed Cache | 7.9.1 | wp.org latest-stable |
| Beaver Builder Lite | 2.11.0.6 | wp.org latest-stable |

Vérifications historiques complémentaires :
- WordPress : tags 2.7 à 3.0 et 5.9 à 7.1 du miroir `WordPress/WordPress` ;
- WP Rocket : tags v3.10, v3.11, v3.12, v3.13, v3.15.10, v3.16 à v3.22, récupérés en clone superficiel.

---

## A. WordPress core (trunk)

| Hook / symbole | fichier:ligne | depuis | arguments | contexte | verdict |
|---|---|---|---|---|---|
| `wp_after_insert_post` | wp-includes/post.php:6095 (dans `wp_after_insert_post()` :6075) | 5.6.0 | `int $post_id, WP_Post $post, bool $update, null\|WP_Post $post_before` | Tous les chemins `wp_insert_post`/`wp_update_post` avec `$fire_after_hooks=true` : admin classique, cron, CLI. REST posts : appel explicite après termes et méta (rest-api/endpoints/class-wp-rest-posts-controller.php:874 en création, :1050 en mise à jour). Aussi `wp_publish_post` et le customizer (class-wp-customize-manager.php:3126) | VERIFIED |
| `post_updated` | post.php:5362 | 3.0.0 | `int $post_id, WP_Post $post_after, WP_Post $post_before` | `wp_insert_post` en mise à jour uniquement, hors pièces jointes | VERIFIED |
| `pre_post_update` | post.php:5100 | 2.5.0 | `int $post_id, array $data` (données déslashées) | `wp_insert_post` en mise à jour uniquement, avant l'écriture en base | VERIFIED |
| `set_object_terms` | taxonomy.php:3121 (dans `wp_set_object_terms()` :2965) | hook 2.8.0, 6e argument 2.9 | `int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids` | Toute affectation de termes, y compris pendant `wp_insert_post` (:5162/:5166/:5213) | VERIFIED |
| `before_delete_post` | post.php:3944 (dans `wp_delete_post()` :3891) | 3.2.0 (`$post` en 5.5.0) | `int $post_id, WP_Post $post` | Suppression définitive. Ne se déclenche **pas** pour les pièces jointes (:3915 → `wp_delete_attachment`, qui émet `delete_attachment` :6962) | VERIFIED |
| `edit_attachment` | post.php:5293 | 2.0.0 | `int $post_id` | `wp_insert_post` en mise à jour d'une pièce jointe. Suivi de `attachment_updated($post_id, $post_after, $post_before)` :5306 | VERIFIED |
| `wp_insert_comment` | comment.php:2249 (dans `wp_insert_comment()` :2171) | 2.8.0 | `int $id, WP_Comment $comment` | Tout nouveau commentaire, quel que soit son statut (approuvé, en attente, spam) | VERIFIED |
| `transition_comment_status` | comment.php:1974 (dans `wp_transition_comment_status()` :1944) | 2.7.0 | `string $new_status, string $old_status, WP_Comment $comment` | Statuts normalisés : `0`/`hold` → `unapproved`, `1`/`approve` → `approved`. N'est émis que si `$new !== $old`. Appelé par `wp_set_comment_status` :2866 (donc trash et spam), `wp_update_comment` :3042 et `wp_delete_comment` :1642 (`'delete'`) | VERIFIED |
| `edit_comment` | comment.php:3038 (dans `wp_update_comment()` :2893) | 1.2.0 (`$data` en 4.6.0) | `int $comment_id, array $data` | Modification d'un commentaire | VERIFIED |
| `deleted_comment` | comment.php:1630 (dans `wp_delete_comment()` :1581) | 2.9.0 (`$comment` en 4.9.0) | `string $comment_id` (chaîne numérique), `WP_Comment $comment` | Suppression définitive seulement. Sans force et avec `EMPTY_TRASH_DAYS`, le commentaire part à la corbeille via `wp_trash_comment` (:1589-1590) | VERIFIED |
| `edit_terms` | taxonomy.php:3520 (`wp_update_term`) ; aussi :2749 (`wp_insert_term`, cas limite slug vide) | 2.9.0 (`$args` en 6.1.0) | `int $term_id, string $taxonomy, array $args` | Avant la mise à jour de `wp_terms` | VERIFIED |
| `edited_term` | taxonomy.php:3637 (`wp_update_term` :3384) | 2.3.0 (`$args` en 6.1.0) | `int $term_id, int $tt_id, string $taxonomy, array $args` | Après la mise à jour d'un terme, y compris les menus (`nav_menu`) | VERIFIED |
| `pre_delete_term` | taxonomy.php:2180 (`wp_delete_term` :2126) | 4.1.0 | `int $term, string $taxonomy` | Avant la suppression | VERIFIED |
| `delete_term` | taxonomy.php:2304 | 2.5.0 (`$object_ids` en 4.5.0) | `int $term, int $tt_id, string $taxonomy, WP_Term $deleted_term, array $object_ids` | Après la suppression | VERIFIED |
| `profile_update` | user.php:2707 (`wp_insert_user` :2250, branche `$update`) | 2.0.0 (`$userdata` en 5.8.0) | `int $user_id, WP_User $old_user_data, array $userdata` | Mise à jour d'un utilisateur (admin, REST, `wp_update_user`) | VERIFIED |
| `upgrader_process_complete` | wp-admin/includes/class-wp-upgrader.php:988 (`run()` :774, si `! is_multi`) ; class-plugin-upgrader.php:413 (bulk) ; class-theme-upgrader.php:511 (bulk) ; class-core-upgrader.php:222 ; class-language-pack-upgrader.php:281 | 3.6.0 / 3.7.0 / 4.6.0 | `WP_Upgrader $upgrader, array $hook_extra` (clés détaillées sous le tableau) | Admin (update.php, update-core.php, AJAX `update-plugin`), CLI, cron (`wp_maybe_auto_update`) | VERIFIED |
| `activated_plugin` | wp-admin/includes/plugin.php:736 (`activate_plugin` :647) | 2.9.0 | `string $plugin` (chemin relatif), `bool $network_wide` | Seulement si `! $silent`. La réactivation après une mise à jour est silencieuse (update.php:86) | VERIFIED |
| `deactivated_plugin` | plugin.php:846 (`deactivate_plugins` :764) | 2.9.0 | `string $plugin, bool $network_deactivating` | Seulement si `! $silent`. La désactivation avant une mise à jour est silencieuse (class-plugin-upgrader.php:578) | VERIFIED |
| `switch_theme` | wp-includes/theme.php:875 (`switch_theme()` :757) | 1.5.0 (`$old_theme` en 4.5.0) | `string $new_name, WP_Theme $new_theme, WP_Theme $old_theme` | Admin, customizer, CLI | VERIFIED |
| `customize_save_after` | class-wp-customize-manager.php:3607 (`_publish_changeset_values` :3480) | 3.6.0 | `WP_Customize_Manager $manager` | Publication d'un changeset via `transition_post_status` → `_wp_customize_publish_changeset` (theme.php:3647, default-filters.php:574). Donc AJAX `customize_save`, ou cron pour un changeset programmé | VERIFIED |
| `wp_update_nav_menu` | nav-menu.php:399 (`wp_update_nav_menu_object`) ; rest-api/endpoints/class-wp-rest-menus-controller.php:456 (`handle_auto_add`) ; wp-admin/includes/nav-menu.php:1512 (`wp_nav_menu_update_menu_items`) | 3.0.0 | `int $menu_id, array $menu_data` (`array()` dans les deux derniers cas) | Admin, REST, customizer | VERIFIED |
| `wp_delete_nav_menu` | nav-menu.php:295 (`wp_delete_nav_menu`) | 3.0.0 | `int $term_id` | Après une suppression réussie | VERIFIED |
| `updated_option` | option.php:1030 | 2.9.0 | `string $option, mixed $old_value, mixed $value` | Seulement si la valeur change (retour anticipé :923). Une option inexistante passe par `add_option` (:928) | VERIFIED |
| `added_option` | option.php:1186 | 2.9.0 | `string $option, mixed $value` | Après l'INSERT | VERIFIED |
| filtre `nonce_user_logged_out` | pluggable.php:2494 (`wp_verify_nonce` :2481) et :2549 (`wp_create_nonce` :2544) | 3.5.0 | `int $uid (=0), string\|int $action` | Appliqué dans les **deux** fonctions, uniquement si `$uid == 0` | VERIFIED |
| filtre `nonce_life` | pluggable.php:2461 (`wp_nonce_tick` :2451) | 2.5.0 (`$action` en 6.1.0) | `int $lifespan = DAY_IN_SECONDS, string\|int $action` | Utilisé par `wp_create_nonce` et `wp_verify_nonce` via `wp_nonce_tick` | VERIFIED |
| fonction `wp_attachment_pages_enabled()` | — | — | — | Aucune fonction de ce nom. Il s'agit de l'**option** `wp_attachment_pages_enabled` (6.4) : valeur 0 en installation neuve (wp-admin/includes/schema.php:562), 1 forcée sur les sites mis à jour (upgrade.php:2373, `upgrade_640`). Lue par canonical.php:553 pour rediriger les pages de pièce jointe vers le fichier | NOT FOUND (c'est une option : `get_option( 'wp_attachment_pages_enabled' )`) |
| fonction `is_post_type_viewable()` | post.php:2467 | 4.4.0 (filtre en 5.9.0) | `string\|WP_Post_Type $post_type` → `bool` | `publicly_queryable \|\| (_builtin && public)`, filtrable via `is_post_type_viewable` | VERIFIED |
| fonction `is_taxonomy_viewable()` | taxonomy.php:5269 | 5.1.0 | `string\|WP_Taxonomy $taxonomy` → `bool` | Renvoie `$taxonomy->publicly_queryable`, sans filtre | VERIFIED |

Notes :

**`wp_after_insert_post`**
- `wp_publish_post` (post.php:5511) capture `$post_before = get_post( $post->ID )` en :5524, avant le changement de statut. Il appelle ensuite `wp_after_insert_post( $post, true, $post_before )` en :5576.
- `wp_publish_post` est aussi le chemin cron des publications programmées : `publish_future_post` → `check_and_publish_future_post` (default-filters.php:360, post.php:5589).
- `wp_trash_post` (:4141) passe par `wp_update_post` (:4188), qui déclenche `wp_after_insert_post` avec `$update=true` et `$post_before` dans son statut d'avant la corbeille.
- Exception : si `EMPTY_TRASH_DAYS` vaut 0, `wp_trash_post` appelle `wp_delete_post( $id, true )` (:4142-4143). Dans ce cas, seuls `before_delete_post`, `delete_post` et `deleted_post` sont émis.
- `wp_untrash_post` passe aussi par `wp_update_post` (:4288).

**Pièces jointes**
- `wp_insert_post` retourne dès :5285-5320 après `edit_attachment` / `attachment_updated` / `add_attachment`.
- Aucun `post_updated`, `save_post` ni `wp_after_insert_post` n'est donc émis pour une pièce jointe, sauf via le contrôleur REST attachments (:612 en création, :1015 en mise à jour), qui l'appelle explicitement.

**`set_object_terms`**
- Le 6e argument (`$old_tt_ids`) est absent du tag 2.8 et présent en 2.9 (miroir `WordPress/WordPress`). Le docblock trunk ne donne pas de `@since` pour cet argument.
- Le hook est émis à chaque appel de `wp_set_object_terms`, même sans changement (aucun retour anticipé hors erreur). Il faut comparer `$tt_ids` et `$old_tt_ids`.

**Clés de `$hook_extra` pour `upgrader_process_complete`**

Mises à jour unitaires, via `run()` :
- extension installée : `{type:'plugin', action:'install'}` (class-plugin-upgrader.php:141) ;
- extension mise à jour : `{plugin:'dir/file.php', type:'plugin', action:'update', temp_backup:{slug,src,dir}}` (:230) ;
- thème installé : `{type:'theme', action:'install'}` (class-theme-upgrader.php:254) ;
- thème mis à jour : `{theme:'slug', type:'theme', action:'update', temp_backup:{…}}` (:330).

Mises à jour en masse : les éléments tournent avec `is_multi=true`, donc sans hook par élément. Un seul appel est émis en fin de lot :
- `{action:'update', type:'plugin', bulk:true, plugins:[…]}` ;
- `{action:'update', type:'theme', bulk:true, themes:[…]}`.

Core : `{action:'update', type:'core'}` (class-core-upgrader.php:222), émis après `update_core()` que la mise à jour ait réussi ou échoué.

Traductions :
- `{action:'update', type:'translation', bulk:true, translations:[{language,type,slug,version}…]}` ;
- `Language_Pack_Upgrader::upgrade()` délègue toujours à `bulk_upgrade()` (:135-146).

Mises à jour automatiques (cron) :
- `WP_Automatic_Updater::update()` appelle `$upgrader->upgrade( $item )` élément par élément (class-wp-automatic-updater.php:478) ;
- extensions et thèmes : forme unitaire (`plugin` ou `theme`, sans `bulk`) ; core : `{action:'update', type:'core'}` ; traductions : forme bulk.

Échecs :
- `run()` sort avant le hook en cas d'échec du système de fichiers, du téléchargement ou de la décompression ;
- après `install_package`, le hook est émis même si l'installation renvoie une erreur.

**Ordre des actions dans `wp_insert_post()` (post.php:4705)**
1. Filtre `wp_insert_post_data` (:5085).
2. `pre_post_update` (:5100, mise à jour seulement).
3. Écriture en base, puis `clean_post_cache` (:5158).
4. Termes : catégories, tags, `tax_input` (:5162-5213), donc `set_object_terms`.
5. `meta_input` (:5220).
6. `clean_post_cache` (:5263).
7. `wp_transition_post_status` (:5283), donc `transition_post_status`, `{old}_to_{new}` et `{new}_{type}`. Pour une pièce jointe : `edit_attachment` / `attachment_updated` / `add_attachment`, puis `return`.
8. En mise à jour : `edit_post_{type}` (:5339), `edit_post` (:5349), `post_updated` (:5362).
9. `save_post_{type}` (:5382), `save_post` (:5393), `wp_insert_post` (:5404).
10. `wp_after_insert_post` (:5406-5407, si `$fire_after_hooks`).

**Ordre de `WP::main()` (class-wp.php:819)**

`init()` → `parse_request()` → (si analysée) `query_posts()` :825 → `handle_404()` → `register_globals()` → `send_headers()` :830 (dans lequel le filtre `wp_headers` :563, puis l'action `send_headers` :598) → action `wp` :839.

`template_redirect` vient après, dans template-loader.php:23 (wp-blog-header.php : `wp()` puis template-loader).

`query_posts` passe **avant** `send_headers` depuis WP **6.1** (tags 5.9 et 6.0 : `send_headers` avant `query_posts`). Les conditionnelles (`is_page()`, `is_singular()`…) sont donc fiables dans `wp_headers` et `send_headers` à partir de 6.1.

---

## B. WooCommerce 11.1.2

| Hook / symbole | fichier:ligne | depuis | arguments | contexte | verdict |
|---|---|---|---|---|---|
| Définition de `DONOTCACHEPAGE` | includes/class-wc-cache-helper.php:276 (`set_nocache_constants()`, qui définit aussi `DONOTCACHEOBJECT` et `DONOTCACHEDB` via `wc_maybe_define_constant`) | — | — | Appelé depuis `prevent_caching()` :67, branché sur `add_action( 'wp_headers', …, 5 )` (:30). `wp_headers` est un filtre de `WP::send_headers()` | VERIFIED (le hook et la condition diffèrent de l'ancien comportement, voir notes) |
| `woocommerce_product_set_stock` | includes/data-stores/class-wc-product-data-store-cpt.php:901 (`handle_updated_props` :853) ; includes/wc-stock-functions.php:75 (`wc_update_product_stock` :30) | 3.0 | `WC_Product $product` | Sauvegarde produit (admin, REST, CLI, import) si `stock_quantity` change ; ajustement de stock (commandes, remboursements, admin) | VERIFIED |
| `woocommerce_variation_set_stock` | data-store-cpt.php:892 ; wc-stock-functions.php:71 | 3.0 | `WC_Product $product` (variation, ou parent qui porte le stock) | Idem, pour les variations | VERIFIED |
| `woocommerce_product_set_stock_status` | data-store-cpt.php:927 | 3.0 | `int $product_id, string $stock_status, WC_Product $product` | `handle_updated_props` si `stock_status` change | VERIFIED |
| `woocommerce_variation_set_stock_status` | data-store-cpt.php:916 | 3.0 | `int $product_id, string $stock_status, WC_Product $product` | Idem, pour les variations | VERIFIED |
| `woocommerce_settings_saved` | includes/admin/class-wc-admin-settings.php:106 (`WC_Admin_Settings::save()` :84) | — | aucun | POST du formulaire classique `wc-settings` (après `woocommerce_update_options` :96 et le flush des endpoints) | VERIFIED |
| `is_cart()` | includes/wc-conditional-functions.php:101 | — | → `bool` | Filtre `woocommerce_is_cart` \|\| constante `WOOCOMMERCE_CART` \|\| `CartCheckoutUtils::is_cart_page()` | VERIFIED |
| `is_checkout()` | wc-conditional-functions.php:119 | — | → `bool` | Même logique (`woocommerce_is_checkout`, `WOOCOMMERCE_CHECKOUT`) | VERIFIED |
| `is_account_page()` | wc-conditional-functions.php:195 | — | → `bool` | `is_page( wc_get_page_id('myaccount') )` \|\| shortcode `woocommerce_my_account` \|\| filtre | VERIFIED |
| `wc_get_page_id()` | includes/wc-page-functions.php:77 | — | `string $page` → `int` | Option `woocommerce_{page}_page_id`, filtrable (`woocommerce_get_{page}_page_id`). Renvoie **-1** si l'option n'est pas définie | VERIFIED |
| Détection | `WC_VERSION` et `WOOCOMMERCE_VERSION` : includes/class-woocommerce.php:541-542 (`define_constants`) ; `final class WooCommerce` :56 (`$version = '11.1.2'` :63) ; `WC()` woocommerce.php:54 | — | — | — | VERIFIED |

Notes :

**`DONOTCACHEPAGE`**
- Depuis WC 10.1.0, `prevent_caching` est un callback du filtre `wp_headers` (priorité 5), et non plus de l'action `wp` (docblock :44).
- Conditions : `is_blog_installed()` (:50) et `is_page()` sur les IDs cart, checkout et myaccount (:62-63).
- La constante n'est donc définie que sur ces trois pages. Une page qui contient seulement le bloc ou le shortcode n'est **pas** couverte par ce chemin.
- Autres définitions de la constante :
  - `wc_nocache_headers()` (includes/wc-core-functions.php:1728), appelée par les gestionnaires de formulaires, les téléchargements et `WC_AJAX::wc_ajax_headers()` (class-wc-ajax.php:102) ;
  - `BlocksSharedState::prevent_cache()` (src/Blocks/Utils/BlocksSharedState.php:53), quand le panier n'est pas vide (:112-113).
- WC retire aussi `no-store` du `Cache-Control` pour les visiteurs non connectés (:116-117).

**`is_cart()` / `is_checkout()`**
- `CartCheckoutUtils::is_page_type()` renvoie `null`, donc `false`, tant que `did_action('wp')` est faux (src/Blocks/Utils/CartCheckoutUtils.php:33-35). Ces deux fonctions sont donc fausses dans `send_headers` et `wp_headers`.
- `is_account_page()` n'a pas cette garde.

**Stock**
- Dans `wc_update_product_stock`, l'action `*_set_stock` est émise à :71/:75 après `update_product_stock`.
- `read_stock_quantity` (data-store-cpt.php:536-541) écrit hors du tableau `changes`. Le `save()` qui suit ne réémet donc pas `*_set_stock` pour la quantité, mais il peut émettre `*_set_stock_status` si le statut change.
- Déclencheurs de commande : `wc_maybe_reduce_stock_levels` sur `woocommerce_payment_complete`, `woocommerce_order_status_completed`, `woocommerce_order_status_processing` et `woocommerce_order_status_on-hold` (wc-stock-functions.php:124-127). Hausse de stock sur `cancelled`, `pending` et `failed` (:156-162).

**Nonces**
- `WC_Session_Handler` branche `nonce_user_logged_out` pour les visiteurs non connectés (includes/class-wc-session-handler.php:98). Pour les actions préfixées `woocommerce`, l'uid devient le customer id de session (:615-620).
- Un nonce `woocommerce*` présent dans une page en cache n'est donc valide que pour la session qui l'a généré.

---

## C. Elementor 4.3.3

| Hook / symbole | fichier:ligne | depuis | arguments | contexte | verdict |
|---|---|---|---|---|---|
| `elementor/core/files/clear_cache` | core/files/manager.php:163 (dans `Files\Manager::clear_cache()` :123) | — | aucun | Voir les déclencheurs sous le tableau | VERIFIED |
| `elementor/document/before_save` | core/base/document.php:854 (`Document::save( $data )` :812) | 2.5.12 | `Document $document, array $data` | Après la vérification `is_editable_by_current_user()` | VERIFIED |
| `elementor/document/after_save` | core/base/document.php:899 | 2.5.12 | `Document $document, array $data` | Après la sauvegarde des réglages et des éléments, la suppression du CSS du post et `delete_cache()` | VERIFIED |
| `Source_Local::CPT` | includes/template-library/sources/local.php:40 | — | `'elementor_library'` | — | VERIFIED |
| Détection `ELEMENTOR_VERSION` | elementor.php:31 (`'4.3.3'`) | — | — | — | VERIFIED |

Déclencheurs de `Files\Manager::clear_cache()` :

Événements WordPress (`register_site_changed_hooks` :259-266, depuis 3.33.0) :
- `activated_plugin`, `deactivated_plugin`, `switch_theme` ;
- `upgrader_process_complete` (10, 2) via `on_upgrader_process_complete` :288. Avec l'expérience `e_optimized_css_files` active, le nettoyage est ignoré si `is_genuine_update()` est faux : traduction, ou bulk vide (:308-336) ;
- `update_option_elementor_element_cache_ttl` ;
- `add_option_` et `update_option_` de `elementor_disable_color_schemes`, `elementor_disable_typography_schemes`, `elementor_css_print_method` et `elementor_local_google_fonts` (includes/settings/settings.php:455-468) ;
- `delete_option__elementor_pro_license_v2_data` (modules/atomic-widgets/styles/atomic-widget-styles.php:43).

Actions dans Elementor :
- `wp_ajax_elementor_clear_cache` et `admin_post_elementor_site_clear_cache` (includes/settings/tools.php:205-206) ;
- `Utils::replace_urls` (includes/utils.php:271, AJAX `elementor_replace_url`) ;
- `Kit::save` (core/kits/documents/kit.php:105) : réglages globaux du site ;
- changement d'état d'une expérience (core/experiments/manager.php:762) ;
- mises à jour de la base (core/base/db-upgrades-manager.php:82) ;
- WP-CLI `wp elementor flush-css` (modules/wp-cli/command.php:65) ;
- routes REST des variables et classes globales, et capacités MCP.

Avec `e_optimized_css_files`, le nettoyage n'a lieu qu'une fois par requête (:124-130).

Notes :

**Chemin de sauvegarde de l'éditeur**
- `wp_ajax_elementor_ajax` (core/common/modules/ajax/module.php:82) → action `save_builder` (core/documents-manager.php:107) → `ajax_save` :511 → `Document::save()`.
- Pour un brouillon, c'est le document autosave qui est sauvegardé (:530-533).

**Séquence dans `Document::save()`**
- `before_save` → `save_settings` → `save_elements`.
- `save_elements` (:1380) met à jour la méta `_elementor_data`, puis `DB::save_plain_text` appelle `wp_update_post` (includes/db.php:233).
- Les hooks WP (`post_updated`, `save_post`, `wp_after_insert_post`) sont donc émis **avant** la suppression du CSS du post et avant `elementor/document/after_save`. Pour purger après la régénération des fichiers Elementor, écouter plutôt `after_save`.

---

## D. WP Rocket 3.23.5.1

### Abonnements de `inc/Addon/Varnish/Subscriber.php` (`get_subscribed_events` :41)

| Hook | fichier:ligne (émission) | depuis | arguments | contexte | verdict |
|---|---|---|---|---|---|
| `before_rocket_clean_domain` | inc/functions/files.php:889 (`rocket_clean_domain` :836) | 1.0 | `string $root` (chemin de cache), `string $lang`, `string $url` (home) | Émis une fois par URL de langue. Varnish : `[clean_domain, 10, 3]` | VERIFIED |
| `before_rocket_clean_home` | files.php:722 (`rocket_clean_home` :694) | 1.0 | `string $root, string $lang` | Varnish : `[clean_home, 10, 2]` | VERIFIED |
| `before_rocket_clean_file` | files.php:592 (`rocket_clean_files` :549) | 1.0 | `string $url` | Une fois par URL, seulement si `$run_actions=true` (3e paramètre, vrai par défaut) | VERIFIED |
| `rocket_rucss_after_clearing_usedcss` | inc/Engine/Optimization/RUCSS/Controller/UsedCSS.php:483 (`clear_url_usedcss` :473) | 3.11 (présent dès le tag v3.11) | `string $url` | admin-post `rocket_clean_saas_url` (barre d'admin) → `rocket_saas_clean_url` → `Admin\Subscriber::clean_url` (RUCSS/Admin/Subscriber.php:316) | VERIFIED |
| `rocket_performance_hints_data_after_clearing` | inc/Engine/Common/PerformanceHints/Admin/Controller.php:172 (`clean_url`) | présent dès v3.17, absent de v3.16 | `string $url` (referer) | admin-post `rocket_clean_performance_hints_url` → `rocket_performance_hints_clean_url`. Émis **avant** `delete_by_url()`, malgré son nom | VERIFIED (existe) |
| `rocket_saas_complete_job_status` | inc/Engine/Common/JobManager/JobProcessor.php:209-214 (`check_job_status` :158), via `rocket_do_action_and_deprecated` | 3.16 (absent de v3.15.10) | `string $url, array $job_details` | Action Scheduler : hook `rocket_saas_job_check_status`, groupe `rocket-rucss` (Queue.php:36) | VERIFIED |
| `rocket_rucss_complete_job_status` (ancien nom) | même appel (JobProcessor.php:213) via `do_action_deprecated` (inc/functions/admin.php:624-627) | v3.11 à v3.15.10 (UsedCSS.php:643 en v3.15.10). Déprécié en 3.16 | `string $url, array $job_details` | `do_action_deprecated` n'émet que s'il existe un écouteur, et ajoute un avis de dépréciation | VERIFIED (déprécié) |

### Autres hooks

| Hook | fichier:ligne | depuis | arguments | contexte | verdict |
|---|---|---|---|---|---|
| filtre `do_rocket_generate_caching_files` | inc/classes/Buffer/class-cache.php:547 (`can_generate_caching_files()`, appelée depuis `maybe_process_buffer` :278) | 2.5 | `bool true` → `bool` | Front, au moment du buffer de sortie | VERIFIED |
| `rocket_after_clean_domain` | files.php:925 | 3.15.5 | `string $lang, array $urls` | Fin de `rocket_clean_domain`. Utilisé par l'intégration nginx-helper (pas par Varnish) | VERIFIED |
| `after_rocket_clean_home` / `after_rocket_clean_file` / `after_rocket_clean_domain` | files.php:775 / :663 / :914 | 1.0 | mêmes arguments que les versions `before_` | Même contexte que `before_` | VERIFIED |
| `get_rocket_i18n_home_url()` | inc/functions/i18n.php:451 | 2.2 | `string $lang = ''` → `string` | `home_url()` sans plugin i18n, sinon l'URL de la langue (WPML, qTranslate, Polylang…) | VERIFIED |
| Détection `WP_ROCKET_VERSION` | wp-rocket.php:23 (`'3.23.5.1'`) | — | — | — | VERIFIED |

### Filtres de débit (preload et SaaS/RUCSS)

| Filtre | fichier:ligne | défaut et unité | présence par tag | verdict |
|---|---|---|---|---|
| `rocket_preload_cache_pending_jobs_cron_rows_count` | inc/Engine/Preload/Controller/PreloadUrl.php:222 (`process_pending_jobs`) ; inc/Engine/Preload/Abilities/CheckCacheHealth.php:142 | `45` (taille max du lot, moins les actions en attente). Valait `100` en v3.12 | v3.12+ (absent de v3.11) | VERIFIED |
| `rocket_preload_cache_min_in_progress_jobs_count` | PreloadUrl.php:229 | `5` (taille min du lot) | v3.17+ (absent de v3.16) | VERIFIED |
| `rocket_preload_pending_jobs_cron_interval` | inc/Engine/Preload/Cron/Subscriber.php:134 (`add_interval`, planification `rocket_preload_process_pending`) ; CheckCacheHealth.php:147 | `60` secondes | v3.12+ (docblock `@since 3.11`, mais absent du tag v3.11) | VERIFIED |
| `rocket_preload_delay_between_requests` | PreloadUrl.php:160 (`preload_url` :63) | `500000` **microsecondes** (passé à `usleep()` :167, soit 0,5 s). Après `absint` ; la valeur 0 rétablit le défaut (:163-165) | v3.12+ | VERIFIED |
| `rocket_saas_pending_jobs_cron_rows_count` | JobProcessor.php:114 (`process_pending_jobs`) et :245 (`process_on_submit_jobs`), via `rocket_apply_filter_and_deprecated` (admin.php:607) | `100` lignes | v3.16+ | VERIFIED |
| `rocket_saas_pending_jobs_cron_interval` | inc/Engine/Common/JobManager/Cron/Subscriber.php:182 (`add_interval`, planification `rocket_saas_pending_jobs`) | `60` secondes | v3.16+ | VERIFIED |
| `rocket_rucss_pending_jobs_cron_rows_count` | ancien nom, mêmes appels (JobProcessor.php:117/:248) | `100` | v3.11 à v3.15.10 en natif ; déprécié en 3.16 (encore appliqué via `apply_filters_deprecated`) | VERIFIED (déprécié) |
| `rocket_rucss_pending_jobs_cron_interval` | ancien nom (Cron/Subscriber.php:185) | `60` secondes | v3.11 à v3.15.10 ; déprécié en 3.16 | VERIFIED (déprécié) |

Ordre d'évaluation dans `rocket_apply_filter_and_deprecated` : l'ancien filtre est appliqué d'abord, puis sa valeur est passée au nouveau.

### Intégration nginx-helper : `inc/3rd-party/plugins/nginx-helper.php`

Emplacement vérifié. Le fichier est chargé sans condition par inc/3rd-party/3rd-party.php:67, lui-même requis par `rocket_init()` (inc/main.php:96) sur `plugins_loaded` (inc/main.php:129). Il ne fait rien sauf si `global $nginx_helper` est défini (:5-7, `if ( isset( $nginx_helper ) ) :`). Il utilise aussi `global $nginx_purger`.

| Callback | hook | priorité / nb args | depuis | rôle | verdict |
|---|---|---|---|---|---|
| `rocket_clear_cache_after_nginx_helper_purge` (:17) | `admin_init` (:37) | 10 / 0 | 3.3.0.1 | Si `?nginx_helper_action=done`, avec le nonce `nginx_helper-purge_all` et la capacité `rocket_purge_cache` → `rocket_clean_domain()` | VERIFIED |
| `rocket_clear_current_page_after_nginx_helper_purge` (:46) | `init` (:77) | 10 / 0 | 3.3.0.1 | Front, avec nonce et capacité `rocket_purge_posts` → `rocket_clean_home()` ou `rocket_clean_files( $referer )` | VERIFIED |
| `rocket_clean_nginx_cache_home` (:89) | `after_rocket_clean_home` (:100) | 10 / 2 | 3.3.0.1 | `$nginx_purger->purge_url( get_rocket_i18n_home_url( $lang ) )` | VERIFIED |
| `rocket_clean_nginx_cache_url` (:111) | `after_rocket_clean_file` (:134) et `rocket_rucss_after_clearing_usedcss` (:155) | 10 / 1 | 3.3.0.1 ; branché sur RUCSS en 3.12.3 | Ne purge que si `$_GET['type']` et `$_GET['_wpnonce']` sont présents (requête de la barre d'admin) | VERIFIED |
| `rocket_clean_nginx_helper_cache` (:141) | `rocket_after_clean_domain` (:148) et `rocket_saas_complete_job_status` (:156) | 10 / 0 | 3.3.0.1 | `do_action( 'rt_nginx_helper_purge_all' )`, sauf si `$_GET['nginx_helper_action']` est présent | VERIFIED |

Différence avec la liste demandée : le domaine est écouté sur **`rocket_after_clean_domain`**, et non sur `after_rocket_clean_domain`.

Cache disque : WP Rocket génère `advanced-cache.php` (inc/Engine/Cache/AdvancedCache.php:97) et ajoute `define( 'WP_CACHE', … ); // Added by WP Rocket` (inc/Engine/Cache/WPCache.php:318).

---

## E. Autoptimize 3.1.16

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `autoptimize_action_cachepurged` | classes/autoptimizeCache.php:421 (fonction `autoptimize_do_cachepurged_action`, définie à la volée dans `clearall()` :383) | aucun | Fonction ajoutée sur **`shutdown` priorité 11** (:424), et seulement si `clearall( $propagate = true )`. Le cache est vidé pendant la requête ; l'action n'est émise qu'en fin de requête | VERIFIED |
| Détection `AUTOPTIMIZE_PLUGIN_VERSION` | autoptimize.php:24 (`'3.1.16'`) | — | — | VERIFIED |

Notes :

**Déclencheurs de `clearall()`**
- Barre d'admin, AJAX `wp_ajax_autoptimize_delete_cache` (classes/autoptimizeToolbar.php:34/:130) ;
- WP-CLI (autoptimizeCLI.php:29) ;
- option `autoptimize_cache_clean` (autoptimizeConfig.php:59-61) ;
- mise à jour majeure (autoptimizeVersionUpdatesHandler.php:111) ;
- désactivation et désinstallation (autoptimizeMain.php:737/:621).

**Propagation et risque de boucle**
- Autoptimize branche lui-même `autoptimizeCache::flushPageCache` sur `autoptimize_action_cachepurged` (:425). Cette fonction tente de vider de nombreux caches de page : `wp_cache_clear_cache`, `w3tc_pgcache_flush`, `WpFastestCache::deleteCache`, `rt_nginx_helper_purge_all` si `NGINX_HELPER_BASENAME`, Kinsta…
- À l'inverse, `hook_page_cache_purge()` (autoptimizeMain.php:280-302) branche `autoptimizeCache::clearall_actionless` (sans propagation) sur les actions suivantes : `after_rocket_clean_domain`, `w3tc_flush_posts`, `w3tc_flush_all`, `wp_cache_cleared`, `wpfc_delete_cache`, `rt_nginx_helper_after_fastcgi_purge_all`, `wpo_cache_flush`, etc. Filtres : `autoptimize_filter_main_hookpagecachepurge` et `autoptimize_filter_main_pagecachepurgeactions`.
- Un module qui émettrait l'une de ces actions déclencherait donc un vidage d'Autoptimize, sans boucle.

**Exemple de détection** : LiteSpeed détecte une purge Autoptimize avec `has_action( 'shutdown', 'autoptimize_do_cachepurged_action', 11 )` (litespeed-cache/thirdparty/autoptimize.cls.php:40).

---

## F. Asset CleanUp, WP Super Cache, W3 Total Cache, WP Fastest Cache, LiteSpeed Cache

### Asset CleanUp 1.4.0.6

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `wpacu_clear_cache_before` | classes/OptimiseAssets/OptimizeCommon.php:1872 (`clearCache()` :1865) | aucun | Avant le vidage des fichiers CSS/JS minifiés | VERIFIED |
| `wpacu_clear_cache_after` | OptimizeCommon.php:2044 | aucun | Après le vidage et `set_transient( wpassetcleanup_last_clear_cache )`. Suivi de `cache_enabler_clear_complete_cache` si Cache Enabler est présent (:2050-2055) | VERIFIED |
| Détection | `WPACU_PLUGIN_VERSION` (wpacu.php:123, = `WPACU_LITE_PLUGIN_VERSION` `'1.4.0.6'` :18) ; `WPACU_PLUGIN_ID = 'wpassetcleanup'` (:133) | — | — | VERIFIED |

`clearCache()` est appelée depuis :
- admin-post `assetcleanup_clear_assets_cache` (OptimizeCommon.php:150-162) ;
- AJAX `wp_ajax_wpassetcleanup_clear_cache` (classes/Update.php:96) ;
- l'action WP Rocket `rocket_purge_cache` (priorité `PHP_INT_MAX`, OptimizeCommon.php:146-148) ;
- maintenance et import.

Les changements de thème (`switch_theme`, `after_switch_theme`) posent seulement un transient : le vidage est différé en AJAX (:141-142).

Asset CleanUp n'est pas un cache de page : il n'écrit ni `advanced-cache.php` ni `WP_CACHE`.

### WP Super Cache 3.1.4

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `wp_cache_cleared` | wp-cache-phase2.php:3428 (`wp_cache_clear_cache( $blog_id )`, après `prune_super_cache`) ; :3595 (`wp_cache_post_edit`, si `$wp_cache_clear_on_post_edit`) ; :2962 (`wp_cache_phase2_clean_cache`) ; inc/cache-files.php:366 (`wp_cache_clean_cache`, émis **au début**, avant la suppression) | aucun | Admin, édition de post, API | VERIFIED |
| `wpsc_after_delete_cache_admin_bar` | inc/delete-cache-button.php:129 | `string $req_path, string $referer` | Bouton « Delete cache » de la barre d'admin | VERIFIED |
| Détection | constante `WPCACHEHOME` (wp-cache.php:104-105, également écrite dans wp-config) ; fonction `wp_cache_clear_cache()` ; `WPSC_VERSION_ID = '1.12.1'` (wp-cache.php:31, identifiant interne différent de la version 3.1.4) | — | — | VERIFIED |

Page cache : copie `advanced-cache.php` dans wp-content (`wp_cache_create_advanced_cache`, inc/lifecycle.php:470-491, puis `wpsc_created_advanced_cache`). Écrit `define('WP_CACHE', true);` dans wp-config (inc/lifecycle.php:605-608, `wp_cache_replace_line`).

### W3 Total Cache 2.10.7

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `w3tc_flush_all` | CacheFlush_Locally.php:318 (`flush_all`) | `mixed $extras` | Seulement si le filtre `w3tc_preflush_all` le permet. C'est l'action **de dispatch** : le cache de page de W3TC y est lui-même branché en priorité 1100 (PgCache_Plugin.php:41) | VERIFIED |
| `w3tc_flush_posts` | CacheFlush_Locally.php:277 | `mixed $extras` | Filtre `w3tc_preflush_posts`. PgCache y est branché en 1100 (:45) | VERIFIED |
| `w3tc_flush_post` | CacheFlush_Locally.php:261 | `int $post_id, bool $force, mixed $extras` | Filtre `w3tc_preflush_post` | VERIFIED |
| `w3tc_flush_url` | CacheFlush_Locally.php:352 | `string $url, mixed $extras` | Filtre `w3tc_preflush_url` | VERIFIED |
| `w3tc_flush_minify` / `w3tc_flush_after_minify` | CacheFlush_Locally.php:143 / :146 (`minifycache_flush`) | aucun | Vidage du cache de minification | VERIFIED |
| Détection | `W3TC` (= true) et `W3TC_VERSION` (w3-total-cache-api.php:12-13) ; fonctions `w3tc_flush_all()` (:291), `w3tc_pgcache_flush()` (:347, équivalent de `w3tc_flush_posts()`) | — | — | VERIFIED |

Autres actions disponibles : `w3tc_flush_group`, `w3tc_flush_browsercache`, `w3tc_flush_objectcache`, `w3tc_flush_dbcache`, `w3tc_flush_fragmentcache`, et leurs variantes `*_after_*`.

Page cache : installe `wp-content/advanced-cache.php` (Generic_Environment.php:300-301, constante `W3TC_ADDIN_FILE_ADVANCED_CACHE`). Ajoute `define('WP_CACHE', true); // Added by W3 Total Cache` (PgCache_Environment.php:57-60 et :643) lors des requêtes wp-admin (`fix_on_wpadmin_request`).

### WP Fastest Cache 1.5.2

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `wpfc_delete_cache` | wpFastestCache.php:2038 (`deleteCache( $minified = false )` :1957) | aucun | Émis seulement si tout a réussi : `tmpWpfc` créé, cache renommé, et cache minifié renommé si `$minified` | VERIFIED |
| `wpfc_clear_all_cache` | wpFastestCache.php:2786/:2794 | `bool $minified` (optionnel) | Action de **commande**, émise par les fonctions publiques `wpfc_clear_all_site_cache()` et `wpfc_clear_all_cache()` ; WPFC y branche `deleteCache` (:172). Ce n'est pas une notification | VERIFIED (déclencheur, pas un événement « cleared ») |
| Détection | classe `WpFastestCache` (:84) ; constantes `WPFC_MAIN_PATH` (:39) et `WPFC_WP_CONTENT_DIR` (:34). Aucune constante de version | — | — | VERIFIED |

Aucune action n'est émise lors de la suppression du cache d'un seul post (les seules `do_action` du plugin sont celles listées ci-dessus).

Page cache : pas d'`advanced-cache.php` ; le service passe par des règles `.htaccess` (`# BEGIN WpFastestCache`, inc/admin.php:848). N'écrit `WP_CACHE` que si wp-postviews est actif (inc/admin.php:375-382).

### LiteSpeed Cache 7.9.1

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `litespeed_purged_all` | src/purge.cls.php:251 (`_purge_all`) | aucun | Fin d'un « Purge All » : lscache, CSS/JS, localres, objet, opcache | VERIFIED |
| `litespeed_purged_all_lscache` | purge.cls.php:279 | aucun | Le tag `*` est mis en file pour l'en-tête `X-LiteSpeed-Purge` | VERIFIED |
| `litespeed_purged_all_cssjs` | purge.cls.php:490 | aucun | Ignoré en cron ou après `send_headers` (:483-486) | VERIFIED |
| `litespeed_purged_post` | purge.cls.php:1067 (`purge_post`) | `int $pid` | — | VERIFIED |
| `litespeed_purged_front` | purge.cls.php:785 | `string $ref` | — | VERIFIED |
| `litespeed_purged_link` | purge.cls.php:968 | `string $url` | — | VERIFIED |
| Détection | `LSCWP_V` (litespeed-cache.php:38, `'7.9.1'`) ; `LITESPEED_SERVER_TYPE` (:95-107, `'NONE'` hors serveur LiteSpeed) | — | — | VERIFIED |

Autres actions émises par purge.cls.php :
- vidages globaux : `litespeed_purged_all_ccss`, `_ucss`, `_optimax`, `_lqip`, `_vpi`, `_avatar`, `_localres`, `_opcache`, `_object` ;
- vidages ciblés : `litespeed_purged_single`, `_frontpage`, `_pages`, `_cat`, `_tag`, `_esi`, `_posttype`, `_widget`, `_comment_widget`, `_feeds`, `_on_logout`.

Les actions `litespeed_purge*` (sans « d ») sont des **commandes** que LSCWP écoute (`litespeed_purge_all`, `litespeed_purge_post`, etc.), pas des notifications.

Page cache : la purge passe par des en-têtes destinés au serveur LiteSpeed ou à QUIC.cloud. Pas d'`advanced-cache.php` écrit dans cette version (aucune occurrence dans le code). Ajoute ou retire `WP_CACHE` dans wp-config (`Activation::manage_wp_cache_const`, src/activation.cls.php:449-484).

---

## G. Beaver Builder Lite 2.11.0.6

| Hook / symbole | fichier:ligne | arguments | contexte | verdict |
|---|---|---|---|---|
| `fl_builder_cache_cleared` | classes/class-fl-builder-admin-settings.php:1153 (`clear_cache()`, POST réglages avec nonce `cache`) et :1185 (`ajax_clear_cache()`, `wp_ajax_fl_clear_cache` :53) ; classes/class-fl-builder-update.php:86 (`maybe_run`, changement de version) ; classes/class-fl-builder-wpcli-command.php:82 (`wp beaver clearcache`) | aucun | Admin, AJAX, mise à jour, CLI | VERIFIED |
| `fl_builder_after_save_layout` | classes/class-fl-builder-model.php:6498 (`FLBuilderModel::save_layout( $publish = true )` :6434) | `int $post_id, bool $publish, array $data` (nœuds), `$settings` (réglages du layout) | AJAX de l'éditeur `save_layout` (class-fl-builder-ajax.php:106), exécuté sur une requête **front** (POST `fl_builder_data`, `FLBuilderAJAX::run` branché sur `wp` :31) | VERIFIED |
| `fl_builder_before_save_layout` | class-fl-builder-model.php:6445 | mêmes arguments | Même contexte | VERIFIED |
| `fl_builder_after_save_draft` | class-fl-builder-model.php:6525 (`save_draft`) | `int $post_id, string $post_status` | Même contexte | VERIFIED |
| `fl_builder_after_save_user_template` | class-fl-builder-model.php:6723 | `int $post_id` | Même contexte | VERIFIED |
| Détection | `FL_BUILDER_VERSION` (classes/class-fl-builder-loader.php:51, `'2.11.0.6'`) ; `FL_BUILDER_LITE` (:55) ; classe `FLBuilder` (classes/class-fl-builder.php:8) | — | — | VERIFIED |

Notes :
- `save_layout` appelle `delete_all_asset_cache( $post_id )`, puis `wp_update_post` (:6479-6483), donc les hooks WP de post, puis `FLBuilder::render_assets()`, avant `fl_builder_after_save_layout`.
- `after_save_layout` est donc émis après la régénération des assets.
- Exemple de référence : LiteSpeed purge tout sur `fl_builder_cache_cleared`, `fl_builder_after_save_layout`, `fl_builder_after_save_user_template` et `upgrader_process_complete` (litespeed-cache/thirdparty/beaver-builder.cls.php:36-45).

## Mesures sur le site de test (www.faaaster.io, inst103480, 04/10/2026)

Image wp-php84 (contrat 1.21), nginx avec `ngx_cache_purge` 2.5.

| Mesure | Résultat |
|---|---|
| `GET /purge/<path>` sur une page **en cache** | **200** |
| `GET /purge/<path>` sur une page **absente** du cache | **412** (pas 404) : traité comme normal par le module |
| `GET /purge/` (accueil, réécrit en `/purge/index.php`) | 200, la page repasse en MISS |
| `GET /purge-all` | 200 en 0,29 s pour 79 fichiers / 24 Mo |
| 200 + `X-Accel-Expires: 30` | MISS → HIT → EXPIRED après 32 s : TTL respecté |
| 200 + `X-Accel-Expires: 0` | jamais mis en cache |
| 404 + `X-Accel-Expires: 30` | **mis en cache** (même corps à la 2ᵉ requête) puis renouvelé après 32 s |
| 404 sans en-tête | jamais mis en cache (`cache-ttl.user.conf` ne cite que 200/302) |
| `X-Accel-Expires` côté client | jamais transmis |
| `X-FastCGI-Cache` sur une 404 | **absent** (`add_header` sans `always`) : le diagnostic du module passe par son propre en-tête |

**Purges du fork en production (log d'accès depuis le 23/09) :** 914 × 412 sur `/purge/?p=<id>` (brouillons, jamais en cache), 2 × 403 sur `/purge`, **aucune** purge d'une vraie page ni purge totale.

**Validation du socle P1** (fichiers de la branche posés temporairement, version de l'image restaurée ensuite) :
`wp faaaster cache purge <url>` → nginx 200 + 1 lot Cloudflare 200 ; `hostmanager/v1/clear_cache` (appel identique au consumer) → `{"code":"ok"}`, FastCGI 200 (9 ms), **un seul** appel Cloudflare (200, 549 ms), object cache et opcache vidés.

**WP-CLI :** `--url` est une option globale de WP-CLI, consommée avant la commande : les URL se passent en arguments (`wp faaaster cache purge <url>…`).

**Validation P2** (04/10/2026, même méthode, fork 3.2.9 présent dans l'image `:latest`) : reprise en main → **23 callbacks** du fork retirés (24 en 3.2.10 : `elementor/core/files/clear_cache` en plus), menu « Server Cache » absent, `$nginx_helper` supprimé. Sauvegarde simulée d'un article publié (sans modifier son contenu) → 10 URL purgées en un passage : l'article, l'accueil, la page des articles `/actualites/` et ses pages 2-3, la catégorie, son flux et ses pages 2-3, le flux principal (statuts 200 et 412) + **un** lot Cloudflare. `switch_theme` simulé → une purge totale + un appel Cloudflare « everything ». Aucune erreur du module.

**Validation P3** (04/10/2026) : sur www.faaaster.io, intégration Elementor détectée et active, Elementor Pro en détection seule, Asset CleanUp inactif sur ce site. Doublon de cache relevé à juste titre : `WP_CACHE` à true et drop-in `advanced-cache.php` (laissé par l'ancien `faaaster-optimizer-plugin`). `wp elementor flush-css` → `elementor/core/files/clear_cache` → une purge totale nginx + Cloudflare en fin de requête.

**Incident pendant la validation P2 (cause : le test, pas le module).** Le `do_action('switch_theme')` simulé en `wp eval` a déclenché le nettoyage des CSS d'Elementor, qui écoute ce hook. Les `custom-*.min.css` (points de rupture) ne se sont pas régénérés : l'object cache APCu de PHP-FPM (drop-in « APCu Objects Cache Handler ») gardait l'option `elementor-custom-breakpoints-files` périmée. WP-CLI a son propre APCu (`apc.enable_cli=1`), qui ne se partage pas avec celui de FPM. Cloudflare a mis les 404 en cache (`max-age=31536000`, `:latest` encore avec `always`). Site sans styles ~09:54→10:08 UTC. Rétabli par `wp elementor flush-css --regenerate`, la route `clear_cache` (qui vide l'object cache dans FPM) et un rendu. **Leçon pour le module :** un vidage déclenché en CLI ne vide pas l'object cache de FPM. Une extension qui efface ses fichiers depuis le CLI (mises à jour par WP-CLI, `wp elementor flush-css`) peut laisser FPM croire qu'ils existent encore.

**Vidage de l'object cache FPM après une purge totale lancée en CLI** (validé le 04/10/2026, sans hook simulé) : `wp faaaster cache purge --all` → `GET /purge-all` 200, puis `POST /?rest_route=/hostmanager/v1/flush_object_cache` 200 (285 ms) et Cloudflare 200 ; sans Bearer, la route répond 401. L'object cache APCu est installé par le provisioning quand l'option `APCU` du site n'est pas `false` (`setup_apcu`, drop-in APCu Manager) ; WP-CLI tourne avec `apc.enable_cli=1` (`cli-conf.d/90-apc.ini`) sur un segment **propre à chaque processus** : toute écriture CLI laisse l'object cache de FPM périmé.

**Validation P4 — TTL par contexte** (04/10/2026) :
- Test SAPI (`php -S`, PHP 7.4 et 8.4) : un `header()` posé dans le handler d'un buffer `ob_start(…, 0)` part au flush final ; `headers_list()` y voit les `Set-Cookie` et en-têtes ajoutés tard ; sur `Location` + `exit`, le handler s'exécute avec le code 302 ; un `ob_clean()` est ignoré.
- www.faaaster.io : sans règle → `X-Faaaster-Cache-TTL: default; rule=default`, aucun `X-Accel-Expires` côté client. `wp faaaster cache ttl set front_page 60` → `60; rule=front_page`, MISS → HIT → EXPIRED après 62 s. `ttl set 404 120` → la 2ᵉ requête d'une 404 sort du cache en 3 ms (même contenu).
- **Piège trouvé** : une règle posée en CLI restait invisible pour PHP-FPM (object cache APCu périmé, cf. incident du même jour) → toute modification des réglages en CLI vide désormais l'object cache de FPM (`faaaster_cache_settings_updated`). Et la clé `'404'` devenait l'entier 404 et était rejetée par l'assainissement → corrigé.
- Site remis en état ensuite : règles retirées, options `faaaster_cache_settings` / `faaaster_cache_events` supprimées, object cache FPM vidé, version de l'image restaurée.

**Validation P6 — Bearer de `clear_cache` journalisé** (04/10/2026, www.faaaster.io) :
- Appel au format du consumer (`hostmanager.ts` : Bearer `$WP_API_KEY` + `Host: $SERVER_NAME`) → 200 `ok`, rien n'est journalisé.
- Appel au format de `fstr-worker.php` (ni Bearer ni `Host`) → toujours accepté (200 `ok`), une ligne `[faaaster-cache] hostmanager/clear_cache called without a valid Bearer (missing)` dans le log FPM (stderr du conteneur `php`), compteur +1 dans `faaaster_cache_hostmanager_auth`, visible dans `site_state` → `other_data.cache`.
- **Piège trouvé** : le premier essai comptait 2 par appel. WordPress 7.0.4 rappelle le `permission_callback` dans `rest_send_allow_header` (`wp-includes/rest-api.php`, ~l. 892) avec le même `WP_REST_Request` → décision mémorisée par objet requête (`SplObjectStorage`).
- `manager_version` → `capabilities.cacheModule: true` ; `takeover: true` (fork 3.2.9 de l'image repris en main).
- Remis en état : version de l'image restaurée, options `faaaster_cache_settings`, `faaaster_cache_hostmanager_auth`, `faaaster_cache_events` supprimées, 14/14 CSS Elementor en 200.

**Coût des vidages de `clear_cache`** (04/10/2026, www.faaaster.io en `wp-php84:test`, opcache 512 Mo sans `file_cache`, `validate_timestamps=1`, `revalidate_freq=2`) : MISS de l'accueil opcache + APCu chauds 1,1 à 1,3 s ; juste après un vidage APCu seul 2,3 à 3,5 s (requête suivante ≈ 1,1 s) ; juste après `clear_cache` 0.12.0-rc1 (APCu + `opcache_reset`) 8,5 s. Le reset opcache ne rafraîchissait rien (dates validées) : retiré.

