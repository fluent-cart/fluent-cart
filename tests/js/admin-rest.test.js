import {beforeEach, describe, expect, it, vi} from 'vitest';
import ResponseProxyItr from '../../resources/admin/utils/http/ResponseProxyItr.js';
import Errors from '../../resources/admin/Modules/Integrations/Common/Errors.js';

class FakeXMLHttpRequest {
    static instances = [];

    constructor() {
        this.headers = {};
        this.status = 0;
        this.statusText = '';
        this.responseText = '';
        FakeXMLHttpRequest.instances.push(this);
    }

    open(method, url, async) {
        this.method = method;
        this.url = url;
        this.async = async;
    }

    setRequestHeader(key, value) {
        this.headers[key] = value;
    }

    send(body) {
        this.body = body;
    }

    respond(status, payload) {
        this.status = status;
        this.responseText = typeof payload === 'string'
            ? payload
            : JSON.stringify(payload);
        this.onload();
    }

    fail(status = 0, statusText = 'Network Error') {
        this.status = status;
        this.statusText = statusText;
        this.onerror();
    }
}

const importRest = async () => {
    vi.resetModules();
    FakeXMLHttpRequest.instances = [];
    vi.stubGlobal('XMLHttpRequest', FakeXMLHttpRequest);
    vi.stubGlobal('window', {
        fluentCartRestVars: {
            rest: {
                url: 'https://store.test/wp-json/fluent-cart/v2',
                nonce: 'phase25-nonce',
            },
        },
    });
    vi.stubGlobal('document', {
        dispatchEvent: vi.fn(),
    });
    vi.stubGlobal('CustomEvent', class {
        constructor(type, options) {
            this.type = type;
            this.detail = options.detail;
        }
    });

    return (await import('../../resources/admin/utils/http/Rest.js')).default;
};

