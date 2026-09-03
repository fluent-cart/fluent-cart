import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

import Filter from '../../resources/public/product-page/Filter.js';

/**
 * Regression: ticking a parent category returned "No Product Found!".
 *
 * Reproduced on https://fluentcart.test/shop/ with
 * Accessories > Child > Nati > Inner Nati, where only Inner Nati holds a
 * product. Ticking Inner Nati returned that product. Ticking its parent Nati
 * ticked Inner Nati in the sidebar and wrote both ids into the URL, but the
 * grid went empty.
 *
 * Two listeners were bound to the same `change` event: the generic one that
 * calls applyFilter(), registered over every input first, and the parent
 * checkbox one that ticks the descendants, registered after. Same element,
 * same event, so they fire in registration order — the request went out
 * carrying `product-categories: [Nati]` alone, and only afterwards were the
 * children ticked. Product::scopeFilterByTaxonomy() matches
 * `term_id IN (...)` with no descendant expansion, so a product filed under
 * Inner Nati alone did not match Nati, and the grid emptied while the sidebar
 * and URL said otherwise.
 *
 * The descendant sync must therefore happen BEFORE the form is read.
 */

const filterSource = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/product-page/Filter.js'),
    'utf8'
);

/**
 * Minimal stand-in for the rendered sidebar markup. The suite has no DOM
 * environment; syncChildCheckboxes() only needs closest() on the checkbox and
 * querySelectorAll() on the group it resolves to.
 */
function buildSidebar() {
    const checkboxes = {};

    const makeCheckbox = (label, isParent) => {
        const node = {
            label,
            type: 'checkbox',
            checked: false,
            parentNode: null,
            isParent,
            closest(selector) {
                if (selector !== '[data-fluent-cart-shop-app-filter-checkbox-child-group]') {
                    throw new Error('unexpected selector: ' + selector);
                }
                let current = this.parentNode;
                while (current && !current.isChildGroup) {
                    current = current.parentNode;
                }
                return current || null;
            },
        };
        checkboxes[label] = node;
        return node;
    };

    // A node with children renders as a child-group wrapping its own checkbox
    // plus a container holding the rendered subtree.
    const makeGroup = (label, children) => {
        const own = makeCheckbox(label, true);
        const group = {
            isChildGroup: true,
            parentNode: null,
            children: [own, ...children],
            querySelectorAll(selector) {
                if (selector !== 'input[type="checkbox"]') {
                    throw new Error('unexpected selector: ' + selector);
                }
                const found = [];
                const walk = (node) => {
                    if (node.type === 'checkbox') {
                        found.push(node);
                        return;
                    }
                    (node.children || []).forEach(walk);
                };
                walk(this);
                return found;
            },
        };
        group.children.forEach(child => child.parentNode = group);
        return group;
    };

    const accessories = makeGroup('Accessories', [
        makeGroup('Child', [
            makeGroup('Nati', [makeCheckbox('Inner Nati', false)]),
            makeCheckbox('Nati 2', false),
        ]),
        makeCheckbox('Child 2', false),
    ]);

    return {accessories, checkboxes};
}

const checkedLabels = (checkboxes) => Object.values(checkboxes)
    .filter(checkbox => checkbox.checked)
    .map(checkbox => checkbox.label)
    .sort();

describe('shop filter parent checkbox', () => {
    const filter = Object.create(Filter.prototype);

    it('ticks the parent subtree only, not the ancestors or their other branches', () => {
        const {checkboxes} = buildSidebar();

        checkboxes['Nati'].checked = true;
        filter.syncChildCheckboxes(checkboxes['Nati']);

        expect(checkedLabels(checkboxes)).toEqual(['Inner Nati', 'Nati']);
    });

    it('ticks every descendant when the root category is ticked', () => {
        const {checkboxes} = buildSidebar();

        checkboxes['Accessories'].checked = true;
        filter.syncChildCheckboxes(checkboxes['Accessories']);

        expect(checkedLabels(checkboxes)).toEqual([
            'Accessories', 'Child', 'Child 2', 'Inner Nati', 'Nati', 'Nati 2',
        ]);
    });

    it('unticks the same subtree it ticked', () => {
        const {checkboxes} = buildSidebar();

        checkboxes['Child'].checked = true;
        filter.syncChildCheckboxes(checkboxes['Child']);
        expect(checkedLabels(checkboxes)).toEqual(['Child', 'Inner Nati', 'Nati', 'Nati 2']);

        checkboxes['Child'].checked = false;
        filter.syncChildCheckboxes(checkboxes['Child']);
        expect(checkedLabels(checkboxes)).toEqual([]);
    });

    it('leaves the tree alone for a checkbox outside any child group', () => {
        const loose = {
            type: 'checkbox',
            checked: true,
            parentNode: null,
            closest: () => null,
        };

        expect(() => filter.syncChildCheckboxes(loose)).not.toThrow();
    });

    // The ordering half of the bug is not observable through syncChildCheckboxes
    // itself — it lives in how the handler sequences the sync against the read.
    it('syncs descendants before the form is read for the request', () => {
        const handlerStart = filterSource.indexOf('\n    listenForFilterValueChange() {');
        expect(handlerStart).toBeGreaterThan(-1);

        const handler = filterSource.slice(
            handlerStart,
            filterSource.indexOf('syncChildCheckboxes(parentCheckbox)')
        );

        const syncAt = handler.indexOf('this.syncChildCheckboxes(input)');
        const applyAt = handler.indexOf('this.applyFilter()');

        expect(syncAt).toBeGreaterThan(-1);
        expect(applyAt).toBeGreaterThan(-1);
        expect(syncAt).toBeLessThan(applyAt);
    });

    it('does not re-introduce a second change listener bound after the generic one', () => {
        expect(filterSource).not.toContain("querySelectorAll('input[data-parent-checkbox]')");
    });
});
