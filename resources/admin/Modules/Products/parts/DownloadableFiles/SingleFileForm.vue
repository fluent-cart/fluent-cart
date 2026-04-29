<script setup>
import FileUploaderDialog from "@/Bits/Components/DownloadableFileSelector/FileUploaderDialog.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import {onBeforeMount} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Str from "@/utils/support/Str";
import VariationSelector from "@/Bits/Components/VariationSelector.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  product: Object,
  file: Object,
  index: Number,
  productDownloadableModel: Object,
  singleMode: {
    type: Boolean,
    default: false
  },
  isEditing: {
    type: Boolean,
    default: false
  }
})

onBeforeMount(() => {
  //Convert the product_variation_id into Object for select input
  if (props.file && typeof props.file.product_variation_id === 'string') {
    try {
      props.file.product_variation_id = JSON.parse(props.file.product_variation_id)
      props.file.product_variation_id = props.file.product_variation_id.map((id) => {
        return parseInt(id)
      })

    } catch (e) {

    }
  }
  //props.productDownloadableModel.closeEditModal(props.index)
})
</script>

<template>
  <div class="el-form el-form--default el-form--label-top ">
    <el-form-item :label="$t('Choose variant')" class="mb-2.5 ">
      <el-select class="" size="small" v-model="file.product_variation_id" multiple
                 :id="`downloadable_files.${index}.product_variation_id`"
                 :placeholder="$t('Keep empty for all')"
                 popper-class="fct-choose-variant-popover"
                 collapse-tags collapse-tags-tooltip>
        <el-option
            v-for="(variant) in productDownloadableModel.generateVariantOptions(product)"
            :key="variant.id"
            :label="variant.variation_title"
            :value="variant.id"
        >

          <VariationSelector :variant="variant"/>

        </el-option>
      </el-select>
      <ValidationError :validation-errors="productDownloadableModel.data.validationErrors"
                       :field-key="`downloadable_files.${index}.product_variation_id`"/>
    </el-form-item>

    <el-form-item v-if="isEditing || productDownloadableModel.insertableFiles.length === 1" class="mb-2.5">
      <FileUploaderDialog
          :isMultiple="!singleMode"
          @onFileSelected="value => {
                        const fileIndex = index;
                        const $selected = value;

                        $selected.value.forEach((vl, idx) => {

                          if(isEditing){
                            $selected.value[idx].serial = fileIndex + 1;
                            $selected.value[idx].settings.download_limit = productDownloadableModel.data.downloadableFiles[fileIndex].settings.download_limit
                            $selected.value[idx].settings.download_expiry = productDownloadableModel.data.downloadableFiles[fileIndex].settings.download_expiry

                            if ($selected.value.length > 1) {
                                $selected.value[idx].product_variation_id = productDownloadableModel.data.downloadableFiles[fileIndex].product_variation_id;
                                file = $selected.value[idx];

                            } else {
                                $selected.value[idx].product_variation_id = productDownloadableModel.data.downloadableFiles[fileIndex].product_variation_id;
                                file.file_name = $selected.value[idx].file_name;
                                file.file_path = $selected.value[idx].file_path;
                                file.file_size = $selected.value[idx].file_size;
                                file.file_url = $selected.value[idx].file_url;
                                file.title = $selected.value[idx].title;
                                file.type = $selected.value[idx].type;
                                file.settings = $selected.value[idx].settings;
                            }
                          }
                          else{
                            $selected.value[idx].serial = fileIndex + 1;
                            $selected.value[idx].settings.download_limit = productDownloadableModel.insertableFiles[fileIndex].settings.download_limit
                            $selected.value[idx].settings.download_expiry = productDownloadableModel.insertableFiles[fileIndex].settings.download_expiry

                            if ($selected.value.length > 1) {
                                $selected.value[idx].product_variation_id = productDownloadableModel.insertableFiles[fileIndex].product_variation_id;
                                productDownloadableModel.insertableFiles[fileIndex + idx] = $selected.value[idx];
                            } else {
                                $selected.value[idx].product_variation_id = productDownloadableModel.insertableFiles[fileIndex].product_variation_id;
                                productDownloadableModel.insertableFiles[fileIndex] = $selected.value[idx];
                            }
                          }



                        })
                      }"
      />

      <ValidationError :validation-errors="productDownloadableModel.data.validationErrors"
                       :field-key="`downloadable_files.${index}.file_url`"/>
    </el-form-item>

    <el-form-item v-if="file.title" class="mb-2.5" :label="$t('File Title')">
      <p class="m-0 mt-1">{{file.title}}</p>
      <el-input v-if="false" size="small"
                :class="productDownloadableModel.hasValidationError(`downloadable_files.${index}.title`) ? 'is-error' : ''"
                :id="`downloadable_files.${index}.title`"
                :placeholder="$t('File Title')" type="text" v-model="file.title"
                @change="value => {
                          file['title'] = value
                        }"
                @focus="productDownloadableModel.clearValidationError(`downloadable_files.${index}.title`)"/>

      <ValidationError :validation-errors="productDownloadableModel.data.validationErrors"
                       :field-key="`downloadable_files.${index}.title`"/>
    </el-form-item>

    <el-form-item v-if="file.driver">
      <p class="m-0">{{
          /* translators: %s is the driver name */
          translate('Driver: %s', file.driver)
        }}</p>
    </el-form-item>

  </div>
</template>