describe('admin REST transport', () => {
    beforeEach(() => {
        vi.spyOn(Date, 'now').mockReturnValue(1785481200123);
    });

    it('serializes GET values and method-overrides PUT with exact headers and JSON', async () => {
        const Rest = await importRest();
        const getPromise = Rest.get('/orders?existing=1', {
            filters: {
                status: ['paid', 'pending'],
                range: {min: 1001},
            },
            omittedNull: null,
            omittedFalse: false,
            page: 2,
        });
        const getXhr = FakeXMLHttpRequest.instances[0];
        const getUrl = new URL(getXhr.url);

        expect(getXhr.method).toBe('GET');
        expect(getXhr.async).toBe(true);
        expect(getXhr.headers).toEqual({'X-WP-Nonce': 'phase25-nonce'});
        expect(Object.fromEntries(getUrl.searchParams)).toEqual({
            existing: '1',
            'filters[status][0]': 'paid',
            'filters[status][1]': 'pending',
            'filters[range][min]': '1001',
            page: '2',
            query_timestamp: '1785481200123',
        });
        expect(getXhr.body).toBeUndefined();

        getXhr.respond(200, {orders: [{id: 42}], total: 1});
        await expect(getPromise).resolves.toEqual({orders: [{id: 42}], total: 1});

        const putPromise = Rest.put('orders/42', {total_amount: 1001});
        const putXhr = FakeXMLHttpRequest.instances[1];
        expect(putXhr.method).toBe('POST');
        expect(putXhr.url).toBe('https://store.test/wp-json/fluent-cart/v2/orders/42');
        expect(putXhr.headers).toEqual({
            'X-WP-Nonce': 'phase25-nonce',
            'X-HTTP-Method-Override': 'PUT',
            'Content-Type': 'application/json;charset=UTF-8',
        });
        expect(JSON.parse(putXhr.body)).toEqual({
            total_amount: 1001,
            query_timestamp: 1785481200123,
        });

        putXhr.respond(201, {updated: true, total_amount: 1001});
        await expect(putPromise).resolves.toEqual({updated: true, total_amount: 1001});
    });

    it('normalizes REST errors, renews invalid nonces, and exposes nested response fields', async () => {
        const Rest = await importRest();
        const promise = Rest.post('orders', {total_amount: 1001});
        const xhr = FakeXMLHttpRequest.instances[0];
        const payload = {
            code: 'rest_cookie_invalid_nonce',
            message: 'Nonce expired',
            errors: {total_amount: ['Exact cents required']},
        };
        xhr.respond(403, payload);

        await expect(promise).rejects.toEqual({
            data: payload,
            status_code: 403,
        });
        expect(document.dispatchEvent).toHaveBeenCalledTimes(1);
        expect(document.dispatchEvent.mock.calls[0][0]).toEqual({
            type: 'fcart_renew_rest_nonce',
            detail: payload,
        });

        const proxy = new ResponseProxyItr({
            status: 422,
            responseJSON: {
                message: 'Validation failed',
                total_amount: ['Exact cents required'],
            },
        });
        expect(proxy.status).toBe(422);
        expect(proxy.message).toBe('Validation failed');
        expect([...proxy]).toEqual(['Validation failed', ['Exact cents required']]);
        expect(Object.keys(proxy)).toEqual(['message', 'total_amount']);

        const errors = new Errors();
        errors.record({total_amount: {required: 'Exact cents required', min: 'Must be positive'}});
        expect(errors.has('total_amount')).toBe(true);
        expect(errors.first('total_amount')).toBe('Exact cents required');
        errors.clear('total_amount');
        expect(errors.get('total_amount')).toBeUndefined();
        errors.clear();
        expect(errors.errors).toEqual({});
    });

    it('covers non-JSON, network, method-override, nonce, and upload outcomes exactly', async () => {
        const Rest = await importRest();

        expect(Rest.getNonce()).toBe('phase25-nonce');

        const patchPromise = Rest.patch('/orders/42', {status: 'pending'});
        const patchXhr = FakeXMLHttpRequest.instances[0];
        expect({method: patchXhr.method, url: patchXhr.url, headers: patchXhr.headers}).toEqual({
            method: 'POST',
            url: 'https://store.test/wp-json/fluent-cart/v2/orders/42',
            headers: {
                'X-WP-Nonce': 'phase25-nonce',
                'X-HTTP-Method-Override': 'PATCH',
                'Content-Type': 'application/json;charset=UTF-8',
            },
        });
        patchXhr.respond(204, '');
        await expect(patchPromise).resolves.toBeNull();

        const nonJsonPromise = Rest.get('orders/42');
        const nonJsonXhr = FakeXMLHttpRequest.instances[1];
        nonJsonXhr.respond(502, '<html>Bad gateway</html>');
        await expect(nonJsonPromise).rejects.toEqual({data: null, status_code: 502});

        const networkPromise = Rest.delete('orders/42');
        const networkXhr = FakeXMLHttpRequest.instances[2];
        expect(networkXhr.headers['X-HTTP-Method-Override']).toBe('DELETE');
        networkXhr.fail(0, 'Connection refused');
        await expect(networkPromise).rejects.toEqual({status: 0, statusText: 'Connection refused'});

        const formData = {file: 'phase31.csv'};
        const uploadPromise = Rest.upload('media', formData);
        const uploadXhr = FakeXMLHttpRequest.instances[3];
        expect({method: uploadXhr.method, url: uploadXhr.url, headers: uploadXhr.headers, body: uploadXhr.body}).toEqual({
            method: 'POST',
            url: 'https://store.test/wp-json/fluent-cart/v2/media',
            headers: {'X-WP-Nonce': 'phase25-nonce'},
            body: formData,
        });
        uploadXhr.respond(201, {attachment_id: 31, filename: 'phase31.csv'});
        await expect(uploadPromise).resolves.toEqual({attachment_id: 31, filename: 'phase31.csv'});

        const failedUpload = Rest.upload('media', formData);
        const failedUploadXhr = FakeXMLHttpRequest.instances[4];
        failedUploadXhr.respond(413, {code: 'upload_too_large', message: 'File exceeds limit'});
        await expect(failedUpload).rejects.toEqual({code: 'upload_too_large', message: 'File exceeds limit'});
    });
});
