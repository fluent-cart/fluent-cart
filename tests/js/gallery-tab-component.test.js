import {describe, it, expect, vi, beforeEach} from 'vitest';

// The factory's whole job is the option set it hands defineAsyncComponent, so
// the spy is the assertion surface.
const defineAsyncComponent = vi.fn(options => ({__options: options}));
vi.mock('vue', () => ({defineAsyncComponent: (...args) => defineAsyncComponent(...args)}));
vi.mock('@/Modules/Products/parts/GalleryTabLoading.vue', () => ({default: {name: 'GalleryTabLoading'}}));
vi.mock('@/Modules/Products/parts/GalleryTabError.vue', () => ({default: {name: 'GalleryTabError'}}));

const {galleryTabComponent} = await import('@/Modules/Products/galleryTabComponent');

describe('galleryTabComponent', () => {
    beforeEach(() => {
        defineAsyncComponent.mockClear();
    });

    it('gives a lazily-imported tab a loading and an error state', () => {
        const loader = () => Promise.resolve({default: {name: 'Tab'}});

        galleryTabComponent(loader);

        const options = defineAsyncComponent.mock.calls[0][0];
        expect(options.loader).toBe(loader);
        expect(options.loadingComponent.name).toBe('GalleryTabLoading');
        expect(options.errorComponent.name).toBe('GalleryTabError');
    });

    it('shows the loading state on the first frame — an empty pane has no flicker to protect', () => {
        galleryTabComponent(() => Promise.resolve({}));

        expect(defineAsyncComponent.mock.calls[0][0].delay).toBe(0);
    });

    it('gives up rather than spinning forever', () => {
        galleryTabComponent(() => Promise.resolve({}));

        expect(defineAsyncComponent.mock.calls[0][0].timeout).toBe(30000);
    });

    it('does not try to reload a failed chunk — the browser caches that failure', () => {
        galleryTabComponent(() => Promise.resolve({}));

        // An onError that retried would re-run the same import() and get the
        // module map's cached rejection back, without a request. Recovering
        // takes a page load, which is what the error component says.
        expect(defineAsyncComponent.mock.calls[0][0].onError).toBeUndefined();
    });

    it('lets a caller override any of it', () => {
        const errorComponent = {name: 'Custom'};

        galleryTabComponent(() => Promise.resolve({}), {delay: 200, timeout: 5000, errorComponent});

        const options = defineAsyncComponent.mock.calls[0][0];
        expect(options.delay).toBe(200);
        expect(options.timeout).toBe(5000);
        expect(options.errorComponent).toBe(errorComponent);
        expect(options.loadingComponent.name).toBe('GalleryTabLoading');
    });

    it('returns what defineAsyncComponent returns, so it can be used as a tab component', () => {
        const component = galleryTabComponent(() => Promise.resolve({}));

        expect(component).toBe(defineAsyncComponent.mock.results[0].value);
    });
});
