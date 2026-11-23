import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Bodies captured from GET /fluent-cart/v2/products/{id}.
const DELETED_PRODUCT = {code: 'Permission Callback Error', message: 'No query results for model [FluentCart\\App\\Models\\Product].', data: {status: 403}};
const NO_PERMISSION = {code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 403}};

describe('Buy Section inspector', () => {
    const renderInspector = async (props) => {
        vi.resetModules();
        let control = null;
        vi.doMock('@/BlockEditor/Components/ProductPicker/ProductSourceControl', () => ({
            default: (controlProps) => {
                control = controlProps;
                return null;
            },
        }));
        globalThis.React = React;
        globalThis.window = {fluent_cart_block_translation: {}};
        globalThis.wp = {
            blockEditor: {InspectorControls: passThrough},
            components: {Panel: passThrough, PanelBody: passThrough, Button: passThrough},
        };

        const {default: InspectorSettings} = await import('@/BlockEditor/BuySection/Components/InspectorSettings.jsx');
        renderToStaticMarkup(React.createElement(InspectorSettings, {
            attributes: {query_type: 'custom', product_id: 39612},
            setAttributes: vi.fn(),
            selectedProduct: {ID: 39612, post_title: 'Gift Box'},
            setSelectedProduct: vi.fn(),
            ...props,
        }));

        return control;
    };

    it('passes the saved id as a string, so it matches the loaded product', async () => {
        const control = await renderInspector({});

        expect(control.productId).toBe('39612');
        expect(control.queryType).toBe('custom');
    });

    it('commits the pick on the confirm button and the toggle sets query_type', async () => {
        const setAttributes = vi.fn();
        const control = await renderInspector({setAttributes});

        control.onSelectionConfirmed({ID: 7});
        control.onQueryTypeChange('default');

        expect(setAttributes.mock.calls).toEqual([
            [{product_id: '7'}],
            [{query_type: 'default'}],
        ]);
        expect(control.onModalClosed).toBeUndefined();
    });

    it('confirming with nothing ticked clears the saved product', async () => {
        const setAttributes = vi.fn();
        const control = await renderInspector({setAttributes});

        control.onSelectionConfirmed(null);

        expect(setAttributes).toHaveBeenCalledWith({product_id: ''});
    });

    it('confirming a product that failed to load does not clear the saved product', async () => {
        const setAttributes = vi.fn();
        const control = await renderInspector({setAttributes, selectedProduct: {loadError: true}});

        control.onSelectionConfirmed({loadError: true});
        control.onSelectionConfirmed({missing: true});

        expect(setAttributes).not.toHaveBeenCalled();
    });

    it('Remove clears the saved id and the loaded product', async () => {
        const setAttributes = vi.fn();
        const setSelectedProduct = vi.fn();
        const control = await renderInspector({setAttributes, setSelectedProduct});

        control.onRemove();

        expect(setAttributes).toHaveBeenCalledWith({product_id: ''});
        expect(setSelectedProduct).toHaveBeenCalledWith({});
    });
});

