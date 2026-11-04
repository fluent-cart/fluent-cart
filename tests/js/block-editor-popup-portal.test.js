import {describe, expect, it, vi} from 'vitest';
import {readFileSync, readdirSync, statSync} from 'node:fs';
import {fileURLToPath, URL} from 'node:url';
import path from 'node:path';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

/**
 * Safari paints a position:fixed overlay drawn inside the editor sidebar only
 * within the sidebar: the Select Product picker opened as a gray sidebar with
 * no dialog. Chrome and Edge let it escape, so it only broke in Safari.
 *
 * No DOM library is installed, so this pins the wiring over the sources:
 * every block-editor component that draws the full-screen popup renders it
 * through createPortal.
 */

const BLOCK_EDITOR_DIR = fileURLToPath(new URL('../../resources/admin/BlockEditor', import.meta.url));

const sourceFiles = (dir) => readdirSync(dir).flatMap((entry) => {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) {
        return sourceFiles(full);
    }
    return /\.jsx?$/.test(entry) ? [full] : [];
});

const popupComponents = () => sourceFiles(BLOCK_EDITOR_DIR)
    .filter((file) => readFileSync(file, 'utf8').includes('className="fct-popup-container"'));

describe('Block editor popups', () => {
    it('finds the shared product pickers', () => {
        const names = popupComponents().map((file) => path.basename(file));

        expect(names).toEqual(expect.arrayContaining(['SelectProductModal.jsx', 'SelectVariationModal.jsx']));
    });

    it.each(popupComponents().map((file) => [path.relative(BLOCK_EDITOR_DIR, file), file]))(
        '%s renders the popup through createPortal',
        (relative, file) => {
            const source = readFileSync(file, 'utf8');

            expect(source).toMatch(/createPortal\(\s*<div className="fct-popup-container"/);
        }
    );

    // Opened from the editor iframe, the top document's activeElement is the
    // iframe itself, and Gutenberg's writing-flow keydown listener on the
    // iframe body moves focus out unless the Tab was already handled. So the
    // trap reads its own document and runs in the capture phase.
    it.each(popupComponents().map((file) => [path.relative(BLOCK_EDITOR_DIR, file), file]))(
        '%s traps Tab against the dialog\'s own document',
        (relative, file) => {
            const source = readFileSync(file, 'utf8');
            const handler = source.match(/const handleDialogKeyDown = [\s\S]*?\n {4}};/);

            expect(handler).not.toBeNull();
            expect(handler[0]).toContain('ownerDocument.activeElement');
            expect(handler[0]).not.toMatch(/(^|[^.\w])document\.activeElement/);
            expect(handler[0]).toMatch(/e\.preventDefault\(\);\s*\/\/[^\n]*\n\s*const index/);
            expect(source).toContain('onKeyDownCapture={handleDialogKeyDown}');
        }
    );
});

const passThrough = ({children}) => React.createElement('div', null, children);

const renderEmptyCarousel = async () => {
    vi.resetModules();
    vi.doMock('@wordpress/api-fetch', () => ({default: () => new Promise(() => {})}));
    vi.doMock('@/BlockEditor/ProductCarousel/Components/InspectorSettings.jsx', () => ({default: () => null}));

    let registered = null;
    const select = () => ({
        getBlocks: () => [],
        getBlock: () => ({innerBlocks: []}),
        getBlockParents: () => [],
        getBlockName: () => null,
    });

    globalThis.React = React;
    globalThis.window = {
        fluent_cart_product_carousel_block_editor_data: {slug: 'fluent-cart', name: 'product-carousel', title: 'Product Carousel'},
        fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
        fluent_cart_block_translation: {},
    };
    globalThis.wp = {
        blocks: {registerBlockType: (name, settings) => { registered = {name, settings}; }},
        blockEditor: {useBlockProps: () => ({}), InnerBlocks: passThrough, InspectorControls: passThrough, store: 'core/block-editor'},
        element: React,
        data: {useSelect: (mapSelect) => mapSelect(select)},
    };

    await import('@/BlockEditor/ProductCarousel/ProductCarouselBlockEditor.jsx');

    return renderToStaticMarkup(React.createElement(registered.settings.edit, {
        attributes: {product_ids: [], carousel_settings: {slidesToShow: 3, infinite: 'no'}, inside_product_info: 'no'},
        setAttributes: () => {},
        clientId: 'carousel',
    }));
};

describe('Product Carousel editor preview', () => {
    it('asks for products instead of loading forever when none are selected', async () => {
        const html = await renderEmptyCarousel();

        expect(html).toContain('Select products in the block settings to show them here.');
        expect(html).not.toContain('Loading products...');
    });
});
