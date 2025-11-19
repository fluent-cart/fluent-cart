<template>
  <div class="fct-number-input">
    <input :disabled="isDisabled" v-model.number="inputValue" type="number"
           @input="value => {handleChange('inputValue', value)}" :min="min" :max="max" @keydown="handleKeydown">
    <div class="fct-number-input-buttons">
      <button @click="increment" class="increment-btn" :disabled="isMaxQuantity || isDisabled">
        <DynamicIcon name="ChevronUp"/>
      </button>
      <button @click="decrement" class="decrement-btn" :disabled="isMinQuantity || isDisabled">
        <DynamicIcon name="ChevronDown"/>
      </button>
    </div>
  </div>
  <span style="color: red;">{{ error }}</span>
</template>

<script setup>
import {
  ref,
  defineProps,
  defineEmits,
  computed,
  watch,
  getCurrentInstance,
  onMounted,
  onBeforeMount,
  onBeforeUpdate
} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";

const emit = defineEmits();

const props = defineProps({
  min: {
    type: Number,
    default: 1,
  },
  max: {
    type: Number,
    default: 999999,
  },
  item: {
    type: Object,
    default: () => ({}),
  },
  isDisabled: {
    type: Boolean,
    default: false,
  },
});

const error = ref('');
const shouldManageStock = () => {
  return (props.item?.manage_stock || props.item?.variants?.product_detail?.manage_stock) == 1;
};

const currentQuantity = ref(props.item?.quantity || props.min);
const inputValue = ref(currentQuantity.value);
const stock = ref(shouldManageStock() ? (props.item?.available || props.item?.variants?.available) : props.max);
const updatedStock = ref(shouldManageStock() ? props.item?.updated_stock : props.max);
const selfRef = getCurrentInstance().ctx;

const isMinQuantity = computed(() => {
  return inputValue.value <= props.min;
});

const isMaxQuantity = computed(() => {
  return inputValue.value >= updatedStock.value;
});

const increment = () => {
  error.value = '';
  if (inputValue.value < updatedStock.value) {
    // If true, increment currentQuantity by 1
    currentQuantity.value++;
    inputValue.value = currentQuantity.value;
    emit('update:value', currentQuantity.value, updatedStock.value, props.item);
  }
}

const decrement = () => {
  error.value = '';
  if (inputValue.value > props.min) {
    // If true, decrement currentQuantity by 1
    currentQuantity.value--;
    inputValue.value = currentQuantity.value;
    emit('update:value', currentQuantity.value, updatedStock.value, props.item);
  }
}

const handleChange = (name, value) => {
  error.value = '';
  inputValue.value = parseInt(inputValue.value);

  if (isNaN(inputValue.value) || inputValue.value === '') {
    currentQuantity.value = 1; // Update currentQuantity
  }
  if (inputValue.value < props.min) {
    currentQuantity.value = props.min; // set min value as currentQuantity
    inputValue.value = currentQuantity.value;
  } else if (inputValue.value > props.max) {
    currentQuantity.value = props.min; // set min value as currentQuantity
    inputValue.value = currentQuantity.value;
    error.value = translate('Unable to adjust quantity');
  } else if (inputValue.value > updatedStock.value) {
    currentQuantity.value = updatedStock.value; // Update currentQuantity
    inputValue.value = currentQuantity.value;
    /* translators: %s is the available stock */
    error.value = translate('Available: %s in stock', updatedStock.value);
  } else if (inputValue.value >= props.min && (inputValue.value === updatedStock.value || inputValue.value < updatedStock.value)) {
    currentQuantity.value = inputValue.value; // Update currentQuantity
    inputValue.value = currentQuantity.value;
  }

  emit('update:value', currentQuantity.value, updatedStock.value, props.item);
}

const handleKeydown = (event) => {
  if (event.key === 'ArrowDown' && inputValue.value <= props.min) {
    event.preventDefault();
  }
};

const resetState = () => {
  error.value = '';
  currentQuantity.value = props.item?.quantity || props.min;
  inputValue.value = currentQuantity.value;
  stock.value = shouldManageStock() ? (props.item?.available || props.item?.variants?.available) : props.max;
  updatedStock.value = shouldManageStock() ? props.item?.updated_stock : props.max;
};

onBeforeMount(() => {
  resetState();
});

watch(() => props.item, (newItem) => {
  resetState();
}, {deep: true});

// onMounted(() => {
//   if(stock.value === 0) {
//     stock.value = props.item?.quantity;
//   }
// });

// watch(() => props.item, (newItem) => {
//   updatedStock.value = shouldManageStock() ? newItem?.updated_stock : props.max;
//   const updatedQuantity = newItem?.quantity || props.min;
//   currentQuantity.value = updatedStock.value > 0 ? updatedQuantity : currentQuantity.value;
// }, { deep: true });

// Watch for changes in inputValue
/*watch(inputValue, (newValue) => {
  console.log(updatedStock.value)
  // If newValue is an empty string or 0
  if (newValue === '' || newValue <= 0) {
    // If newValue is empty, set currentQuantity to props.min
    currentQuantity.value = props.min;

    updatedStock.value = updatedStock.value - props.min;
    
    // Emit an update:value event with the new currentQuantity value
    emit('update:value', currentQuantity.value, updatedStock.value, props.item);

    // Showing error
    selfRef.handleError('Quantity must be greater than 0 or equal to 1');

  } else {
    // Parse newValue into an integer
    const parsedValue = parseInt(newValue, 10);

    // Check if parsedValue is a valid number within the allowed range
    if (!isNaN(parsedValue) && parsedValue >= props.min && parsedValue <= props.max) {
      // Update currentQuantity with parsedValue
      currentQuantity.value = parsedValue;

      updatedStock.value = updatedStock.value - parsedValue;

      // Emit an update:value event with the new values
      emit('update:value', newValue, updatedStock.value, props.item);
    }
  }
  console.log(updatedStock.value)
});*/
</script>

