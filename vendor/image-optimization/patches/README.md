# Optimization Detective backports (revisions 1, 2 and 3)

## Revision 1 — parser

Prepared with manager-plugin 0.15.0 on 2026-11-10. Based on the official
Optimization Detective **1.0.0-beta7** archive recorded in `../manifest.json`.
Image Prioritizer **1.0.0-beta4** and all compiled browser assets are unchanged.
The upstream GPL license remains in `../optimization-detective/LICENSE.txt`.

`optimization-detective-parser-v1.patch` reproduces the two changed PHP files.
The manifest records the original file hashes, patched file hashes and patch
hash. To reproduce on an extracted upstream archive:

```sh
cd optimization-detective
patch -p1 < /path/to/optimization-detective-parser-v1.patch
```

The original two files used to exercise a competing client-installed copy are
also retained under `test/image-optimization/fixtures/od-upstream/`, outside
the release archive. Their hashes must match the manifest's `upstream_files`.

### Scope

The processor searches the open stack for the nearest matching closer, closes
intervening tags and ignores unmatched closers. Tags, attributes and sibling
indices are updated together. Bookmarks additionally restore the preceding
void/raw-text state, document structure flags, XPath cache and EOF state.
This fixes common builder headings with unclosed inline tags without changing
the customer's HTML. Contextual optional closures are handled for paragraphs,
list items, options/groups and table cells/rows/sections, including sibling
openers and nested list/table boundaries. It does not implement HTML5 tree
construction or synthesize omitted document tags. Other structural tags left
open across a closer, duplicate/misplaced document tags and missing
BODY insertion points prevent injection. Buffered additions and preload headers
are withheld when the structure is ambiguous.

The effective class declares `OD_HTML_Tag_Processor::FAAASTER_PARSER_FIX_REVISION`.
The manager still checks exact OD/IP compatibility. An unmarked class selects
`srcset_only` mode (`od_parser_unpatched`), leaving the client's detector and
native visitors in control. Our adaptive backgrounds, STYLE rewriting and
injection bookkeeping are disabled.

The optimizer adds two narrow integration hooks:

- `od_template_url_metrics`: filter only the rendering view before the metric
  groups are built; the manager excludes measurements lacking the effective parser
  revision from all XPath-based decisions. Marked measurements remain usable
  across unrelated ETag changes according to OD's native behavior. Stored measurements and native REST replacement are untouched.
- `od_detection_script_injection`: reports whether detection was necessary and
  its exact markup was actually inserted immediately before the BODY closing
  token. Calling `append_body_html()` alone does not count as success.

The manager adds the parser revision to OD's native ETag data. Existing page
caches expire normally; upgrading a healthy 0.14 module does not enqueue a
site-wide purge. The generated detector REST URL carries
`faaaster_parser_revision=1` so submissions from older cached HTML cannot create
new manager XPath-based purge decisions. The existing OD root-schema extension
registers the optional integer `faaasterParserRevision`. A route-scoped
`rest_request_before_callbacks` filter stamps it into decoded OD submissions
using this URL revision; it removes any payload marker on old/unknown revisions.
OD's native HMAC authorization, strict validation and storage remain in control.
No additional browser module or per-page metadata is introduced. Decisions and
purges check the stored per-measurement marker, not the whole ETag. This retains
OD's native stale-ETag LCP behavior after unrelated theme/plugin changes while
requesting fresh samples normally. Existing measurements are not deleted. Normal current-revision
decisions retain the existing confirmed, coalesced page-purge mechanism.

Failed necessary injections increment an atomic JSON total per blog/site at
`wp-content/cache/faaaster-image-optimization/.detection-{blog_id}.json` (only
`count`, `next_flush` and the four `css_lcp_unmatched` reason totals, a few hundred
bytes; no per-page files or URL lists).
The existing shared-volume lock (reserved post ID 0) serializes updates. A missing
snapshot initializes from the existing non-autoloaded option
`faaaster_image_detection_failures` (legacy integer or the new shared totals); malformed snapshots are never reset silently.

