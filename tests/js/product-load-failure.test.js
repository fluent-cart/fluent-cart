import {beforeAll, describe, expect, it, vi} from 'vitest';

// Bodies captured from GET /fluent-cart/v2/products/{id}; the server side of the
// missing-product body is pinned by tests/wp-cli/integration/product-not-found-response.php.
const DELETED_PRODUCT = {
    code: 'Permission Callback Error',
    message: 'No query results for model [FluentCart\\App\\Models\\Product].',
    data: {status: 403},
};
const NO_PERMISSION = {code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 403}};
const LOGGED_OUT = {code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 401}};

let productLoadFailure;

beforeAll(async () => {
    vi.doMock('@wordpress/api-fetch', () => ({default: () => Promise.resolve({})}));
    vi.doMock('@wordpress/url', () => ({addQueryArgs: (url) => url}));
    vi.doMock('@/BlockEditor/ShopApp/Context/SingleProductContext', () => ({useSingleProductData: () => ({})}));

    globalThis.window = {fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}}};
    globalThis.wp = {
        element: {useEffect: () => {}, useState: (value) => [value, () => {}]},
        data: {useSelect: () => null},
        blockEditor: {store: 'core/block-editor'},
    };

    ({productLoadFailure} = await import('@/BlockEditor/ProductReviews/Components/reviewBlockShared.js'));
});

describe('productLoadFailure', () => {
    it('reads the real deleted-product response as missing', () => {
        expect(productLoadFailure(DELETED_PRODUCT)).toEqual({missing: true});
    });

    it('reads a plain 404 as missing', () => {
        expect(productLoadFailure({code: 'rest_no_route', message: 'Not found', data: {status: 404}})).toEqual({missing: true});
    });

    it('does not call a permission denial a deleted product', () => {
        expect(productLoadFailure(NO_PERMISSION)).toEqual({loadError: true});
        expect(productLoadFailure(LOGGED_OUT)).toEqual({loadError: true});
    });

    it('treats a network failure as a load error', () => {
        expect(productLoadFailure({code: 'fetch_error', message: 'You are probably offline.'})).toEqual({loadError: true});
        expect(productLoadFailure(undefined)).toEqual({loadError: true});
    });
});
