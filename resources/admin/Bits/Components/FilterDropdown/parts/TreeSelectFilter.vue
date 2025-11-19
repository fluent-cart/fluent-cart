<template>
  <el-tree-select
      :placeholder="$t('Select Products')"
      v-model="filter.data.variation_ids"
      :data="products"
      :filter-method="Utils.debounce(searchVariantByName)"
      filterable
      show-checkbox
      multiple
      clearable
      :loading="loading"
      @check="onCheck"
      popper-class="fct-tooltip-long"
      @remove-tag="onRemove"
      @clear="onClear"
      size="small"
  >
        <template #tag>
          <div style="flex-wrap: wrap; row-gap: 4px; display: flex; align-items: center;">
              <el-tag style="margin-right: 4px;" v-for="(items, itemName) in filteredProducts">
                  {{ itemName }}
                  <span v-if="items.length"> | {{ items.join(', ') }}</span>
              </el-tag>
          </div>
        </template>
  </el-tree-select>
</template>

<script setup>
import {computed, getCurrentInstance, onMounted, ref} from "vue";
import Utils from "@/utils/Utils";
import Storage from "@/utils/Storage";

const props = defineProps({
  filterState: {
    type: Object,
    required: true,
  }
});

const filter = props.filterState;
const cachedProducts = ref([]);
const products = ref([]);
const loading = ref(false);
const selfRef = getCurrentInstance().ctx;

const searchVariantByName = (name) => {
  const productVariations = Storage.get('product_variations');

  if (productVariations) {
    cachedProducts.value = productVariations;
  }

  if (cachedProducts.value.length && !name) {
    products.value = [...cachedProducts.value];
    return;
  }

  if (name && cachedProducts.value.length) {
    products.value = cachedProducts.value.filter(product => {
      return product.label.toLowerCase().includes(name.toLowerCase());
    });
    return;
  }

  loading.value = true;

  selfRef
      .$get("products/searchVariantByName", { name })
      .then((response) => {
        cachedProducts.value = response;
        products.value = response;

        Storage.set('product_variations', response);
      })
      .catch(() => {})
      .finally(() => {
        loading.value = false;
      });
};

const onCheck = (value, info) => {
  const checkedNodes = info.checkedNodes || [];
  filter.setTreeSelectItem(checkedNodes);
};

const onRemove = (removedValue) => {
  filter.removeTreeSelectItem(removedValue);
};

const onClear = () => {
  filter.clearTreeSelect();
};

const filteredProducts = computed(() => {
  let keyedProducts = {};
  let keyedVariations = {};
  products.value.forEach(product => {
      keyedProducts[product.label] = product.children;
      product.children.forEach(variation => {
          keyedVariations[variation.value] = {
              productName: product.label,
              variationName: variation.label
          };
      });
  });

  let formattedItems = {};

  // loop through keyedVariations and format them
  filter.data.variation_ids.forEach(id => {
      if (keyedVariations[id]) {
          let variation = keyedVariations[id];
          if (!formattedItems[variation.productName]) {
              formattedItems[variation.productName] = [];
          }
          formattedItems[variation.productName].push(variation.variationName);
      } else {
          if (!formattedItems['Unknown']) {
              formattedItems['Unknown'] = [];
          }
          formattedItems['Unknown'].push(id);
      }
  });

  Object.keys(formattedItems).forEach(key => {
      if (formattedItems[key].length === keyedProducts[key]?.length) {
          formattedItems[key] = [];
      }
  });

  return formattedItems;
});

onMounted(() => {
  const savedFilters = filter.retrieveSavedReportFilters();
  if (savedFilters && savedFilters.variation_ids) {
    filter.data.variation_ids = savedFilters.variation_ids;
  }

  searchVariantByName('');
});
</script>