Both the total and the next checkpoint time are persisted before SQL I/O. There
is at most one SQL checkpoint attempt per minute, including failed attempts and
interrupted workers. SQL errors do not lose the file-backed cumulative total;
the next eligible counter event checkpoints all totals. CSS coverage events and
injection failures share this single SQL budget. No new cron or
per-view database retry is added. If traffic stops, the latest tail remains
persisted in the snapshot and visible in site state even though the SQL checkpoint
may be older. Isolated site-state requests read the atomic snapshot without
loading OD/IP or changing anything. An unreadable/malformed snapshot reports a
null total and `detection_counter_error=detection_counter_unavailable`. Existing
cache logging handles write/checkpoint errors. WP-CLI never increments or flushes.

OD's detector associates actual DOM elements with `data-od-xpath` attributes;
it does not evaluate these paths against the browser's reconstructed tree.
The browser regression therefore checks the real detector's outgoing metric
against the tagged image, including the `<em>` reconstruction case.

## Revision 2 — no stored URL Metrics in pages under WP_DEBUG

Prepared with manager-plugin 0.15.3 on 2026-11-11, on top of revision 1.
`optimization-detective-detection-v2.patch` changes `detection.php` only; the
manifest records its original hash, patched hash and patch hash, and the original
file is kept in `test/image-optimization/fixtures/od-upstream/detection.php`.

Upstream adds `urlMetricGroupCollection` (every stored URL Metric of the page) to
the detector arguments whenever `WP_DEBUG` is on. That JSON is printed into every
optimized page, kept in the page cache and downloaded by every visitor: 148 KB on
the www.faaaster.io home page (11/11/2026). `detect.js` treats the property as
optional. It is now included only when `WP_DEBUG` is on **and** the filter
`faaaster_od_detection_url_metric_group_collection` returns true. `isDebug` and
the browser console logging are unchanged.

```sh
cd optimization-detective
patch -p1 < /path/to/optimization-detective-detection-v2.patch
```

## Revision 3 — no diagnostic attributes in pages

Prepared with manager-plugin 0.16.0 on 2026-11-15, on top of revision 1 (same file).
`optimization-detective-meta-attributes-v3.patch` changes
`class-od-html-tag-processor.php` only; the manifest records the original hash, the
patched hash and the patch hash. The three patches applied in order to the upstream
files reproduce the vendored ones.

Upstream's `set_attribute()` and `remove_attribute()` record every change through
`set_meta_attribute()`: `data-od-replaced-<name>="<former value>"`, `data-od-added-<name>`,
`data-od-removed-<name>`. Image Prioritizer adds `data-od-unknown-tag` and
`data-od-fetchpriority-already-added` the same way. Every image whose `srcset` the manager
rewrites therefore carried its whole former `srcset` a second time: on the
www.stephaneolivier.fr home page (15/11/2026), 231 `data-od-replaced-*` attributes, 62 KB of
577 KB of HTML (2.8 KB of 56.1 KB once compressed), kept in the page cache and downloaded by
every visitor. No script or PHP code reads them: `detect.js` only reads `data-od-xpath`.

`set_meta_attribute()` now writes `xpath` as before and the other names only when the filter
`faaaster_od_meta_attributes` returns true (asked once per document); it returns false when
nothing was written. `data-od-xpath` and the `data-od-added-tag` marker of generated LINK
elements are unchanged. The parser revision constant stays at 1: XPaths and stored
measurements are not affected.

```sh
cd optimization-detective
patch -p1 < /path/to/optimization-detective-meta-attributes-v3.patch
```

## Validation and upstream follow-up

The PHP regression cases live in `test/image-optimization/v015.php` and include
actual `od_optimize_template_output_buffer()` execution. The browser suite
runs the unchanged detection modules and captures their real measurement POST
locally. It does not claim to validate a production WordPress REST endpoint.

`upstream-proposal.md` is a reviewable issue/PR draft for WordPress Performance.
It has not been published. Before updating OD, check upstream's parser and
integration hooks, reapply only still-needed changes, update all manifest
hashes, and rerun the parser, competing-copy, migration and browser regressions.
