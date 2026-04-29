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
      v-model="variant.compare_price"
      :placeholder="$t('Compare price')"
      :disabled="disabled"
      @input="emit('change')"
    />
  </template>
  <span v-else class="text-gray-300 text-sm flex justify-center">&mdash;</span>
</template>
