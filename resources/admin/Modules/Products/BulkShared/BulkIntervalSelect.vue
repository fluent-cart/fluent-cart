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
    <fct-select
      v-model="variant.other_info.repeat_interval"
      :options="[
        {label: $t('Yearly'), value: 'yearly'},
        {label: $t('Half Yearly'), value: 'half_yearly'},
        {label: $t('Quarterly'), value: 'quarterly'},
        {label: $t('Monthly'), value: 'monthly'},
        {label: $t('Weekly'), value: 'weekly'},
        {label: $t('Daily'), value: 'daily'},
      ]"
      size="small"
      :class="{ 'is-field-error': hasError }"
      :disabled="disabled"
      @change="emit('change')"
    />
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
