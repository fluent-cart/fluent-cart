<script setup>
import FormRenderer from "@/Bits/Components/Form/Renderer/FormRenderer.vue";

const props = defineProps({
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

  <div class="gap-2 grid grid-cols-dynamic sm:grid-cols-sm-dynamic md:grid-cols-md-dynamic lg:grid-cols-lg--dynamic" :style="{
  '--grid-columns': field.columns?.default??2,
  '--sm-grid-columns': field.columns?.sm?? field.columns?.default??2,
  '--md-grid-columns': field.columns?.sm?? field.columns?.md?? field.columns?.default??2,
  '--lg-grid-columns': field.columns?.lg??field.columns?.md?? field.columns?.sm?? field.columns?.default??2
  }">

    <FormRenderer
        :form="form"
        :schema="field.schema"
        :value="value"
        :statePath="statePath"
    />
  </div>

</template>