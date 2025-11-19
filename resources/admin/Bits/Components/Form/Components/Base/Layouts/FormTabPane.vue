<script setup>
import FormRenderer from "@/Bits/Components/Form/Renderer/FormRenderer.vue";


const props = defineProps({
  fieldKey: {
    type: String
  },
  field: {
    type: Object
  },
  value: {
    type: Object
  },
  statePath: {
    type: String
  },
  form:{
    type:Object,
    required: true
  },

})

</script>

<template>
    <el-tab-pane :label="field.title" :name="fieldKey">
      <FormRenderer :form="form" :schema="field.schema" :value="value" :statePath="statePath"/>
    </el-tab-pane>
</template>