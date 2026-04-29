<script setup>
defineProps({
  value: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: '',
  },
});

defineEmits(['click']);
</script>

<template>
  <div class="fct-bulk-editor" @click="$emit('click')">
    <span v-if="value" class="bulk-editor-preview" v-text="value"></span>
    <span v-else class="fct-bulk-editor-placeholder">{{ placeholder }}</span>
  </div>
</template>
