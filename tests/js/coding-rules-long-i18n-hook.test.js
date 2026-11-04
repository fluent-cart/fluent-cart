import {describe, expect, it} from 'vitest';
import {createRequire} from 'node:module';

/**
 * An admin translate() sentence of 80+ characters belongs in reservedWords.json under a short key.
 * The hook blocks a new one in admin bundles and leaves the block editor and portal alone.
 */

const rule = createRequire(import.meta.url)('../../.claude/hooks/coding-rules/no-long-i18n-literal.js');
const long = 'Coupons will be calculated based on their priority. The lower the number the higher the priority is.';

describe('no-long-i18n-literal hook rule', () => {
    it('blocks a new long sentence in an admin component', () => {
        const violations = rule({filePath: '/repo/resources/admin/Modules/Coupons/AddOrEditCoupon.vue', content: `{{ translate('${long}') }}`});
        expect(violations).toHaveLength(1);
        expect(violations[0].pattern).toContain('reservedWords.json');
    });

    it('allows the short key, a short sentence, and non-admin bundles', () => {
        expect(rule({filePath: '/repo/resources/admin/Modules/X.vue', content: "translate('Coupon_Coupons_Calculated')"})).toEqual([]);
        expect(rule({filePath: '/repo/resources/admin/Modules/X.vue', content: "translate('Save')"})).toEqual([]);
        expect(rule({filePath: '/repo/resources/admin/BlockEditor/X.vue', content: `translate('${long}')`})).toEqual([]);
        expect(rule({filePath: '/repo/resources/public/customer-profile/X.vue', content: `translate('${long}')`})).toEqual([]);
    });

    it('does not block an edit that keeps an existing long sentence', () => {
        const old = `translate('${long}')`;
        expect(rule({filePath: '/repo/resources/admin/Modules/X.vue', content: old + "\ntranslate('Save')", oldContent: old})).toEqual([]);
    });
    it('catches a long sentence in backticks, and counts characters, not bytes', () => {
        expect(rule({filePath: '/repo/resources/admin/Modules/X.vue', content: 'translate(`' + long + '`)'})).toHaveLength(1);
        // 79 characters, more than 80 bytes
        expect(rule({filePath: '/repo/resources/admin/Modules/X.vue', content: "translate('" + '→'.repeat(79) + "')"})).toEqual([]);
    });
});
