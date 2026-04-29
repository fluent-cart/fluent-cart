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
import {ref} from "vue";

const props = defineProps({
  productTable: {
    type: Object,
    required: true,
  }
});

const emit = defineEmits(['delete', 'duplicate', 'selectionChange']);

const elTableRef = ref(null);

const handleProductCommand = (command) => {
  if (command.action === 'delete') {
    /* translators: %s - product title */
    $confirm(translate("Are you sure you want to delete this product : %s ?", command.product.post_title), translate("Confirm Delete!"),
        {
          confirmButtonText: translate("Yes, Delete!"),
          cancelButtonText: translate("Cancel"),
          type: 'warning'
        }
    ).then(() => {
      emit('delete', command.product.ID);
    }).catch((errors) => {
      console.log(errors, ' handle product delete errors');
    });
  } else if (command.action === 'duplicate') {
    emit('duplicate', command.product);
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
      Str.headline(type)
  }
}

const getVariationTypeText = (type) => {

  switch (type) {
    case 'simple':
      return translate('Simple');
    case 'simple_variations':
      return translate('Simple Variations');
    default:
      Str.headline(type)
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
  <el-table
      ref="elTableRef"
      :data="productTable.getTableData()"
      class="w-full compact-table"
      @selection-change="handleSelectionChange"
  >
    <el-table-column type="selection" width="45"/>

    <el-table-column :label="translate('Products')" width="380">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <ProductInfo :product="scope.row" class="fct-product-table-product-info"/>
        </RouteCell>
        <div class="fct-product-action show-on-mobile absolute right-0 top-1">
          <el-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              popper-class="fct-dropdown"
              @command="handleProductCommand"
              placement="bottom-end"
          >
                  <span class="more-btn pr-3">
                    <DynamicIcon name="More"/>
                  </span>

            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item class="item-link" v-if="scope.row.ID">
                  <router-link
                      :to="{
                        name: 'product_edit',
                        params: {
                          product_id: scope.row.ID
                        }
                      }"
                  >
                    <DynamicIcon name="Edit"/>
                    {{ translate('Edit') }}
                  </router-link>
                </el-dropdown-item>

                <el-dropdown-item
                    :command="{
                      action: 'duplicate',
                      product: scope.row
                    }"
                >
                  <DynamicIcon name="Duplicate"/>
                  {{ translate('Duplicate') }}
                </el-dropdown-item>

                <el-dropdown-item
                    :command="{
                      action: 'delete',
                      product: scope.row
                    }"
                    class="item-destructive"
                >
                  <DynamicIcon name="Delete"/>
                  {{ translate('Delete') }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>

      </template>
    </el-table-column>

    <el-table-column v-if="productTable.isColumnVisible('product_type')" :label="translate('Type')"
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
    </el-table-column>

    <el-table-column v-if="productTable.isColumnVisible('variation_type')" :label="translate('Variation')"
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
    </el-table-column>

    <el-table-column v-if="productTable.isColumnVisible('item_price')" :label="translate('Price')"
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
    </el-table-column>

    <el-table-column v-if=" productTable.isColumnVisible('stock_availability')" :label="translate('Stock')"
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
    </el-table-column>

    <el-table-column v-if="productTable.isColumnVisible('post_status')" :label="translate('Status')"
                     width="130">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <div class="flex items-center gap-0.5">

            <Badge :key="scope.row.ID"
                   :status="scope.row.post_status === 'future' ? 'scheduled' : scope.row.post_status"/>
            <el-tooltip
                v-if="scope.row.post_status === 'future'"
                effect="dark"
                placement="top"
                popper-class="fct-label-hint-popover fct-tooltip"
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
            </el-tooltip>
          </div>
        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column v-if="productTable.isColumnVisible('post_date')" :label="translate('Date')" width="120">
      <template #default="scope">
        <RouteCell class="hover:no-underline"
                   :to="{ name: 'product_edit', params: { product_id: scope.row.ID } }">
          <ConvertedTime :date-time="scope.row.post_date" :with-time="false"/>
        </RouteCell>
      </template>

    </el-table-column>

    <el-table-column v-for="column of productTable.getCustomColumns()" :label="column.label">
      <template #default="scope">
        <div v-if="column.render_template">
          <component
              :is="CustomColumnRenderer"
              :templateContent="column.template"
              :data="{
                column,
                product: scope.row,
                index: scope.$index,
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

    </el-table-column>

    <el-table-column :label="translate('Actions')" width="80" align="right">
      <template #default="scope">
        <span class="fct-product-id hidden" title="Product ID">ID: {{ scope.row.ID }}</span>
        <el-dropdown
            trigger="click"
            class="fct-more-option-wrap"
            popper-class="fct-dropdown"
            @command="handleProductCommand"
            placement="bottom-end"
        >
        <span class="more-btn pr-3">
          <DynamicIcon name="More"/>
        </span>

          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item class="item-link" v-if="scope.row.ID">
                <router-link
                    :to="{
                      name: 'product_edit',
                      params: {
                        product_id: scope.row.ID
                      }
                    }"
                >
                  <DynamicIcon name="Edit"/>
                  {{ translate('Edit') }}
                </router-link>
              </el-dropdown-item>

              <el-dropdown-item
                  :command="{
                    action: 'duplicate',
                    product: scope.row
                  }"
              >
                <DynamicIcon name="Duplicate"/>
                {{ translate('Duplicate') }}
              </el-dropdown-item>

              <el-dropdown-item
                  :command="{
                    action: 'delete',
                    product: scope.row
                  }"
                  class="item-destructive"
              >
                <DynamicIcon name="Delete"/>
                {{ translate('Delete') }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>
    </el-table-column>

    <template #empty>
      <Empty icon="Empty/ListView" :has-dark="true"
             :text="productTable.emptyMessage"/>
    </template>
  </el-table>
</template>

<style scoped>

</style>
