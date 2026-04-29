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
</script>

<template>
  <template v-if="variant">
    <el-input
        size="small"
      :class="{ 'is-error': hasError }"
      v-model="variant.item_price"
      :placeholder="$t('Price')"
      type="number"
      :disabled="disabled"
      @input="emit('change')"
    >
      <template v-if="variant.other_info?.manage_setup_fee === 'yes' && variant.other_info.signup_fee" #suffix>
        <el-tooltip
          :content="(variant.other_info.signup_fee_name || $t('Setup fee')) + ': ' + currencySign() + variant.other_info.signup_fee"
          placement="top"
          popper-class="fct-tooltip"
        >
          <span class="bulk-setup-fee-badge">+ {{ $t('fee') }}</span>
        </el-tooltip>
      </template>
    </el-input>
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
