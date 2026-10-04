# Module cache de pages

Pilote unique du cache de pages d'un site Faaaster : purge du cache **nginx FastCGI** du pod et de **Cloudflare** (via Next), TTL par contexte, intégrations. Il remplace le mu-plugin `faaaster-cache-manager` (fork de Nginx Helper) et `faaaster-wp-rocket.php` de wp-builder.

Ce qu'il ne fait **pas** : pas de cache PHP, pas de drop-in `advanced-cache.php`, aucune écriture dans `/app/conf`, aucun rechargement de nginx depuis PHP. Les exclusions, cookies et durées par défaut restent dans les fichiers `conf` du site (centre d'aide).

Hooks et mesures vérifiés dans les sources et sur un site réel : [hooks-verified.md](hooks-verified.md).

## Architecture

```
Déclencheurs (contenu, filet global, intégrations, rt_nginx_helper_purge_all, manuels)
   │ enqueue_url / enqueue_all
   ▼
FaaasterCachePurgeQueue  dédoublonnage ; purge totale prioritaire ; escalade (seuil, budget 3 s) ;
   │                      garde-fou anti-tempête (APCu) ; vidage unique à shutdown (100000)
   │                      après fastcgi_finish_request() ; immédiat si forcé
   ├─► GET http://127.0.0.1/purge<path> (Host = hôte de l'URL ; 200 purgé, 412 absent) | /purge-all
   ├─► POST /?rest_route=/hostmanager/v1/flush_object_cache  (purge totale lancée en CLI)
   ├─► Cloudflare : un appel « everything » ou des lots de 30 URL (route Next /cloudflare)
   └─► journal (option faaaster_cache_events, 100 entrées) + error_log('[faaaster-cache] …')
FaaasterCacheTtlEmitter → X-Accel-Expires + X-Faaaster-Cache-TTL
```

| Fichier (`class/cache/`) | Rôle |
|---|---|
| `bootstrap.php` | chargement, `faaaster_cache()`, `faaaster_cache_boot()` |
| `cache.php` | singleton, contexte (CLI, hostmanager, reprise en main), `hook()` protégé (try/catch Throwable) |
| `settings.php`, `events.php` | réglages (option `faaaster_cache_settings`), journal |
| `url.php`, `transport.php`, `purge-queue.php` | normalisation, appels nginx, file |
| `cascade.php`, `content-listener.php`, `global-triggers.php` | purge en cascade, contenu, filet global |
| `takeover.php`, `compat.php` | reprise en main du fork, shim `$nginx_purger` |
| `integrations/` | WP Rocket, WooCommerce, Elementor, Beaver Builder, optimiseurs, caches tiers ; payants en détection seule |
| `ttl-rules.php`, `ttl-emitter.php`, `nginx-conf.php` | TTL par contexte |
| `hostmanager.php`, `cli.php` | `clear_cache` / `flush_object_cache`, WP-CLI |
| `admin/` | page Réglages › Cache Faaaster, barre d'administration, icônes du DS |

`class/wp-rocket-policy.php` (hors module) : cache de pages de WP Rocket coupé selon `DISABLE_WPROCKET`, préchargement et RUCSS bridés.

## Interrupteurs (constantes dans `wp-config.php`)

| Constante | Effet |
|---|---|
| `FAAASTER_CACHE_MODULE_DISABLED` | module entier ; sans le fork dans l'image, **plus aucune purge automatique** (dernier recours) |
| `FAAASTER_CACHE_TTL_DISABLED` | aucun `X-Accel-Expires` ni diagnostic |
| `FAAASTER_CACHE_INTEGRATIONS_DISABLED`, `FAAASTER_CACHE_DISABLE_<ID>` | toutes les intégrations, ou une (`WP_ROCKET`, `WOOCOMMERCE`, `ELEMENTOR`…) |
| `FAAASTER_WP_ROCKET_POLICY_DISABLED` | bridage WP Rocket (le filtre de cache de pages reste régi par `DISABLE_WPROCKET`) |
| `FAAASTER_HOSTMANAGER_REQUIRE_AUTH` | Bearer obligatoire sur **toutes** les routes locales de la plateforme (`hostmanager/v1`, `faaaster-agent/v1`), via la garde commune `faaaster_hostmanager_guard()` (`class/hostmanager-auth.php`, hors module cache). Sans elle, un appel sans Bearer valide est accepté mais journalisé (`[faaaster-hostmanager] <route> called without a valid Bearer (missing\|invalid\|no_key)`) et compté (option `faaaster_hostmanager_auth`, `site_state` → `other_data.hostmanager_auth`). À poser par défaut quand ce compteur reste à zéro sur le parc. |

## WP-CLI

```bash
wp faaaster cache status
wp faaaster cache purge https://example.com/page/ [https://…]
wp faaaster cache purge --all
wp faaaster cache ttl list
wp faaaster cache ttl set front_page 3600   # 0 = ne pas mettre en cache
wp faaaster cache ttl unset front_page
wp faaaster cache ttl reset
```

Les URL se passent en arguments : `--url` est une option globale de WP-CLI. `wp nginx-helper purge-all` reste un alias déprécié quand le fork est absent.

## TTL par contexte

Contextes : `404`, `front_page`, `home`, `singular[:<type>]`, `archive[:<type>]`, `taxonomy[:<taxonomie>]`, `author`, `date`, `search`, `feed`. Le plus précis gagne. Sans règle, **aucun en-tête** : `/app/conf/cache-ttl.user.conf` (10 h) s'applique. Toujours `0` pour `DONOTCACHEPAGE` et les pages panier, commande et compte ; plafond de 11 h si un nonce a été généré pour un visiteur anonyme. `X-Accel-Expires` n'est jamais transmis au visiteur ; `X-Faaaster-Cache-TTL` l'est (ex. `600; rule=front_page`).

