<script setup>
import FormGrid from "@/Bits/Components/Form/Components/Base/Layouts/FormGrid.vue";
import FormSection from "@/Bits/Components/Form/Components/Base/Layouts/FormSection.vue";
import {getCurrentInstance, onMounted, ref} from "vue";
import FormTab from "@/Bits/Components/Form/Components/Base/Layouts/FormTab.vue";
import FormTabPane from "@/Bits/Components/Form/Components/Base/Layouts/FormTabPane.vue";
import Helper from "@/Bits/Components/Form/utils/Helper";

const props = defineProps({
  form: {
    type: Object,
    required: true
  },
  field: {
    type: Object,
    required: true
  },
  value: {
    type: Object,
    required: true,
  },
  statePath: {
    type: String,
    required: true,
  },
  fieldKey: {
    type: String,
    required: true,
  },
  fullStatePath: {
    type: String,
    required: true,
  }
});


const selfRef = getCurrentInstance().ctx;
const callback = (callback, data) => {
  if (typeof callback === 'function') {
    return callback({
      data: props.value,
      form: props.form,
      statePath: props.statePath,
      field: props.field,
      event: (Array.isArray(data) && data.length === 1) ? data[0] : data
    });
  }
}

const isAttributeReady = ref(false);
const attribute = ref({});

onMounted(async () => {
  attribute.value = await Helper.attributesBuilders(
      props.field,
      callback,
      props.form
  );
  isAttributeReady.value = true;
})


</script>

<template>


  <template v-if="field.type === 'grid' && typeof field.schema !== 'undefined'">

    <form-grid :class="field.wrapperClass" :form="form" :field="field" :value="value"
               :statePath="fullStatePath" v-bind="attribute"/>
  </template>

  <template v-if="field.type === 'tab' && typeof field.schema !== 'undefined'">
    <form-tab :form="form" :field="field" :value="value"
              :statePath="fullStatePath" v-bind="attribute"/>
  </template>

  <template
      v-if="field.type === 'tab-pane' && typeof field.schema !== 'undefined' && typeof field.title !== 'undefined' ">
    <form-tab-pane :form="form" :field="field" :value="value"
                   :fieldKey="fieldKey" :statePath="fullStatePath" v-bind="attribute"/>
  </template>

  <template v-if="field.type === 'section'">
    <form-section
        :form="form"
        :statePath="field.disable_nesting === true? fullStatePath : fieldKey+'.'"
        :field="field"
        :field-key="fieldKey"
        :value="field.disable_nesting === true ? value: value[fieldKey]"
        :nesting="field.disable_nesting === true"
        v-bind="attribute"
    />
  </template>


</template>
