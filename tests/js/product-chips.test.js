import {describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace(`%${index + 1}$s`, arg), text),
}));

const productChipsModule = await import('@/Bits/Components/ProductChips/productChips.js');
const {buildProductChips, groupIdsByTree} = productChipsModule;

const tree = [
    {label: 'Plan', value: 1, children: [{label: 'Basic', value: 11}, {label: 'Pro', value: 12}, {label: 'Team', value: 13}]},
    {label: 'Shirt', value: 2, children: [{label: 'S', value: 21}, {label: 'M', value: 22}]},
    {label: 'Ebook', value: 3},
];

describe('groupIdsByTree', () => {
    it('groups variations under their product', () => {
        expect(groupIdsByTree([11, 21, 12], tree)).toEqual([
            {key: 'id-1', productName: 'Plan', total: 3, labels: ['Basic', 'Pro']},
            {key: 'id-2', productName: 'Shirt', total: 2, labels: ['S']},
        ]);
    });

    it('treats a childless product as picked whole', () => {
        expect(groupIdsByTree([3], tree)).toEqual([
            {key: 'id-3', productName: 'Ebook', total: 0, labels: []},
        ]);
    });

    it('collects ids missing from the tree under Unknown', () => {
        expect(groupIdsByTree([99, 98], tree)).toEqual([
            {key: 'unknown', productName: null, total: 0, labels: ['99', '98']},
        ]);
    });
});

describe('buildProductChips', () => {
    it('names the product alone when every variation is picked', () => {
        const [chip] = buildProductChips(groupIdsByTree([21, 22], tree));
        expect(chip).toEqual({key: 'id-2', text: 'Shirt', variations: []});
    });

    it('shows "x of y" with the picked variations for a partial product', () => {
        const [chip] = buildProductChips(groupIdsByTree([11, 13], tree));
        expect(chip).toEqual({key: 'id-1', text: 'Plan (2 of 3)', variations: ['Basic', 'Team']});
    });

    it('falls back to "n picked" when the total is unknown', () => {
        const [chip] = buildProductChips([{key: 'k', productName: 'Plan', total: 0, labels: ['Basic', 'Pro']}]);
        expect(chip.text).toBe('Plan (2 picked)');
    });

    it('labels groups without a product as Unknown', () => {
        const [chip] = buildProductChips(groupIdsByTree([99], tree));
        expect(chip.text).toBe('Unknown | 99');
    });
});

describe('fitChipCount', () => {
    const {fitChipCount} = productChipsModule;
    const widths = [100, 100, 100, 100];

    it('shows every chip when they all fit', () => {
        expect(fitChipCount(widths, 30, 4, 412)).toBe(4);
    });

    it('leaves room for the +N chip', () => {
        // 2 chips + "+2" = 100 + 4 + 100 + 4 + 30 = 238; a third would need 342.
        expect(fitChipCount(widths, 30, 4, 300)).toBe(2);
    });

    it('keeps at least one chip when none fit', () => {
        expect(fitChipCount(widths, 30, 4, 50)).toBe(1);
    });

    it('never exceeds max', () => {
        expect(fitChipCount(widths, 30, 4, 2000, 2)).toBe(2);
    });

    it('returns 0 without chips', () => {
        expect(fitChipCount([], 30, 4, 500)).toBe(0);
    });
});
