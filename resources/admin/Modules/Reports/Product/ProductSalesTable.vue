<script setup>
import {computed, ref, watch} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import translate, {_x, pluralizeTranslate} from "@/utils/translator/Translator";
import ExportReport from "../ExportReport.vue";
import {formatNumber} from "../Utils/formatNumber";

const props = defineProps({
  products: {type: Array, default: () => []},
  totalNet: {type: Number, default: 0},
  loading: {type: Boolean, default: false},
  reportFilter: {type: Object, required: true},
});

const emit = defineEmits(['open']);

const search = ref('');
const sort = ref({prop: 'net', order: 'descending'});
const page = ref(1);
const perPage = 20;

/* translators: %1$s - number of variants */
const variantCount = (count) => pluralizeTranslate('%1$s variant', '%1$s variants', formatNumber(count));
const money = (value) => props.reportFilter.formatAmount(value || 0, {cents: true});
const share = (net) => props.totalNet > 0 ? Math.round(net / props.totalNet * 1000) / 10 : 0;
const hasCompare = computed(() => props.products.some(product => product.previous_net !== null && product.previous_net !== undefined));
const hasInstallments = computed(() => props.products.some(product => product.installment_net));

// null: no previous sales to compare with
const change = (product) => {
  if (!product.previous_net) {
    return null;
  }
  return Math.round((product.net - product.previous_net) / Math.abs(product.previous_net) * 1000) / 10;
};

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) {
    return props.products;
  }
  return props.products.filter(product => product.product_title.toLowerCase().includes(term)
    || product.variations.some(variation => variation.variation_title.toLowerCase().includes(term)));
});

// Sorted before paging, so a page shows the next rows of the whole list
const sorted = computed(() => {
  const {prop, order} = sort.value;
  if (!prop || !order) {
    return filtered.value;
  }
  const value = (product) => prop === 'change' ? (change(product) ?? -Infinity) : (product[prop] ?? 0);
  const direction = order === 'ascending' ? 1 : -1;
  return [...filtered.value].sort((a, b) => (value(a) - value(b)) * direction || a.product_id - b.product_id);
});

const rows = computed(() => sorted.value.slice((page.value - 1) * perPage, page.value * perPage));

watch([search, () => props.products], () => {
  page.value = 1;
});
// The Change column goes when the comparison is off; its sort must not stay behind
watch(hasCompare, (value) => {
  if (!value && sort.value.prop === 'change') {
    sort.value = {prop: 'net', order: 'descending'};
  }
});

const onSort = ({prop, order}) => {
  sort.value = {prop, order};
  page.value = 1;
};

