<template>
  <div class="fct-upgrade-paths-wrap fct-layout-width">
    <CardContainer>
      <CardHeader :title="translate('Upgrade Paths')"/>
      <template v-if="isProActive && !isBundleProduct">
        <CardBody class="px-0 pb-0">

          <UpgradePathsLoader v-if="loading" />
          <el-table class="hide-on-mobile" v-if="!loading" :data="upgradePaths">
            <el-table-column :label="translate('From Plan')" prop="object_id" :width="300">
              <template #default="scope">
                <div class="grid gap-1">
                  <div class="text-system-dark dark:text-gray-50">
                    {{ Arr.get(variantTitleMap, scope.row.object_id, '') }}
                  </div>

                  <div class="text-xs text-system-mid dark:text-gray-300">
                    {{ Arr.get(billingSummaryMap, scope.row.object_id, '') }}
                  </div>
                </div>
              </template>
            </el-table-column>

            <el-table-column :label="translate('To Plan')" :width="200">
              <template #default="scope">
                <div class="flex gap-1 flex-wrap">
                  <template v-for="id in Arr.get(scope.row, 'meta_value.to_variants',[])" :key="id">
                    <el-tag v-if="Arr.get(variantTitleMap, id)">{{ Arr.get(variantTitleMap, id) }}</el-tag>
                  </template>
                </div>
              </template>
            </el-table-column>

            <el-table-column :label="translate('Discount Amount')" :width="110">
              <template #default="scope">
              <span v-if="scope.row.meta_value.discount_amount > 0">
                {{ Arr.get(scope.row, 'meta_value.discount_amount', '0') }}
              </span>
                <span v-else>--</span>
              </template>
            </el-table-column>

            <el-table-column :label="translate('Is Prorate')" :width="100">
              <template #default="scope">
                {{
                  Arr.get(scope.row, 'meta_value.is_prorate', '0') === '1' ? 'Yes' : 'No'
                }}
              </template>
            </el-table-column>

            <el-table-column :width="100">
              <template #default="scope">
                <div class="fct-btn-group sm">
                  <EditModal
                      :pathId="scope.row.id"
                      :preSelectedToVariants="Arr.get(scope.row, 'meta_value.to_variants', [])"
                      :preSelectedIsProrate="Arr.get(scope.row, 'meta_value.is_prorate', '0')"
                      :preSelectedDiscountAmount="Arr.get(scope.row, 'meta_value.discount_amount', '0')"
                      :pathOptions="upgradePathOptions"
                      :all-variants="product.variants"
                      :existingUpgradePathMap="existingUpgradePathMap"
                      :fromName="Arr.get(variantTitleMap, scope.row.object_id, '')"
                      :preSelectedFromVariant="scope.row.object_id"
                      @pathGenerated="handlePathGenerated"
                  />

                  <icon-button size="x-small" tag="button" outline @click="() => deletePath(scope.row.id)">
                    <DynamicIcon name="Delete"/>
                  </icon-button>
                </div>
              </template>
            </el-table-column>

            <template #empty>
              {{ translate('Control available upgrade options and offer tailored discounts when moving between plans.') }}
            </template>

          </el-table>

          <!-- mobile view -->
          <div v-if="!loading" class="fct-upgrade-paths-mobile">
            <div v-for="(row, rowIndex) in upgradePaths" class="fct-upgrade-paths-mobile-row" :key="rowIndex">
              <div class="from-plan">
                <div class="title">
                  {{ translate('From Plan') }}:
                </div>

                <div>
                  <div class="plan-title">
                    {{ Arr.get(variantTitleMap, row.object_id, '') }}
                  </div>

                  <div class="plan-billing-summary" v-if="Arr.get(billingSummaryMap, row.object_id, '')">
                    {{ Arr.get(billingSummaryMap, row.object_id, '') }}
                  </div>
                </div>
              </div><!-- from-plan -->

              <div class="to-plan">
                <div class="title">
                  {{ translate('To Plan') }}:
                </div>

                <template v-for="id in Arr.get(row, 'meta_value.to_variants',[])" :key="id">
                  <el-tag v-if="Arr.get(variantTitleMap, id)">{{ Arr.get(variantTitleMap, id) }}</el-tag>
                </template>
              </div><!-- to-plan -->

              <div class="discount-amount">
                <div class="title">
                  {{ translate('Discount Amount') }}:
                </div>

                <span v-if="row.meta_value.discount_amount > 0">
                {{ Arr.get(row, 'meta_value.discount_amount', '0') }}
              </span>

                <span v-else>--</span>
              </div><!-- discount-amount -->

              <div class="is-prorate">
                <div class="title">
                  {{ translate('Is Prorate') }}:
                </div>

                <span>{{Arr.get(row, 'meta_value.is_prorate', '0') === '1' ? 'Yes' : 'No' }}</span>
              </div><!-- is-prorate -->

              <div class="path-actions">
                <div class="title">
                  {{ translate('Actions') }}:
                </div>

                <div class="fct-btn-group sm mt-3">
                  <EditModal
                      :pathId="row.id"
                      :preSelectedToVariants="Arr.get(row, 'meta_value.to_variants', [])"
                      :preSelectedIsProrate="Arr.get(row, 'meta_value.is_prorate', '0')"
                      :preSelectedDiscountAmount="Arr.get(row, 'meta_value.discount_amount', '0')"
                      :pathOptions="upgradePathOptions"
                      :all-variants="product.variants"
                      :existingUpgradePathMap="existingUpgradePathMap"
                      :fromName="Arr.get(variantTitleMap, row.object_id, '')"
                      :preSelectedFromVariant="row.object_id"
                      @pathGenerated="handlePathGenerated"
                  />

                  <icon-button size="x-small" tag="button" outline @click="() => deletePath(row.id)">
                    <DynamicIcon name="Delete"/>
                  </icon-button>
                </div>
              </div><!-- path-actions -->
            </div>

            <div v-if="upgradePaths.length == 0" class="py-6 text-center">
              {{ translate('No upgrade paths found.') }}
            </div>
          </div>
          <!-- mobile view -->

        </CardBody>

        <CardFooter v-if="!loading">
          <el-button
              text
              v-if="upgradePathOptions.length"
              @click="() => addModalRef?.openModal()"
          >
            <DynamicIcon name="Plus"/>
            {{ translate('Add Path') }}
          </el-button>

          <span v-else>
          {{ translate('No more upgrade paths available') }}
        </span>
        </CardFooter>
      </template>

      <div v-else-if="!isProActive" class="border border-solid border-gray-divider mt-5 pt-5 border-x-0 border-b-0 dark:border-dark-400">
        <ProFeatureNotice
            class="py-7.5"
            :title="translate('Get access to this feature by Upgrading to FluentCart Pro')"
            :text="translate('This feature is only available in FluentCart Pro.')"
        />
      </div>

      <div v-else-if="isBundleProduct" class="border border-solid border-gray-divider mt-5 border-x-0 border-b-0 dark:border-dark-400">
        <Empty
            icon="Empty/WebPage"
            :title="translate('Upgrade Paths Temporarily Unavailable for Bundle Products')"
            :text="translate('Upgrade paths feature is temporarily disabled for bundle products. This feature will be enabled in a future update.')"
        />
      </div>

    </CardContainer>

  </div>

  <AddModal
      v-if="!isBundleProduct"
      :productId="product.ID"
      @pathGenerated="handlePathGenerated"
      ref="addModalRef"
      :pathOptions="upgradePathOptions"
      :all-variants="product.variants"
      :existingUpgradePathMap="existingUpgradePathMap"
  />
