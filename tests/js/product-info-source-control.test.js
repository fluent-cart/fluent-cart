import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Bodies captured from GET /fluent-cart/v2/products/{id}.
const DELETED_PRODUCT = {code: 'Permission Callback Error', message: 'No query results for model [FluentCart\\App\\Models\\Product].', data: {status: 403}};
const NO_PERMISSION = {code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 403}};

describe('Product Info inspector', () => {
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
            components: {Panel: passThrough, PanelBody: passThrough},
        };

        const {default: InspectorSettings} = await import('@/BlockEditor/ProductInfo/Components/InspectorSettings.jsx');
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

    it('passes an empty id when no product is saved', async () => {
        const control = await renderInspector({attributes: {query_type: 'custom', product_id: ''}});

        expect(control.productId).toBe('');
    });

    it('commits the pick on the confirm button, and the toggle sets query_type', async () => {
        const setAttributes = vi.fn();
        const control = await renderInspector({setAttributes});

        control.onSelectionConfirmed({ID: 7});
        control.onSelectionConfirmed(null);
        control.onQueryTypeChange('default');

        expect(setAttributes.mock.calls).toEqual([
            [{product_id: '7'}],
            [{product_id: ''}],
            [{query_type: 'default'}],
        ]);
        expect(control.onModalClosed).toBeUndefined();
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
        expect(setSelectedProduct).toHaveBeenCalledWith(null);
    });
});

describe('Product Info editor loading a saved product', () => {
    afterEach(() => {
        vi.resetModules();
    });

    // Runs the block's edit function with a tiny state and effect shim, since there is no DOM.
    const createEditor = async (apiFetchImpl) => {
        vi.resetModules();
        const seen = {inspector: null, provider: null};
        const store = [];
        let effects = [];
        let cleanups = [];
        let slot = 0;

        vi.doMock('@wordpress/api-fetch', () => ({default: apiFetchImpl}));
        vi.doMock('@wordpress/url', () => ({addQueryArgs: (url) => url}));
        vi.doMock('@/BlockEditor/ProductInfo/Components/InspectorSettings.jsx', () => ({
            default: (props) => {
                seen.inspector = props;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/ShopApp/Context/SingleProductContext.jsx', () => ({
            SingleProductDataProvider: ({value, children}) => {
                seen.provider = value;
                return React.createElement('div', null, children);
            },
        }));
        vi.doMock('@/BlockEditor/Icons', () => ({ProductInfo: () => null}));

        let registered = null;
        globalThis.React = React;
        globalThis.window = {
            fluent_cart_product_info_data: {slug: 'fluent-cart', name: 'product-info', title: 'Product Info', description: '', placeholder_image: ''},
            fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
            fluent_cart_block_translation: {},
        };
        globalThis.wp = {
            blocks: {registerBlockType: (name, settings) => { registered = settings; }},
            blockEditor: {useBlockProps: Object.assign(() => ({}), {save: () => ({})}), InnerBlocks: Object.assign(passThrough, {Content: passThrough})},
            element: {
                useState: (initial) => {
                    const index = slot++;
                    if (!(index in store)) {
                        store[index] = {value: typeof initial === 'function' ? initial() : initial};
                    }
                    return [store[index].value, (next) => {
                        store[index].value = typeof next === 'function' ? next(store[index].value) : next;
                    }];
                },
                useEffect: (effect) => { effects.push(effect); },
            },
        };

        await import('@/BlockEditor/ProductInfo/ProductInfoBlockEditor.jsx');

        const render = (productId) => {
            slot = 0;
            effects = [];
            renderToStaticMarkup(React.createElement(registered.edit, {
                attributes: {product_id: productId, query_type: 'custom'},
                setAttributes: () => {},
            }));
        };
        // What React does when the id changes: clean up the old effect, then run the new one.
        const runEffects = () => {
            cleanups.forEach((cleanup) => typeof cleanup === 'function' && cleanup());
            cleanups = effects.map((effect) => effect());
        };
        const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

        return {seen, render, runEffects, flush};
    };

    const mountEditor = async (apiFetchImpl) => {
        const editor = await createEditor(apiFetchImpl);
        editor.render('39612');
        editor.runEffects();
        await editor.flush();
        editor.render('39612');

        return editor.seen;
    };

    it('a deleted product becomes missing, and the inner blocks get no product', async () => {
        const seen = await mountEditor(() => Promise.reject(DELETED_PRODUCT));

        expect(seen.inspector.selectedProduct).toEqual({missing: true});
        expect(seen.provider.product).toBeNull();
    });

    it('a permission denial becomes a load error, not a deleted product', async () => {
        const seen = await mountEditor(() => Promise.reject(NO_PERMISSION));

        expect(seen.inspector.selectedProduct).toEqual({loadError: true});
        expect(seen.provider.product).toBeNull();
    });

    it('an empty response becomes missing', async () => {
        const seen = await mountEditor(() => Promise.resolve({}));

        expect(seen.inspector.selectedProduct).toEqual({missing: true});
    });

    it('a loaded product reaches both the inspector and the inner blocks', async () => {
        const product = {ID: 39612, post_title: 'Gift Box'};
        const seen = await mountEditor(() => Promise.resolve({product}));

        expect(seen.inspector.selectedProduct).toEqual(product);
        expect(seen.provider.product).toEqual(product);
    });

    it('a slower earlier request cannot overwrite the product picked after it', async () => {
        let resolveFirst;
        const first = new Promise((resolve) => { resolveFirst = resolve; });
        const apiFetch = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve({product: {ID: 8, post_title: 'Second'}}));
        const editor = await createEditor(apiFetch);

        editor.render('7');
        editor.runEffects();
        editor.render('8');
        editor.runEffects();
        await editor.flush();
        resolveFirst({product: {ID: 7, post_title: 'First'}});
        await editor.flush();
        editor.render('8');

        expect(editor.seen.inspector.selectedProduct).toEqual({ID: 8, post_title: 'Second'});
        expect(editor.seen.provider.product).toEqual({ID: 8, post_title: 'Second'});
    });

    it('a late failure of an earlier request cannot replace the product picked after it', async () => {
        let rejectFirst;
        const first = new Promise((resolve, reject) => { rejectFirst = reject; });
        const apiFetch = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve({product: {ID: 8, post_title: 'Second'}}));
        const editor = await createEditor(apiFetch);

        editor.render('7');
        editor.runEffects();
        editor.render('8');
        editor.runEffects();
        await editor.flush();
        rejectFirst(NO_PERMISSION);
        await editor.flush();
        editor.render('8');

        expect(editor.seen.inspector.selectedProduct).toEqual({ID: 8, post_title: 'Second'});
    });
});
