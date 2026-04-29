<template>
    <el-tabs class="demo-tabs" v-model="settings[index]">
      <el-tab-pane v-for="(tab, index) of tabs['schema']" :label="tab.label" :name="tab.value" :key="index">
        <div>
          <Renderer :fields="tab['schema']" :settings="settings"/>
        </div>
      </el-tab-pane>
    </el-tabs>
  </template>
  
  <script setup>
    import Renderer from "@/Modules/Settings/StorageComponent/Renderer.vue";
    defineProps({
      fields: Object,
      settings: Object,
      tabs: Object,
      index: [String, Number],
    })
  </script>
  
  