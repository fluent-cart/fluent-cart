import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

// Just enough of DOMParser to decode the entities the price field carries.
class EntityParser {
    parseFromString(value) {
        const text = String(value)
            .replace(/&#(\d+);/g, (match, code) => String.fromCodePoint(Number(code)))
            .replace(/&amp;/g, '&');
        return {body: {textContent: text}};
    }
}

const render = async (props) => {
    vi.resetModules();
    // The picker dialog is not under test; show only the trigger it is handed.
    vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
        default: ({renderTrigger}) => renderTrigger(() => {}),
    }));

    globalThis.React = React;
    globalThis.DOMParser = EntityParser;
    globalThis.window = {fluent_cart_block_translation: {}, requestAnimationFrame: (callback) => callback()};
    globalThis.wp = {
        element: React,
        components: {
            DropdownMenu: ({label}) => React.createElement('button', {'aria-label': label}, '⋮'),
            MenuGroup: ({children}) => React.createElement('div', null, children),
            MenuItem: ({children}) => React.createElement('button', null, children),
        },
    };

    const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');

    const base = {
        queryType: 'custom',
        onQueryTypeChange: () => {},
        productId: '',
        selectedProduct: {},
        setSelectedProduct: () => {},
        onSelectionConfirmed: () => {},
        onRemove: () => {},
    };
    // A prop set to undefined is left out, as a caller that never passes it.
    const merged = {...base, ...props};
    Object.keys(merged).forEach((key) => merged[key] === undefined && delete merged[key]);

    return renderToStaticMarkup(React.createElement(ProductSourceControl, merged));
};

describe('ProductSourceControl states', () => {
    beforeEach(() => vi.resetModules());
    afterEach(() => {
        delete globalThis.DOMParser;
    });

    it('Default mode explains where the product comes from and offers no picker', async () => {
        const html = await render({queryType: 'default', selectedProduct: {ID: 7, post_title: 'Page product'}});

        expect(html).toContain('Uses the product from the current page, template, or loop.');
        expect(html).not.toContain('Choose product');
        expect(html).not.toContain('Page product');
    });

    it('Custom with nothing chosen shows the empty box', async () => {
        const html = await render({});

        expect(html).toContain('Pick a product to display.');
        expect(html).toContain('Choose product');
    });

    it('a saved product still loading shows the placeholder, not the empty box', async () => {
        const html = await render({productId: '39612', selectedProduct: {}});

        expect(html).toContain('is-loading');
        expect(html).toContain('Loading product…');
        expect(html).not.toContain('Choose product');
    });

    it('does not show the page product as the saved one while the saved one loads', async () => {
        const html = await render({productId: '39612', selectedProduct: {ID: 5, post_title: 'Page product'}});

        expect(html).toContain('is-loading');
        expect(html).not.toContain('Page product');
    });

    it('a deleted product says so and lets the merchant pick another', async () => {
        const html = await render({productId: '39612', selectedProduct: {missing: true}});

        expect(html).toContain('The chosen product no longer exists. Pick another.');
        expect(html).toContain('Choose product');
        expect(html).not.toContain('is-loading');
    });

    it('announces a deleted or failed product in the live region', async () => {
        const missing = await render({productId: '39612', selectedProduct: {missing: true}});
        const failed = await render({productId: '39612', selectedProduct: {loadError: true}});

        expect(missing).toMatch(/role="status">The chosen product no longer exists\. Pick another\.</);
        expect(failed).toMatch(/role="status">The chosen product could not be loaded\./);
    });

    it('a failed load does not claim the product was deleted', async () => {
        const html = await render({productId: '39612', selectedProduct: {loadError: true}});

        expect(html).toContain('could not be loaded');
        expect(html).not.toContain('no longer exists');
        expect(html).toContain('Choose product');
    });

    it('a loaded product shows its title and a decoded price', async () => {
        const html = await render({
            productId: '39612',
            selectedProduct: {ID: 39612, post_title: 'Gift Box', detail: {formatted_min_price: '&#36;12,00'}},
        });

        expect(html).toContain('Gift Box');
        expect(html).toContain('$12,00 · #39612');
        expect(html).not.toContain('&#36;');
        expect(html).toContain('Product actions');
    });

    it('a product with an empty title still shows a card, so it can be replaced', async () => {
        const html = await render({productId: '88', selectedProduct: {ID: 88, post_title: ''}});

        expect(html).toContain('(no title)');
        expect(html).toContain('#88');
        expect(html).not.toContain('is-loading');
    });

    it('takes the whole first character of an emoji title for the tile', async () => {
        const html = await render({productId: '9', selectedProduct: {ID: 9, post_title: '🎁 Gift Box'}});

        expect(html).toContain('aria-hidden="true">🎁</span>');
    });
});

