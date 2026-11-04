<template>
  <span :class="`badge ${badgeType} ${badgeSize} ${HighContrast}`">
    <template v-if="status || text">
      <span v-if="icon">
        <DynamicIcon :name="icon"/>
      </span>

      <template v-if="text">
        {{ text }}
      </template>
      <template v-else>
        {{
          getStatusText()
        }}
      </template>
    </template>
    <slot v-else/>
  </span>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Str from "../../utils/support/Str";
import translate, {_x} from "@/utils/translator/Translator";

// Status words several record types share, per record type: gendered languages need a
// different form (fr: commande "Terminée", abonnement "Terminé"). Literal _x() calls,
// so the string extractor can read every pair.
const KIND_LABELS = {
    order: () => ({completed: _x('Completed', 'Order status'), canceled: _x('Canceled', 'Order status'), failed: _x('Failed', 'Order status')}),
    payment: () => ({paid: _x('Paid', 'Payment status'), pending: _x('Pending', 'Payment status'), failed: _x('Failed', 'Payment status'), refunded: _x('Refunded', 'Payment status'), authorized: _x('Authorized', 'Payment status')}),
    transaction: () => ({succeeded: _x('Succeeded', 'Transaction status'), paid: _x('Paid', 'Transaction status'), pending: _x('Pending', 'Transaction status'), failed: _x('Failed', 'Transaction status'), refunded: _x('Refunded', 'Transaction status'), authorized: _x('Authorized', 'Transaction status')}),
    subscription: () => ({active: _x('Active', 'Subscription status'), pending: _x('Pending', 'Subscription status'), expired: _x('Expired', 'Subscription status'), canceled: _x('Canceled', 'Subscription status'), completed: _x('Completed', 'Subscription status')}),
    coupon: () => ({active: _x('Active', 'Coupon status'), expired: _x('Expired', 'Coupon status'), disabled: _x('Disabled', 'Coupon status')}),
    order_bump: () => ({active: _x('Active', 'Order bump status')}),
    pricing_rule: () => ({active: _x('Active', 'Pricing rule status'), inactive: _x('Inactive', 'Pricing rule status')}),
    review: () => ({pending: _x('Pending', 'Review status')}),
    log: () => ({failed: _x('Failed', 'Log status')}),
    withdrawal: () => ({succeeded: _x('Succeeded', 'Withdrawal request status'), pending: _x('Pending', 'Withdrawal request status'), failed: _x('Failed', 'Withdrawal request status')}),
};

export default {
  name: "Badge",
  components: {
    DynamicIcon
  },
  props: {
    status: String,
    text: {
      type: String,
      default: null,
      required: false
    },
    hideIcon: {
      type: Boolean,
      default: false
    },
    size: String,
    highContrast: {
      type: Boolean,
      default: false
    },
    type: String,
    icon: String,
    // Record type of the status (order, payment, subscription …), see KIND_LABELS
    kind: String
  },
  computed: {
    Str() {
      return Str
    },
    computedStatus() {
      return this.status || this.type;
    },
    badgeType() {
      switch (this.computedStatus) {
        case 'completed':
        case 'paid':
        case 'active':
        case 'publish':
        case 'shipped':
        case 'delivered':
        case 'success':
        case 'licensed':
        case 'succeeded':
        case 'approved':
          return 'success';

        case 'failed':
        case 'returned':
        case 'error':
        case 'canceled':
        case 'expired':
        case 'trash':
          return 'danger';

        case 'spam':
          return 'warning';

        case 'blue':
        case 'partially_paid':
        case 'partially_shipped':
        case 'in_transit':
        case 'intended':
          return 'blue';

        // Terminal "this order will never ship" — a dark neutral, so it reads
        // as settled rather than as the light grey of a pending `unshipped`.
        case 'unshippable':
          return 'primary';

        case 'unshipped':
        case 'dispatched':
          return 'info';

        case 'scheduled':
        case 'on-hold':
        case 'pending':
        case 'unpaid':
        case 'warning':
        case 'processing':
        case 'future':
          return 'warning';
        case 'inactive':
          return 'warning';
        case 'dispute':
          return 'warning';
        default:
          return 'info';
      }
    },
    badgeSize() {
      return this.size ? this.size : '';
    },
    HighContrast() {
      return this.highContrast ? 'is-high-contrast' : '';
    },
  },
  methods: {
    getStatusText() {
      const kindLabel = KIND_LABELS[this.kind] && KIND_LABELS[this.kind]()[this.computedStatus];
      if (kindLabel) {
        return kindLabel;
      }

      switch (this.computedStatus) {
        case 'completed':
          return translate('Completed');
        case 'paid':
          return translate('Paid');
        case 'active':
          return translate('Active');
        case 'publish':
          return translate('Published');
        case 'draft':
          return translate('Draft');
        case 'unshipped':
          return translate('Unshipped');
        case 'partially_shipped':
          return translate('Partially Shipped');
        case 'shipped':
          return translate('Shipped');
        case 'delivered':
          return translate('Delivered');
        case 'dispatched':
          return translate('Dispatched');
        case 'in_transit':
          return translate('In Transit');
        case 'returned':
          return translate('Returned');
        case 'unshippable':
          return translate('Unshippable');
        case 'success':
          return translate('Success');
        case 'licensed':
          return translate('Licensed');
        case 'succeeded':
          return translate('Succeeded');
        case 'failed':
          return translate('Failed');
        case 'error':
          return translate('Error');
        case 'canceled':
          return translate('Canceled');
        case 'expired':
          return translate('Expired');
        case 'partially_paid':
          return translate('Partially Paid');
        case 'intended':
          return translate('Intended');
        case 'scheduled':
          return translate('Scheduled');
        case 'on-hold':
          return translate('On Hold');
        case 'pending':
          return translate('Pending');
        case 'unpaid':
          return translate('Unpaid');
        case 'warning':
          return translate('Warning');
        case 'processing':
          return translate('Processing');
        case 'future':
          return translate('Future');
        case 'inactive':
          return translate('Inactive');
        case 'dispute':
          return translate('Dispute');
        case 'disabled':
          return translate('Disabled');
        case 'beta':
          return translate('Beta');
        default:
          return Str.headline(this.computedStatus);
      }
    }
  }
};
</script>
