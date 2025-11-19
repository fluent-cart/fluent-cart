<template>
  <div class="fct-add-product-item-elementor-modal-wrap">
    <el-input v-model="model.data.search" @click="openModal()" :placeholder="translate('Search products')">
      <template #prefix>
        <DynamicIcon name="Search"/>
      </template>
    </el-input>


    <el-dialog
        v-model="model.data.showModal"
        :title="translate('Select Items')"
        @close="model.closeModal()"
    >
      <div class="-mx-5 pb-5 border border-solid border-t-0 border-x-0 border-gray-divider dark:border-dark-400">
        <div class="px-5">
          <el-input
              :placeholder="translate('Search products')"
              autofocus
              ref="fc_product_search_new"
              @input="model.fetchProducts(defaultScopes)"
              v-model="model.data.search"
              clearable
          >
            <template #prefix>
              <DynamicIcon name="Search"/>
            </template>
          </el-input>
        </div>
      </div>

      <div>
        <el-skeleton class="px-5 py-4" :loading="model.loading" :rows="5" animated v-if="model.loading"/>
        <div class="fc_product_search_table" v-else>
          <VariationSelector
              :model="model"
              :products="model.data.products"
              :preSelectedVariations="{}"
              :isMultiple="true"
              :allow_subscription="false"
          />
        </div>
      </div>
      <div class="dialog-footer">
        <el-button @click="model.closeModal()">
          {{ translate('Cancel') }}
        </el-button>
        <el-button :disabled="!model.data.selectedVariationIds.length" type="primary" @click="onSelectedVariantions">
          {{ translate('Ok') }}
        </el-button>
      </div>
    </el-dialog>

  </div>
</template>

<script setup>
import {ref, watch} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import VariationSelector from "@/elementor/VariationSelector/VariationSelector.vue";
import useSelectorModel from "@/elementor/VariationSelector/SelectorModel";
import translate from "@/utils/translator/Translator";
const model = useSelectorModel();
const emit = defineEmits(['onVariationSelectionUpdated']);

const props = defineProps({
  scopes: {
    type: Array,
    default: []
  },
  isMultiple: {
    type: Boolean,
    default: false
  },
  selectedIds: {
    type: Array,
    default: []
  }
});

// Set the isMultiple property in the model
model.data.isMultiple = props.isMultiple;

const defaultScopes = ref(props.scopes);
const defaultSelectedIds = ref(props.selectedIds);

watch(
    () => props.selectedIds,
    (newVal, oldVal) => {
      defaultSelectedIds.value = newVal;
    }
);


const onSelectedVariantions = () => {
  emit('onVariationSelectionUpdated', model.data.selectedVariationIds, model.data.products);
  model.closeModal();
}

const openModal = async () => {
  model.data.showModal = true;
  await model.fetchProducts(defaultScopes.value);

  model.setPreselectedVariations(defaultSelectedIds.value);
}

</script>
<style lang="scss">
.fct-add-product-item-elementor-modal-wrap {
  .el-overlay {
    z-index: 9999 !important;
  }
}
</style>
