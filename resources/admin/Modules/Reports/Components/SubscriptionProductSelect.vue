<script setup>
import {computed, onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import reportFilter from "@/Models/Reports/ReportFilterModel";

// Variants that have subscriptions; they do not change with the page's filters
const products = ref([]);
const loading = ref(false);

const selected = computed({
  get: () => reportFilter.data.subscriptionVariationIds,
  set: (ids) => reportFilter.setSubscriptionVariations(ids),
});

onMounted(() => {
  loading.value = true;
  Rest.get('reports/subscription-variations')
    .then(response => {
      products.value = response.products || [];
      // A saved pick the list no longer offers would filter the pages without showing
      const offered = products.value.flatMap(product => product.variations.map(variation => variation.id));
      const kept = selected.value.filter(id => offered.includes(id));
      if (kept.length !== selected.value.length) {
        selected.value = kept;
      }
    })
    .catch(error => Notify.error(error))
    .finally(() => {
      loading.value = false;
    });
});
</script>

<template>
  <el-select
      v-model="selected"
      multiple
      collapse-tags
      collapse-tags-tooltip
      clearable
      filterable
      size="small"
      style="width: 260px"
      :loading="loading"
      :placeholder="translate('All products')"
      :aria-label="translate('Filter by product')"
  >
    <el-option-group v-for="product in products" :key="product.product_id" :label="product.product_title">
      <el-option v-for="variation in product.variations" :key="variation.id" :label="variation.title" :value="variation.id"/>
    </el-option-group>
  </el-select>
</template>
