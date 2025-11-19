<script setup>
import {defineModel, onMounted, ref, getCurrentInstance, computed, onUpdated} from "vue";
import Resolver from "@/Bits/Components/Form/Components/Resolver.vue";
import AppConfig from "@/utils/Config/AppConfig";

const selfRef = getCurrentInstance().ctx;

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

const prefix = ref();

const buildPrefix = async () => {
  if (typeof props.field.prefix === "function") {
    prefix.value = await props.callback(props.field.prefix)
  } else {
    prefix.value = props.field.prefix;
  }
}

onMounted(() => {
  buildPrefix();
})

onUpdated(() => {
  buildPrefix();
})

const computedPrefix = computed(() => {
  if (props.fieldKey === 'amount') {
    return props.form.data.values['type'] === 'fixed' ?
        AppConfig.shop.get('currency_sign') : '%';
  }
  return selfRef.appVars.shop.currency_sign;
});
</script>
<template>
  <el-input v-model="model" v-bind="attribute" :disabled="field.disabled">
    <template #prefix v-if="field.prefix?.component">
      <Resolver :form="form" :name="field.prefix.component" :field="field" :value="value"/>
    </template>
    <template v-else-if="field.prefix" #prefix>
      <div v-html="prefix"></div>
    </template>
  </el-input>
</template>

<style scoped>

</style>