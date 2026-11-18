import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import {parse} from '@vue/compiler-sfc';

/**
 * The grouped variant table uses table-layout: fixed. With every column fixed it was
 * 890px wide and pushed the product page sideways at 1400px; the Variant column must
 * take the leftover width, and the inner (expanded) table must keep the same columns.
 */

const template = parse(fs.readFileSync(new URL('../../resources/admin/Modules/Products/parts/AdvancedVariationGroupedTable.vue', import.meta.url), 'utf8')).descriptor.template.ast;

const findAll = (node, tag, out = []) => {
    if (node.tag === tag) {
        out.push(node);
    }
    (node.children || []).forEach(child => findAll(child, tag, out));
    return out;
};

const widths = colgroup => colgroup.children
    .filter(node => node.tag === 'col')
    .map(col => {
        const width = col.props.find(prop => prop.name === 'width');
        return width ? width.value.content : 'flex';
    });

describe('grouped variant table columns', () => {
    // The first two colgroups are the desktop table and its expanded rows.
    const [outer, inner] = findAll(template, 'colgroup').map(widths);

    it('lets the Variant column take the leftover width', () => {
        expect(outer[2]).toBe('flex');
        expect(outer.filter(width => width === 'flex')).toHaveLength(1);
    });

    it('keeps the expanded rows aligned with the header', () => {
        expect(inner).toEqual(outer);
    });
});
