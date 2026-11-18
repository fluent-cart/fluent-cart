import {describe, expect, it} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {QUICK_VIEW_PATH, QUICK_VIEW_CIRCLE} from '../../resources/admin/Bits/Components/Icons/quickView.js';

describe('quick view icon', () => {
    it('renders the shared asset as unfilled React shapes', async () => {
        globalThis.React = React;
        const {QuickView} = await import('@/BlockEditor/Icons.jsx');
        const html = renderToStaticMarkup(React.createElement(QuickView));
        expect(QUICK_VIEW_PATH).toBe('M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z');
        expect(QUICK_VIEW_CIRCLE).toEqual({cx: 12, cy: 12, r: 3});
        expect(html).toContain('d="' + QUICK_VIEW_PATH + '" style="fill:none"');
        expect(html).toContain('<circle cx="12" cy="12" r="3" style="fill:none"');
        expect(html).toContain('aria-hidden="true"');
    });
});