## Administration

Page **Réglages › Cache Faaaster** (`manage_options`), rendue côté serveur. Onglets : règles de purge, durées de cache, outils (purger ou tester une adresse, état), journal, intégrations. Toutes les actions passent par `admin-post.php` avec nonce et capacité, puis redirection. Barre d'administration : « Vider le cache » → tout le cache, ou cette page (en front).

- **DS de Next.** `node scripts/sync-ds.mjs [chemin de Next]` (défaut `../next/next`) régénère `assets/ds/tokens.css`, `assets/ds/components.css` (primitives préfixées sous `.fstr-ds`, thème clair seul) et `class/cache/admin/ds-icons.php`. Ne jamais éditer ces fichiers : `assets/admin.css` porte la mise en page locale et la neutralisation de wp-admin (dont `.card` de `common.css` : `max-width: 520px`). Tokens uniquement, aucune couleur en dur (test statique). Comme `Card padded={false}` dans Next, les `.set-row` sont directement dans `.card` ; les champs dans `.card-b`.
- **Feuilles** chargées uniquement sur `settings_page_faaaster-cache` ; polices : familles du DS si elles sont installées sur le poste, sinon la pile standard du tableau de bord WordPress (`sync-ds.mjs` réécrit `--font-ui`, `--font-display` et `--font-mono`) ; aucun fichier de police embarqué, aucun appel à Google Fonts (décision du 04/10/2026 : ne pas alourdir wp-admin).
- **Traductions.** Chaînes source en anglais, domaine `faaaster-manager-plugin`, chargé sur `init`. Éditer `languages/*.po` puis `php scripts/build-i18n.php` (`.mo` + `.l10n.php`, sortie déterministe vérifiée en CI). Un test échoue si une chaîne de `class/cache/admin/` n'est pas traduite ou si une entrée est orpheline.
- **Textes affichés au client** : s'adresser au propriétaire du site, sans renvoyer vers des outils internes ; le détail technique (callbacks du fork retirés) reste replié.

## Suivi par la plateforme

- `site_state` → `other_data.cache` : `module` (`active`/`disabled`), `takeover` (fork repris en main), `ttl_rules`, `purge_rules_customized`.
- `site_state` → `other_data.hostmanager_auth` : appels des routes locales sans Bearer valide (`count`, `last_at`, `last_route`, `last_reason`, `last_agent` ; jamais le jeton).
- `manager_version` → `capabilities.cacheModule` : module actif, donc `clear_cache` répond `502 purge_failed` en cas d'échec.
- Appelants des routes locales au 04/10/2026 : consumer `hostmanager.ts` (hostmanager/v1 et faaaster-agent/v1) et `fstr-wp-op.sh` envoient le Bearer ; `fstr-worker.php` de wp-builder l'envoie depuis 0.12 (`local_route_headers()`, aussi sur `abilities/run` et `rest`). Exception publique à contrôle interne : `sso/v1/login` (jeton validé auprès de Next). `public-hostmanager/v1/toggle_mu_plugin` (code mort : installait un mu-plugin « benchmark-analysis » depuis un bucket de dev) est supprimée en 0.12. `flush_object_cache` et `list_agent_users` exigent toujours le Bearer (bloquant).

## API publique

| Hook | Type | Rôle |
|---|---|---|
| `faaaster_cache_cascade_urls` | filtre `($urls, $post)` | URL purgées pour un contenu |
| `faaaster_cache_hosts` | filtre `($hosts)` | hôtes autorisés pour Cloudflare |
| `faaaster_cache_settings_updated` | action `($section)` | réglages modifiés |
| `rt_nginx_helper_purge_all` | action écoutée | compat : purge totale demandée par un tiers |
| `$GLOBALS['nginx_purger']` | shim | `purge_url()`, `purge_all()`, `purge_post()` (build-agent de Next) |

`$GLOBALS['nginx_helper']` n'est **jamais** défini : il garde éteinte l'intégration nginx-helper native de WP Rocket (purge totale à chaque job RUCSS).

## Pièges

- **Jamais de reset opcache**, même sur `clear_cache` : les images ont `opcache.validate_timestamps=1` et `revalidate_freq=2`, un fichier modifié est recompilé en ≤ 2 s ; un reset ne rafraîchit rien et recompile tout le code à froid (premier MISS 8,5 s contre 1,2 s, mesuré le 04/10/2026). Code réellement périmé : redémarrage de php-fpm (consumer, scope `php`). `clear_cache` vide en revanche l'object cache (premier MISS +1,2 à 2,3 s), indispensable après une mutation WP-CLI.
- **Object cache APCu : CLI ≠ FPM.** WP-CLI a son propre segment APCu par processus. Le module vide l'object cache de FPM après une purge totale ou un changement de réglages lancés en CLI ; ailleurs, passer par la route `clear_cache`.
- **`permission_callback` appelé deux fois** par requête REST : WordPress le rejoue dans `rest_send_allow_header` (en-tête `Allow`) avec le même objet requête. Tout effet de bord (journal, compteur) doit être mémorisé par requête.
- **Ne jamais simuler un hook global** (`do_action('switch_theme')`) sur un site réel : les extensions y réagissent (Elementor efface tous ses CSS).
- Erreurs du module : `class/error-handler.php` ignore les fichiers du manager-plugin ; le module journalise lui-même (`[faaaster-cache]` dans le log d'erreurs PHP).

## Tests

```bash
php test/cache/run.php      # sans WordPress ; FC_VERBOSE=1 pour voir les error_log attendus
php test/auth-cookie.php
```

CI GitHub : PHP 7.4, 8.2, 8.4, 8.5 (`.github/workflows/tests.yml`), plus la vérification que `languages/` est recompilé.
