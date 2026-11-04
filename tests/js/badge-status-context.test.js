import {describe, expect, it, vi} from 'vitest';

vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
// Shows which lookup ran: the contextual one carries its context.
vi.mock('@/utils/translator/Translator', () => ({
    default: text => `plain|${text}`,
    _x: (text, context) => `${context}|${text}`,
}));

import Badge from '@/Bits/Components/Badge.vue';

const label = (status, kind) => Badge.methods.getStatusText.call({computedStatus: status, kind});

describe('Badge status label by record type', () => {
    it('gives a shared status word the context of its record type', () => {
        expect(label('completed', 'order')).toBe('Order status|Completed');
        expect(label('completed', 'subscription')).toBe('Subscription status|Completed');
        expect(label('paid', 'payment')).toBe('Payment status|Paid');
        expect(label('paid', 'transaction')).toBe('Transaction status|Paid');
        // The canonical successful transaction, also shown for an approved withdrawal request
        expect(label('succeeded', 'transaction')).toBe('Transaction status|Succeeded');
        expect(label('succeeded', 'withdrawal')).toBe('Withdrawal request status|Succeeded');
    });

    it('keeps the plain label without a kind, for a status only one type uses, or for an unknown kind', () => {
        expect(label('completed')).toBe('plain|Completed');
        expect(label('processing', 'order')).toBe('plain|Processing');
        expect(label('completed', 'nonsense')).toBe('plain|Completed');
    });
});
