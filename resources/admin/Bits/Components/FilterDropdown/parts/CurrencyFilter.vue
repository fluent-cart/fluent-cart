<template>
  <el-select
      v-model="modelValue"
      :placeholder="$t('Select Currency')"
      filterable
      @change="onChange"
      popper-class="fct-dropdown-compact"
      size="small"
  >
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

const emit = defineEmits(['update:modelValue', 'change']);

const modelValue = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
});

const onChange = (value) => {
  emit('change', value)
}
</script>
