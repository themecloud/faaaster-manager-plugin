/** Enrich OD's LCP measurement through its native extension API, without scanning the DOM. */
export const name = 'Faaaster CSS LCP';

export function initialize({ onLCP, extendRootData }) {
    onLCP((metric) => {
        let geometry = null;
        const entry = metric.entries[metric.entries.length - 1];
        const element = entry && entry.element;
        if (entry && typeof entry.url === 'string' && entry.url.length <= 500
            && element instanceof HTMLElement && !(element instanceof HTMLImageElement)
            && !(element instanceof HTMLVideoElement) && !element.style.backgroundImage
            && element.tagName.length <= 100) {
            const id = element.getAttribute('id');
            const classes = element.getAttribute('class');
            const source = new URL(entry.url, location.href);
            if ((id === null || id.length <= 100) && (classes === null || classes.length <= 500)
                && source.origin === location.origin && !source.search && !source.hash
                && /^\/_faaaster\/images\/v1\/webp\/q80\/w(?:0|[1-9][0-9]{0,3})\/m[1-9][0-9]{0,11}-s[1-9][0-9]{0,11}\/wp-content\/uploads\//.test(source.pathname)
                && getComputedStyle(element).backgroundImage.includes('url("' + source.href + '")')) {
                const rect = element.getBoundingClientRect();
                if (Number.isFinite(rect.width) && Number.isFinite(rect.height) && rect.width > 0 && rect.height > 0) {
                    geometry = { url: entry.url, width: rect.width, height: rect.height };
                }
            }
        }
        // A later image/text LCP must not retain an earlier CSS candidate's geometry.
        extendRootData({ faaasterCssLcp: geometry });
    }, { reportAllChanges: true });
}
