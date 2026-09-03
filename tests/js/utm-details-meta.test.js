import {describe, expect, it, vi} from 'vitest';

// The component reaches AppConfig (and therefore `window`) through the
// translator, and Card.js pulls the admin component kit. Neither is under test
// here; the meta normalisation is.
vi.mock('@/utils/translator/Translator', () => ({
    default: (text) => text,
}));

vi.mock('@/Bits/Components/Card/Card.js', () => ({
    Container: {},
    Header: {},
    Body: {},
}));

import UtmDetails from '@/Modules/Orders/Components/UtmDetails.vue';

/**
 * Ad-network click identifiers (gclid, gbraid, wbraid, gad_campaignid,
 * gad_source, msclkid, fbclid) are stored in the `meta` JSON column of
 * fct_order_operations rather than in dedicated columns.
 *
 * The REST response carries that column as an OBJECT when it holds values and
 * as an empty ARRAY when it does not, because PHP json_encode maps an
 * associative array to `{}` and an empty array to `[]`. A guard of
 * `Array.isArray(meta)` is therefore true only when there is nothing to show,
 * and false whenever there is — which hid every captured click ID.
 */
const metaItems = (order_operation) => UtmDetails.computed.metaItems.call({order_operation});

describe('UtmDetails meta rendering', () => {
    it('lists click identifiers when the API sends meta as an object', () => {
        const items = metaItems({
            meta: {gclid: 'Cj0KCQ', gad_source: '1', msclkid: 'abc123'},
        });

        expect(items).toHaveLength(3);
        expect(items.map((item) => item.key)).toEqual(['gclid', 'gad_source', 'msclkid']);
        expect(items.map((item) => item.value)).toEqual(['Cj0KCQ', '1', 'abc123']);
    });

    it('gives each known click identifier a human label', () => {
        const [item] = metaItems({meta: {gclid: 'Cj0KCQ'}});

        expect(item.label).toContain('gclid');
        expect(item.label).not.toBe('gclid');
    });

    it('falls back to the raw key for an identifier added through the filter', () => {
        const [item] = metaItems({meta: {ttclid: 'tiktok-123'}});

        expect(item.label).toBe('ttclid');
        expect(item.value).toBe('tiktok-123');
    });

    it('renders nothing when the API sends the empty-array form of meta', () => {
        expect(metaItems({meta: []})).toEqual([]);
    });

    it('renders nothing when meta is absent or the row itself is missing', () => {
        expect(metaItems({})).toEqual([]);
        expect(metaItems({meta: null})).toEqual([]);
        expect(metaItems(undefined)).toEqual([]);
    });

    it('skips identifiers whose captured value is blank', () => {
        const items = metaItems({
            meta: {gclid: 'Cj0KCQ', fbclid: '', msclkid: null},
        });

        expect(items).toHaveLength(1);
        expect(items[0].key).toBe('gclid');
    });
});
