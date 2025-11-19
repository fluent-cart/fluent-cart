<script setup>
import {getCurrentInstance, nextTick, onMounted, ref, watch} from "vue";
import FormRenderer from "@/Bits/Components/Form/Renderer/FormRenderer.vue";
import translate from "@/utils/translator/Translator";


const props = defineProps({
  form: {
    type: Object,
    required: true
  },
  schema: {
    type: Object
  },
  value: {
    type: Object
  },
  showSubmitButton: {
    type: Boolean,
    default: false
  },
  submitButtonText: {
    type: String,
    default: 'Save'
  },
  loading: {
    type: Boolean,
    default: false
  }
})
const emit = defineEmits(['onSubmit', 'onChange', 'onSubmitButtonClick']);
let called = false;


onMounted(() => {
  nextTick(() => {
    selfRef.registerCopyAction()
  })
})

const data = ref({});
const selfRef = getCurrentInstance().ctx;


const onSubmit = () => {
  return props.form.values;
}
const onSubmitBtnClick = () => {
  emit('onSubmitButtonClick');
}
const getState = () => {
  return props.form.values;
}

defineExpose({
  getState
})

</script>

<template>

  <div v-if="form.isReady" class="vue-form-wrap">

    <FormRenderer :form="form" ref="formRenderer" :schema="form.schema"
                  :value="form.values"
    />

    <div v-if="showSubmitButton" class="form-section-save-action">
      <el-button @click="onSubmitBtnClick" type="primary" :loading="loading">
        <span v-if="!loading" class="cmd block leading-none">⌘s</span>
        {{ loading ? translate('Saving') : submitButtonText }}
      </el-button>
    </div>
  </div>

</template>