const exportRows = computed(() => sorted.value.flatMap(product => [product, ...product.variations.map(variation => ({
  ...variation, product_title: product.product_title,
}))]).map(row => ({
  product: row.product_title,
  variant: row.variation_title || '',
  net_sales: (row.net / 100).toFixed(2),
  new_sales: (row.new_net / 100).toFixed(2),
  renewals: (row.renewal_net / 100).toFixed(2),
  installment_payments: (row.installment_net / 100).toFixed(2),
  refunds: (row.refunds / 100).toFixed(2),
  refund_rate: row.refund_rate,
  units: row.units,
  orders: row.orders,
  new_customers: row.new_customers,
})));
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header
        :title="translate('Products')"
        :text="translate('ProductRpt_Net_Sales')"
        title_size="small"
        border_bottom
    >
      <template #action>
        <div class="flex items-center gap-2">
          <fct-input
              v-model="search"
              size="small"
              clearable
              style="width: 220px"
              :placeholder="translate('Search products')"
              :aria-label="translate('Search products')"
          />
          <ExportReport v-if="exportRows.length" :data="exportRows" filename="products.csv"/>
        </div>
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <fct-skeleton v-if="loading" animated :rows="6" class="px-4 pt-4 pb-4"/>
      <template v-else>
        <fct-table
            :data="rows"
            row-key="product_id"
            :default-sort="{prop: 'net', order: 'descending'}"
            @sort-change="onSort"
        >
          <fct-table-column type="expand" width="40">
            <template #default="{row}">
              <fct-table :data="row.variations" class="fct-product-variants" :show-header="true">
                <fct-table-column :label="translate('Variant')" min-width="220">
                  <template #default="{row: variation}">
                    {{ variation.variation_title }}
                    <span class="text-gray-500 text-xs">(#{{ variation.variation_id }})</span>
                  </template>
                </fct-table-column>
                <fct-table-column :label="translate('Net sales')" min-width="120">
                  <template #default="{row: variation}">{{ money(variation.net) }}</template>
                </fct-table-column>
                <fct-table-column :label="translate('New sales')" min-width="120">
                  <template #default="{row: variation}">{{ money(variation.new_net) }}</template>
                </fct-table-column>
                <fct-table-column :label="translate('Renewals')" min-width="120">
                  <template #default="{row: variation}">{{ money(variation.renewal_net) }}</template>
                </fct-table-column>
                <fct-table-column v-if="hasInstallments" :label="translate('Installment payments')" min-width="130">
                  <template #default="{row: variation}">{{ money(variation.installment_net) }}</template>
                </fct-table-column>
                <fct-table-column :label="translate('Units')" min-width="80">
                  <template #default="{row: variation}">{{ formatNumber(variation.units) }}</template>
                </fct-table-column>
                <fct-table-column :label="translate('Orders')" min-width="80">
                  <template #default="{row: variation}">{{ formatNumber(variation.orders) }}</template>
                </fct-table-column>
                <fct-table-column :label="translate('New customers')" min-width="120">
                  <template #default="{row: variation}">{{ formatNumber(variation.new_customers) }}</template>
                </fct-table-column>
                <fct-table-column :label="translate('Refund rate')" min-width="100">
                  <template #default="{row: variation}">{{ variation.refund_rate }}%</template>
                </fct-table-column>
              </fct-table>
            </template>
          </fct-table-column>
          <fct-table-column prop="product_title" :label="translate('Product')" min-width="200">
            <template #default="{row}">
              <button type="button" class="fct-link-button p-0 bg-transparent border-0 cursor-pointer text-left text-primary-500 hover:underline" @click="emit('open', row)">
                {{ row.product_title }}
              </button>
              <div class="text-gray-500 text-xs">
                {{ variantCount(row.variations.length) }}
              </div>
            </template>
          </fct-table-column>
          <fct-table-column prop="net" :label="translate('Net sales')" min-width="125" sortable="custom">
            <template #default="{row}">
              <div>{{ money(row.net) }}</div>
              <!-- translators: %1$s - a percentage -->
              <div class="text-gray-500 text-xs">{{ translate('%1$s of sales', share(row.net) + '%') }}</div>
            </template>
          </fct-table-column>
          <fct-table-column v-if="hasCompare" prop="change" :label="_x('Change', 'Report column: difference from the previous period')" min-width="95" sortable="custom">
            <template #default="{row}">
              <span v-if="change(row) === null" class="text-gray-500">{{ row.net > 0 ? translate('New') : '—' }}</span>
              <span v-else :class="change(row) >= 0 ? 'text-success-500' : 'text-red-500'" dir="ltr">
                {{ change(row) > 0 ? '+' : '' }}{{ change(row) }}%
              </span>
            </template>
          </fct-table-column>
          <fct-table-column prop="new_net" :label="translate('New sales')" min-width="120" sortable="custom">
            <template #default="{row}">{{ money(row.new_net) }}</template>
          </fct-table-column>
          <fct-table-column prop="renewal_net" :label="translate('Renewals')" min-width="120" sortable="custom">
            <template #default="{row}">{{ money(row.renewal_net) }}</template>
          </fct-table-column>
          <fct-table-column v-if="hasInstallments" prop="installment_net" :label="translate('Installment payments')" min-width="130" sortable="custom">
            <template #default="{row}">{{ money(row.installment_net) }}</template>
          </fct-table-column>
          <fct-table-column prop="units" :label="translate('Units')" min-width="85" sortable="custom">
            <template #default="{row}">{{ formatNumber(row.units) }}</template>
          </fct-table-column>
          <fct-table-column prop="orders" :label="translate('Orders')" min-width="90" sortable="custom">
            <template #default="{row}">{{ formatNumber(row.orders) }}</template>
          </fct-table-column>
          <fct-table-column prop="new_customers" :label="translate('New customers')" min-width="125" sortable="custom">
            <template #default="{row}">{{ formatNumber(row.new_customers) }}</template>
          </fct-table-column>
          <fct-table-column prop="refund_rate" :label="translate('Refund rate')" min-width="110" sortable="custom">
            <template #default="{row}">{{ row.refund_rate }}%</template>
          </fct-table-column>
          <template #empty>
            <el-empty :description="search ? translate('No products match this search') : translate('No sales in this date range')"/>
          </template>
        </fct-table>
        <div v-if="sorted.length > perPage" class="flex justify-end px-4 py-3">
          <el-pagination
              v-model:current-page="page"
              layout="prev, pager, next"
              :page-size="perPage"
              :total="sorted.length"
              size="small"
          />
        </div>
      </template>
    </Card.Body>
  </Card.Container>
</template>
