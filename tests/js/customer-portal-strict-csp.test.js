import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';

// Same full build the vite alias ships, so the runtime template compiler is present.
vi.mock('vue', async () => await import('vue/dist/vue.esm-bundler.js'));
// The vue plugin compiles every SFC to a render function; the real App's import tree is not under test.
vi.mock('../../resources/public/customer-profile/Vue/App.vue', () => ({default: {name: 'App', render: () => null}}));

// runtime-dom reads `document` once at import, so the stubs must exist before vue loads.
vi.hoisted(() => {
    const node = () => ({nodeType: 8, parentNode: null});
    globalThis.window = globalThis;
    globalThis.document = {createElement: node, createElementNS: node, createComment: node, createTextNode: node};
    globalThis.Element = class {};
    globalThis.SVGElement = class {};
    globalThis.MathMLElement = class {};
});

// A browser under `script-src` without 'unsafe-eval' throws this for `new Function(string)`.
function CspBlockedFunction() {
    throw new EvalError("Evaluating a string as JavaScript violates the following Content Security Policy directive because 'unsafe-eval' is not an allowed source of script");
}

const makeContainer = () => Object.assign(new Element(), {
    nodeType: 1,
    // What CustomerProfileHandler::renderCustomerApp() prints inside the mount point.
    innerHTML: '<app></app>',
    textContent: '',
    attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; },
    removeAttribute(name) { delete this.attributes[name]; },
    insertBefore(child) { child.parentNode = this; },
});

let fctCreatePortalApp;

describe('customer portal under a strict Content Security Policy', () => {
    beforeAll(async () => {
        ({default: fctCreatePortalApp} = await import('../../resources/public/customer-profile/fctCreatePortalApp.js'));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('mounts without compiling a template at runtime', () => {
        const app = fctCreatePortalApp();
        const container = makeContainer();

        vi.stubGlobal('Function', CspBlockedFunction);
        let error = null;
        try {
            app.mount(container);
        } catch (e) {
            error = e;
        }
        vi.unstubAllGlobals();

        expect(error, 'mounting must not need eval: ' + (error && error.message)).toBeNull();
        expect(container.attributes, 'the app should have mounted').toHaveProperty('data-v-app');
    });

    it('uses the precompiled App as the root component', () => {
        const root = fctCreatePortalApp()._component;

        expect(typeof root.render, 'root needs a render function or Vue compiles the mount HTML').toBe('function');
    });
});
