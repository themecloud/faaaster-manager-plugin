import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const code = await readFile(new URL('../../assets/image-css-lcp.js', import.meta.url), 'utf8');
const { initialize } = await import('data:text/javascript,' + encodeURIComponent(code));
globalThis.location = new URL('https://client.test/page/');
globalThis.HTMLElement = class {
    tagName = 'DIV';
    style = { backgroundImage: '' };
    attrs = { id: 'hero', class: 'hero' };
    rect = { width: 390, height: 844 };
    reads = 0;
    getAttribute(name) { return this.attrs[name] ?? null; }
    getBoundingClientRect() { this.reads++; return this.rect; }
};
globalThis.HTMLImageElement = class extends HTMLElement {};
globalThis.HTMLVideoElement = class extends HTMLElement {};
globalThis.getComputedStyle = (element) => ({ backgroundImage: element.computed ?? 'url("' + url + '")' });
const url = 'https://client.test/_faaaster/images/v1/webp/q80/w0/m123-s456/wp-content/uploads/hero.png';
let callback;
const results = [];
initialize({ onLCP(fn, options) { callback = fn; assert.equal(options.reportAllChanges, true); }, extendRootData(data) { results.push(data); } });
const element = new HTMLElement();
callback({ entries: [{ url, element }] });
assert.deepEqual(results.at(-1), { faaasterCssLcp: { url, width: 390, height: 844 } });
assert.equal(element.reads, 1);
callback({ entries: [{ url, element: new HTMLImageElement() }] });
assert.equal(results.at(-1).faaasterCssLcp, null, 'a later IMG candidate clears CSS geometry');
callback({ entries: [{ url, element: new HTMLVideoElement() }] });
assert.equal(results.at(-1).faaasterCssLcp, null);
for (const make of [
    () => ({ url: '', element: new HTMLElement() }),
    () => ({ url: 'https://other.test/' + url.split('client.test/')[1], element: new HTMLElement() }),
    () => ({ url: 'https://client.test/wp-content/themes/hero.png', element: new HTMLElement() }),
    () => ({ url: 'https://client.test/_faaaster/images/v1/webp/q80/w0/m123-s456/wp-content/themes/hero.png', element: new HTMLElement() }),
    () => ({ url: 'https://client.test/x/wp-content/uploads/hero.png', element: new HTMLElement() }),
    () => ({ url: 'https://client.test/wp-content/uploads/hero.png?v=1', element: new HTMLElement() }),
    () => ({ url: url + '?variant=1', element: new HTMLElement() }),
    () => ({ url, element: Object.assign(new HTMLElement(), { style: { backgroundImage: 'url("' + url + '")' } }) }),
    () => ({ url, element: Object.assign(new HTMLElement(), { computed: 'none' }) }),
    () => ({ url, element: Object.assign(new HTMLElement(), { rect: { width: 0, height: 844 } }) }),
    () => ({ url, element: Object.assign(new HTMLElement(), { rect: { width: Infinity, height: 844 } }) }),
    () => ({ url, element: Object.assign(new HTMLElement(), { attrs: { id: 'x'.repeat(101) } }) }),
]) {
    callback({ entries: [make()] });
    assert.equal(results.at(-1).faaasterCssLcp, null);
}
// A stylesheet file still naming the original upload is measured too (its rule is adapted in the page).
const original = 'https://client.test/wp-content/uploads/2026/11/hero.png';
const styled = Object.assign(new HTMLElement(), { computed: 'url("' + original + '")' });
callback({ entries: [{ url: original, element: styled }] });
assert.deepEqual(results.at(-1), { faaasterCssLcp: { url: original, width: 390, height: 844 } });
callback({ entries: [{ url, element }, { url: '', element }] });
assert.equal(results.at(-1).faaasterCssLcp, null, 'only the final entry is recorded');
assert.equal(element.reads, 1, 'no geometry read for skipped candidates');
console.log('OK CSS LCP collector: dimensions, final candidate, origin, original and variant paths, inline/pseudo exclusions, no DOM scan');
