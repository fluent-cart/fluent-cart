import translate from '../translator/Translator';
import Str from '@/utils/support/Str';

/**
 * Single source for badge/status/type labels across the customer portal.
 * Keys are the raw machine values the API sends (order.status,
 * transaction.order_type, subscription.status, ...). Every label here must
 * also exist as a key in app/Services/Translations/customer-profile-translation.php,
 * otherwise translate() falls back to English.
 */
const LABELS = {
    completed: 'Completed',
    paid: 'Paid',
    active: 'Active',
    publish: 'Published',
    draft: 'Draft',
    shipped: 'Shipped',
    success: 'Success',
    licensed: 'Licensed',
    succeeded: 'Succeeded',
    failed: 'Failed',
    error: 'Error',
    canceled: 'Canceled',
    expired: 'Expired',
    partially_paid: 'Partially Paid',
    intended: 'Intended',
    scheduled: 'Scheduled',
    'on-hold': 'On Hold',
    pending: 'Pending',
    unpaid: 'Unpaid',
    warning: 'Warning',
    processing: 'Processing',
    future: 'Future',
    inactive: 'Inactive',
    dispute: 'Dispute',
    disabled: 'Disabled',
    beta: 'Beta',
    subscription: 'Subscription',
    renewal: 'Renewal',
    payment: 'Payment',
    unshipped: 'Unshipped',
    trialing: 'Trialing',
};

export default function statusLabel(status) {
    const label = LABELS[status];
    return label ? translate(label) : Str.headline(status);
}
