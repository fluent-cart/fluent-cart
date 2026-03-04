<script setup>
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

const emit = defineEmits(['change']);

const isSubscription = () => props.variant?.other_info?.payment_type === 'subscription';
</script>

<template>
  <template v-if="variant && isSubscription()">
    <el-input
        class="fct-bulk-input"
        :class="{ 'is-error': hasError }"
        v-model="variant.other_info.trial_days"
        placeholder="0"
        :disabled="disabled"
        @change="emit('change')"
        size="small"
    />
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
