# Image optimization — manager-plugin 0.15.2

The manager embeds Optimization Detective **1.0.0-beta7** and Image Prioritizer
**1.0.0-beta4**, including compiled assets and licenses. There is no separate
plugin to install. OD has the declared parser/injection backport revision 1; IP
and compiled upstream assets remain unchanged. Archive origins, upstream/patched
SHA-256 hashes and the reproducible patch are pinned in
`vendor/image-optimization/manifest.json` and `patches/README.md` alongside it.

Release 0.15.0 retains the 0.13.1 registered-width manifest and public URL contract.
It adds supported JavaScript lazy-load source sets, STYLE background conversion,
adaptive CSS backgrounds identified as LCP, and per-feature cuts. IMG `src` stays original.
Before activation, the compatible Nginx reader for `widths.json` must be deployed.

Release 0.15.1 hardens 0.15.0 before activation, without changing the URL contract,
the embedded upstream copies or their patch:
- `data-bg` author text is never copied into a generated STYLE unless it is plain layer
  syntax (stored XSS for Contributors/Authors in 0.13–0.15.0); emitted image paths are
  restricted to URL-safe bytes;
- a suspension after the embedded OD/IP are loaded silences them (no buffering, rewriting
  or store), and OD's save_post replay is never registered;
- the anonymous store route is bounded before WordPress parses it (gzip and raw body
  capped at 256 KiB, IP lock checked first), refused while not active, and capped at
  2,000 URL Metrics posts per site for new URLs;
- page purges no longer hold the per-post lock during Nginx/Cloudflare transport;
- an unchanged `widths.json` is read without taking its lock; a purge-all throttled by the
  storm guard is retried; WP-CLI cron reschedules image purges instead of consuming them.

