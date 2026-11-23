import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Bodies captured from GET /fluent-cart/v2/products/{id}.
const DELETED_PRODUCT = {code: 'Permission Callback Error', message: 'No query results for model [FluentCart\\App\\Models\\Product].', data: {status: 403}};
const NO_PERMISSION = {code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 403}};

describe('Related Product inspector', () => {
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
            element: React,
            blockEditor: {InspectorControls: passThrough},
            components: {
                PanelBody: passThrough,
                CheckboxControl: () => null,
                SelectControl: () => null,
                RangeControl: () => null,
                ToggleControl: () => null,
                __experimentalToolsPanel: passThrough,
                __experimentalToolsPanelItem: passThrough,
            },
        };

        const {default: RelatedProductInspectorSettings} = await import('@/BlockEditor/RelatedProduct/Components/RelatedProductInspectorSettings.jsx');
        renderToStaticMarkup(React.createElement(RelatedProductInspectorSettings, {
            attributes: {query_type: 'custom', product_id: 39612, related_by_categories: true, related_by_brands: false, order_by: 'title_asc', columns: 4, posts_per_page: 6},
            setAttributes: vi.fn(),
            selectedProduct: {ID: 39612, post_title: 'Gift Box'},
            setSelectedProduct: vi.fn(),
            showDisplayElements: false,
            ...props,
        }));

        return control;
    };

    it('passes the saved id as a string, so it matches the loaded product', async () => {
        const control = await renderInspector({});

        expect(control.productId).toBe('39612');
        expect(control.queryType).toBe('custom');
    });

    it('keeps the block\'s own Default-mode wording', async () => {
        const control = await renderInspector({});

        expect(control.defaultNote).toBe("Automatically use the current page's product context.");
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

    it('confirming a product that failed to load does not clear the saved product', async () => {
        const setAttributes = vi.fn();
        const control = await renderInspector({setAttributes, selectedProduct: {loadError: true}});

        control.onSelectionConfirmed({loadError: true});
        control.onSelectionConfirmed({missing: true});

        expect(setAttributes).not.toHaveBeenCalled();
    });

    it('confirming with nothing ticked removes the product and its related products, as Remove does', async () => {
        const setAttributes = vi.fn();
        const setSelectedProduct = vi.fn();
        const control = await renderInspector({setAttributes, setSelectedProduct});

        control.onSelectionConfirmed(null);

        expect(setAttributes).toHaveBeenCalledWith({product_id: '', related_product_ids: [], related_products: []});
        expect(setSelectedProduct).toHaveBeenCalledWith({});
    });

    it('Remove clears the product and the related products that belonged to it', async () => {
        const setAttributes = vi.fn();
        const setSelectedProduct = vi.fn();
        const control = await renderInspector({setAttributes, setSelectedProduct});

        control.onRemove();

        expect(setAttributes).toHaveBeenCalledWith({product_id: '', related_product_ids: [], related_products: []});
        expect(setSelectedProduct).toHaveBeenCalledWith({});
    });
});

