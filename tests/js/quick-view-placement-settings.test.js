import {beforeAll, describe, expect, it} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

let InspectorSettings;
let selects;

const pass = ({children}) => React.createElement('div', null, children);

beforeAll(async () => {
    globalThis.React = React;
    globalThis.window = {fluent_cart_block_translation: {}};
    globalThis.wp = {
        element: React,
        blockEditor: {InspectorControls: pass},
        components: {
            Panel: pass,
            PanelBody: pass,
            SelectControl: (props) => {
                selects.push(props);
                return null;
            },
        },
    };
    ({default: InspectorSettings} = await import('@/BlockEditor/ProductQuickView/Components/InspectorSettings.jsx'));
});

const render = (props) => {
    selects = [];
    const attributes = {position: 'image', show_on: 'hover', display: 'icon_text', image_position: 'bottom-center'};
    return renderToStaticMarkup(React.createElement(InspectorSettings, {attributes, setAttributes: () => {}, ...props}));
};

describe('Quick View settings follow where the block sits', () => {
    it('in the image block: Position like the badges, then Show and Style', () => {
        render({onImage: true});
        expect(selects.map((select) => select.label)).toEqual(['Position', 'Show', 'Style']);
        expect(selects[0].value).toBe('bottom-center');
        expect(selects[0].options.map((option) => option.value))
            .toEqual(['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right']);
    });

    it('beside the button: no settings, only a note', () => {
        const html = render({onImage: false});
        expect(selects).toHaveLength(0);
        expect(html).toContain('Shown as an icon beside the button.');
    });
});