Release 0.15.2 keeps those store-route refusals when another plugin's `rest_pre_dispatch` or
`rest_request_before_callbacks` callback returns null instead of the value it received (ACF
Pro's `ACF_Rest_Api::initialize` does, seen on www.faaaster.io on 11/11/2026): a refused
request is emptied for every later callback, so nothing decompresses or parses it, and the
refusal is returned again by a last callback (`PHP_INT_MAX`) on both hooks.

## Activation and prerequisites

The module is **off by default**. It requires the exact value
`IMAGE_OPTIMIZATION_ENABLED=true`, WordPress >= 6.9, PHP >= 7.4, the Faaaster
page-cache module, and local uploads under `/wp-content/uploads/`.
Enable it only after the customer-domain image route, PX2 signing and shared
image service are available. This release does not deploy those components or
change the wp-builder version pin.

WP-CLI skips the image module: its container environment is not FPM hot config.
It neither persists an image-module observation nor requests image-module purges.
A WP-CLI cron run (`wp cron event run`) reschedules a pending image purge ten minutes
later instead of consuming the event without a handler.
The `hostmanager` request path preserves its existing isolation. Private, trial
and `*.faaaster.dev` sites are excluded. Incompatible effective copies of the
embedded plugins suspend the connector, including client-installed copies; those client
copies keep their native behavior. Any other suspension once the embedded copies are
loaded (cache module, uploads location, REST availability, manifest) silences them:
`od_can_optimize_response` is false, the store route answers 503 to valid submissions,
and OD's `od_trigger_post_update_actions` (save_post/clean_post_cache replay) stays removed. A
compatible OD copy without the marker on the actual processor class keeps only
our normal/lazy srcset rewrites (`srcset_only`, `od_parser_unpatched`); the client
extension retains control of its own detection script and native visitors.

`IMAGE_OPTIMIZATION_DISABLED_FEATURES` lists functions to disable, never those
allowed. With the master switch on, an absent/empty list enables all five:
`srcset` (normal IMG/PICTURE), `lazy` (JavaScript lazy source sets), `inline_bg`
(inline/data-bg), `style_bg` (STYLE conversion) and `css_lcp` (CSS LCP adaptation
and collector). Cutting `style_bg` also cuts `css_lcp`. Names are lowercased;
whitespace, empty segments and duplicates are ignored. Unknown names are exposed
but ignored. A non-string value, more than 200 characters or invalid characters
is ignored with `features_invalid`; the master switch still overrides everything.
`$_SERVER` takes precedence over `getenv()`. FPM transmission belongs to wp-builder.
The parser correction remains active while the module is active.

## Public URL contract

```text
/_faaaster/images/v1/webp/q80/w{width}/m{mtime}-s{size}/wp-content/uploads/{path}
```

URLs are root-relative, on the customer domain. Output is WebP, quality 80, with
no format negotiation. The source extension remains in the filename.
`mtime` is the source Unix modification time and `size` its byte length; both
are positive integers of at most 12 digits. A changed source version produces
a different URL when the HTML is next generated.

Allowed URL widths are **0, 320, 480, 640, 768, 960, 1280, 1600, 1920, 2560**,
plus integer widths from `wp_get_registered_image_subsizes()` between 1 and 2560.
`w0` converts without resizing. Widths are physical pixels, never enlarged.
The plugin publishes `wp-content/cache/faaaster-image-optimization/widths.json`
under a lock with atomic replacement, retaining previously declared widths for cached URLs.
The manifest is capped at 256 widths and 64 KiB; invalid/unwritable metadata suspends rewriting explicitly.
Nginx must validate this manifest before forwarding a nonstandard width.
Existing cropped WordPress files retain their own source through `w0`; a
`300w` descriptor describes that source's physical width and does not require
an image-service `w300` URL.

Each source-path segment uses PHP `rawurlencode()` (space `%20`, plus `%2B`,
UTF-8 percent-encoded). Literal percent signs in filenames are excluded because
PX2 rejects `%25`. The complete encoded public path is limited to 1,024 bytes;
source eligibility reserves space for the longest emitted width segment.
External sources, query strings, fragments, traversal, encoded separators,
double decoding, files outside the real uploads directory, animated images and
unsupported formats remain unconverted. JPEG, PNG and static WebP are supported.

## HTML rewriting

The connector uses OD's existing HTML traversal before IP creates preload links.
IMG `src` remains the original WordPress URL. Eligible variants are carried in
`srcset` and picture sources, preserving crops and media conditions. A new width
descriptor set requires authored usable `sizes` or a positive integer HTML
`width`. With width W and no usable sizes, it adds
`sizes="(max-width: Wpx) 100vw, Wpx"`. Unknown rendered width gets a single
`srcset="<w0 URL> 1x"`, without inventing `sizes` or changing intrinsic dimensions.

Existing source sets retain their crops; expansion requires known display sizes.
Full-size candidates use `w0` with the natural width descriptor, including widths
above 2560. Picture sources keep their authored candidates and receive usable
sizes for width descriptors when missing. Unsupported picture groups and unsupported IMG
JavaScript loaders remain intact as a group. Supported `data-src` /
`data-lazy-src` families keep their source attributes and convert existing
`data-srcset` / `data-lazy-srcset` candidates individually. Without a real srcset,
a missing twin gets `w0 1x`; an existing real srcset prevents this twin. Lazy
PICTURE groups are validated together. Existing loaders still trigger loading.
IP retains ownership of prioritization, native lazy loading and auto-sizes.

Inline `background-image` and `background` support quoted/unquoted URLs, CSS
escapes and multiple layers. Eligible non-IMG `data-bg` values are also rewritten;
their existing loader still starts loading. Because the adaptive `data-bg` rule copies
the authored value into a generated STYLE, text around the converted `url()` tokens must
be plain layer syntax (letters, digits, spaces and `, ( ) . % # + _ / -`, balanced
parentheses): any quote, escape, comment, `;`, `{`, `}`, `!`, `@`, `:` or `<` leaves the
element exactly as authored. A generated STYLE is never emitted if it contains `</`. In STYLE blocks only background and
background-image URLs are converted to w0; cursor URLs, custom properties and
other declarations are preserved. Visible hash-based style CSP prevents STYLE
rewriting; nonces are retained. External CSS files and video remain outside this lot.
Without measurements, backgrounds use `w0`. Measured sizing uses the conservative
cover extent `max(element.width, element.height * source.width / source.height)`
per viewport group, rounded up to an admitted width and capped at the source width.
CSS resolution queries apply actual DPR within 1..3; densities outside that range
use its end variants. Identical adjacent viewport decisions share CSS rules.
Critical-background preloads use the same variants/media conditions. Inline
backgrounds retain IP's lazy loader, including authored `!important` styles.

Only CSS backgrounds identified as LCP become adaptive: a viewport group needs
complete sample agreement on the exact tag/id/class identity, URL and measured
geometry. The target must be unique in the HTML and the source present in an
eligible STYLE block. The same cover calculation and source/DPR buckets produce
CSS variables and preloads inside the existing STYLE, retaining its nonce/media
and leading imports. Missing/ambiguous data keeps w0. Other CSS backgrounds only
receive w0 conversion; external stylesheet selectors are never evaluated by PHP.

## Measurements, scripts and page-cache purges

Upstream defaults are unchanged: four viewport groups (breakpoints 480, 600,
782), three fresh samples per group, seven-day freshness. The detector loader
waits for `window.load`, then idle when available. Collection uses native
observers and posts the metric on page hide. The store route is guarded before WordPress
parses the request: a non-empty submission is refused while the module is not active
(503) or IP-locked (423); a gzip body over 64 KiB, or decompressing beyond 256 KiB, and
a raw body over 256 KiB are refused (413) without unbounded decompression. A refused body is
emptied, and the refusal is reasserted last on both hooks, so a plugin that drops filter values
(ACF Pro) cannot cancel it. OD's maximum
URL Metric size is lowered to the same 256 KiB. After validation, a submission for a URL
without an OD post is refused (507) once the site holds 2,000 URL Metrics posts
(`faaaster_image_optimization_max_url_metrics_posts`). An empty body (OD's availability
check) and validation errors keep their native answers. The parser revision is stamped
on the validated, sanitized parameters, without re-encoding the body. Gzipped measurement modules total
about 7.8 KB locally, excluding inline parameters and HTML metadata. The small
`assets/image-css-lcp.js` extension is declared only with `css_lcp` active. It uses
OD's native onLCP callback for the final candidate, without a whole-DOM scan, and
submits same-origin background URL/geometry as `faaasterCssLcp`. Full
collection completion removes the detector on the next HTML generation; cached
HTML may keep it until regeneration. No server/browser CPU benchmark is claimed.

Source dimensions by XPath are stored as one snapshot per existing OD URL post in
`_faaaster_image_background_sources`. The completed HTML traversal writes only
when this snapshot changes, clears removed backgrounds, and creates no metadata
for pages without eligible backgrounds. CSS LCP source dimensions share that
snapshot under `CSS:<source variant key>`; no second per-page store is added. The first visit may not yet have an OD
post; dimensions are saved on a later generation after OD creates it. Measurement
storage reads this snapshot without resolving image files or rewriting dimensions.

OD ETag data contains the effective parser revision and sorted active features.
Each new measurement is stamped with `faaasterParserRevision` from the generated
REST URL. Unmarked/older measurements remain stored but are excluded from all
XPath decisions; current-revision samples remain usable after unrelated ETag
changes. The parser upgrade alone does not purge a healthy 0.14 site's pages.
A changed effective feature list is observed by the existing enqueue_all flow.

Semantic decisions are fingerprinted in OD postmeta. Background CSS and the
fingerprint share the same cover calculation and admitted-width rounding; raw
decimal geometry and IMG geometry, including `*[n][self::IMG]` paths, do not enter
background decisions. Work stays in the related
WordPress post's `_faaaster_image_pending` metadata until the existing purge
queue confirms Nginx and any targeted managed Cloudflare purge. The successful
purge timestamp is written after confirmation. A shared-volume lock per WP
blog/post serializes metadata read-modify-write and acknowledgement; postmeta
cache is refreshed after lock acquisition. The lock is held for postmeta I/O only:
a purge claims its due entries (attempt persisted), releases the lock for the
Nginx/Cloudflare transport, then settles on a fresh read. A decision stored during
transport keeps its own pending entry. A lock or postmeta error in the store path is
logged and never turns the stored sample into a REST error.

The existing `od_trigger_page_cache_invalidation` cron coalesces work over ten
minutes. At most **five attempts per decision** are allowed, separated by at
least ten minutes. Attempts are persisted before transport. Exhausted work
remains pending without an automatic loop; a changed semantic decision resets
its budget. Cron lock/transport exceptions are caught and recorded. A lock
acquisition failure preserves work and does not start an automatic retry loop.
This is HTML page-cache invalidation; there is no image-variant purge API.

## Site state and verification

`other_data.image_optimization` exposes configuration, compatibility, suspension
reason, dated web observation, currently initialized allowed widths (null on isolated requests), manifest path, the stored
`od_rest_api_unavailable` health result (null until checked), and the latest
purge error. It also exposes parser revision/mode, `features`, `features_disabled`
(including dependency cuts), `features_ignored`, `features_source`
(default/configured/invalid) and `features_error`. CLI leaves effective feature
configuration null. No new REST health probe runs on each page. REST unavailability
suspends rewriting while retaining ownership of already scheduled purges.

`detection_injection_failures` counts necessary injections that failed to appear
immediately before the closing BODY. `css_lcp_unmatched` counts valid viewport
consensus groups that cannot adapt, by `target_not_found`, `target_not_unique`,
`source_not_in_style` or `csp`; there is no URL list. Both use one atomic site/blog
snapshot `.detection-{blog_id}.json`, the existing shared lock and one shared SQL
checkpoint attempt per minute at most. State includes the durable uncheckpointed
tail. Legacy integer options/snapshots are readable; corrupt data reports null
and `detection_counter_error`. SQL errors retain totals; WP-CLI never writes them.

```sh
WP_TEST_CORE_DIR=/path/to/wordpress-6.9 php test/image-optimization/run.php
php test/cache/run.php
php test/auth-cookie.php
php test/agent-catalog-json-safe.php
php test/sso-userinfo-headers.php
php test/loopback-tls.php
php test/theme-quiet-skin.php
WP_TEST_CORE_DIR=/path/to/wordpress-6.9 python3 test/image-optimization/browser.py --chrome /path/to/chrome
```

The image suite uses the real WordPress 6.9 parser, declared OD backports and unchanged IP. Purge
tests use the real queue, simulated HTTP and multiple PHP processes against
shared file-backed metadata; they do not contact production. CI covers PHP
7.4, 8.2, 8.4 and 8.5. Release validation runs the full PHP lint and suites on
PHP 7.4.33 (980 active-path, 62 purge, 37 counter, 20 REST-metric and 528 cache
checks), collector syntax/unit tests and 80 local Chrome scenarios; deployment and activation require a separate authorized operation.
The browser suite requires Python >= 3.9 and Chrome/Chromium. It serves local
fixtures with actual OD preload tags/headers and checks variant selection,
cover heroes, fractional DPR, original-free image requests, lazy loading, duplicate
downloads and rejected variant paths. Pinned real lazysizes, vanilla-lazyload, WP
Rocket, LiteSpeed, Smush and Swiper 8/11 fixtures exercise the existing loaders.
The actual OD detector POST, optional HTML closures, STYLE CSP and CSS LCP
preload/variant agreement are also checked. It simulates delivery with PNG fixtures;
shared-service encoding and the live site recipe are separate checks.