describe('Related Product editor loading a saved product', () => {
    afterEach(() => {
        vi.resetModules();
    });

    // Runs the block's edit function with a tiny state and effect shim, since there is no DOM.
    const createEditor = async (productRequest, relatedRequest = () => Promise.resolve({products: []})) => {
        vi.resetModules();
        const seen = {inspector: null};
        const store = [];
        let effects = [];
        let cleanups = [];
        let slot = 0;

        // The editor loads the product and its related products; only the product request varies.
        const apiFetch = (options) => (String(options.path).includes('/related-products')
            ? relatedRequest()
            : productRequest());

        vi.doMock('@wordpress/api-fetch', () => ({default: apiFetch}));
        vi.doMock('@wordpress/url', () => ({addQueryArgs: (url) => url}));
        vi.doMock('@/BlockEditor/RelatedProduct/Components/RelatedProductInspectorSettings', () => ({
            default: (props) => {
                seen.inspector = props;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Components/ProductPicker/ProductDropdownPicker.jsx', () => ({default: () => null}));
        vi.doMock('@/BlockEditor/RelatedProduct/Components/ProductTemplateBlock.jsx', () => ({}));
        vi.doMock('@/BlockEditor/RelatedProduct/Components/productTemplate', () => ({anyLoopUsesDisplayElements: () => false}));
        vi.doMock('@/BlockEditor/Icons', () => ({RelatedProduct: () => null}));

        let registered = null;
        globalThis.React = React;
        globalThis.window = {
            fluent_cart_related_product_data: {slug: 'fluent-cart', name: 'related-products', title: 'Related Products', description: ''},
            fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
            fluent_cart_block_translation: {},
        };
        globalThis.wp = {
            blocks: {registerBlockType: (name, settings) => { registered = settings; }},
            blockEditor: {useBlockProps: () => ({}), InnerBlocks: Object.assign(passThrough, {Content: passThrough})},
            components: {Placeholder: ({label, children}) => React.createElement('div', null, label, children)},
            data: {useSelect: (mapSelect) => mapSelect(() => ({getBlocks: () => []}))},
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

        await import('@/BlockEditor/RelatedProduct/RelatedProductBlockEditor.jsx');

        const setAttributes = vi.fn();
        const render = (productId, queryType = 'custom') => {
            slot = 0;
            effects = [];
            return renderToStaticMarkup(React.createElement(registered.edit, {
                attributes: {query_type: queryType, product_id: productId, related_by_categories: true, related_by_brands: false, order_by: 'title_asc', posts_per_page: 6},
                setAttributes,
                clientId: 'related',
            }));
        };
        const runEffects = () => {
            cleanups.forEach((cleanup) => typeof cleanup === 'function' && cleanup());
            cleanups = effects.map((effect) => effect());
        };
        const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

        return {seen, render, runEffects, flush, setAttributes};
    };

    const mount = async (productRequest) => {
        const editor = await createEditor(productRequest);
        editor.render('39612');
        editor.runEffects();
        await editor.flush();
        editor.render('39612');

        return editor.seen.inspector.selectedProduct;
    };

    it('a deleted product becomes missing, so the sidebar can say so', async () => {
        expect(await mount(() => Promise.reject(DELETED_PRODUCT))).toEqual({missing: true});
    });

    it('a permission denial becomes a load error, not a deleted product', async () => {
        expect(await mount(() => Promise.reject(NO_PERMISSION))).toEqual({loadError: true});
    });

    it('an empty response becomes missing', async () => {
        expect(await mount(() => Promise.resolve({}))).toEqual({missing: true});
    });

    it('a loaded product reaches the inspector', async () => {
        const product = {ID: 39612, post_title: 'Gift Box'};

        expect(await mount(() => Promise.resolve({product}))).toEqual(product);
    });

    it('a slower earlier request cannot overwrite the product picked after it', async () => {
        let resolveFirst;
        const first = new Promise((resolve) => { resolveFirst = resolve; });
        const productRequest = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve({product: {ID: 8, post_title: 'Second'}}));
        const editor = await createEditor(productRequest);

        editor.render('7');
        editor.runEffects();
        editor.render('8');
        editor.runEffects();
        await editor.flush();
        resolveFirst({product: {ID: 7, post_title: 'First'}});
        await editor.flush();
        editor.render('8');

        expect(editor.seen.inspector.selectedProduct).toEqual({ID: 8, post_title: 'Second'});
    });

    it('a related-products request for a removed product writes nothing back and leaves no spinner', async () => {
        let resolveRelated;
        const related = new Promise((resolve) => { resolveRelated = resolve; });
        const editor = await createEditor(() => Promise.resolve({product: {ID: 7, post_title: 'Seven'}}), () => related);

        editor.render('7');
        editor.runEffects();
        await editor.flush();
        // The merchant switches to Default (product_id is cleared); the slow request is still out.
        editor.render('', 'default');
        editor.runEffects();
        resolveRelated({products: [{ID: 70}, {ID: 71}]});
        await editor.flush();
        const markup = editor.render('', 'default');

        const wroteOldItems = editor.setAttributes.mock.calls.some(([attributes]) => (attributes.related_products || []).length > 0);
        expect(wroteOldItems).toBe(false);
        expect(markup).not.toContain('Loading Related Products...');
    });

    it('a slower earlier related-products request cannot overwrite the product picked after it', async () => {
        let resolveFirst;
        const first = new Promise((resolve) => { resolveFirst = resolve; });
        const relatedRequest = vi.fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve({products: [{ID: 80}]}));
        const editor = await createEditor(() => Promise.resolve({product: {ID: 8}}), relatedRequest);

        editor.render('7');
        editor.runEffects();
        editor.render('8');
        editor.runEffects();
        await editor.flush();
        resolveFirst({products: [{ID: 70}]});
        await editor.flush();

        const written = editor.setAttributes.mock.calls.map(([attributes]) => attributes.related_product_ids);
        expect(written).toEqual([[80]]);
    });
});