describe('ProductSourceControl as a shared component', () => {
    it('shows no Default/Custom toggle when the block has no Default mode', async () => {
        const html = await render({onQueryTypeChange: undefined, queryType: undefined});

        expect(html).not.toContain('radiogroup');
        expect(html).not.toContain('Uses the product from the current page');
        expect(html).toContain('Choose product');
    });

    it('shows the picker for a block without a toggle even if queryType says default', async () => {
        const html = await render({onQueryTypeChange: undefined, queryType: 'default'});

        expect(html).toContain('Choose product');
    });

    it('takes its own note for Default mode', async () => {
        const html = await render({queryType: 'default', defaultNote: 'Follows the product in this card.'});

        expect(html).toContain('Follows the product in this card.');
        expect(html).not.toContain('Uses the product from the current page');
    });

    it('shows the chosen product with no productId, where the parent updates it as the merchant picks', async () => {
        const html = await render({onQueryTypeChange: undefined, productId: undefined, selectedProduct: {ID: 41, post_title: 'Picked Now'}});

        expect(html).toContain('Picked Now');
        expect(html).not.toContain('is-loading');
        expect(html).not.toContain('Choose product');
    });

    it('renders children under the card, and not under the empty box', async () => {
        const withProduct = await render({
            onQueryTypeChange: undefined,
            productId: undefined,
            selectedProduct: {ID: 41, post_title: 'Picked Now'},
            children: React.createElement('div', {className: 'preview-row'}, 'Excerpt: soft cotton'),
        });
        const empty = await render({children: React.createElement('div', null, 'Excerpt: soft cotton')});

        expect(withProduct).toContain('Excerpt: soft cotton');
        expect(empty).not.toContain('Excerpt: soft cotton');
    });

    it('hands the dialog the commit-on-close callback when the block uses onModalClosed', async () => {
        vi.resetModules();
        let dialogProps = null;
        vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
            default: (props) => {
                dialogProps = props;
                return props.renderTrigger(() => {});
            },
        }));
        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.window = {fluent_cart_block_translation: {}, requestAnimationFrame: (callback) => callback()};
        globalThis.document = {body: {}, activeElement: {live: true}, contains: () => true};
        globalThis.wp = {element: React, components: {DropdownMenu: () => null, MenuGroup: () => null, MenuItem: () => null}};

        const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');
        const onModalClosed = vi.fn();
        renderToStaticMarkup(React.createElement(ProductSourceControl, {
            selectedProduct: {},
            setSelectedProduct: () => {},
            onModalClosed,
        }));

        // The dialog commits on confirm only when it is given onSelectionConfirmed.
        expect(dialogProps.onSelectionConfirmed).toBeUndefined();
        dialogProps.onModalClosed({ID: 5});
        expect(onModalClosed).toHaveBeenCalledWith({ID: 5});
    });

    it('hands the dialog the confirm callback when the block uses onSelectionConfirmed', async () => {
        vi.resetModules();
        let dialogProps = null;
        vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
            default: (props) => {
                dialogProps = props;
                return props.renderTrigger(() => {});
            },
        }));
        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.window = {fluent_cart_block_translation: {}, requestAnimationFrame: (callback) => callback()};
        globalThis.document = {body: {}, activeElement: {live: true}, contains: () => true};
        globalThis.wp = {element: React, components: {DropdownMenu: () => null, MenuGroup: () => null, MenuItem: () => null}};

        const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');
        const onSelectionConfirmed = vi.fn();
        renderToStaticMarkup(React.createElement(ProductSourceControl, {
            selectedProduct: {},
            setSelectedProduct: () => {},
            onSelectionConfirmed,
        }));

        expect(dialogProps.onModalClosed).toBeUndefined();
        dialogProps.onSelectionConfirmed({ID: 6});
        expect(onSelectionConfirmed).toHaveBeenCalledWith({ID: 6});
    });
});

