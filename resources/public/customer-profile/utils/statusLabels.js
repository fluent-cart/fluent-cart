import translate from '../translator/Translator';
import Str from '@/utils/support/Str';

/**
 * Single source for badge/status/type labels across the customer portal.
 * Keys are the raw machine values the API sends (order.status,
 * transaction.order_type, subscription.status, ...). Each label is a literal
 * translate() call so `npm run translate:all` extracts it into
 * app/Services/Translations/customer-profile-translation.php.
 */
const getLabels = () => ({
    completed: translate('Completed'),
    paid: translate('Paid'),
    active: translate('Active'),
    publish: translate('Published'),
    draft: translate('Draft'),
    shipped: translate('Shipped'),
    partially_shipped: translate('Partially Shipped'),
    delivered: translate('Delivered'),
    unshippable: translate('Unshippable'),
    success: translate('Success'),
    licensed: translate('Licensed'),
    succeeded: translate('Succeeded'),
    failed: translate('Failed'),
    error: translate('Error'),
    canceled: translate('Canceled'),
    expired: translate('Expired'),
    partially_paid: translate('Partially Paid'),
    intended: translate('Intended'),
    scheduled: translate('Scheduled'),
    'on-hold': translate('On Hold'),
    pending: translate('Pending'),
    unpaid: translate('Unpaid'),
    warning: translate('Warning'),
    processing: translate('Processing'),
    future: translate('Future'),
    inactive: translate('Inactive'),
    dispute: translate('Dispute'),
    disabled: translate('Disabled'),
    beta: translate('Beta'),
    subscription: translate('Subscription'),
    renewal: translate('Renewal'),
    payment: translate('Payment'),
    unshipped: translate('Unshipped'),
    trialing: translate('Trialing'),
});

export default function statusLabel(status) {
    const label = getLabels()[status];
    return label || Str.headline(status);
}
