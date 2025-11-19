<script setup>

import AddProductItemModal from "@/elementor/VariationSelector/AddProductItemModal.vue";
import {ref, reactive, computed, onMounted, nextTick, onBeforeMount} from "vue";
import translate from "@/utils/translator/Translator";
import useElementor from "@/elementor/mixins/useElementor";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import ServerSidePreview from "@/elementor/Components/ServerSidePreview.vue";
import Notify from "@/utils/Notify";
import CaretRight from "@/Bits/Components/Icons/CaretRight.vue";
import Delete from "@/Bits/Components/Icons/Delete.vue";
// Import necessary components
// Import icons or create Vue components for them
let elementorHelper = null;

const props = defineProps({
  settings: {
    type: String,
    default: ''
  }
});


const previewSettings = ref(props.settings);

const variationsData = ref({});
const variations = ref([]);
const tabContent = ref([]);
const active_tab = ref(0);
const active_variant = ref({});
const expandedProducts = ref({});
const defaultVariantIds = ref([]);
const selectedVariants = ref([]);
const activeName = ref('editor');


const handleVariationSelection = (ids, products) => {
  setDefaultVariant(ids);

  previewSettings.value.variations = ids;

  // Similar to the onModalClosed callback in React
  let selectedVariations = {};

  // Process the selected variations from products
  products.forEach(product => {
    product.variants.forEach(variant => {
      if (ids.includes(variant.id)) {
        selectedVariations[variant.id] = {
          other_info: variant.other_info,
          variation_title: variant.variation_title,
          id: variant.id
        };
      }
    });
  });

  // Update state
  variationsData.value = {...selectedVariations};
  variations.value = Object.keys(selectedVariations);
  onChangeVariationGroup(selectedVariations);
}

// Function to toggle product description visibility
const toggleProductDescription = (productKey) => {
  const newExpandedState = {};
  Object.keys(expandedProducts.value).forEach(key => {
    newExpandedState[key] = false; // Collapse all items
  });

  // Toggle the specific product's expanded state
  newExpandedState[productKey] = !expandedProducts.value[productKey];
  expandedProducts.value = newExpandedState;
};

// Function to update variation data
const updateVariationData = (key, variation) => {
  Rest.put('/products/variants/'+key+'/pricing-table', {
    description: variation.other_info.description
  })
      .then(response => {

      })
      .catch(error => {
        console.error('Error updating variation data:', error);
        Notify.error(translate('Failed to update variation data'));
      });
};

// Function to delete a variation
const deleteVariation = (key) => {
  // Create a copy of variations data
  let variationsCopy = {...variationsData.value};

  // Delete the specific key from variations if it exists
  if (key in variationsCopy) {
    delete variationsCopy[key];
  }

  variationsData.value = {...variationsCopy};

  // Update the variations array
  variations.value = Object.keys(variationsCopy);

  // Update elementor settings
  // Handle defaultVariantIds properly whether it's an array or string
  if (Array.isArray(defaultVariantIds.value)) {
    defaultVariantIds.value = defaultVariantIds.value.filter(id => id.toString() !== key.toString());
  } else if (typeof defaultVariantIds.value === 'string') {
    defaultVariantIds.value = defaultVariantIds.value.split(',').filter(id => id !== key);
  }
  
  let formattedVariantIds = [];
  if (typeof defaultVariantIds.value === 'string') {
    formattedVariantIds = defaultVariantIds.value.split(',').map(Number);
  } else if (Array.isArray(defaultVariantIds.value)) {
    formattedVariantIds = defaultVariantIds.value.map(id => parseInt(id, 10));
  }
  
  setDefaultVariant(defaultVariantIds.value);
  previewSettings.value.variations = formattedVariantIds;

  // Rebuild tab content with updated variations data
  onChangeVariationGroup(variationsData.value);
};

