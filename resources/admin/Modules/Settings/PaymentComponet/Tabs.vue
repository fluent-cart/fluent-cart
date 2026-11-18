<template>
  <el-tabs class="demo-tabs" v-model="settings[index]">
    <el-tab-pane v-for="(tab, index) of tabs['schema']" :label="tab.label" :name="tab.value" :key="index">
      <div>
        <Renderer :fields="tab['schema']" :settings="settings"/>
        <!-- Connect gateways show their credentials in the provider field, so the button renders there. -->
        <TestConnection v-if="!hasProvider" :mode="tab.value"/>
      </div>
    </el-tab-pane>
  </el-tabs>
</template>
<script setup>
  import {computed} from "vue";
  import Renderer from "@/Modules/Settings/PaymentComponet/Renderer.vue";
  import TestConnection from "@/Modules/Settings/PaymentComponet/TestConnection.vue";

  const props = defineProps({
    fields: Object,
    settings: Object,
    tabs: Object,
    index: [String, Number]
  })

  const hasProvider = computed(() => Object.values(props.fields || {}).some(field => field?.type === 'provider'));
</script>
