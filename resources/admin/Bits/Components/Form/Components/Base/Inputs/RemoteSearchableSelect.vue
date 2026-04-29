<script setup>
import {defineModel} from 'vue';
import {ref} from "vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
const model = defineModel()
const props = defineProps({
  field: {
    type: Object
  },

  key: {
    type: String
  }
})
const searching = ref(false)

const options = ref(props.field.options || []) ;
const getSelectOptions = (data) => {

  if(props.field.search_only_on_type === true){
    if(data.query.length === 0) {
      return;
    }
  }else{
   if(data.query.length < 1 && options.value.length > 0){
      return;
    }
  }

  searching.value = true;
  Rest.get('forms/search_options', {
    search_by: data.query,
    search_for: data.key
  })
      .then(response => {
        options.value = response.options;
        searching.value = false;
      })
}


</script>

<template>

  <el-select
      v-model="model"
      filterable
      remote
      :placeholder="field.placeholder ?? translate('Please enter a keyword')"
      :remote-method="query=>{
        getSelectOptions({query, key:field.remote_key})
      }"
      :loading="searching"
      :multiple="field.multiple"
  >
    <el-option v-for="item in options" :key="item.value" :label="item.label" :value="item.value"/>
  </el-select>
</template>

<style scoped>

</style>
