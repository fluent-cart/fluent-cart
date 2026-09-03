<script setup>
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";

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
      :model-value="variant.compare_price"
      :placeholder="$t('Compare price')"
      :disabled="disabled"
      @update:model-value="variant.compare_price = $event; emit('change')"
    />
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
