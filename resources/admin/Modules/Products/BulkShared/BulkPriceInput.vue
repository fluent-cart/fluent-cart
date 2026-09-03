<script setup>
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";
import {formatNumber} from "@/Bits/productService";

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
    <PriceInput
      size="small"
      :error-class="hasError ? 'is-error' : ''"
      :model-value="variant.item_price"
      :placeholder="$t('Price')"
      :disabled="disabled"
      @update:model-value="variant.item_price = $event; emit('change')"
    >
      <template v-if="variant.other_info?.manage_setup_fee === 'yes' && variant.other_info.signup_fee" #suffix>
        <el-tooltip
          :content="(variant.other_info.signup_fee_name || $t('Setup fee')) + ': ' + formatNumber(variant.other_info.signup_fee, true)"
          placement="top"
          popper-class="fct-tooltip"
        >
          <span class="bulk-setup-fee-badge">+ {{ $t('fee') }}</span>
        </el-tooltip>
      </template>
    </PriceInput>
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
