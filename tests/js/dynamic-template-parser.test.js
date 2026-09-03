import {beforeAll, describe, expect, it, vi} from 'vitest';
import {createSSRApp, ssrContextKey} from 'vue';

const rest = vi.hoisted(() => ({
    get: vi.fn(async (...args) => args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    upload: vi.fn(),
    getNonce: vi.fn(() => 'phase31-nonce'),
}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({
    default: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        validationErrors: vi.fn(),
    },
}));
vi.mock('@/utils/translator/Translator', () => ({default: (value) => value}));
vi.mock('@/Bits/Components/Animation.vue', () => ({default: {name: 'Animation'}}));
vi.mock('@/Bits/Components/StepIndicator.vue', () => ({default: {name: 'StepIndicator'}}));
vi.mock('@/Bits/Components/Buttons/LoadingButton.vue', () => ({default: {name: 'LoadingButton'}}));

let parser;

describe('dynamic Vue template parser', () => {
    beforeAll(async () => {
        vi.stubGlobal('window', {
            navigator: {language: 'en-US', languages: ['en-US'], onLine: true},
            ELEMENT: {},
        });

        const {parseStringComponent} = await import(
            '../../resources/admin/utils/DynamicTemplateParser.js'
        );

        // Mirrors the capability set VueTemplateLoader.vue injects. Anything a
        // template needs must arrive here explicitly — ambient globals are
        // shadowed to undefined inside the factory, which is the point.
        const services = {
            Vue: {},
            Rest: rest,
            Notify: {success: vi.fn(), error: vi.fn(), info: vi.fn(), validationErrors: vi.fn()},
            translate: (value) => value,
            Animation: {name: 'Animation'},
            StepIndicator: {name: 'StepIndicator'},
            LoadingButton: {name: 'LoadingButton'},
        };

        parser = (source) => parseStringComponent(source, services);
    });

    it('extracts the outer template, preserves nested templates, and executes safe options', async () => {
        const {component, error} = parser(`
            <template data-phase="31">
                <section><template v-if="ready"><strong>safe</strong></template></section>
            </template>
            <script>
                export default {
                    data() { return {ready: true, total: 1001}; },
                    methods: {load() { return Rest.get('orders', {page: 2}); }}
                }
            </script>
        `);

        expect(error).toBe('');
        expect(component).not.toBeNull();
        expect(component.template).toBe(
            '<section><template v-if="ready"><strong>safe</strong></template></section>'
        );
        expect(component.data()).toEqual({ready: true, total: 1001});
        await expect(component.methods.load()).resolves.toEqual(['orders', {page: 2}]);
        expect(rest.get).toHaveBeenCalledWith('orders', {page: 2});
    });

    it.each([
        ['fetch', `export default {mounted() { fetch('/private'); }}`],
        ['window', `export default {mounted() { window.location.href = '/'; }}`],
        ['storage', `export default {mounted() { localStorage.setItem('x', '1'); }}`],
        ['privileged navigator', `export default {mounted() { navigator.geolocation.getCurrentPosition(() => {}); }}`],
        ['dynamic code', `export default {mounted() { return new Function('return 1')(); }}`],
    ])('blocks %s access before dynamic component construction', (label, script) => {
        const result = parser(`<template><div>${label}</div></template><script>${script}</script>`);

        // The parser now reports WHY it refused. Assert the refusal AND the
        // reason — a generic failure would also satisfy `component === null`.
        expect(result.component).toBeNull();
        expect(result.error).toMatch(/blocked global/i);
    });

    it('fails closed for non-strings, missing templates, missing exports, malformed nesting, and syntax errors', () => {
        const results = [
            parser(null),
            parser('<script>export default {}</script>'),
            parser('<template><template><div></template>'),
            parser('<template><div /></template><script>const value = 1;</script>'),
            parser('<template><div /></template><script>export default { broken: ; }</script>'),
        ];

        expect(results.map(r => r.component)).toEqual([null, null, null, null, null]);
        // Every refusal must explain itself; a silent empty reason is a regression.
        results.forEach(r => expect(r.error).toBeTruthy());
    });
});
