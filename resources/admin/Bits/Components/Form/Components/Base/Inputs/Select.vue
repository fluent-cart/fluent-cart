<script setup>

import {defineModel, onMounted, ref} from 'vue';

const model = defineModel();

const props = defineProps({
  name: {
    type: String,
    required: true
  },

  field: {
    type: Object
  },

  fieldKey: {
    type: String
  },
  value: {
    required: true
  },
  variant: {
    type: String
  },
  nesting: {
    type: Boolean,
    default: false
  },
  statePath: {
    type: String
  },
  form: {
    type: Object,
    required: true
  },
  callback: {
    type: Function,
    required: true
  },
  label: {
    type: String
  },
  attribute: {
    required: true
  }
})


const options = ref([]);
const loading = ref(false);
const optionsBuilder = async (search = '') => {

  loading.value = true;

  if (typeof props.field.options === 'function') {
    options.value = await props.field.options({
      data: props.value, form: props.form, statePath: props.statePath, search
    });
  }

  if (Array.isArray(props.field.options)) {
    options.value = [...props.field.options];
  }
  loading.value = false;
}


onMounted(async () => {
  await optionsBuilder()
});

const isOptionDisabled = (option) => {
  if (typeof option.disabled === 'function') {
    return props.callback(option.disabled, {});
  }
  return option.disabled;
}

</script>

<template>
  <el-select
      v-model="model"
      v-bind="attribute"
      :remote-method="optionsBuilder"
      :loading="loading"
      :filterable="field.filterable === true"
      :clearable="field.clearable === true"
      :disabled="field.disabled"
  >
    <el-option
        v-for="(option, index) in options" :key="index"
        :value="option.value"
        :label="option.label"
        :disabled="isOptionDisabled(option)">
      <span v-if="option.slot">{{ option.slot }}</span>
    </el-option>
  </el-select>
</template>

<style scoped>

</style>
