import { defineAsyncComponent } from 'vue';
import GalleryTabLoading from '@/Modules/Products/parts/GalleryTabLoading.vue';
import GalleryTabError from '@/Modules/Products/parts/GalleryTabError.vue';

/**
 * Wraps a Product Gallery tab's lazy import (see galleryTabs.js for the tab
 * contract) so its pane is never blank while the chunk is on the wire: a bare
 * `defineAsyncComponent` renders nothing until it resolves, which on a cold
 * cache reads as a broken tab.
 *
 * `delay: 0` puts the skeleton up on the first frame — the pane is empty
 * before it, so there is no content for a grace period to protect from
 * flicker — and a chunk that never arrives lands on an error rather than on
 * that same blank.
 *
 * No retry: the browser records a failed dynamic import in its module map, so
 * re-running the same `import()` resolves to that failure without touching the
 * network. Recovering really does take a page load, which is what the error
 * component tells the reader.
 *
 * Kept out of galleryTabs.js so the media picker, which only needs that file's
 * ordering helpers, does not pull these components into its bundle.
 *
 * @param {() => Promise<object>} loader the same `() => import('...')` you would pass to defineAsyncComponent.
 * @param {object} options overrides merged over the defaults.
 */
export function galleryTabComponent(loader, options = {}) {
    return defineAsyncComponent({
        loader,
        loadingComponent: GalleryTabLoading,
        errorComponent: GalleryTabError,
        delay: 0,
        timeout: 30000,
        ...options,
    });
}
