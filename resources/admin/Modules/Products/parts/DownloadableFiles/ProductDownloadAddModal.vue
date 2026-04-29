<script setup>
import {computed} from "vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SingleFileForm from "@/Modules/Products/parts/DownloadableFiles/SingleFileForm.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import FileUploaderDialog from "@/Bits/Components/DownloadableFileSelector/FileUploaderDialog.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  product: Object,
  productEditModel: Object,
  productDownloadableModel: Object
})

const drawerSize = computed(() => (window.innerWidth <= 768 ? "90%" : "34%"));


</script>

<template>
  <div class="add-download-btn"
       :class="productDownloadableModel.hasVariationOption(product) ? 'px-6 py-3 border border-solid border-gray-divider border-x-0 border-b-0 dark:border-dark-400' : ''">

    <div class="text-right" v-if="productDownloadableModel.hasVariationOption(product)">
      <el-button text type="primary" @click="()=>{
            productDownloadableModel.openAddModal()
          }">
        <DynamicIcon name="Plus"/>
        {{ $t('Add') }}
      </el-button>
    </div>
    <div v-else class="px-6 py-3">
      {{ $t('No pricing added yet') }}
    </div>
  </div>

  <el-drawer v-model="productDownloadableModel.data.addModal.isModalOpen" :size="drawerSize" @close="() => {
      productDownloadableModel.clearInsertableFile();
    }" @open="() => {
      productDownloadableModel.addDummyInsertableFile();
    }">
    <template #header>
      <LabelHint
          :title="$t('Add Downloadable Asset(s)')"
          :content="$t('Attach the WordPress-supported digital files that customers will receive after purchasing this product. If this downloadable file is only for a specific product variation (e.g., \'eBook Version\' or \'Audiobook Version\'), please select that variant. Otherwise, you may leave the \'Choose variant\' field empty to make the file available for all variants of this product. Please note that any additional files added after any sell will be accessible to both existing and new orders.')"
          placement="bottom"
      />
    </template>
    <div class="fct-product-download-inner-wrap flex flex-col gap-4">
      <template v-if="productDownloadableModel.insertableFiles.length">
        <div v-for="(file, index) of productDownloadableModel.insertableFiles"
             class="rounded p-3 bg-gray-25 dark:bg-dark-500 relative">
          <el-button
              v-if="productDownloadableModel.insertableFiles.length > 1"
              @click="productDownloadableModel.deleteInsertableFile(index)"
              size="small"
              plain
              class="absolute right-2 top-2 p-0 border-none hover:text-red-500"
          >
            <DynamicIcon name="Delete"/>
          </el-button>
          <SingleFileForm
              :product="product"
              :file="file"
              :index="index"
              :productDownloadableModel="productDownloadableModel"
          />
        </div>
      </template>
      <div v-if="productDownloadableModel.insertableFiles.length > 1">
        <FileUploaderDialog
            :uploadBtnText="translate('Add More Files')"
            @onFileSelected="value => {
              const fileIndex = productDownloadableModel.insertableFiles.length - 1;
              const $selected = value;

              $selected.value.forEach((vl, idx) => {
                  $selected.value[idx].serial = fileIndex + 1;
                  $selected.value[idx].settings.download_limit = productDownloadableModel.insertableFiles[fileIndex].settings.download_limit;
                  $selected.value[idx].settings.download_expiry = productDownloadableModel.insertableFiles[fileIndex].settings.download_expiry;
                  $selected.value[idx].product_variation_id = productDownloadableModel.insertableFiles[fileIndex].product_variation_id;



                  // Push new items instead of replacing
                  productDownloadableModel.insertableFiles.push($selected.value[idx]);
              })

            }"
        />
      </div>


      <div v-for="(variant, index) of product.variants" :key="index">
        <ValidationError :validation-errors="productDownloadableModel.data.validationErrors"
                         :field-key="`variants.${index}.downloadable`"/>
      </div>

<!--      <div class="fct-product-download-actions">-->
<!--        <div class="fct-product-download-action-buttons">-->
<!--          <el-button @click="()=>{productDownloadableModel.addDummyInsertableFile(null)}" size="small">-->
<!--            <DynamicIcon name="Plus"/>-->
<!--            {{ $t('Add asset') }}-->
<!--          </el-button>-->


<!--          <el-button @click="()=>{-->
<!--          productDownloadableModel.addDummyDownloadableFileForAllVariant(product);-->
<!--        }" size="small">-->
<!--            <DynamicIcon name="Stars"/>-->
<!--            {{ $t('Generate') }}-->
<!--          </el-button>-->
<!--        </div>-->
<!--      </div>-->

    </div>

    <template #footer>
      <div>
        <el-button @click="productDownloadableModel.data.addModal.isModalOpen = false">{{$t('Cancel')}}</el-button>
        <el-button
            type="primary"
            @click="()=>{
              productDownloadableModel.attachInsertableFiles(product.ID);
            }"
        >
          {{$t('Save Asset')}}
        </el-button>
      </div>
    </template>
  </el-drawer>

</template>

