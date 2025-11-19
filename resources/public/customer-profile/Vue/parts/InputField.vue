<script setup>
import {getCurrentInstance, ref, onMounted, watch} from "vue";

const $this = getCurrentInstance().ctx;
const activeInput = ref(false);
const inputVal = ref();

const props = defineProps({
  id: String,
  label: String,
  type: {
    type: String,
    default: 'text'
  },
  imageUrl: {
    type: String,
    default: ''
  },
  modelValue: {
    type: [String, Number],
    default: null,
  },
});

// Define emits using defineEmits
const emit = defineEmits(['update:modelValue', 'change', 'input']);

// Reactive selected value, initialized with modelValue prop
const selectedValue = ref(props.modelValue);

// Function to set active input on focus
const setActivate = (inputValue) => {
  activeInput.value = inputValue;
};

// Function to set deactivated state on blur
const setDeactivate = (inputValue) => {
  // Check if input has a value
  if ($this.$refs.input.value) {
    // Keep active if there is a value
    activeInput.value = true;
  } else {
    // Deactivate if no value
    activeInput.value = inputValue;
  }
};

// Emit event to update modelValue when the selected value changes
const onChange = () => {
  // Update v-model
  emit('update:modelValue', selectedValue.value);

  // Emit custom change event
  emit('change', selectedValue.value);
};

// Function to emit the input value when typing
const onInput = () => {
  // update v-model
  emit('update:modelValue', selectedValue.value);

  // Emit custom input-change event
  emit('input', selectedValue.value);
};

// Watch for changes in modelValue prop and update selectedValue
watch(() => props.modelValue, (newValue) => {
  selectedValue.value = newValue;
});

// Set initial state based on selectedValue when component mounts
onMounted(() => {
  // Set to true if selectedValue has a value
  activeInput.value = !!selectedValue.value;
});

// Set initial state based on selectedValue when component mounts
onMounted(() => {
// Set to true if selectedValue has a value
  activeInput.value = !!selectedValue.value;
});

</script>

<template>
  <div class="form-input-group" :id="id" :class="{'is-focused': activeInput}" role="group">
    <label v-if="label" :for="id">{{ label }}</label>
    <input
        :id="id"
        :type="type"
        ref="input"
        v-model="selectedValue"
        @focus="setActivate(true)"
        @blur="setDeactivate(false)"
        @change="onChange"
        @input="onInput"
        :aria-describedby="imageUrl ? `${id}-icon` : null"
        :aria-required="required || false"
    >
    <img v-if="imageUrl" :src="imageUrl" alt="" :id="`${id}-icon`"
      aria-hidden="true">
  </div>
</template>

