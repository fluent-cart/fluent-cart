<script setup>
import {getCurrentInstance, onMounted, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";


const emit = defineEmits(['onProductCreated']);


const props = defineProps({
  products: {}
})

const $this = getCurrentInstance().ctx;
const showModal = ref(false);
const dummyProductInfo = ref([]);
const selectedCategoryIndex = ref(null);
const lastRequestedIndex = ref(0);

const inserting = ref(false);
const currentInfo = ref(null);
const percentage = ref(0);

const handleToggleModal = (value) => {
  showModal.value = value;
}

const createDummyProduct = (index = 0) => {
  if (selectedCategoryIndex.value === null) {
    resetProgress()
    return;
  }

  inserting.value = true;

  const info = dummyProductInfo.value[selectedCategoryIndex.value];
  currentInfo.value = info;


  if (index > info.count - 1) {
    setTimeout(() => {
      resetProgress();
      showModal.value = false;
      emit('onProductCreated')
    }, 1000)
    return;
  }

  lastRequestedIndex.value = index;

  $this.$post('products/create-dummy', {
    'category': info.category,
    'index': index
  }).then(response => {
    percentage.value = parseFloat(
        (((index + 1) / info.count) * 100).toFixed(0)
    );
    createDummyProduct(index + 1)
  }).catch(errors => {
    if (errors.code === 400) {
      resetProgress();
    } else {
      createDummyProduct(index + 1)
    }
    $this.handleError(errors);
  });
}
const resetProgress = () => {
  percentage.value = 0;
  lastRequestedIndex.value = 0;
  inserting.value = false;
  currentInfo.value = null;
}

onMounted(() => {
  dummyProductInfo.value = AppConfig.get('dummy_product_info');
})

</script>

<template>
  <el-button @click="handleToggleModal(true)">
    <DynamicIcon name="Stars" class="hidden lg:inline"/>
    {{ $t('Add Dummy Product') }}
  </el-button>

  <el-dialog
      v-model="showModal"
      :title="$t('Create Dummy Product')"
      :append-to-body="true"
  >


    <div v-if="inserting" class="fct-dummy-product-content">
      <div v-if="currentInfo != null" class="title">
        {{
          /* translators: %1$s - current product number, %2$s - total product number, %3$s - product category name */
          translate('Inserting %1$s of %2$s %3$s Product', lastRequestedIndex + 1, currentInfo.count, currentInfo.title)
        }}
      </div>
      <div class="text">
        {{ $t('Can close the window - product will continue to insert.') }}
        <div class="fct-dummy-product-loading">
          <div class="fct-loading-bars">
            <div v-for="i in 8" :key="i" class="bar-block" :id="`bar-block-${i + 1}`"></div>
          </div>
          {{
            /* translators: %s - upload progress */
            translate('Uploading... %s%', percentage)
          }}
        </div>
      </div>
      <el-progress :percentage="percentage" striped-flow :stroke-width="6" striped :show-text="false"/>
    </div>

    <div v-else class="fct-form-group">
      <label class="required">
        {{ $t('Product Category') }}
      </label>
      <el-select v-model="selectedCategoryIndex" clearable :placeholder="$t('Select a Product Category')">
        <el-option v-for="(info, key) in dummyProductInfo"
                   :key="key"
                   :label="info.title"
                   :value="key"
        />
      </el-select>
    </div>

    <template v-if="!inserting" #footer>
      <div class="dialog-footer">
        <el-button @click="handleToggleModal(false)">{{ $t('Cancel') }}</el-button>
        <el-button type="primary" @click="()=>{
          createDummyProduct()
        }" :disabled="!selectedCategoryIndex">
          {{ $t('Create Products') }}
        </el-button>
      </div>
    </template>

  </el-dialog>
</template>
