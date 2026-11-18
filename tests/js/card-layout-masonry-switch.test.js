import {beforeAll, describe, expect, it} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

let CardLayoutSettings;
let toggles;

const pass = ({children}) => React.createElement('div', null, children);

beforeAll(async () => {
    globalThis.React = React;
    globalThis.window = {
        fluent_cart_block_translation: {},
        // The presets as the editor gets them: Masonry is kept for the page builders only.
        fluent_cart_product_card_data: {card_presets: {
            classic: {label: 'Classic', template: []},
            compact: {label: 'Compact', template: [], description: 'Price and button share one row. Good for 4+ columns.'},
            masonry: {label: 'Masonry', template: [], builder_only: true},
            floating: {label: 'Floating', template: []},
            framed: {label: 'Framed', template: []},
        }},
    };
    globalThis.wp = {
        element: React,
        blockEditor: {InspectorControls: pass},
        data: {useSelect: () => []},
        components: {
            Panel: pass,
            PanelBody: pass,
            FormToggle: (props) => {
                toggles.push(props);
                return null;
            },
        },
    };
    ({default: CardLayoutSettings} = await import('@/BlockEditor/ShopApp/InnerBlocks/Components/CardLayoutSettings.jsx'));
});

const render = (props) => {
    toggles = [];
    return renderToStaticMarkup(React.createElement(CardLayoutSettings, {clientId: 'card', layout: 'compact', ...props}));
};

describe('Masonry as a switch on every card layout', () => {
    it('lists the four layouts, without Masonry', () => {
        const html = render({});
        // The picker's buttons only; the info box below repeats the current layout's name.
        const tiles = [...html.matchAll(/<button[^>]*fct-card-preset-choice[^>]*>.*?<strong>([^<]+)<\/strong><\/button>/g)].map((m) => m[1]);
        expect(tiles).toEqual(['Classic', 'Compact', 'Floating', 'Framed']);
    });

    it('offers the Masonry grid switch in a grid loop and saves it', () => {
        const saved = [];
        const html = render({masonry: true, onMasonry: (value) => saved.push(value)});
        // Its label names the switch, and the one-line hint describes it.
        expect(html).toContain(`<label for="${toggles[0].id}" class="fct-card-masonry-title">Masonry grid</label>`);
        expect(html).toContain(`id="${toggles[0]['aria-describedby']}"`);
        expect(toggles).toHaveLength(1);
        expect(toggles[0].checked).toBe(true);
        toggles[0].onChange({target: {checked: false}});
        expect(saved).toEqual([false]);
    });

    it('has no switch where there is no grid to stagger (a carousel, a single card)', () => {
        render({onMasonry: null});
        expect(toggles).toHaveLength(0);
    });

    it('draws the Layout panel v2: a Card style heading, one grey line, no info box or footer', () => {
        const html = render({});
        expect(html).toMatch(/<span id="fct-card-style-card" class="fct-card-style-title">Card style<\/span>/);
        expect(html).toContain('aria-labelledby="fct-card-style-card"');
        expect(html).toContain('<p class="fct-card-style-note">Price and button share one row. Good for 4+ columns.</p>');
        // Undo appears only after a switch; the old info box, notice and footer help are gone.
        expect(html).not.toContain('fct-card-preset-undo');
        expect(html).not.toMatch(/fct-card-preset-(info|notice|help)/);
        // Compact's thumbnail puts price and button in one row.
        expect(html).toMatch(/fct-card-preset-thumb--compact.*?fct-card-preset-thumb-row/);
    });
});
