import {beforeEach, describe, expect, it, vi} from 'vitest';

// Route params come from the page URL, so a crafted link reaches the REST helper.
// A safe URL stays inside the REST base and has no param that changes the method or the route.
class FakeXMLHttpRequest {
    static instances = [];

    constructor() {
        this.headers = {};
        FakeXMLHttpRequest.instances.push(this);
    }

    open(method, url) {
        this.method = method;
        this.url = url;
    }

    setRequestHeader(key, value) {
        this.headers[key] = value;
    }

    send(body) {
        this.body = body;
    }
}

const pretty = 'https://store.test/wp-json/fluent-cart/v2';
const plain = 'https://store.test/index.php?rest_route=/fluent-cart/v2';

const load = async (base, querySeparators) => {
    vi.resetModules();
    FakeXMLHttpRequest.instances = [];
    vi.stubGlobal('XMLHttpRequest', FakeXMLHttpRequest);
    const rest = {url: base, nonce: 'n'};
    if (querySeparators !== undefined) {
        rest.query_separators = querySeparators;
    }
    vi.stubGlobal('window', {
        location: {href: 'https://store.test/wp-admin/admin.php?page=fluent-cart'},
        fluentCartRestVars: {rest},
    });
    vi.stubGlobal('document', {dispatchEvent: vi.fn()});

    return import('../../resources/admin/utils/http/Rest.js');
};

describe.each([
    ['pretty permalinks', pretty, [
        '../../orders/1?_method=DELETE#',
        '../x',
        '%2e%2e/%2e%2e/wp/v2/users',
        'slug?rest_route=/wp/v2/users',
        // "&" is part of the path on pretty permalinks, so it must not end the check
        'customer-profile/licenses/x&/../../../orders/1',
    ]],
    ['plain permalinks', plain, [
        'x&rest_route=/fluent-cart/v2/orders/1',
        'x&rest.route=/fluent-cart/v2/orders/1',
        '../../wp/v2/users',
    ]],
])('isSafeRestUrl with %s', (label, base, unsafeForBase) => {
    const sep = base === plain ? '&' : '?';

    it.each([
        'orders',
        'products/42/related-products',
        `coupons/${encodeURIComponent('a b?c&d#e')}`,
        `orders${sep}page=2&filters%5Bstatus%5D=paid&query_timestamp=1`,
    ])('allows %s', async (route) => {
        const {isSafeRestUrl} = await load(base);

        expect(isSafeRestUrl(`${base}/${route}`)).toBe(true);
    });

    it.each([
        ...unsafeForBase,
        `slug${sep}_method=DELETE`,
        `slug${sep}%5Fmethod=DELETE`,
        `slug${sep}_method[]=DELETE`,
        `slug${sep}.method=DELETE`,
        `slug${sep}%20_method=DELETE`,
        `slug${sep}_method%00=DELETE`,
        `slug${sep}_jsonp=x`,
        `slug${sep}_envelope=1`,
        'slug#x',
        'slug\\x',
        // dot segments inside the route resolve into another route
        'orders/1/../../customers/3',
        'orders/1/%2e%2e/%2e%2e/customers/3',
        'coupons/..%2F..%2F..%2Fwp%2Fv2%2Fusers',
        // an encoded slash or backslash leaves its segment once the server decodes it
        'orders/5%2Frefund',
        'orders/5%5Crefund',
        // the URL parser strips tabs and newlines, which turns ".\t." into ".."
        'orders/.\t./x',
        'orders/1/.\n./customers/3',
        'orders/1/.\r./customers/3',
        'orders/1\u0000',
        // PHP can be set to split query params on ";" too (arg_separator.input="&;"), which URLSearchParams does not
        'x;rest_route=/wp/v2/users/1;_method=DELETE',
        `slug${sep}x=1;_method=DELETE`,
        'orders;x',
        // PHP turns an unmatched "[" into "_": rest[route=... reaches WordPress as rest_route=... (a second route)
        `slug${sep}rest[route=/wp/v2/users`,
        `slug${sep}x=1&rest[route=/wp/v2/users`,
    ])('rejects %s', async (route) => {
        const {isSafeRestUrl} = await load(base);

        expect(isSafeRestUrl(`${base}/${route}`)).toBe(false);
    });

    it('rejects another origin', async () => {
        const {isSafeRestUrl} = await load(base);

        expect(isSafeRestUrl('https://evil.test/wp-json/fluent-cart/v2/orders')).toBe(false);
    });
});

