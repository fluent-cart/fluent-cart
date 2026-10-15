import {describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace(`%${index + 1}$s`, arg), text),
}));

const {mergeProductTree, mergeOptionLabels, groupIdsByLabels} = await import('@/Bits/Components/ProductChips/labelIndex.js');
const {buildProductChips, groupIdsByTree} = await import('@/Bits/Components/ProductChips/productChips.js');

const initial = [
    {label: 'Plan', value: 'product_1', children: [{label: 'Basic', value: 11}, {label: 'Pro', value: 12}]},
    {label: 'Shirt', value: 'product_2', children: [{label: 'S', value: 21}]},
];

describe('mergeProductTree', () => {
    it('keeps products a later search left out', () => {
        const searched = [{label: 'Mug', value: 'product_3', children: [{label: 'Red', value: 31}]}];
        const merged = mergeProductTree(initial, searched);

        expect(merged.map(product => product.label)).toEqual(['Plan', 'Shirt', 'Mug']);
        expect(groupIdsByTree([11, 31], merged).map(group => group.productName)).toEqual(['Plan', 'Mug']);
    });

    it('unions variations and takes the newer label', () => {
        const searched = [{label: 'Shirt v2', value: 'product_2', children: [{label: 'Small', value: 21}, {label: 'M', value: 22}]}];
        const [, shirt] = mergeProductTree(initial, searched);

        expect(shirt).toEqual({
            label: 'Shirt v2',
            value: 'product_2',
            children: [{label: 'Small', value: 21}, {label: 'M', value: 22}],
        });
    });

    it('does not mutate its inputs', () => {
        const known = JSON.parse(JSON.stringify(initial));
        mergeProductTree(known, [{label: 'Plan', value: 'product_1', children: [{label: 'Team', value: 13}]}]);

        expect(known).toEqual(initial);
    });

    it('handles empty sides', () => {
        expect(mergeProductTree(null, initial)).toEqual(initial);
        expect(mergeProductTree(initial, undefined)).toEqual(initial);
    });
});

describe('mergeOptionLabels / groupIdsByLabels', () => {
    it('keeps labels of options a search replaced', () => {
        let labels = mergeOptionLabels({}, [{value: 1, label: 'Alpha'}, {value: 2, label: 'Beta'}]);
        labels = mergeOptionLabels(labels, [{value: 3, label: 'Gamma'}]);

        expect(groupIdsByLabels([1, 3], labels)).toEqual([
            {key: 'id-1', productName: 'Alpha', total: 0, labels: []},
            {key: 'id-3', productName: 'Gamma', total: 0, labels: []},
        ]);
    });

    it('falls back to the raw id for an unknown label', () => {
        expect(groupIdsByLabels(['admin'], {})[0].productName).toBe('admin');
    });

    it('renders as whole-item chips', () => {
        const chips = buildProductChips(groupIdsByLabels([1, 2], {1: 'Alpha', 2: 'Beta'}));

        expect(chips).toEqual([
            {key: 'id-1', text: 'Alpha', variations: []},
            {key: 'id-2', text: 'Beta', variations: []},
        ]);
    });
});
