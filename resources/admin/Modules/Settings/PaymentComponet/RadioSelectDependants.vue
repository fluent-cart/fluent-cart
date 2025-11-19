<template>
   <p class="setting-label">
        {{ fields[index].label }}
        <el-tooltip v-if="fields[index].tooltip" placement="top-start"
                    popper-class="fct-tooltip">
            <template #content>
            <p v-html="fields[index].tooltip">
            </p>
            </template>
            <el-icon><InfoFilled /></el-icon>
        </el-tooltip>
    </p>
    <el-radio-group v-model="settings[index]">
        <el-radio v-for="(opt, ind) in fields[index].options" :label="opt" :value="ind" :key="ind" />
    </el-radio-group>
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
  </script>
  
