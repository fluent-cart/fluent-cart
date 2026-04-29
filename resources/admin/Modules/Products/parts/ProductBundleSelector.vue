<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted, ref, nextTick} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {useFilterPopoverOutsideClickMixin} from '@/mixin/filterPopoverOutsideClickMixin';
import Animation from "@/Bits/Components/Animation.vue";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";
import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";


const props = defineProps({
  product: Object,
  productEditModel: Object,
})
const emit = defineEmits(['update:modelValue'])
const hasPro = AppConfig.get('app_config.isProActive');
const proInventory = false;
const editableProductVariations = ref([]);
onMounted(() => {
  props.productEditModel.addOnProductUpdatedListener('bundle', function () {
    getBundleInfo();
  })
  getBundleInfo();

})


const saveBundleInfo = (variationId, bundleChildIds) => {

  Rest.post(`products/save-bundle-info/${variationId}`, {
    bundle_child_ids: bundleChildIds
  }).then((data) => {
    Notify.success(translate('Bundle info saved successfully'));
  })
      .catch((error) => {
        if (error?.status_code == '422') {
          Notify.validationErrors(error);
        } else {
          Notify.error(error?.message);
        }
      });
}

const loading = ref(true);
const getBundleInfo = () => {
  loading.value = true;
  Rest.get(`products/get-bundle-info/${props.product.ID}`).then((data) => {
    editableProductVariations.value = data;
    loading.value = false;
  })
}


</script>

<template>
  <div v-if="hasPro" class="fct-product-inventory-wrap">
    <Card.Container class="overflow-hidden">
      <Card.Header :title="translate('Map bundle items')">
      </Card.Header>
      <Animation :visible="true" accordion>

        <Card.Body class="px-0 pb-0">
          <div class="fct-product-inventory-inner-wrap hide-on-mobile">
            <el-table :data="editableProductVariations" v-if="!loading">
              <el-table-column :label="translate('Title')" v-if="product.detail.variation_type === 'simple_variations'"
                               width="140">
                <template #default="scope">
                  <div class="space-x-5">
                    <span>{{ scope.row.variation_title }}</span>

                    <span v-if="scope.row.other_info?.payment_type === 'subscription'"
                          class="fct-variant-badge bg-white border border-solid border-gray-outline text-primary-500 rounded-xs dark:bg-primary-700 dark:text-gray-50 dark:border-primary-500">
                    {{ scope.row.other_info.repeat_interval }}
                  </span>
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Bundle Items')" width="140">
                <template #default="scope">


                  <ProductVariationSelector
                      v-model="scope.row.bundle_child_ids"
                      :is_multiple="true"
                      popoverClass="fct-create-new-order-bump-modal-popover"
                      @visible-change="visible => {
                        if(!visible){
                          saveBundleInfo(scope.row.id, scope.row.bundle_child_ids)
                        }
                      }"
                      :scopes="['nonBundle']"
                  />
                </template>

              </el-table-column>

            </el-table>
          </div>


        </Card.Body>
      </Animation>
    </Card.Container>
  </div>
  <div v-else>
    <Card.Container>
      <Card.Header border_bottom>
        <template #title>
          <h2 class="fct-card-header-title">
            {{ translate('Map bundle items') }}
            <DynamicIcon name="Crown" class="w-4 h-4 text-warning-500"/>
          </h2>
        </template>
      </Card.Header>
      <Card.Body>
        <div class="fct-product-inventory-pro-text">
          <p class="m-0">{{ translate('This feature is only available for pro version.') }}</p>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
