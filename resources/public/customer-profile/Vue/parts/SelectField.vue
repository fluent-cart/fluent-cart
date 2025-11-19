<script setup>
import {ref, watch} from 'vue';

const props = defineProps({
  id: String,
  options: {
    type: Array,
    required: true,
    default: () => [],
  },
  modelValue: {
    type: [String, Number],
    default: null,
  },
  placeholder: {
    type: String,
    default: 'Please select an option',
  }
});

// Define emits using defineEmits
const emit = defineEmits(['update:modelValue']);

// Reactive selected value, initialized with modelValue prop
const selectedValue = ref(props.modelValue);

// Watch for changes in modelValue prop and update selectedValue
watch(() => props.modelValue, (newValue) => {
  selectedValue.value = newValue;
});

// Emit event to update modelValue when the selected value changes
const onChange = () => {
  emit('update:modelValue', selectedValue.value);
};
</script>

<template>
  <div class="form-input-group" :id="id">
    <select v-model="selectedValue" @change="onChange">
      <option disabled value="">{{ placeholder }}</option>
      <option v-for="(option, index) in options" :key="index" :value="option.value">
        {{ option.label }}
      </option>
    </select>
  </div>
</template>
