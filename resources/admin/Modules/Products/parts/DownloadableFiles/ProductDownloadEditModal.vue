<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SingleFileForm from "@/Modules/Products/parts/DownloadableFiles/SingleFileForm.vue";
import {computed, onBeforeMount, onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  product: Object,
  productDownloadable: Object,
  productEditModel: Object,
  productDownloadableModel: Object,
  index: Number | String
})

const drawerSize = computed(() => (window.innerWidth <= 768 ? "90%" : "40%"));
const editableFile = ref({
  ...props.productDownloadable
})

const modalStates = props.productDownloadableModel.data.editModal.states;

onBeforeMount(() => {
  modalStates[props.index] = {}
  modalStates[props.index]['visible'] = false
  //modalStates[props.index]['file'] = editableFile
})


</script>

<template>
  <el-drawer append-to-body
             v-model="modalStates[index]['visible']" :size="drawerSize"
             :title="translate('Edit Downloadable Asset')"
             @close="() => {
               productDownloadableModel.closeEditModal(index)
    }">

    <div class="fluent-cart-admin-pages">
      <div class="fct-product-download-inner-wrap flex flex-col gap-4">
        <div class="rounded p-3 bg-gray-25 dark:bg-dark-500">
          <SingleFileForm
              :product="product"
              :file="editableFile"
              :index="index"
              :productDownloadableModel="productDownloadableModel"
              :singleMode="true"
              is-editing
          />
        </div>

      </div>

    </div>

    <template #footer>
      <el-button type="primary"
                 :loading="productDownloadableModel.saving"
                 @click="()=>{
           productDownloadableModel.updateDownloadableFile();
          }">
        {{ productDownloadableModel.saving ? translate('Updating') : translate('Update') }}
      </el-button>
    </template>
  </el-drawer>


  <div class="el-dropdown-menu__item w-full"
       @click.prevent="() => {
                modalStates[index]['visible'] = true;
                productDownloadableModel.openEditModal(index,editableFile)
              }"
  >
    <DynamicIcon name="Edit"/>
    {{ translate('Edit') }}
  </div>
</template>
