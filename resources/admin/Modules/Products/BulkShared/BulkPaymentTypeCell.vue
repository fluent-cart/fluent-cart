<script setup>
import BulkSubscriptionPopover from '@/Modules/Products/BulkInsert/BulkSubscriptionPopover.vue';

const props = defineProps({
  variant: {
    type: Object,
    default: null,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  hasError: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['change', 'payment-type-change']);

const hasOtherInfo = () => props.variant?.other_info;
</script>

<template>
  <template v-if="variant && hasOtherInfo()">
    <div class="flex items-center">
      <fct-select
        v-model="variant.other_info.payment_type"
        :options="[{label: $t('One Time'), value: 'onetime'}, {label: $t('Subscription'), value: 'subscription'}]"
        size="small"
        :class="{ 'is-field-error': hasError }"
        :disabled="disabled"
        @change="emit('payment-type-change', variant)"
      />
      <BulkSubscriptionPopover :variant="variant" :disabled="disabled" @changed="emit('change')" />
    </div>
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
