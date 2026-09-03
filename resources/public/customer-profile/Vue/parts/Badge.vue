<template>
  <span :class="`fct-badge ${badgeType} ${badgeSize} ${HighContrast}`">
    <template v-if="computedStatus || text">
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
import statusLabel from "../../utils/statusLabels";

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
          return 'fct-success';

        case 'failed':
        case 'error':
        case 'canceled':
        case 'expired':
          return 'fct-danger';

        case 'partially_paid':
        case 'intended':
          return 'fct-blue';

        case 'scheduled':
        case 'on-hold':
        case 'pending':
        case 'unpaid':
        case 'warning':
        case 'processing':
        case 'future':
          return 'fct-warning';
        case 'inactive':
          return 'fct-warning';
        case 'dispute':
          return 'fct-warning';
        default:
          return this.computedStatus ? `fct-${this.computedStatus}` : 'fct-info';
      }
    },
    badgeSize() {
      return this.size ? `fct-${this.size}` : '';
    },
    HighContrast() {
      return this.highContrast ? 'fct-is-high-contrast' : '';
    },
  },
  methods: {
    getStatusText() {
      return statusLabel(this.computedStatus);
    }
  }
};
</script>