const onChangeVariationGroup = (items, groupBy = 'repeat_interval') => {
  let groupTitles = {};
  let groupKey = 'repeat_interval';

  if (groupBy === 'payment_type') {
    groupKey = 'payment_type';
  } else if (groupBy === 'none') {
    groupKey = 'onetime';
  }

  const groupedVariations = {};

  // Group items by the specified key
  Object.entries(items).forEach(([key, value]) => {
    // Extract the groupKey value or fallback to 'onetime'
    let groupValue = value.other_info?.[groupKey] || 'onetime';
    
    // Check if installment is set to 'yes' and prioritize it
    if (value.other_info && value.other_info['installment'] === 'yes') {
      groupValue = 'installment';
    }

    if (!groupedVariations[groupValue]) {
      groupedVariations[groupValue] = {};
    }

    groupedVariations[groupValue][key] = {
      id: key,
      other_info: value.other_info,
      variation_title: value.variation_title
    };
  });

  // Convert to array format for tabs
  const newTabContent = Object.keys(groupedVariations).map(groupKey => ({
    title: groupKey,
    content: Object.entries(groupedVariations[groupKey]).reduce((acc, [id, variation]) => {
      acc[id] = {id, ...variation};
      return acc;
    }, {})
  }));

  tabContent.value = newTabContent;

  // Reset active tab if needed
  if (newTabContent.length) {
    active_tab.value = 0;
  }
};


const setDefaultVariant = (variantIds) => {
  if (typeof variantIds === 'string') {
    defaultVariantIds.value = variantIds.split(',').map(id => parseInt(id, 10));
  } else if (Array.isArray(variantIds)) {
    defaultVariantIds.value = variantIds.map(id => parseInt(id, 10));
  }

  setTimeout(() => {
    elementorHelper.setSettings('variant_ids', defaultVariantIds.value);
  }, 100);
}

const fetchProducts = (ids = []) => {
  const advancedFilter = [
    [
      {
        "source": [
          "variations",
          "variation_items"
        ],
        "filter_type": "relation",
        "operator": "contains",
        "value": ids,
        "column": "id",
        "relation": "variants"
      }
    ]
  ];

  let queryParams = {
    "active_view": 'publish',
    "per_page": 10,
    "page": 1,
    'filter_type': 'advanced',
    'sort_by': 'ID',
    'with': ['detail.variants.media', 'categories', 'variants.media'],
    'advanced_filters': JSON.stringify(advancedFilter)
  };

  Rest.get('products', queryParams)
      .then(response => {
        selectedVariants.value = Arr.get(response, 'products.data', []);

        handleVariationSelection(ids, selectedVariants.value);
      })
      .catch((errors) => {
        console.log(errors);
      })
      .finally(() => {

      });
}

const handleRecommendedVariantChange = (active_variant) => {
  previewSettings.value.active_variant = active_variant;
  setTimeout(() => {
    elementorHelper.setSettings('active_variant', active_variant);
  }, 100);
}

const setDefaultSettings = () => {
  if (previewSettings.value.active_tab !== '') {
    active_tab.value = previewSettings.value.active_tab;
  }

  if (previewSettings.value.variations) {
    defaultVariantIds.value = previewSettings.value.variations;
  }

  if (previewSettings.value.active_variant) {
    active_variant.value = previewSettings.value.active_variant;
  }

  if (previewSettings.value.editor_active_tab) {
    activeName.value = previewSettings.value.editor_active_tab;
  }

  setDefaultVariants();
}

const setDefaultVariants = () => {
  setTimeout(() => {
    if (defaultVariantIds.value.length !== '') {
      let formattedVariantIds = [];
      if (typeof defaultVariantIds.value === 'string') {
        formattedVariantIds = defaultVariantIds.value.split(',').map(id => parseInt(id, 10));
      } else if (Array.isArray(defaultVariantIds.value)) {
        formattedVariantIds = defaultVariantIds.value.map(id => parseInt(id, 10));
      }
      fetchProducts(formattedVariantIds);
    }
  }, 100);
}

const handleTabChange = (index) => {
  active_tab.value = index;

  setTimeout(() => {
    elementorHelper.setSettings('active_tab', active_tab.value);
  }, 100);
}

const handleEditorTabChange = (name) => {

  setTimeout(() => {
    elementorHelper.setSettings('editor_active_tab', name);
  }, 100);
}


onBeforeMount(() => {
  elementorHelper = useElementor();
})
onMounted(() => {
  previewSettings.value = JSON.parse(props.settings);
  setDefaultSettings();
})
</script>


