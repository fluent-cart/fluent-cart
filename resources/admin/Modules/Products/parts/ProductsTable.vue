<script setup>

import translate, {translateNumber} from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";
import Arr from "@/utils/support/Arr";
import {$confirm, formatDate} from "@/Bits/common";
import Badge from "@/Bits/Components/Badge.vue";
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import {formatNumber} from "@/Bits/productService";
import CustomColumnRenderer from "@/Bits/Components/CustomColumnRenderer.vue";
import AppConfig from "@/utils/Config/AppConfig";
import {ref} from "vue";
import {useRouter} from "vue-router";
import StarRating from "@/Bits/Components/StarRating.vue";

// A stored column preference may still name 'reviews'; the column is not
// shown while the reviews module is off, whatever the preference says.
const reviewsModuleActive = AppConfig.get('modules_settings.reviews.active') === 'yes';

const props = defineProps({
  productTable: {
    type: Object,
    required: true,
  }
});

const emit = defineEmits(['delete', 'duplicate', 'selectionChange']);

const router = useRouter();
const elTableRef = ref(null);

const productActions = (product) => [
  ...(product.ID ? [{command: 'edit', label: translate('Edit'), icon: 'Edit', href: router.resolve({name: 'product_edit', params: {product_id: product.ID}}).href}] : []),
  {command: 'duplicate', label: translate('Duplicate'), icon: 'Duplicate'},
  {command: 'delete', label: translate('Delete'), icon: 'Delete', danger: true},
];

const handleProductCommand = (command, product) => {
  if (command === 'delete') {
    /* translators: %s - product title */
    $confirm(translate("Are you sure you want to delete this product : %s ?", product.post_title), translate("Confirm Delete!"),
        {
          confirmButtonText: translate("Yes, Delete!"),
          cancelButtonText: translate("Cancel"),
          type: 'warning'
        }
    ).then(() => {
      emit('delete', product.ID);
    }).catch((errors) => {
    });
  } else if (command === 'duplicate') {
    emit('duplicate', product);
  }
}


const getPrice = (detail) => {
  if (detail.min_price === detail.max_price) {
    return formatNumber(detail.min_price, true);
  }
  return `${formatNumber(detail.min_price, true)} - ${formatNumber(detail.max_price, true)}`;
}

const getProductTypeText = (type) => {
  switch (type) {
    case 'physical':
      return translate('Physical');
    case 'digital':
      return translate('Digital');
    case 'bundle':
      return translate('Bundle');
    default:
      return Str.headline(type);
  }
}

const getVariationTypeText = (type) => {
  switch (type) {
    case 'simple':
      return translate('Simple');
    case 'simple_variations':
      return translate('Simple Variations');
    case 'advanced_variations':
      return translate('Advanced Variations');
    default:
      return type ? Str.headline(type) : '';
  }
}

const handleSelectionChange = (selectedRows) => {
  emit('selectionChange', selectedRows);
}

const clearSelection = () => {
  elTableRef.value?.clearSelection?.();
}

defineExpose({
  clearSelection
});

</script>

