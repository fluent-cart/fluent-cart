<template>
  <el-select
      v-model="modelValue"
      :placeholder="$t('Select Currency')"
      filterable
      clearable
      @change="onChange"
      popper-class="fct-dropdown-compact"
      size="small"
  >
    <template #label="{ label }">
      <el-tag disable-transitions>{{ label }}</el-tag>
    </template>
    <el-option
        v-for="(currency, index) in currencies"
        :key="index"
        :label="currency.label"
        :value="currency.value"
    />
  </el-select>
</template>

<script setup>
import {computed} from "vue";

const props = defineProps({
  modelValue: String,
  currencies: {
    type: Array,
    required: true
  }
})

const emit = defineEmits(['update:modelValue', 'change', 'clear']);

const modelValue = computed({
  get: () => props.modelValue,
  // Element Plus clears a single select to undefined; the report model and
  // its query string expect '' for "no currency chosen".
  set: (val) => emit('update:modelValue', val || '')
});

// Element Plus fires change (with the empty value) and then clear for one
// click; routing clear off change keeps the report to a single refresh.
const onChange = (value) => {
  if (value) {
    emit('change', value);
  } else {
    emit('clear');
  }
}
</script>
