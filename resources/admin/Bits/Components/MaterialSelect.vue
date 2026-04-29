<script setup>

import {defineEmits, onMounted, ref} from "vue";

const props = defineProps({
  label: {
    type: String,
    required: true
  },
  options: Array,
  clearable: Boolean,
  filterable: Boolean,
  required: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['onChange']);

const model = defineModel();
const label = ref(props.label);
const isFocus = ref(false);

const handleChange = (e) => {
  emit('onChange', e);
}

const handleInputFocus = () => {
  isFocus.value = true;
}
const handleInputBlur = () => {
  isFocus.value = true;
  if (!model.value) {
    isFocus.value = false;
  }
}

onMounted(() => {
  if (model.value) {
    isFocus.value = true;
  }
})
</script>

<template>
  <div class="fct-material-select">
    <span v-if="false" class="fct-material-select-label" :class="{'is-focus': isFocus || model}">
      {{ label }} <span v-if="required" class="required-mark">*</span>
    </span>
    <el-select
        :required="required"
        v-model="model"
        :filterable="filterable"
        @change="handleChange"
        :placeholder="label + (required ? ' *' : '')"
        :clearable="clearable"
        @focus="handleInputFocus"
        @blur="handleInputBlur"
        @clear="() => {
          model = '';
        }">
      <el-option
          v-for="country in options"
          :key="country.value"
          :label="country.name"
          :value="country.value"
      />
    </el-select>
  </div>
</template>