// Walks a React element tree and returns the first element the predicate accepts.
const findElement = (node, predicate) => {
    if (!node || typeof node !== 'object') {
        return null;
    }
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findElement(child, predicate);
            if (found) {
                return found;
            }
        }
        return null;
    }
    if (predicate(node)) {
        return node;
    }
    return findElement(node.props?.children, predicate);
};

describe('ProductSourceControl keeps the dialog open and hands focus on', () => {
    const mountWithDialog = async (props) => {
        vi.resetModules();
        const dialog = {props: null, trigger: null};
        vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
            default: (dialogProps) => {
                dialog.props = dialogProps;
                dialog.trigger = dialogProps.renderTrigger(() => {});
                return dialog.trigger;
            },
        }));
        const page = {body: {}, activeElement: null, contains: () => true};
        page.activeElement = page.body;
        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.document = page;
        globalThis.window = {fluent_cart_block_translation: {}, requestAnimationFrame: (callback) => callback()};
        globalThis.wp = {element: React, components: {DropdownMenu: () => null, MenuGroup: () => null, MenuItem: () => null}};

        const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');
        renderToStaticMarkup(React.createElement(ProductSourceControl, {setSelectedProduct: () => {}, ...props}));

        return {dialog, page};
    };

    it('does not unmount the dialog while the saved product loads (commit-on-close pick)', async () => {
        // The merchant clicked product 6 while 5 is saved: the parent already holds 6.
        const {dialog} = await mountWithDialog({
            queryType: 'custom',
            onQueryTypeChange: () => {},
            productId: '5',
            selectedProduct: {ID: 6, post_title: 'Newly Picked'},
            onModalClosed: () => {},
        });

        expect(dialog.props).not.toBeNull();
        expect(findElement(dialog.trigger, (el) => String(el.props?.className).includes('is-loading'))).not.toBeNull();
    });

    it('keeps the dialog mounted in every state a block can be in', async () => {
        const {dialog} = await mountWithDialog({queryType: 'custom', onQueryTypeChange: () => {}, productId: '', selectedProduct: {}, onModalClosed: () => {}});

        expect(dialog.props).not.toBeNull();
    });

    it('with the toggle and no productId, never shows the page product as the pick', async () => {
        const {dialog} = await mountWithDialog({
            queryType: 'custom',
            onQueryTypeChange: () => {},
            selectedProduct: {ID: 12, post_title: 'Page Product'},
            onModalClosed: () => {},
        });

        expect(findElement(dialog.trigger, (el) => el.props?.className === 'fct-product-source__summary')).toBeNull();
        expect(findElement(dialog.trigger, (el) => el.props?.className === 'fct-product-source__choose')).not.toBeNull();
    });

    it('moves focus to the new control after a pick in commit-on-close mode', async () => {
        const {dialog, page} = await mountWithDialog({
            onModalClosed: () => {},
            selectedProduct: {},
        });
        const chooseButton = findElement(dialog.trigger, (el) => el.props?.className === 'fct-product-source__choose');
        chooseButton.ref.current = {focus: vi.fn()};

        dialog.props.onModalClosed({ID: 5});

        expect(chooseButton.ref.current.focus).toHaveBeenCalledTimes(1);
        expect(page.activeElement).toBe(page.body);
    });

    it('leaves focus alone when the dialog already gave it back to a live element', async () => {
        const {dialog, page} = await mountWithDialog({onModalClosed: () => {}, selectedProduct: {}});
        const chooseButton = findElement(dialog.trigger, (el) => el.props?.className === 'fct-product-source__choose');
        chooseButton.ref.current = {focus: vi.fn()};
        page.activeElement = {live: true};

        dialog.props.onModalClosed({ID: 5});

        expect(chooseButton.ref.current.focus).not.toHaveBeenCalled();
    });
});

