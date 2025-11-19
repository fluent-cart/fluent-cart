<script setup>
import {defineModel, nextTick, onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Badge from "@/Bits/Components/Badge.vue";
import FieldNote from "@/Bits/Components/Form/Components/Base/Inputs/FieldNote.vue";
import Arr from "@/utils/support/Arr";
import CreatePageButton from "./CreatePageButton.vue";


const model = defineModel({
  get: (modelValue) => {
    if (!modelValue) {
      return '';
    }
    return typeof modelValue === 'string' ? Number(modelValue) : modelValue;
  }
});


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


const options = ref(props.field.options);
const defaultPage = ref(model.value);
const previewLinks = ref({});
const inputFocus = ref(false);


const getPreviewLink = () => {
  if (!model.value) {
    return '';
  }
  return Arr.get(previewLinks.value, model.value.toString(), '');
}

const handleFocus = (val) => {
  inputFocus.value = val;
}

const baseAdminUrl = Arr.get(window, 'fluentCartAdminApp.admin_url', '').toString().replace('admin.php?page=fluent-cart#/', 'post.php?action=edit&post=');

const editUrl = () => {
  if (!model.value) {
    return '';
  }
  return baseAdminUrl + model.value;
}


onMounted(() => {
  previewLinks.value[model.value.toString()] = props.field.preview_link;
})

</script>

<template>

  <div class="fct-form-group">
    <div class="fct-input-group w-full" :class="{'focused': inputFocus}">
      <div class="input-prepend">

        <el-select filterable v-model="model" @focus="_=>handleFocus(true)" @blur="_=>handleFocus(false)"
                   :placeholder="translate('Select')" clearable @change="(value)=>{
                    if(!value){
                      model = '';
                    }
                   }">
          <el-option v-for="(page,index) of options" :key="index" :label="page.label"
                     :value="Number(page.value)"/>
        </el-select>

        <div class="input-info-note flex gap-2 mt-2 flex-wrap items-center">
          <div v-if="field.note" class="form-note mr-1 !mt-0" v-html="field.note"></div>


          <div class="flex gap-2">
            <a v-if="editUrl()" class="preview-link !outline-none !shadow-none" :href="editUrl()" target="_blank">
              <p class="flex gap-1 items-end m-0 underline">
                {{ translate('Edit') }}
              </p>
            </a>

            <a v-if="getPreviewLink()" class="preview-link !outline-none !shadow-none" :href="getPreviewLink()" target="_blank">
              <p class="flex gap-1 items-end m-0 underline">
                {{ translate('Preview') }}
                <DynamicIcon class="w-3 h-3 mb-1" name="Redirect"/>
              </p>
            </a>
          </div>
        </div>

      </div>
      <div class="input-append">
        <CreatePageButton :title="field.page_title"
                          @on-page-created="(page)=>{
                            options.push({
                              label: page.label,
                              value: page.value
                            });
                            previewLinks[page.value.toString()] = page.page_link;
                            model = page.value;
                        }"
                          :content="field.page_key"/>

      </div>
    </div>
  </div>


</template>

<style scoped>

</style>
