import {describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace(`%${index + 1}$s`, arg), text),
}));

const {mergeProductTree} = await import('@/Bits/Components/ProductChips/labelIndex.js');
const {buildProductChips, groupIdsByTree} = await import('@/Bits/Components/ProductChips/productChips.js');

// Shape returned by VariationFilter::getTreeFilterOptions().
const firstPage = [
    {value: 1, label: 'Plan', children: [{value: 11, label: 'Basic'}, {value: 12, label: 'Pro'}, {value: 13, label: 'Team'}]},
    {value: 2, label: 'Shirt', children: [{value: 21, label: 'S'}, {value: 22, label: 'M'}]},
];
const searchResult = [
    {value: 3, label: 'Ebook', children: [{value: 31, label: 'PDF'}]},
];

describe('mergeProductTree', () => {
    it('keeps products a later search dropped', () => {
        const cache = mergeProductTree(mergeProductTree([], firstPage), searchResult);
        expect(cache.map(product => product.value)).toEqual([1, 2, 3]);
    });

    it('replaces a product seen again in place', () => {
        const renamed = [{value: 1, label: 'Plan v2', children: [{value: 11, label: 'Basic'}]}];
        const cache = mergeProductTree(firstPage, renamed);
        expect(cache.map(product => product.label)).toEqual(['Plan v2', 'Shirt']);
    });

    it('does not mutate its inputs', () => {
        const cached = [...firstPage];
        mergeProductTree(cached, searchResult);
        expect(cached).toHaveLength(2);
    });

    it('ignores empty and malformed responses', () => {
        expect(mergeProductTree(firstPage, null)).toHaveLength(2);
        expect(mergeProductTree(null, [{label: 'No value'}, null])).toEqual([]);
    });
});

describe('advanced filter product chips from the cache', () => {
    const cache = mergeProductTree(mergeProductTree([], firstPage), searchResult);

    it('shows picks hidden by the current search', () => {
        // The visible options are only the search result, yet picks from the first page still resolve.
        const chips = buildProductChips(groupIdsByTree([12, 13, 21, 31], cache));
        expect(chips.map(chip => chip.text)).toEqual(['Plan (2 of 3)', 'Shirt (1 of 2)', 'Ebook']);
    });

    it('reads a ticked parent as the product name alone', () => {
        // A ticked parent puts every child id in v-model, not the product id.
        const [chip] = buildProductChips(groupIdsByTree([11, 12, 13], cache));
        expect(chip).toEqual({key: 'id-1', text: 'Plan', variations: []});
    });
});
