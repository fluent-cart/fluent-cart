<template>
  <span :class="`badge ${badgeType} ${badgeSize} ${HighContrast}`">
    <template v-if="status">
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
import Str from "@/utils/support/Str";
import translate from "../../translator/Translator";

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
    icon: String
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
        case 'success':
        case 'licensed':
        case 'succeeded':
          return 'success';

        case 'failed':
        case 'error':
        case 'canceled':
        case 'expired':
          return 'danger';

        case 'partially_paid':
        case 'intended':
          return 'blue';

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
        case 'shipped':
          return translate('Shipped');
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
