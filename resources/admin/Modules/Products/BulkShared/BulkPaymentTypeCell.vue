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
      <el-select
        v-model="variant.other_info.payment_type"
        size="small"
        :class="{ 'is-field-error': hasError }"
        :disabled="disabled"
        @change="emit('payment-type-change', variant)"
      >
        <el-option :label="$t('One Time')" value="onetime" />
        <el-option :label="$t('Subscription')" value="subscription" />
      </el-select>
      <BulkSubscriptionPopover :variant="variant" :disabled="disabled" @changed="emit('change')" />
    </div>
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