describe('request helper', () => {
    // the shape Rest.js uses for every failure, so notices read error.data.message
    const invalid = {data: {code: 'fcart_invalid_rest_route'}, status_code: 0};

    beforeEach(() => {
        vi.spyOn(Date, 'now').mockReturnValue(1785481200123);
    });

    it('rejects a crafted route without sending it', async () => {
        const {default: Rest} = await load(pretty);

        await expect(Rest.get('../../orders/1?_method=DELETE#')).rejects.toMatchObject(invalid);
        await expect(Rest.post('../orders/1')).rejects.toMatchObject(invalid);
        await expect(Rest.put('../orders/1')).rejects.toMatchObject(invalid);
        await expect(Rest.patch('../orders/1')).rejects.toMatchObject(invalid);
        await expect(Rest.delete('../orders/1')).rejects.toMatchObject(invalid);
        await expect(Rest.upload('../orders/1', new FormData())).rejects.toMatchObject(invalid);
        await expect(Rest.get('customer-profile/licenses/x&/../../../orders/1')).rejects.toMatchObject(invalid);
        await expect(Rest.delete('orders/.\t./x')).rejects.toMatchObject(invalid);

        expect(FakeXMLHttpRequest.instances).toHaveLength(0);
    });

    it('keeps the route out of the error message, which is rendered as HTML', async () => {
        const {default: Rest} = await load(pretty);

        const error = await Rest.get('orders/<img src=x onerror=alert(1)>#/x').catch((e) => e);

        expect(error).toEqual({data: {message: 'Invalid REST route.', code: 'fcart_invalid_rest_route'}, status_code: 0});
    });

    it('rejects an override param in the query data', async () => {
        const {default: Rest} = await load(pretty);

        await expect(Rest.get('orders', {_method: 'DELETE'})).rejects.toMatchObject(invalid);
        // an array value is sent as _method[0], which PHP reads as _method
        await expect(Rest.get('orders', {_method: ['DELETE']})).rejects.toMatchObject(invalid);
        expect(FakeXMLHttpRequest.instances).toHaveLength(0);

        // a nested key is $_GET['filters']['_method'], which WordPress never reads as the method
        Rest.get('orders', {filters: {_method: 'DELETE'}});
        expect(FakeXMLHttpRequest.instances).toHaveLength(1);
    });

    it('still sends a normal request and a normal method override', async () => {
        const {default: Rest} = await load(pretty);

        Rest.get('orders', {page: 2});
        Rest.put('orders/42', {total_amount: 1});

        expect(FakeXMLHttpRequest.instances.map((xhr) => xhr.method)).toEqual(['GET', 'POST']);
        expect(FakeXMLHttpRequest.instances[1].headers['X-HTTP-Method-Override']).toBe('PUT');
    });

    it('checks a custom base URL against that base, not the FluentCart one', async () => {
        const {default: Rest} = await load(pretty);
        const playerBase = 'https://store.test/wp-json/fluent-player/v1';

        Rest.get('media/search', {q: 'intro'}, playerBase);
        expect(FakeXMLHttpRequest.instances[0].url).toContain(`${playerBase}/media/search`);

        await expect(Rest.get('../../fluent-cart/v2/orders', {}, playerBase)).rejects.toMatchObject(invalid);
        // the rejected call sent nothing, so only the first request exists
        expect(FakeXMLHttpRequest.instances).toHaveLength(1);

        Rest.post('media', {}, playerBase);
        expect(FakeXMLHttpRequest.instances).toHaveLength(2);
        expect(FakeXMLHttpRequest.instances[1].url).toBe(`${playerBase}/media`);
    });
});

// PHP splits the query on every character in arg_separator.input; the server passes that set as rest.query_separators.
describe('configured PHP query separators', () => {
    it('rejects a separator the server is set to use, on plain permalinks', async () => {
        const {isSafeRestUrl} = await load(plain, '&|');

        expect(isSafeRestUrl(`${plain}/x|rest_route=/wp/v2/users/1|_method=DELETE`)).toBe(false);
        expect(isSafeRestUrl(`${plain}/orders&page=2`)).toBe(true);
    });

    it('rejects a separator the server is set to use in the query on pretty permalinks', async () => {
        const {isSafeRestUrl} = await load(pretty, '&|');

        expect(isSafeRestUrl(`${pretty}/orders?x=1|_method=DELETE`)).toBe(false);
        // the path is not parsed as a query, so the character is harmless there
        expect(isSafeRestUrl(`${pretty}/orders/a|b`)).toBe(true);
    });

    it('leaves a character alone when the server does not split on it', async () => {
        const {isSafeRestUrl} = await load(plain, '&');

        expect(isSafeRestUrl(`${plain}/orders/a|b`)).toBe(true);
    });

    it('still refuses ";" when the page does not say which separators the server uses', async () => {
        const {isSafeRestUrl} = await load(plain);

        expect(isSafeRestUrl(`${plain}/x;rest_route=/wp/v2/users/1`)).toBe(false);
    });

    it('refuses the request without sending it', async () => {
        const {default: Rest} = await load(plain, '&|');

        await expect(Rest.get('x|rest_route=/wp/v2/users/1|_method=DELETE')).rejects.toMatchObject({status_code: 0});
        expect(FakeXMLHttpRequest.instances).toHaveLength(0);
    });
});
