# Pinned browser-only loader fixtures

These scripts are copied from the official projects recorded in `manifest.json`.
The manifest pins source URLs, versions/commits, SHA-256 hashes and retained
licenses. They are regression fixtures, not runtime dependencies. `/test/` is
excluded from the manager release archive by `.gitattributes`.

The Chrome suite loads the actual scripts against PHP-rewritten HTML, checks
that the image is not fetched before scrolling, and records all image requests.
Each loader is exercised with a generated twin attribute, an authored width
set at DPR 1 and 2, and a PICTURE source. IMG `src` ends as the original URL;
the browser downloads a variant, without a second image request.

Swiper 8 uses its JavaScript lazy module with `checkInView: true`; authored
`data-sizes="400px"` also supplies the size to its detached image preloader.
Without this Swiper setting, it may preload a slide before it enters view;
without `data-sizes`, its detached preloader may select a larger candidate.
The manager preserves those authored choices and does not configure Swiper.
Swiper 11 uses the native `loading="lazy"` path introduced in Swiper 9.

WP Rocket uses its `data-lazy-*` settings. LiteSpeed includes its official
initialization code as well as its library. Smush uses its shipped lazy-load
bundle, including its bundled lazysizes implementation. Delivery is simulated
with local PNG files; shared-service WebP encoding is tested separately.
