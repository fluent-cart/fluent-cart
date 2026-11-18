<template>
   <p class="setting-label">
        {{ fields[index].label }}
        <fct-tooltip v-if="fields[index].tooltip" placement="top-start">
            <template #content>
            <p v-html="fields[index].tooltip">
            </p>
            </template>
            <el-icon><InfoFilled /></el-icon>
        </fct-tooltip>
    </p>
    <fct-choice type="radio" v-model="settings[index]" :options="toOptions(fields[index].options)"/>
     <!-- Renderer for displaying fields based on selected option -->
     <br/><br/>
     <div v-for="(depandant, ind) in fields[index].dependants" :key="ind">
        <div v-if="settings[index] === depandant.value">
            <Renderer :fields="depandant.schema" :settings="settings" :index="index"/> 
        </div>
     </div>

</template>
  
  <script setup>
    import Renderer from "@/Modules/Settings/PaymentComponet/Renderer.vue";
    import {InfoFilled} from "@element-plus/icons-vue";
    defineProps({
      fields: Object,
      settings: Object,
      index: [String, Number],
    })
    // Same values v-for gave: array index or object key.
    const toOptions = (options) => Object.entries(options || {}).map(([value, label]) => ({value: Array.isArray(options) ? Number(value) : value, label}));
  </script>
  