<template>
  <fct-table
      ref="elTableRef"
      :data="productTable.getTableData()"
      class="w-full compact-table"
      @selection-change="handleSelectionChange"
  >
    <fct-table-column type="selection" width="45"/>

    <fct-table-column :label="translate('Products')" width="380">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <ProductInfo :product="scope.row" class="fct-product-table-product-info"/>
        </RouteCell>
        <div class="fct-product-action show-on-mobile absolute right-0 top-1">
          <fct-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              :items="productActions(scope.row)"
              @command="(command) => handleProductCommand(command, scope.row)"
              placement="bottom-end"
          >
                  <span class="more-btn pr-3">
                    <DynamicIcon name="More"/>
                  </span>
          </fct-dropdown>
        </div>

      </template>
    </fct-table-column>

    <fct-table-column v-if="productTable.isColumnVisible('product_type')" :label="translate('Type')"
                     width="170">
      <template #default="scope">

        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <div class="flex items-center gap-2">
            <span v-if="scope.row.detail?.other_info?.is_bundle_product === 'yes'">{{ translate('Bundle') }} - </span>
            <span v-if="scope.row.detail">
              {{
                getProductTypeText(scope.row.detail.fulfillment_type)
              }}
            </span>
            <span v-else>{{ translate('N/A') }}</span>
          </div>
        </RouteCell>

      </template>
    </fct-table-column>

    <fct-table-column v-if="productTable.isColumnVisible('variation_type')" :label="translate('Variation')"
                     width="150">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">

          <span v-if="scope.row.detail">
            {{ getVariationTypeText(scope.row.detail.variation_type) }}
          </span>
          <span v-else>
            {{ 'N/A' }}
          </span>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column v-if="productTable.isColumnVisible('item_price')" :label="translate('Price')"
                     width="130">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <span v-if="scope.row.detail">
            {{ getPrice(scope.row.detail) }}
          </span>
          <span v-else>{{ translate('Set Price') }}</span>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column v-if=" productTable.isColumnVisible('stock_availability')" :label="translate('Stock')"
                     width="100">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <span v-if="scope.row.detail">
            <template v-if="scope.row.detail.manage_stock.toString() === '0'">
              {{ translate('Unlimited') }}
            </template>
            <template v-else>
              {{ translateNumber(Arr.sum(scope.row.variants, 'available')) }}
            </template>
          </span>
          <span v-else>{{ '' }}</span>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column v-if="productTable.isColumnVisible('post_status')" :label="translate('Status')"
                     width="130">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <div class="flex items-center gap-0.5">

            <Badge :key="scope.row.ID"
                   :status="scope.row.post_status === 'future' ? 'scheduled' : scope.row.post_status"/>
            <fct-tooltip
                v-if="scope.row.post_status === 'future'"
                popper-class="fct-label-hint-popover"
                :show-after="400"
                :hide-after="0"
            >
              <template #content>
                {{
                  /* translators: %s is the post date */
                  translate('Publishes On: %s', formatDate(scope.row.post_date, true))
                }}
              </template>
              <DynamicIcon name="InformationFill" class="w-4 h-4 flex-none"/>
            </fct-tooltip>
          </div>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column v-if="productTable.isColumnVisible('post_date')" :label="translate('Date')" width="100">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <ConvertedTime :date-time="scope.row.post_date" :with-time="false"/>
        </RouteCell>
      </template>

    </fct-table-column>

    <fct-table-column v-if="reviewsModuleActive && productTable.isColumnVisible('reviews')" :label="translate('Reviews')"
                     width="130">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{ name: 'reviews', query: { post_id: scope.row.ID } }"
            :aria-label="translate('View reviews for %s', scope.row.post_title)"
        >
          <span class="flex items-center gap-1.5">
            <StarRating :rating="scope.row.avg_rating || 0" size="small"/>
            <span class="text-xs text-gray-500">({{ translateNumber(scope.row.reviews_count || 0) }})</span>
          </span>
        </RouteCell>
      </template>
    </fct-table-column>

    <fct-table-column v-for="column of productTable.getCustomColumns()" :label="column.label">
      <template #default="scope">
        <div v-if="column.render_template">
          <component
              :is="CustomColumnRenderer"
              :templateContent="column.template"
              :data="{
                column,
                product: scope.row,
                index: scope.index,
              }"
          />
        </div>
        <div v-else>
          <RouteCell v-if="column.as_link !== false" class="hover:no-underline"
                     :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
            <div v-html="Arr.get(scope.row, column.accessor)"/>
          </RouteCell>

          <div v-else v-html="Arr.get(scope.row, column.accessor)"/>
        </div>
      </template>

    </fct-table-column>

    <fct-table-column :label="translate('Actions')" width="90" align="right">
      <template #default="scope">
        <span class="fct-product-id hidden" title="Product ID">ID: {{ scope.row.ID }}</span>
        <fct-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              :items="productActions(scope.row)"
              @command="(command) => handleProductCommand(command, scope.row)"
              placement="bottom-end"
          >
        <span class="more-btn pr-3">
          <DynamicIcon name="More"/>
        </span>
          </fct-dropdown>
      </template>
    </fct-table-column>

    <template #empty>
      <Empty icon="Empty/ListView" :has-dark="true"
             :text="productTable.emptyMessage"/>
    </template>
  </fct-table>
</template>

<style scoped>

</style>
