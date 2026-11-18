import {beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import React from 'react';

// The Layout panel's choose -> Undo wiring for a grid-only layout, driven without a DOM: the hooks are stubbed,
// the component is called directly and its element tree is searched for the buttons.
let CardLayoutSettings;
let switched;
let store;
let dispatch;
let card;
let removed;

const preset = (extra = {}) => ({label: 'Layout', template: [], ...extra});
const SWITCHER = 'fluent-cart/shopapp-product-view-switcher';

const find = (node, predicate, found = []) => {
    if (Array.isArray(node)) {
        node.forEach((child) => find(child, predicate, found));
    } else if (node && typeof node === 'object' && node.props) {
        if (predicate(node)) found.push(node);
        find(node.props.children, predicate, found);
    }
    return found;
};
const panel = (layout) => CardLayoutSettings({clientId: 'card', layout});
const choiceFor = (tree, label) => find(tree, (node) => node.props.className === 'fct-card-preset-choice')
    .find((node) => find(node.props.children, (child) => child.type === 'strong' && child.props.children === label).length);
const undoButton = (tree) => find(tree, (node) => node.props.className === 'fct-card-preset-undo')[0];

beforeAll(async () => {
    globalThis.React = React;
    globalThis.window = {
        fluent_cart_block_translation: {},
        fluent_cart_product_card_data: {card_presets: {
            classic: preset({label: 'Classic'}),
            compact: preset({label: 'Compact'}),
            floating: preset({label: 'Floating', view_switcher: false}),
        }},
    };
    globalThis.wp = {
        element: {useState: () => [switched, (value) => {switched = value;}]},
        blockEditor: {InspectorControls: ({children}) => children},
        components: {FormToggle: () => null},
        data: {useSelect: (read) => read(() => store), select: () => store, dispatch: () => dispatch},
        blocks: {getBlockType: () => ({}), createBlocksFromInnerBlocksTemplate: () => [], createBlock: (name) => ({clientId: 'restored', name, attributes: {}, innerBlocks: []})},
    };
    ({default: CardLayoutSettings} = await import('@/BlockEditor/ShopApp/InnerBlocks/Components/CardLayoutSettings.jsx'));
});

beforeEach(() => {
    switched = null;
    removed = [];
    card = {clientId: 'card', attributes: {template_version: 1, card_layout: 'compact'}};
    const switcherBlock = {clientId: 'switcher', name: SWITCHER, attributes: {}, innerBlocks: []};
    // A Products block whose first loop holds the card, with its switch in an action container.
    store = {
        getBlock: (id) => id === 'card' ? card : (id === 'switcher' && !removed.includes('switcher') ? switcherBlock : null),
        getBlocks: () => [],
        getBlockAttributes: (id) => id === 'card' ? card.attributes : {},
        getBlockName: (id) => ({loop: 'fluent-cart/shopapp-product-loop', card: 'fluent-cart/product-card', switcher: SWITCHER, container: 'fluent-cart/shopapp-product-action-container'})[id] || '',
        getClientIdsOfDescendants: () => ['container', 'loop', 'card', ...(removed.includes('switcher') ? [] : ['switcher'])],
        getBlockOrder: (id) => id === 'loop' ? ['card'] : [],
        getBlockParentsByBlockName: () => ['products'],
        getBlockRootClientId: (id) => id === 'card' ? 'loop' : 'container',
        getBlockIndex: () => 1,
    };
    dispatch = {
        replaceBlocks: vi.fn((id, next) => Object.assign(card, next)),
        replaceInnerBlocks: vi.fn(),
        updateBlockAttributes: vi.fn(),
        removeBlocks: vi.fn((ids) => removed.push(...ids)),
        insertBlocks: vi.fn(),
    };
});

describe('Layout panel Undo for a grid-only layout', () => {
    it('puts the grid/list switch back after switching to Floating', () => {
        choiceFor(panel('compact'), 'Floating').props.onClick();
        expect(removed).toEqual(['switcher']);
        expect(dispatch.insertBlocks).not.toHaveBeenCalled();

        const undo = undoButton(panel('floating'));
        expect(undo).toBeTruthy();
        undo.props.onClick();
        expect(dispatch.insertBlocks).toHaveBeenCalledTimes(1);
        const [block, index, parentId] = dispatch.insertBlocks.mock.calls[0];
        expect([block.clientId, index, parentId]).toEqual(['switcher', 1, 'container']);
        expect(undoButton(panel('floating'))).toBeUndefined();
    });

    it('keeps Undo for a card that already has Floating when the switch is removed', () => {
        card.attributes.card_layout = 'floating';
        expect(undoButton(panel('floating'))).toBeUndefined();
        choiceFor(panel('floating'), 'Floating').props.onClick();
        expect(removed).toEqual(['switcher']);

        const undo = undoButton(panel('floating'));
        expect(undo).toBeTruthy();
        undo.props.onClick();
        const [block, index, parentId] = dispatch.insertBlocks.mock.calls[0];
        expect([block.clientId, index, parentId]).toEqual(['switcher', 1, 'container']);
    });

    it('offers no Undo when picking the layout the card already has and there is no switch to remove', () => {
        card.attributes.card_layout = 'floating';
        store.getClientIdsOfDescendants = () => ['loop', 'card'];
        choiceFor(panel('floating'), 'Floating').props.onClick();
        expect(removed).toEqual([]);
        expect(undoButton(panel('floating'))).toBeUndefined();
    });

    it('brings the switch back when leaving Floating, and Undo takes it out again', () => {
        card.attributes.card_layout = 'floating';
        removed.push('switcher');
        choiceFor(panel('floating'), 'Compact').props.onClick();
        expect(dispatch.insertBlocks).toHaveBeenCalledTimes(1);
        const [block, index, parentId] = dispatch.insertBlocks.mock.calls[0];
        expect([block.name, index, parentId]).toEqual([SWITCHER, 0, 'container']);

        undoButton(panel('compact')).props.onClick();
        expect(dispatch.removeBlocks).toHaveBeenCalledWith(['restored'], false);
    });

    it('puts the switch first in the Products block when it has no action container', () => {
        card.attributes.card_layout = 'floating';
        removed.push('switcher');
        store.getClientIdsOfDescendants = () => ['loop', 'card'];
        choiceFor(panel('floating'), 'Compact').props.onClick();
        const [block, index, parentId] = dispatch.insertBlocks.mock.calls[0];
        expect([block.name, index, parentId]).toEqual([SWITCHER, 0, 'products']);
    });

    it('adds no second switch when the Products block already has one', () => {
        card.attributes.card_layout = 'floating';
        choiceFor(panel('floating'), 'Compact').props.onClick();
        expect(dispatch.insertBlocks).not.toHaveBeenCalled();
    });

    it('leaves a switch the merchant removed alone when switching between other layouts', () => {
        removed.push('switcher');
        choiceFor(panel('compact'), 'Classic').props.onClick();
        expect(dispatch.insertBlocks).not.toHaveBeenCalled();
    });
});