</template>

<script setup>
import {ref, computed, onMounted} from 'vue';
import {
  Container as CardContainer,
  Header as CardHeader,
  Body as CardBody,
  Footer as CardFooter
} from '@/Bits/Components/Card/Card.js';

import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import AddModal from "@/Modules/Products/UpgradePaths/AddModal.vue";
import Rest from "@/utils/http/Rest";
import Arr from "../../../utils/support/Arr";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import EditModal from "@/Modules/Products/UpgradePaths/EditModal.vue";
import UpgradePathsLoader from "@/Modules/Products/UpgradePaths/UpgradePathsLoader.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Empty from "@/Bits/Components/Table/Empty.vue";

// Props
const props = defineProps({
  product: {
    type: Object,
    required: true
  },
  product_id: {
    type: [String, Number],
    required: true
  }
});

// Computed
const isBundleProduct = computed(() => {
  return Arr.get(props.product, 'detail.other_info.is_bundle_product') === 'yes';
});

// Refs
const loading = ref(false);
const addModalRef = ref(null);
const editModalRefs = ref([]);
const upgradeSettings = ref({
  status: 'yes',
  paths: {}
});
const isProActive = AppConfig.get('app_config.isProActive');

const upgradePaths = ref([]);

const upgradePathOptions = computed(() => {
  return props.product.variants.filter(variant => {
    const existingPathsCount = existingUpgradePathMap.value[variant.id]?.length || 0;
    return existingPathsCount < totalVariationCount.value - 1;
  });
});

const handlePathGenerated = (path) => {
  fetchUpgradeSettings();
};


const fetchUpgradeSettings = async () => {
  loading.value = true;
  Rest.get(`products/${props.product_id}/upgrade-paths`).then(function (response) {
    upgradePaths.value = response.data;
    initializeUpgradeSettings();
  })
      .catch(function (error) {
        console.log(error);
      })
      .finally(() => {
        loading.value = false;
      });
};

const addNewPath = (variationId) => {
  if (upgradeSettings.value.paths[variationId]) {
    Notify.error({
      title: translate('Error'),
      message: translate('You already have a path for this variation')
    });
    return;
  }

  upgradeSettings.value.paths[variationId] = [{
    target_product_id: props.product_id,
    target_variation_id: '',
    is_prorate: '1',
    discont_amount: ''
  }];
};


const variantTitleMap = ref({});
const billingSummaryMap = ref({});
const totalVariationCount = ref(
    Arr.get(props.product, 'variants', []).length
);

const existingUpgradePathMap = ref({});

const initializeUpgradeSettings = () => {
  props.product.variants.entries().forEach(([key, value]) => {
    variantTitleMap.value[value.id] = value.variation_title;
    billingSummaryMap.value[value.id] = value.other_info.billing_summary;
  });

  existingUpgradePathMap.value = {};

  upgradePaths.value.forEach((path) => {
    if (!existingUpgradePathMap.value.hasOwnProperty(path.object_id)) {
      existingUpgradePathMap.value[path.object_id] = [
        ...path.meta_value.to_variants
      ];
    } else {
      existingUpgradePathMap.value[path.object_id] = [
        ...existingUpgradePathMap.value[path.object_id].concat(
            [...path.meta_value.to_variants]
        )
      ];
      existingUpgradePathMap.value[path.object_id] = [...new Set(existingUpgradePathMap.value[path.object_id])]
    }
  })
};


const deletePath = (id) => {
  Rest.delete(`products/upgrade-path/${id}/delete`).then(function (response) {
    fetchUpgradeSettings();
    Notify.success(response.message);
  })
      .catch(function (error) {

      });
}

// Lifecycle hooks
onMounted(() => {
  fetchUpgradeSettings();

  const header = document.querySelector('#fct_admin_menu_holder .fct-admin-product-header');
  if (header) {
    const app = document.querySelector('#fluent_cart_plugin_app');
    app.prepend(header);
  }
});
</script>

