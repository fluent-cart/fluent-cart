<template>
  <button
      class="fct-applied-filter-remove"
      @click="handleRemove"
      :title="$t('Remove')"
  >
    <DynamicIcon name="Close" />
  </button>
</template>

<script setup>
import { defineEmits } from 'vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

const props = defineProps({
  filterKey: {
    type: String,
    required: true
  },
  itemId: {
    type: [String, Number],
    default: null
  }
});

const emit = defineEmits(['remove']);

const handleRemove = () => {
  emit('remove', {
    filterKey: props.filterKey,
    itemId: props.itemId
  });
};
</script>
