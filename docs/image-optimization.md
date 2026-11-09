# Image optimization — manager-plugin 0.13.0

The manager embeds Optimization Detective **1.0.0-beta7** and Image Prioritizer
**1.0.0-beta4**, including compiled assets and licenses. There is no separate
plugin to install. Upstream files remain unchanged; archive origins and file
SHA-256 hashes are pinned in `vendor/image-optimization/manifest.json`.

Local correction after release 0.13.0: registered WordPress widths are supported again.
This correction is not published or activated; its release must be paired with the Nginx widths.json reader.

## Activation and prerequisites

The module is **off by default**. It requires the exact value
`IMAGE_OPTIMIZATION_ENABLED=true`, WordPress >= 6.9, PHP >= 7.4, the Faaaster
page-cache module, and local uploads under `/wp-content/uploads/`.
Enable it only after the customer-domain image route, PX2 signing and shared
image service are available. This release does not deploy those components or
change the wp-builder version pin.

WP-CLI skips the image module: its container environment is not FPM hot config.
It neither persists an image-module observation nor requests image-module purges.
The `hostmanager` request path preserves its existing isolation. Private, trial
and `*.faaaster.dev` sites are excluded. Incompatible effective copies of the
embedded plugins suspend the connector, including client-installed copies.

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
It rewrites eligible `src` and existing `srcset`, preserving crops and picture
media conditions. A new `srcset` requires authored usable `sizes` or a positive
integer HTML `width`. With width W and no usable sizes, it adds
`sizes="(max-width: Wpx) 100vw, Wpx"`. Unknown rendered width gets only a `w0`
`src`; file dimensions alone do not establish rendered width.

Existing source sets without known display sizes are converted without adding
candidates. Picture source sets retain their authored candidates. Unsupported
picture groups and common JavaScript loaders (`data-src`, `data-srcset`, etc.)
are left intact as a group. Simple single-URL inline backgrounds use `w0`;
stylesheet background conversion and video optimization are outside this lot.
IP retains ownership of prioritization, native lazy loading and auto-sizes.

## Measurements, scripts and page-cache purges

Upstream defaults are unchanged: four viewport groups (breakpoints 480, 600,
782), three fresh samples per group, seven-day freshness. The detector loader
waits for `window.load`, then idle when available. Collection uses native
observers and posts the metric on page hide. Gzipped measurement modules total
about 7.8 KB locally, excluding inline parameters and HTML metadata. Full
collection completion removes the detector on the next HTML generation; cached
HTML may keep it until regeneration. No server/browser CPU benchmark is claimed.

Semantic decisions are fingerprinted in OD postmeta. Work stays in the related
WordPress post's `_faaaster_image_pending` metadata until the existing purge
queue confirms Nginx and any targeted managed Cloudflare purge. The successful
purge timestamp is written after confirmation. A shared-volume lock per WP
blog/post serializes metadata read-modify-write and acknowledgement; postmeta
cache is refreshed after lock acquisition.

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
purge error. No new REST health probe runs on each page. REST unavailability
suspends rewriting while retaining ownership of already scheduled purges.

```sh
WP_TEST_CORE_DIR=/path/to/wordpress-6.9 php test/image-optimization/run.php
php test/cache/run.php
php test/auth-cookie.php
php test/agent-catalog-json-safe.php
php test/sso-userinfo-headers.php
php test/loopback-tls.php
php test/theme-quiet-skin.php
```

The image suite uses the real WordPress 6.9 parser and unmodified OD/IP. Purge
tests use the real queue, simulated HTTP and multiple PHP processes against
shared file-backed metadata; they do not contact production. CI covers PHP
7.4, 8.2, 8.4 and 8.5. Release validation runs the full PHP lint and suites on
PHP 7.4.33; deployment and activation require a separate authorized operation.
