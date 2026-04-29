<script setup>
import useProductTable from "@/utils/table-new/ProductTable";
import useOrderTable from "@/utils/table-new/OrderTable";
import useCustomerTable from "@/utils/table-new/CustomerTable";
import useCouponTable from "@/utils/table-new/CouponTable";
import {onBeforeMount, ref} from "vue";
import translate from "@/utils/translator/Translator";
import NavigableList from "@/Bits/Components/NavigableList.vue";
import {useRouter} from 'vue-router';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

import Str from "@/utils/support/Str";
import {formatNumber} from "@/Bits/productService";
import Product from "@/Modules/GlobalSearch/ListItems/Product.vue";
import Order from "@/Modules/GlobalSearch/ListItems/Order.vue";
import Customer from "@/Modules/GlobalSearch/ListItems/Customer.vue";
import Coupon from "@/Modules/GlobalSearch/ListItems/Coupon.vue";
import Utils from "@/utils/Utils";

// Add this import


const emit = defineEmits(['close-modal', 'onRemoteSearchStateChange']);

const router = useRouter();
const tag = ref({});
const search = ref('');
const navigableList = ref();
const selectedSearchingFor = ref({});


const results = ref([]);

const onDataLoaded = (response) => {
  loading.value = false;
  results.value = model.data.tableData;
  emit('onRemoteSearchStateChange', false);
}

const loading = ref(false);
const modelMap = {
  'orders': useOrderTable({
    fetch: false
  }),
  'products': useProductTable({
    fetch: false
  }),
  'customers': useCustomerTable({
    fetch: false
  }),
  'coupons': useCouponTable({
    fetch: false
  })
};


const resetSelection = () => {
  //navigableList.value?.resetSelection();
}

const focusList = () => {
  navigableList.value?.focusList(0);

};

const performFirstAction = () => {
  navigableList.value?.performFirstAction();
}

onBeforeMount(() => {
  for (const key of Object.keys(modelMap)) {
    modelMap[key].onDataLoaded(onDataLoaded);
  }
})

let model = null;

const loadData = () => {
  model = modelMap[tag.value.key];
  model.data.search = search.value;
  loading.value = true;
  model.fetch();
  emit('onRemoteSearchStateChange', true);
}


const loadDataDebounce = Utils.debounce(loadData, 800);

const fetchData = (query, selectedTag) => {
  //reset
  results.value = [];
  if (model) {
    model.data.search = '';
  }

  tag.value = selectedTag;

  selectedSearchingFor.value = {
    title: selectedTag.title,
    key: selectedTag.key
  };

  if (!query) {
    search.value = '';
    return;
  }

  search.value = query;

  //fetch
  loadDataDebounce();

}

const handleViewAll = () => {
  router.push({
    name: selectedSearchingFor.value.key,
    query: {
      search: search.value
    }
  });
  // Emit an event to close the modal
  emit('close-modal');
}

const singleRouteMap = {
  'orders': {
    name: 'view_order',
    key: 'order_id'
  },
  'products': {
    name: 'product_edit',
    key: 'product_id'
  },
  'customers': {
    name: 'view_customer',
    key: 'customer_id'
  },
  'coupons': {
    name: 'edit_coupon',
    key: 'coupon_id'
  },
}

const handleAction = (item) => {
  if (tag.value.key in singleRouteMap) {
    router.push({
      name: singleRouteMap[tag.value.key].name,
      params: {
        [singleRouteMap[tag.value.key].key]: item.id || item.ID,
        action: 'edit'
      }
    });
    emit('close-modal');
  }
}

defineExpose({
  fetchData, focusList, resetSelection, performFirstAction
})



</script>

<template>
  <div class="fct-global-remote-result-container">

    <div class="fct-global-remote-result-label">
      <span class="text">{{ tag.title }}</span>
      <span v-if="selectedSearchingFor.key" class="view-all" @click="handleViewAll">
        {{
          search ?
              /* translators: %1$s - search term, %2$s - search in */
              translate('Search "%1$s" in %2$s', search, selectedSearchingFor.title) :
              /* translators: %s is the search term */
              translate('See All %s', selectedSearchingFor.title)
        }}
      </span>
    </div>

    <NavigableList
        :items="results"
        :on-action="(item)=>{
          handleAction(item);
        }"
        :no-results-text="search ?
          /* translators: %s is the search term */
          translate('No matches found for %s',tag.title)
          :
          /* translators: %s is the search term */
          translate('Type something to find %s',tag.title)"
        ref="navigableList"
    >
      <template #default="{ item, index, isSelected }">

        <div class="navigable-content">
          <template v-if="tag.key === 'products'">
            <Product :product="item"/>
          </template>

          <template v-if="tag.key === 'orders'">
            <Order :order="item"/>
          </template>

          <template v-if="tag.key === 'customers'">
            <Customer :customer="item"/>
          </template>
          <template v-if="tag.key === 'coupons'">
            <Coupon :coupon="item"/>
          </template>

        </div>
        <div class="action-icon">
          <DynamicIcon name="Enter"/>
        </div>
      </template>
    </NavigableList>

  </div>
</template>

<style scoped>

</style>
