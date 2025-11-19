<script setup>

import {defineModel, onMounted, ref} from 'vue';


const model = defineModel();

const props = defineProps({
  field: {
    type: Object
  },
  statePath: {
    type: String
  },
  value: {
    required: true
  },
  form: {
    type: Object
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

const optionsBuilder = async (search = '') => {

  if (typeof props.field.options === 'function') {
    return options.value = await props.field.options({
      data: props.value, form: props.form, statePath: props.statePath, search
    });
  }

  if (Array.isArray(props.field.options)) {
    return options.value = [...props.field.options];
  }
}


onMounted(async () => {
  await optionsBuilder()
});

</script>

<template>

  <el-radio-group v-model="model" v-bind="attribute">
    <el-radio v-for="(option, index) in field.options" :key="index" :value="option.value" :class="option.option_class">
      <div>
        {{ option.label }}
      </div>
      <div v-html="option.note"></div>
    </el-radio>
  </el-radio-group>

</template>

<style scoped>

</style>