describe('Buy Section editor loading a saved product', () => {
    afterEach(() => {
        vi.resetModules();
    });

    // A tiny state and effect shim, since there is no DOM. Effects run on demand and, like React,
    // only when their dependency list changed. Context is React's own, so the real provider is used.
    const createEditor = async (apiFetchImpl) => {
        vi.resetModules();
        const seen = {inspector: null};
        const store = [];
        let effects = [];
        const cleanups = [];
        const lastDeps = [];
        let slot = 0;
        const state = {insideParent: false};

        vi.doMock('@wordpress/api-fetch', () => ({default: apiFetchImpl}));
        vi.doMock('@wordpress/url', () => ({addQueryArgs: (url) => url}));
        vi.doMock('@/BlockEditor/BuySection/Components/InspectorSettings', () => ({
            default: (props) => {
                seen.inspector = props;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Icons', () => ({BuySection: () => null}));

        let registered = null;
        globalThis.React = React;
        globalThis.window = {
            fluent_cart_buy_section_data: {slug: 'fluent-cart', name: 'buy-section', title: 'Buy Section', description: ''},
            fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
            fluent_cart_block_translation: {},
        };
        globalThis.wp = {
            blocks: {registerBlockType: (name, settings) => { registered = settings; }},
            blockEditor: {useBlockProps: () => ({}), store: 'core/block-editor'},
            element: {
                createContext: React.createContext,
                useContext: React.useContext,
                useState: (initial) => {
                    const index = slot++;
                    if (!(index in store)) {
                        store[index] = {value: typeof initial === 'function' ? initial() : initial};
                    }
                    return [store[index].value, (next) => {
                        store[index].value = typeof next === 'function' ? next(store[index].value) : next;
                    }];
                },
                useEffect: (effect, deps) => { effects.push({effect, deps}); },
            },
            data: {useSelect: () => state.insideParent},
        };

        // The provider Product Info, cards, loops and the carousel all use.
        const {SingleProductDataProvider} = await import('@/BlockEditor/ShopApp/Context/SingleProductContext');
        await import('@/BlockEditor/BuySection/BuySectionBlockEditor.jsx');

        const setAttributes = vi.fn();
        const render = ({productId = '', queryType = 'custom', provided = null, context = {}} = {}) => {
            slot = 0;
            effects = [];
            const block = React.createElement(registered.edit, {
                attributes: {product_id: productId, query_type: queryType},
                setAttributes,
                clientId: 'buy',
                context,
            });
            seen.inspector = null;
            return renderToStaticMarkup(provided
                ? React.createElement(SingleProductDataProvider, {value: {product: provided}}, block)
                : block);
        };
        const runEffects = () => {
            effects.forEach(({effect, deps}, index) => {
                const previous = lastDeps[index];
                const changed = !deps || !previous || deps.some((dep, i) => !Object.is(dep, previous[i]));
                if (!changed) {
                    return;
                }
                if (typeof cleanups[index] === 'function') {
                    cleanups[index]();
                }
                lastDeps[index] = deps;
                cleanups[index] = effect();
            });
        };
        const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
        // Render, run effects, let requests settle, render again: what the merchant sees.
        const settle = async (options) => {
            render(options);
            runEffects();
            await flush();
            seen.markup = render(options);
            return seen.inspector ? seen.inspector.selectedProduct : null;
        };

        return {seen, render, runEffects, flush, settle, setAttributes, state};
    };

    const custom = {productId: '39612', queryType: 'custom'};

    it('a deleted product becomes missing, so the sidebar can say so', async () => {
        const editor = await createEditor(() => Promise.reject(DELETED_PRODUCT));
        expect(await editor.settle(custom)).toEqual({missing: true});
    });

    it('a permission denial becomes a load error, not a deleted product', async () => {
        const editor = await createEditor(() => Promise.reject(NO_PERMISSION));
        expect(await editor.settle(custom)).toEqual({loadError: true});
    });

    it('an empty response becomes missing', async () => {
        const editor = await createEditor(() => Promise.resolve({}));
        expect(await editor.settle(custom)).toEqual({missing: true});
    });

    it('a loaded product reaches the inspector', async () => {
        const product = {ID: 39612, post_title: 'Gift Box'};
        const editor = await createEditor(() => Promise.resolve({product}));
        expect(await editor.settle(custom)).toEqual(product);
    });

    it('with no saved id it does not request a product at all', async () => {
        const apiFetch = vi.fn(() => Promise.resolve({}));
        const editor = await createEditor(apiFetch);

        expect(await editor.settle({productId: '', queryType: 'custom'})).toEqual({});
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('a slower earlier request cannot overwrite the product picked after it', async () => {
        let resolveFirst;
        const first = new Promise((resolve) => { resolveFirst = resolve; });
        const apiFetch = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve({product: {ID: 8, post_title: 'Second'}}));
        const editor = await createEditor(apiFetch);

        editor.render({productId: '7'});
        editor.runEffects();
        editor.render({productId: '8'});
        editor.runEffects();
        await editor.flush();
        resolveFirst({product: {ID: 7, post_title: 'First'}});
        await editor.flush();
        editor.render({productId: '8'});

        expect(editor.seen.inspector.selectedProduct).toEqual({ID: 8, post_title: 'Second'});
    });

    it('Default mode inside a card or loop shows the product the real provider gives', async () => {
        const apiFetch = vi.fn(() => Promise.resolve({}));
        const editor = await createEditor(apiFetch);
        const cardProduct = {ID: 5, post_title: 'Card Product'};

        expect(await editor.settle({productId: '39612', queryType: 'default', provided: cardProduct})).toEqual(cardProduct);
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('Default mode on a product page, with no provider, loads that page\'s product', async () => {
        const pageProduct = {ID: 55, post_title: 'Page Product'};
        const apiFetch = vi.fn(() => Promise.resolve({product: pageProduct}));
        const editor = await createEditor(apiFetch);

        expect(await editor.settle({queryType: 'default', context: {postType: 'fluent-products', postId: 55}})).toEqual(pageProduct);
        expect(apiFetch.mock.calls[0][0].path).toContain('/products/55');
    });

    it('Default mode away from a product page shows nothing and requests nothing', async () => {
        const apiFetch = vi.fn(() => Promise.resolve({}));
        const editor = await createEditor(apiFetch);

        expect(await editor.settle({productId: '39612', queryType: 'default', context: {postType: 'page', postId: 9}})).toEqual({});
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('Custom mode inside a card keeps the saved product, not the card\'s', async () => {
        const saved = {ID: 39612, post_title: 'Gift Box'};
        const editor = await createEditor(() => Promise.resolve({product: saved}));

        expect(await editor.settle({...custom, provided: {ID: 5, post_title: 'Card Product'}})).toEqual(saved);
    });

    it('inside Product Info it shows the parent\'s product and requests nothing', async () => {
        const apiFetch = vi.fn(() => Promise.resolve({}));
        const editor = await createEditor(apiFetch);
        editor.state.insideParent = true;
        const parentProduct = {ID: 77, post_title: 'Parent Product'};

        // Inside a parent the block has no sidebar of its own; the canvas shows the product.
        expect(await editor.settle({...custom, provided: parentProduct})).toBeNull();
        expect(editor.seen.markup).toContain('Parent Product');
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('moving the block out of Product Info loads its own saved product', async () => {
        const saved = {ID: 39612, post_title: 'Gift Box'};
        const editor = await createEditor(() => Promise.resolve({product: saved}));
        editor.state.insideParent = true;
        await editor.settle({...custom, provided: {ID: 77, post_title: 'Parent Product'}});

        editor.state.insideParent = false;

        expect(await editor.settle(custom)).toEqual(saved);
    });

    it('switching from Custom to Default drops the custom product from the preview', async () => {
        const editor = await createEditor(() => Promise.resolve({product: {ID: 39612, post_title: 'Gift Box'}}));
        expect(await editor.settle(custom)).toEqual({ID: 39612, post_title: 'Gift Box'});

        expect(await editor.settle({productId: '39612', queryType: 'default'})).toEqual({});
    });

    it('takes the full record when the parent replaces the picker copy of the same product', async () => {
        const editor = await createEditor(() => Promise.resolve({}));
        editor.state.insideParent = true;
        const pickerCopy = {ID: 77, post_title: 'Parent Product'};
        const fullRecord = {ID: 77, post_title: 'Parent Product', variants: [{id: 1, variation_title: 'Large Size', item_price: 1200}]};

        await editor.settle({...custom, provided: pickerCopy});
        expect(editor.seen.markup).not.toContain('Large Size');

        // Same id, new object with the variants: the canvas must pick them up.
        await editor.settle({...custom, provided: fullRecord});

        expect(editor.seen.markup).toContain('Large Size');
    });
});
