<script setup>

import {onMounted, ref} from "vue";

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false
  },
  required: {
    type: Boolean,
    default: false
  },
  type: {
    type: String,
    default: 'text'
  }
});
const model = defineModel();
const label = ref(props.label);
const isFocus = ref(false);

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
  <div class="fct-material-input">
    <span v-if="false" class="fct-material-input-label">
      {{ label }} <span v-if="required" class="required-mark">*</span>
    </span>
    <el-input
        :type="type"
        :disabled="disabled"
        :required="required"
        v-model="model"
        @focus="handleInputFocus"
        @blur="handleInputBlur"
        :placeholder="label+(required ? ' *' : '')"
    />
  </div>
</template>


