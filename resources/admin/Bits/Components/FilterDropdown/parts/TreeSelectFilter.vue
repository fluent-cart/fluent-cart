<template>
  <el-tree-select
      :placeholder="$t('Select Products')"
      v-model="filter.data.variation_ids"
      :data="products"
      :filter-method="onFilter"
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

// Searches are fired per keystroke and can resolve out of order, so only the
// most recently issued one may write to products or clear loading.
let latestRequest = 0;

const searchVariantByName = (name) => {
  const productVariations = Storage.get('product_variations');

  if (productVariations) {
    cachedProducts.value = productVariations;
  }

  // Claimed before the early return so that restoring the cached list also
  // invalidates a search still in flight — otherwise clearing the box would let
  // that response land on top of the full list a moment later.
  const requestId = ++latestRequest;

  if (cachedProducts.value.length && !name) {
    products.value = [...cachedProducts.value];
    loading.value = false;
    return;
  }

  // The cache only holds the first page of products, so a search term must
  // always reach the server — filtering the cache here hid every product that
  // was not already in the dropdown.
  loading.value = true;

  selfRef
      .$get("products/searchVariantByName", { name })
      .then((response) => {
        if (requestId !== latestRequest) {
          return;
        }

        products.value = response;

        // Only the unfiltered list may be cached — caching a search result
        // would make the next empty term render that narrow result as "all".
        if (!name) {
          cachedProducts.value = response;
          Storage.set('product_variations', response);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (requestId === latestRequest) {
          loading.value = false;
        }
      });
};

// Built once, not in the template: calling Utils.debounce() during render
// returns a new function with its own timer on every re-render, so no keystroke
// ever cancelled the one before it.
const onFilter = Utils.debounce(searchVariantByName);

const onCheck = (value, info) => {
  const checkedNodes = info.checkedNodes || [];

  // A checked variant only knows its own title, so the owning product is taken
  // from the payload: fully ticked products arrive in checkedNodes, partly
  // ticked ones in halfCheckedNodes. Without this the tag has nothing to group
  // by once a search drops that product from the visible tree.
  const parents = {};
  [...checkedNodes, ...(info.halfCheckedNodes || [])].forEach(node => {
    (node.children || []).forEach(variation => {
      parents[variation.value] = node.label;
    });
  });

  filter.setTreeSelectItem(
    checkedNodes.map(node => ({...node, productName: parents[node.value]}))
  );
};

const onRemove = (removedValue) => {
  filter.removeTreeSelectItem(removedValue);
};

const onClear = () => {
  filter.clearTreeSelect();
};

const filteredProducts = computed(() => {
  // Read the selection itself, not the visible list — a product dropped by the
  // current search (or never loaded by this instance) still has to show a name.
  const selectedItems = filter.data.selectedFilters.variation_ids?.value || [];

  let formattedItems = {};

  selectedItems.forEach(item => {
      const productName = item.productName || 'Unknown';

      if (!formattedItems[productName]) {
          formattedItems[productName] = [];
      }
      formattedItems[productName].push(item.label || item.id);
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
