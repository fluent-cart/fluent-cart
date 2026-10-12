import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * A template's preset must deliver what its card description promises.
 * Source-contract check: the vitest environment is `node` and nothing here mounts an SFC.
 */
const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const CREATE_RULE = 'resources/admin/Modules/DynamicPricing/CreateNewPricingRule.vue';

const templateBlock = (value) => {
    const source = fs.readFileSync(path.join(repoRoot, CREATE_RULE), 'utf8');
    const start = source.indexOf(`value: '${value}'`);
    expect(start, `template ${value} should exist`).toBeGreaterThan(-1);

    const end = source.indexOf('\n    },', start);
    return source.slice(start, end);
};

const presetField = (block, field) => {
    const match = block.match(new RegExp(`\\b${field}: '([^']*)'`));
    return match ? match[1] : null;
};

describe('dynamic pricing rule templates', () => {
    it('gives free shipping above the minimum on every purchase, not only the first', () => {
        const block = templateBlock('free_shipping_minimum');

        expect(block).toContain("translate('Free shipping when the subtotal is at least %1$s.'");
        expect(presetField(block, 'when_to_apply')).toBe('every');
        expect(presetField(block, 'applies_to')).toBe('shipping');
        expect(presetField(block, 'price_type')).toBe('discount');
        expect(presetField(block, 'apply_as')).toBe('percentage');
        expect(presetField(block, 'amount')).toBe('100');
        expect(block).toContain("{key: 'cart_subtotal', operator: 'is_at_least', value: 7500}");
    });

    it('gives the bulk purchase discount on every purchase', () => {
        const block = templateBlock('volume_discount');

        expect(block).toContain("translate('10% discount for bulk purchases.')");
        expect(presetField(block, 'when_to_apply')).toBe('every');
        expect(presetField(block, 'applies_to')).toBe('line_item');
        expect(presetField(block, 'price_type')).toBe('discount');
        expect(presetField(block, 'apply_as')).toBe('percentage');
        expect(presetField(block, 'amount')).toBe('10');
    });

    it('starts with "Start from scratch" selected', () => {
        const source = fs.readFileSync(path.join(repoRoot, CREATE_RULE), 'utf8');
        const form = source.slice(source.indexOf('const form = ref({'));

        expect(form.match(/rule_type: '([^']*)'/)?.[1]).toBe('scratch');
    });
});
