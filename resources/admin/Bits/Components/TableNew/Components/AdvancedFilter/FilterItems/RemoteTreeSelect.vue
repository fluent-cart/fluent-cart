<script setup>
import {computed, onMounted, ref} from "vue";
import Utils from "@/utils/Utils";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ProductChips from "@/Bits/Components/ProductChips/ProductChips.vue";
import {groupIdsByTree} from "@/Bits/Components/ProductChips/productChips";
import {mergeProductTree} from "@/Bits/Components/ProductChips/labelIndex";

const props = defineProps({
  table: {
    type: Object,
    required: true
  },
  itemConfig: {
    type: Object,
    required: true
  }
})

const model = defineModel();
const searchQuery = ref('');

const options = ref(props.itemConfig.options ?? []);
const loading = ref(false)
const dropdownOpen = ref(false);

const isProductTree = computed(() => props.itemConfig.remote_data_key === 'product_variations');
const productCache = ref([]);

const productGroups = computed(() => {
  const ids = Array.isArray(model.value) ? model.value : [];
  return groupIdsByTree(ids, productCache.value);
});

const fetchData = async () => {

  if(searchQuery.value.toString().length === 0 && options.value.length > 0) {
    return;
  }

  loading.value = true;
  Rest.get("advance_filter/get-filter-options", {
    'search': searchQuery.value,
    'remote_data_key': props.itemConfig.remote_data_key,
    'include_ids': model.value,
    'limit': props.itemConfig.limit
  })
      .then((response) => {
        options.value = response.options;
        if (isProductTree.value) {
          productCache.value = mergeProductTree(productCache.value, response.options);
        }
      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false
      });
}

const filter = (value) => {
  searchQuery.value = value;
  fetchData()
}

// Built once: a debounce created in the template gets a new timer every render.
const debouncedFilter = Utils.debounce(filter, 500);


onMounted(() => {
  if (!(typeof options.value === 'object' && Object.values(options.value).length > 0)) {
    fetchData();
  }

});
</script>

<template>
  <div class="relative">
    <el-tree-select
        v-model="model"
        :data="options"
        multiple
        filterable
        :check-strictly="itemConfig.check_strictly || false"
        show-checkbox
        :reserve-keyword="false"
        :filter-method="debouncedFilter"
        :render-after-expand="true"
        :clearable="isProductTree"
        :class="isProductTree ? ['fct-advanced-product-tree', { 'is-tree-open': dropdownOpen }] : []"
        size="small"
        @visible-change="(open) => dropdownOpen = open"
        @change="()=>{
          table.applyAdvancedFilter();
        }"
    >
      <template v-if="isProductTree" #tag>
        <ProductChips :groups="productGroups" :tooltips-disabled="dropdownOpen"/>
      </template>
    </el-tree-select>
    <div class="absolute top-0 left-0 w-full h-full " v-if="loading">
      <div
          v-loading="true" class="h-full w-full">
      </div>
    </div>
  </div>
</template>
