# Draft: tolerate unmatched and unclosed inline tags in OD HTML processing

Target: WordPress/performance, Optimization Detective. Not yet published.

On Optimization Detective 1.0.0-beta7 with WordPress 6.9, a heading containing
an unclosed inline element can corrupt the processor's stack and BODY insertion
bookmark. The browser renders the page, but OD's detector can be missing or
inserted after the BODY closer. An unmatched inline closer can also change the
identifiers assigned to later siblings.

Minimal reproduction, replacing the contents of MAIN with each heading:

```html
<html><head></head><body><main id="main">
  <h2><span>Title</h2>
  <img src="example.jpg"><img src="example.jpg">
</main></body></html>
```

Additional cases: `<h2><span><em>Title</h2>` and
`<h2>Title</span></h2>`. Iterate an `OD_HTML_Tag_Processor`, append a BODY script,
and inspect the resulting insertion point and identifiers of both IMG siblings.

Proposed repair: find the nearest matching opener for a closing token; ignore
unmatched closers; pop matching/intervening tags, attributes and sibling-index
state together. Handle optional paragraph/list/select/table closures in context,
including sibling openers and nested scopes. Restore void/raw-text and EOF state
when seeking a bookmark.
Use the actual matched tag to identify HEAD/BODY insertion points. Conservative
injection guards avoid attempting full browser tree reconstruction.

Expected result: detection markup precedes the real BODY closer, sibling
identifiers remain stable and distinct, and the detector's actual DOM-based
measurements map back to their `data-od-xpath` identifiers. Ambiguous structural
markup should not receive injected markup. The customer's original markup is
otherwise preserved.

The accompanying patch also demonstrates optional hooks for filtering the
rendering metric view and observing actual injection. These can be reviewed
separately from the parser fix. Our integration uses them to exclude old-format
ETag samples without deleting data and count failed necessary injections.
The Faaaster capability constant and manager hooks are downstream integration
details; they need not be adopted upstream verbatim.

Regression artifacts in this repository: `test/image-optimization/v015.php`,
`test/image-optimization/browser.py`, and the portable patch beside this draft.
