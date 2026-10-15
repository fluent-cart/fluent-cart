import {beforeEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

/**
 * The Currency Switcher block's Switcher Settings panel comes from Pro (through
 * editor.BlockEdit, like the checkout block's Products panel). Core keeps the
 * block, its attributes and the preview, and shows the Pro gate without Pro.
 */

const BUTTON_PREVIEW = {
    style: 'button',
    currencies: [
        {code: 'USD', symbol: '$', name: 'US Dollar'},
        {code: 'EUR', symbol: '€', name: 'Euro'},
    ],
};

const passThrough = ({children}) => React.createElement('div', null, children);

let registered;

const loadBlock = async (blockData) => {
    vi.resetModules();
    registered = null;

    globalThis.React = React;
    globalThis.window = {
        fluent_cart_currency_switcher_data: {
            slug: 'fluent-cart',
            name: 'currency-switcher',
            title: 'Currency Switcher',
            empty_note: 'Shows when more than one currency is offered.',
            pro_note: 'The currency switcher is a FluentCart Pro feature.',
            ...blockData,
        },
        fluent_cart_block_translation: {},
    };
    globalThis.wp = {
        blocks: {registerBlockType: (name, settings) => { registered = {name, settings}; }},
        blockEditor: {useBlockProps: () => ({}), InspectorControls: passThrough},
        element: React,
        components: {
            Dashicon: () => null,
            PanelBody: ({title, children}) => React.createElement('section', {'data-panel': title}, children),
            Notice: passThrough,
            Button: ({children}) => React.createElement('a', null, children),
        },
    };

    await import('@/BlockEditor/CurrencySwitcher/CurrencySwitcherBlockEditor.jsx');

    return registered;
};

const renderEdit = (block) => renderToStaticMarkup(
    React.createElement(block.settings.edit, {attributes: {}, setAttributes: () => {}})
);

describe('Currency Switcher block settings', () => {
    beforeEach(() => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    it('shows the Pro gate in the sidebar and on the canvas without Pro', async () => {
        const block = await loadBlock({is_pro_active: false, preview: BUTTON_PREVIEW});
        const html = renderEdit(block);

        expect(html).toContain('data-panel="Switcher Settings"');
        expect(html).toContain('Available in FluentCart Pro');
        expect(html).toContain('fct-pro-notice');
        expect(html).not.toContain('fct-currency-switcher-button');
    });

    it('leaves the settings panel to Pro when Pro is active', async () => {
        const block = await loadBlock({is_pro_active: true, preview: BUTTON_PREVIEW});
        const html = renderEdit(block);

        expect(html).toContain('fct-currency-switcher-button');
        expect(html).not.toContain('data-panel=');
        expect(html).not.toContain('fct-pro-notice');
    });

    it('keeps declaring the settings attributes so they survive without Pro', async () => {
        const block = await loadBlock({is_pro_active: false, preview: BUTTON_PREVIEW});

        expect(block.name).toBe('fluent-cart/currency-switcher');
        expect(Object.keys(block.settings.attributes)).toEqual([
            'display', 'flag', 'symbol', 'flag_position',
            'list_display', 'list_flag', 'list_symbol', 'list_flag_position',
        ]);
    });
});
