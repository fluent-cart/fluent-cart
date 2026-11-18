import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import {parse} from '@vue/compiler-sfc';

/**
 * The Per Weight tiers table sits in the shipping method drawer; the drawer must be
 * wide enough for the table's column minimums, or the Actions column is cut off.
 */

const read = file => parse(fs.readFileSync(new URL('../../resources/admin/Modules/Shipping/' + file, import.meta.url), 'utf8')).descriptor.template.ast;

const findAll = (node, tag, out = []) => {
    if (node.tag === tag) {
        out.push(node);
    }
    (node.children || []).forEach(child => findAll(child, tag, out));
    return out;
};

const attr = (node, name) => {
    const prop = node.props.find(item => item.name === name);
    return prop && prop.value ? prop.value.content : undefined;
};

// Element Plus drawer body padding, both sides.
const DRAWER_PADDING = 40;

describe('shipping method drawer', () => {
    it('fits the Per Weight tiers table', () => {
        const columns = findAll(read('Components/WeightTiersEditor.vue'), 'fct-table-column');
        const tableWidth = columns.reduce((sum, column) => sum + parseInt(attr(column, 'width') || attr(column, 'min-width'), 10), 0);

        const drawer = findAll(read('ShippingMethods.vue'), 'fct-dialog').find(node => attr(node, 'class') === 'fct-shipping-method-drawer');
        const drawerWidth = attr(drawer, 'width');

        expect(drawerWidth).toMatch(/^\d+px$/);
        expect(parseInt(drawerWidth, 10)).toBeGreaterThanOrEqual(tableWidth + DRAWER_PADDING);
    });
});
