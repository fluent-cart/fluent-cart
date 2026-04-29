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
    <el-select
      v-model="variant.other_info.repeat_interval"
      size="small"
      :class="{ 'is-field-error': hasError }"
      :disabled="disabled"
      @change="emit('change')"
    >
      <el-option :label="$t('Yearly')" value="yearly" />
      <el-option :label="$t('Half Yearly')" value="half_yearly" />
      <el-option :label="$t('Quarterly')" value="quarterly" />
      <el-option :label="$t('Monthly')" value="monthly" />
      <el-option :label="$t('Weekly')" value="weekly" />
      <el-option :label="$t('Daily')" value="daily" />
    </el-select>
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
