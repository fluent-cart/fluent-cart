import {afterEach, describe, expect, it} from 'vitest';
import fs from 'node:fs';
import statusLabel from '../../resources/public/customer-profile/utils/statusLabels.js';

globalThis.window = globalThis.window || {};

// Payment, transaction and subscription statuses the portal shows as a badge.
const LABELS = {
    refunded: 'Refunded',
    partially_refunded: 'Partially Refunded',
    authorized: 'Authorized',
    payment_scheduled: 'Payment Scheduled',
    dispute_lost: 'Dispute Lost',
    past_due: 'Past Due',
    paused: 'Paused',
    failing: 'Failing',
    expiring: 'Expiring',
};

afterEach(() => {
    delete window.fluentcart_customer_profile_vars;
});

describe('customer portal status labels', () => {
    it('translates every status a portal badge can show', () => {
        window.fluentcart_customer_profile_vars = {
            trans: Object.fromEntries(Object.values(LABELS).map(label => [label, 'de: ' + label])),
        };

        Object.entries(LABELS).forEach(([status, label]) => {
            expect(statusLabel(status), status).toBe('de: ' + label);
        });
    });

    it('ships each label in the portal translation map', () => {
        const map = fs.readFileSync('app/Services/Translations/customer-profile-translation.php', 'utf8');

        Object.values(LABELS).forEach(label => {
            expect(map, label).toContain(`'${label}' => __('${label}', 'fluent-cart')`);
        });
    });

    it('still names a status it does not know', () => {
        expect(statusLabel('held_for_review')).toBe('Held For Review');
    });
});
