<script setup>
import {defineModel, ref} from "vue";
import translate from "@/utils/translator/Translator";
import CreatePageButton from "@/Bits/Components/Buttons/CreatePageButton.vue";

const model = defineModel({
  get: (modelValue) => {
    if (!modelValue) {
      return '';
    }
    return typeof modelValue === 'string' ? Number(modelValue) : modelValue;
  }
});


const props = defineProps({
  title: {
    type: String,
    required: true
  },
  pages: {
    type: Array,
    required: true
  },
  modelId: {
    type: String,
    required: true
  }
});

const emit = defineEmits(['onPageCreated']);

const inputFocus = ref(false);

const handleFocus = (val) => {
  inputFocus.value = val;
}
</script>

<template>
  <div class="fct-form-group">
    <label>{{ title }}</label>
    <div class="fct-form-group-inner">
      <div class="fct-input-group w-full" :class="{'focused': inputFocus}">
        <div class="input-prepend">
          <el-select filterable v-model="model" @focus="_=>handleFocus(true)" @blur="_=>handleFocus(false)"
                     :placeholder="translate('Select')" clearable>
            <el-option v-for="(page, index) of pages" :key="index" :label="page.label"
                       :value="Number(page.value)"/>
          </el-select>
        </div>
        <div class="input-append">
          <CreatePageButton :title="title"
                            @on-page-created="(page) => emit('onPageCreated', page, modelId)"
                            :content="modelId"/>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>