<template>

  <el-tabs v-model="activeName" class="demo-tabs" @tab-change="handleEditorTabChange">
    <el-tab-pane :label="translate('Editor')" name="editor"></el-tab-pane>
    <el-tab-pane :label="translate('Preview')" name="preview"></el-tab-pane>
  </el-tabs>
  <div v-if="activeName === 'preview'">
    <ServerSidePreview block="fluent-cart/product-pricing-table" :attributes="previewSettings"/>
  </div>

  <div v-else class="fluent-cart-pricing-table-block-editor">
    <div class="fluent-cart-pricing-table-block-editor-head">
      <AddProductItemModal :isMultiple="true" :selectedIds="defaultVariantIds" @onVariationSelectionUpdated="handleVariationSelection"/>
    </div>

    <div class="fluent-cart-pricing-table-block-editor-body">
      <div v-if="tabContent.length > 0 && Object.keys(tabContent[active_tab]?.content || {}).length > 0"
           class="fct-custom-tabs">
        <!-- Tab Headers -->
        <div v-if="tabContent.length > 1" class="fct-tab-header">
          <el-button
              v-for="(tab, index) in tabContent"
              :key="index"
              :class="{ 'is-primary': active_tab == index , ' components-button': true}"
              @click="handleTabChange(index)"
          >
            {{ tab.title }}
          </el-button>
        </div>

        <!-- Tab Content -->
        <div class="fct-tab-content">
          <div v-if="tabContent[active_tab]" class="fct-tab-content-inner">
            <!-- Recommended Variant Selector -->
            <div class="fct-active-product-select-container">
              <div class="fct-form-group">
                <label>{{ translate('Recommended variant') }}</label>
                <el-select
                    :key="tabContent[active_tab]?.title || ''"
                    v-model="active_variant[tabContent[active_tab]?.title || '']"
                    @change="(value) => {
                      active_variant = {
                        ...active_variant,
                        [tabContent[active_tab]?.title || '']: value
                      }
                      handleRecommendedVariantChange(active_variant);
                    }"
                >
                  <el-option
                      v-for="key in Object.keys(tabContent[active_tab]?.content || {})"
                      :key="key"
                      :value="key"
                      :label="tabContent[active_tab]?.content[key]?.variation_title || key"
                  >
                  </el-option>
                </el-select>
              </div>
            </div>

            <!-- Selected Products Container -->
            <div class="fct-selected-products-container">
              <div
                  v-for="key in Object.keys(tabContent[active_tab]?.content || {})"
                  :key="`variations-${tabContent[active_tab]?.content[key]?.id}`"
                  class="fct-selected-product"
              >
                <!-- Toggle Button -->
                <div
                    :class="['fct-selected-product-toggle', { 'is-expanded': expandedProducts[key] }]"
                    @click="toggleProductDescription(key)"
                >
                  <div class="icon">
                    <!-- Replace with your icon component -->
                    <CaretRight class="w-3 h-3"/>
                  </div>
                </div>

                <!-- Product Contents -->
                <div class="fct-selected-product-contents">
                  <div class="product-title" :title="tabContent[active_tab]?.content[key]?.variation_title">
                    {{ tabContent[active_tab]?.content[key]?.variation_title }}
                  </div>
                  <div class="product-id">#{{ key }}</div>
                  <div :class="['product-textarea', { 'is-expanded': expandedProducts[key] }]">
                    <div class="product-textarea-inner">
                      <textarea
                          :placeholder="translate('Variants features...')"
                          :value="tabContent[active_tab]?.content[key]?.other_info?.description || ''"
                          @blur="(event) => {
                            const variation = tabContent[active_tab]?.content[key];
                            if (variation) {
                              variation.other_info.description = event.target.value.replace(/\r?\n/g, '\n');
                              updateVariationData(key, variation);
                            }
                          }"
                      ></textarea>
                      <div class="hint-text">
                        <strong>{{ translate('Hint: ') }}</strong>
                        {{ translate('Use Enter to add line breaks for a list format') }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Delete Button -->
                <div
                    class="product-delete-icon"
                    @click="deleteVariation(key)"
                    :title="translate('Delete')"
                    style="cursor: pointer;"
                >
                  <Delete class="w-4 h-4" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-else>
        <!-- Empty state -->
        <div>{{ translate('Find products to make a pricing table') }}</div>
      </div>
    </div>
  </div>
</template>

<style>

</style>