describe('ProductSourceControl Replace menu item', () => {
    it('opens the picker from the product card, not from the menu item that is about to unmount', async () => {
        vi.resetModules();
        const open = vi.fn();
        const onClose = vi.fn();
        let trigger = null;
        const menuHandlers = {};

        vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
            default: ({renderTrigger}) => {
                trigger = renderTrigger(open);
                return trigger;
            },
        }));

        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.window = {fluent_cart_block_translation: {}, requestAnimationFrame: (callback) => callback()};
        globalThis.wp = {
            element: React,
            components: {
                DropdownMenu: ({children}) => children({onClose}),
                MenuGroup: ({children}) => React.createElement('div', null, children),
                MenuItem: ({children, onClick}) => {
                    menuHandlers[String(children)] = onClick;
                    return React.createElement('button', null, children);
                },
            },
        };

        const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');
        renderToStaticMarkup(React.createElement(ProductSourceControl, {
            queryType: 'custom',
            onQueryTypeChange: () => {},
            productId: '39612',
            selectedProduct: {ID: 39612, post_title: 'Gift Box'},
            setSelectedProduct: () => {},
            onSelectionConfirmed: () => {},
            onRemove: () => {},
        }));

        // Server rendering attaches no refs, so stand in for the card button.
        const card = findElement(trigger, (el) => el.props?.className === 'fct-product-source__summary');
        const cardButton = {focus: vi.fn()};
        card.ref.current = cardButton;

        menuHandlers['Replace']({currentTarget: {detachedMenuItem: true}});

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(cardButton.focus).toHaveBeenCalledTimes(1);
        expect(open).toHaveBeenCalledTimes(1);
        expect(open.mock.calls[0][0].currentTarget).toBe(cardButton);
        expect(Object.keys(menuHandlers)).toEqual(['Replace', 'Remove']);
    });

    it('leaves Remove out of the menu when the block gives no onRemove', async () => {
        vi.resetModules();
        const menuHandlers = {};

        vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
            default: ({renderTrigger}) => renderTrigger(() => {}),
        }));
        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.window = {fluent_cart_block_translation: {}, requestAnimationFrame: (callback) => callback()};
        globalThis.wp = {
            element: React,
            components: {
                DropdownMenu: ({children}) => children({onClose: () => {}}),
                MenuGroup: ({children}) => React.createElement('div', null, children),
                MenuItem: ({children, onClick}) => {
                    menuHandlers[String(children)] = onClick;
                    return React.createElement('button', null, children);
                },
            },
        };

        const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');
        renderToStaticMarkup(React.createElement(ProductSourceControl, {
            selectedProduct: {ID: 41, post_title: 'Gift Box'},
            setSelectedProduct: () => {},
            onModalClosed: () => {},
        }));

        expect(Object.keys(menuHandlers)).toEqual(['Replace']);
    });
});

describe('ProductSourceControl card menu icon', () => {
    it('shows the three dots stacked vertically, not WordPress\'s horizontal ellipsis', async () => {
        vi.resetModules();
        let menuIcon = null;
        vi.doMock('@/BlockEditor/Components/ProductPicker/SelectProductModal', () => ({
            default: ({renderTrigger}) => renderTrigger(() => {}),
        }));
        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.window = {fluent_cart_block_translation: {}};
        globalThis.wp = {
            element: React,
            components: {
                DropdownMenu: ({icon}) => {
                    menuIcon = icon;
                    return null;
                },
                MenuGroup: ({children}) => React.createElement('div', null, children),
                MenuItem: ({children}) => React.createElement('button', null, children),
            },
        };

        const {default: ProductSourceControl} = await import('@/BlockEditor/Components/ProductPicker/ProductSourceControl.jsx');
        renderToStaticMarkup(React.createElement(ProductSourceControl, {
            queryType: 'custom',
            onQueryTypeChange: () => {},
            productId: '9',
            selectedProduct: {ID: 9, post_title: 'Gift Box'},
            setSelectedProduct: () => {},
            onSelectionConfirmed: () => {},
        }));

        // The vertical dots are three stacked circles' worth of path: the icon's own path data is the check.
        const markup = renderToStaticMarkup(menuIcon);
        expect(markup).toContain('M13 19h-2v-2h2v2zm0-6h-2v-2h2v2zm0-6h-2V5h2v2z');
    });
});

describe('ProductSourceControl toggle label', () => {
    it('says "Product source" unless the block gives its own label', async () => {
        const plain = await render({});
        const named = await render({label: 'Related to'});

        expect(plain).toContain('Product source');
        expect(plain).not.toContain('Related to');
        expect(named).toContain('Related to');
        expect(named).not.toContain('Product source');
    });
});
