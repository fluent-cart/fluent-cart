import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Bodies captured from GET /fluent-cart/v2/products/{id}.
const DELETED_PRODUCT = {code: 'Permission Callback Error', message: 'No query results for model [FluentCart\\App\\Models\\Product].', data: {status: 403}};
const NO_PERMISSION = {code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.', data: {status: 403}};

describe('Product Card inspector', () => {
    const renderInspector = async (props) => {
        vi.resetModules();
        let control = null;
        vi.doMock('@/BlockEditor/Components/ProductPicker/ProductSourceControl', () => ({
            default: (controlProps) => {
                control = controlProps;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Components/SegmentedToggle', () => ({default: () => null}));
        globalThis.React = React;
        globalThis.window = {fluent_cart_block_translation: {}};
        globalThis.wp = {
            element: React,
            blockEditor: {InspectorControls: passThrough},
            components: {
                Panel: passThrough,
                PanelBody: passThrough,
                RangeControl: () => null,
                SelectControl: () => null,
                __experimentalNumberControl: () => null,
            },
        };

        const {default: InspectorSettings} = await import('@/BlockEditor/ProductCard/Components/InspectorSettings.jsx');
        renderToStaticMarkup(React.createElement(InspectorSettings, {
            attributes: {query_type: 'custom', product_id: 39612, price_format: 'starts_from', card_width: 300},
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
        const control = await renderInspector({attributes: {query_type: 'custom', product_id: '', price_format: 'starts_from', card_width: 300}});

        expect(control.productId).toBe('');
    });

    it('commits the pick on the confirm button and the toggle sets query_type', async () => {
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

    it('Remove clears the saved id and leaves an empty product, which the card editors already handle', async () => {
        const setAttributes = vi.fn();
        const setSelectedProduct = vi.fn();
        const control = await renderInspector({setAttributes, setSelectedProduct});

        control.onRemove();

        expect(setAttributes).toHaveBeenCalledWith({product_id: ''});
        expect(setSelectedProduct).toHaveBeenCalledWith({});
    });
});

// A tiny state and effect shim, since there is no DOM: effects run on demand, with cleanups.
const createShim = () => {
    const store = [];
    let effects = [];
    let cleanups = [];
    let slot = 0;

    return {
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
        beforeRender: () => {
            slot = 0;
            effects = [];
        },
        runEffects: () => {
            cleanups.forEach((cleanup) => typeof cleanup === 'function' && cleanup());
            cleanups = effects.map((effect) => effect());
        },
        flush: () => new Promise((resolve) => setTimeout(resolve, 0)),
    };
};

describe('Legacy Product Card editor loading a saved product', () => {
    afterEach(() => {
        vi.resetModules();
    });

    const createEditor = async (apiFetchImpl) => {
        vi.resetModules();
        const shim = createShim();
        const seen = {inspector: null};

        vi.doMock('@wordpress/api-fetch', () => ({default: apiFetchImpl}));
        vi.doMock('@wordpress/url', () => ({addQueryArgs: (url) => url}));
        vi.doMock('@/BlockEditor/ProductCard/Components/InspectorSettings', () => ({
            default: (props) => {
                seen.inspector = props;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Components/ErrorBoundary', () => ({default: passThrough}));
        vi.doMock('@/BlockEditor/ProductCard/productCardDefaults', () => ({convertToTemplateCard: () => ({}), convertedCardTemplate: () => []}));
        vi.doMock('@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets', () => ({availableTemplate: (template) => template}));

        globalThis.React = React;
        globalThis.window = {
            fluent_cart_product_card_data: {currency_sign: '$', currency_position: 'before', placeholder_image: ''},
            fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
            fluent_cart_block_translation: {},
        };
        globalThis.wp = {
            blockEditor: {useBlockProps: () => ({}), InspectorControls: passThrough},
            components: {PanelBody: passThrough, Button: passThrough},
            element: shim.element,
        };

        const {default: LegacyProductCard} = await import('@/BlockEditor/ProductCard/LegacyProductCard.jsx');
        const render = (productId) => {
            shim.beforeRender();
            renderToStaticMarkup(React.createElement(LegacyProductCard, {
                attributes: {product_id: productId, query_type: 'custom', price_format: 'starts_from'},
                setAttributes: () => {},
                clientId: 'card',
                name: 'fluent-cart/product-card',
            }));
        };

        return {seen, render, runEffects: shim.runEffects, flush: shim.flush};
    };

    const mount = async (apiFetchImpl) => {
        const editor = await createEditor(apiFetchImpl);
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
    });
});

describe('Product Card editor loading a saved product', () => {
    afterEach(() => {
        vi.resetModules();
    });

    const createEditor = async (fetchImpl) => {
        vi.resetModules();
        const shim = createShim();
        const seen = {inspector: null, provider: null};

        vi.doMock('@/BlockEditor/ProductCard/Components/InspectorSettings', () => ({
            default: (props) => {
                seen.inspector = props;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/ProductCard/Components/InspectorSettings.jsx', () => ({
            default: (props) => {
                seen.inspector = props;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/ShopApp/InnerBlocks/Components/CardLayoutSettings', () => ({default: () => null}));
        vi.doMock('@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets', () => ({
            availableTemplate: (template) => template,
            cardLayoutName: () => 'classic',
            cardPresets: () => ({classic: {}}),
            cardTemplate: () => [],
            layoutOwnedByProducts: () => false,
        }));
        vi.doMock('@/BlockEditor/ShopApp/Context/SingleProductContext', () => ({
            SingleProductDataProvider: ({value, children}) => {
                seen.provider = value;
                return React.createElement('div', null, children);
            },
            useSingleProductData: () => null,
        }));

        globalThis.React = React;
        globalThis.window = {
            fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
            fluent_cart_block_translation: {},
        };
        globalThis.wp = {
            blockEditor: {useBlockProps: () => ({}), useInnerBlocksProps: () => ({}), BlockContextProvider: passThrough},
            element: shim.element,
            data: {useSelect: (mapSelect) => mapSelect(() => ({getBlockParents: () => [], getBlockName: () => null}))},
            apiFetch: fetchImpl,
            url: {addQueryArgs: (url) => url},
        };

        const {default: ProductCardTemplate} = await import('@/BlockEditor/ProductCard/ProductCardTemplate.jsx');
        const render = (productId = '39612') => {
            shim.beforeRender();
            renderToStaticMarkup(React.createElement(ProductCardTemplate, {
                attributes: {product_id: productId, query_type: 'custom', price_format: 'starts_from', masonry: false},
                setAttributes: () => {},
                clientId: 'card',
                context: {},
            }));
        };

        return {seen, render, runEffects: shim.runEffects, flush: shim.flush};
    };

    const mount = async (fetchImpl) => {
        const editor = await createEditor(fetchImpl);
        editor.render();
        editor.runEffects();
        await editor.flush();
        editor.render();

        return editor.seen;
    };

    it('a deleted product becomes missing', async () => {
        const seen = await mount(() => Promise.reject(DELETED_PRODUCT));

        expect(seen.inspector.selectedProduct).toEqual({missing: true});
    });

    it('a permission denial becomes a load error', async () => {
        const seen = await mount(() => Promise.reject(NO_PERMISSION));

        expect(seen.inspector.selectedProduct).toEqual({loadError: true});
    });

    it('an empty response becomes missing', async () => {
        const seen = await mount(() => Promise.resolve({}));

        expect(seen.inspector.selectedProduct).toEqual({missing: true});
    });

    it('a loaded product reaches the inspector and the card', async () => {
        const product = {ID: 39612, post_title: 'Gift Box'};
        const seen = await mount(() => Promise.resolve({product}));

        expect(seen.inspector.selectedProduct).toEqual(product);
        expect(seen.provider.product).toEqual(product);
        // The inspector previews the price of what the card shows.
        expect(seen.inspector.displayedProduct).toEqual(product);
    });

    it('the inner blocks get no marker when the product is missing or failed', async () => {
        const missing = await mount(() => Promise.reject(DELETED_PRODUCT));
        const failed = await mount(() => Promise.reject(NO_PERMISSION));

        expect(missing.provider.product).toEqual({});
        expect(failed.provider.product).toEqual({});
    });

    it('keeps the product the picker just handed over while the full detail loads', async () => {
        // The fetch never answers, so only the hand-over can fill the card.
        const editor = await createEditor(() => new Promise(() => {}));
        editor.render('7');
        editor.runEffects();
        await editor.flush();

        // The merchant confirms product 8: the dialog sets the product, then the id changes.
        editor.seen.inspector.setSelectedProduct({ID: 8, post_title: 'Picked'});
        editor.render('8');
        editor.runEffects();
        editor.render('8');

        expect(editor.seen.inspector.selectedProduct).toEqual({ID: 8, post_title: 'Picked'});
    });

    it('still clears an old product when the id changes without a pick (Undo)', async () => {
        const first = {ID: 7, post_title: 'First'};
        const editor = await createEditor(() => new Promise(() => {}));
        editor.render('7');
        editor.runEffects();
        editor.seen.inspector.setSelectedProduct(first);
        editor.render('8');
        editor.runEffects();
        editor.render('8');

        expect(editor.seen.inspector.selectedProduct).toEqual({});
    });
});
