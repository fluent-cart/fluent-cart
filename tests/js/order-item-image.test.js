import {describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, defaultValue) => defaultValue, onShopUpdate: () => {}}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text, pluralizeTranslate: text => text}));
vi.mock('@/utils/http/Rest', () => ({default: {}}));

const {getOrderItemImageUrl} = await import('@/Bits/common');

// The order API shape for a simple product whose only image is in the product gallery.
const galleryOnlyItem = {variants: {media: null, thumbnail: 'https://example.test/gallery.jpg'}};

describe('getOrderItemImageUrl', () => {
    it('shows the variant thumbnail, which carries the gallery fallback', () => {
        expect(getOrderItemImageUrl(galleryOnlyItem, 'placeholder.png')).toBe('https://example.test/gallery.jpg');
    });

    it('still prefers the item featured media', () => {
        const item = {...galleryOnlyItem, featured_media: 'https://example.test/featured.jpg'};
        expect(getOrderItemImageUrl(item, 'placeholder.png')).toBe('https://example.test/featured.jpg');
    });

    it('falls back to the placeholder when there is no image', () => {
        expect(getOrderItemImageUrl({variants: {media: null, thumbnail: null}}, 'placeholder.png')).toBe('placeholder.png');
    });
});
