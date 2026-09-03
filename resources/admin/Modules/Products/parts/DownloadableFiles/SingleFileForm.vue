<script setup>
import FileUploaderDialog from "@/Bits/Components/DownloadableFileSelector/FileUploaderDialog.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import {onBeforeMount, computed} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Str from "@/utils/support/Str";
import VariationSelector from "@/Bits/Components/VariationSelector.vue";
import translate from "@/utils/translator/Translator";
import {getVariantLabel, getProductGroupOrder} from "@/utils/variantLabel";

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

// Canonical group order from the product's saved attribute_config so the
// variant label leads with the same group the AdvancedVariationTable groups
// by (e.g. "Single Site / 4 GB" rather than "4 GB / Single Site").
const groupOrder = computed(() => getProductGroupOrder(props.product));

onBeforeMount(() => {
  //Convert the product_variation_id into Object for select input
  if (props.file && typeof props.file.product_variation_id === 'string') {
    try {
      // eslint-disable-next-line vue/no-mutating-props
      props.file.product_variation_id = JSON.parse(props.file.product_variation_id)
      // eslint-disable-next-line vue/no-mutating-props
      props.file.product_variation_id = props.file.product_variation_id.map((id) => {
        return parseInt(id)
      })

    } catch (e) {

    }
  }

  // Drop stale variant references. When an attribute group is deleted
  // on an advanced-variation product, the cascade removes the variants
  // it owned — but any downloadable asset that had pointed at those
  // variants still holds their now-orphan ids. Without this filter the
  // el-select renders the raw numeric id (e.g. "6218") in a tag because
  // v-model can't find a matching <el-option>. Reconcile against the
  // current variant list once on mount.
  if (props.file && Array.isArray(props.file.product_variation_id) && props.product?.variants) {
    const variantList = Array.isArray(props.product.variants) ? props.product.variants : [];
    const liveIds = new Set(variantList.map(v => parseInt(v.id, 10)));
    const filtered = props.file.product_variation_id.filter(id => liveIds.has(parseInt(id, 10)));
    if (filtered.length !== props.file.product_variation_id.length) {
      // eslint-disable-next-line vue/no-mutating-props
      props.file.product_variation_id = filtered;
    }
  }
  //props.productDownloadableModel.closeEditModal(props.index)
})
</script>

<template>
  <div class="el-form el-form--default el-form--label-top ">
    <el-form-item :label="$t('Choose variant')">
      <el-select class="" size="small" v-model="file.product_variation_id" multiple
                 :id="`downloadable_files.${index}.product_variation_id`"
                 :placeholder="$t('Keep empty for all')"
                 popper-class="fct-choose-variant-popover"
                 collapse-tags collapse-tags-tooltip>
        <el-option
            v-for="(variant) in productDownloadableModel.generateVariantOptions(product)"
            :key="variant.id"
            :label="getVariantLabel(variant, groupOrder)"
            :value="variant.id"
        >

          <VariationSelector :variant="variant" :group-order="groupOrder"/>

        </el-option>
      </el-select>
      <ValidationError :validation-errors="productDownloadableModel.data.validationErrors"
                       :field-key="`downloadable_files.${index}.product_variation_id`"/>
    </el-form-item>

    <el-form-item v-if="isEditing || productDownloadableModel.insertableFiles.length === 1">
      <FileUploaderDialog
          :isMultiple="!singleMode"
          @on-file-selected="value => {
                        const fileIndex = index;
                        const $selected = value;

                        $selected.forEach((vl, idx) => {

                          if(isEditing){
                            $selected[idx].serial = fileIndex + 1;
                            $selected[idx].settings.download_limit = productDownloadableModel.data.downloadableFiles[fileIndex].settings.download_limit
                            $selected[idx].settings.download_expiry = productDownloadableModel.data.downloadableFiles[fileIndex].settings.download_expiry

                            if ($selected.length > 1) {
                                $selected[idx].product_variation_id = productDownloadableModel.data.downloadableFiles[fileIndex].product_variation_id;
                                file = $selected[idx];

                            } else {
                                $selected[idx].product_variation_id = productDownloadableModel.data.downloadableFiles[fileIndex].product_variation_id;
                                file.file_name = $selected[idx].file_name;
                                file.file_path = $selected[idx].file_path;
                                file.file_size = $selected[idx].file_size;
                                file.file_url = $selected[idx].file_url;
                                file.title = $selected[idx].title;
                                file.type = $selected[idx].type;
                                file.settings = $selected[idx].settings;
                            }
                          }
                          else{
                            $selected[idx].serial = fileIndex + 1;
                            $selected[idx].settings.download_limit = productDownloadableModel.insertableFiles[fileIndex].settings.download_limit
                            $selected[idx].settings.download_expiry = productDownloadableModel.insertableFiles[fileIndex].settings.download_expiry

                            if ($selected.length > 1) {
                                $selected[idx].product_variation_id = productDownloadableModel.insertableFiles[fileIndex].product_variation_id;
                                productDownloadableModel.insertableFiles[fileIndex + idx] = $selected[idx];
                            } else {
                                $selected[idx].product_variation_id = productDownloadableModel.insertableFiles[fileIndex].product_variation_id;
                                productDownloadableModel.insertableFiles[fileIndex] = $selected[idx];
                            }
                          }



                        })
                      }"
      />

      <ValidationError :validation-errors="productDownloadableModel.data.validationErrors"
                       :field-key="`downloadable_files.${index}.file_url`"/>
    </el-form-item>

    <el-form-item v-if="file.title" :label="$t('File Title')">
      <p>{{file.title}}</p>
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
      <p>{{
          /* translators: %s is the driver name */
          translate('Driver: %s', file.driver)
        }}</p>
    </el-form-item>

  </div>
</template>
