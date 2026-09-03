<script setup>


import * as VueInstance from 'vue';
import {defineComponent} from 'vue';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Animation from "@/Bits/Components/Animation.vue";
import StepIndicator from "@/Bits/Components/StepIndicator.vue";
import LoadingButton from "@/Bits/Components/Buttons/LoadingButton.vue";
import {parseStringComponent} from "@/utils/DynamicTemplateParser";

const props = defineProps({
  widget: {
    type: Object,
    required: true
  },
  data: {
    required: false
  }
});

const SafeVue = VueInstance.readonly(VueInstance);
const SafeRest = Object.freeze({
  get: (...args) => Rest.get(...args),
  post: (...args) => Rest.post(...args),
  put: (...args) => Rest.put(...args),
  delete: (...args) => Rest.delete(...args),
  patch: (...args) => Rest.patch(...args),
  upload: (...args) => Rest.upload(...args),
  getNonce: () => Rest.getNonce()
});
const SafeNotify = Object.freeze({
  success: (...args) => Notify.success(...args),
  error: (...args) => Notify.error(...args),
  info: (...args) => Notify.info(...args),
  validationErrors: (...args) => Notify.validationErrors(...args)
});

// Everything an add-on template is allowed to reach. Anything not listed here
// is shadowed to `undefined` inside the template — see the blocklist and the
// factory construction in utils/DynamicTemplateParser.js.
const services = {
  Vue: SafeVue,
  Rest: SafeRest,
  Notify: SafeNotify,
  translate,
  Animation,
  StepIndicator,
  LoadingButton,
  ElMessage: window?.ELEMENT?.ElMessage,
  ElMessageBox: window?.ELEMENT?.ElMessageBox
};

const getDynamicComponent = (component) => {
  // If it's a string, parse it first
  if (typeof component === 'string') {
    return parseStringComponent(component, services).component;
  }

  // If it's already an object, use it directly
  return defineComponent(component);
};


</script>

<template>
  <component
      ref="componentRefs"
      :is="getDynamicComponent(widget.component)"
      :data="data"
      v-bind="{
        ...widget
      }"
  />
</template>

<style scoped>

</style>
