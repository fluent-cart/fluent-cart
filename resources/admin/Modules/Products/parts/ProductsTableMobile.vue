<script setup>
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {$confirm, formatDate} from "@/Bits/common";
import Str from "@/utils/support/Str";
import {formatNumber} from "@/Bits/productService";
import Arr from "@/utils/support/Arr";
import Badge from "@/Bits/Components/Badge.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import CustomColumnRenderer from "@/Bits/Components/CustomColumnRenderer.vue";

const props = defineProps({
  productTable: {
    type: Object,
    required: true,
  }
});


const emit = defineEmits(['delete']);

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

</script>

<template>
  <div class="fct-product-table-mobile-wrap">
    <div
        v-for="(row, rowIndex) in productTable.getTableData()"
        :key="row.id"
        class="fct-product-table-mobile-row"
    >
      <div class="fct-product-table-mobile-header">
        <div v-if="productTable.isColumnVisible('post_status')" class="fct-product-table-post-status-col">
          <div class="flex items-center gap-1.5">
            <Badge :key="row.ID" :status="row.post_status"
                   @click="$router.push({ name: 'product_edit', params: { product_id: row.ID } })"/>
            <el-tooltip
                v-if="row.post_status === 'future'"
                effect="dark"
                placement="top"
                popper-class="fct-label-hint-popover fct-tooltip"
                :show-after="400"
                :hide-after="0"
            >
              <template #content>
                {{
                  /* translators: %s is the post date */
                  translate('Publishes On: %s', formatDate(row.post_date, true))
                }}
              </template>
              <DynamicIcon name="InformationFill" class="w-4 h-4"/>
            </el-tooltip>
          </div>
        </div>

        <div class="fct-product-action">
          <el-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              popper-class="fct-dropdown"
              @command="handleProductCommand"
              placement="bottom-end"
          >
          <span class="more-btn">
            <DynamicIcon name="More"/>
          </span>

            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item class="item-link" v-if="row.ID">
                  <router-link
                      :to="{
                        name: 'product_edit',
                        params: {
                          product_id: row.ID
                        }
                      }"
                  >
                    <DynamicIcon name="Edit"/>
                    {{ translate('Edit') }}
                  </router-link>
                </el-dropdown-item>

                <el-dropdown-item
                    :command="{
                      action: 'delete',
                      product: row
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
      </div><!-- fct-product-table-mobile-header -->

      <div
          class="fct-product-table-mobile-body"
          @click="$router.push({ name: 'product_edit', params: { product_id: row.ID } })"
      >
        <div class="fct-product-table-mobile-body-header">

          <div class="fct-product-table-date-col">
            <template v-if="productTable.isColumnVisible('post_date')">
              <ConvertedTime class="date" :date-time="row.post_date" :with-time="false"/>
              <span class="bullet">•</span>
            </template>
            <span class="invoice-no" title="Product ID">#{{ row.ID }}</span>
          </div><!-- fct-product-table-date-col -->

          <div v-if="productTable.isColumnVisible('item_price')" class="fct-product-table-price-col">
            <span v-if="row.detail">
              {{ getPrice(row.detail) }}
            </span>
            <span v-else>{{ translate('Set Price') }}</span>
          </div><!-- fct-product-table-price-col -->
        </div><!-- fct-product-table-mobile-body-header -->

        <div class="fct-product-table-product-col">
          <ProductInfo :product="row"/>
        </div>
      </div><!-- fct-product-table-mobile-body -->

      <div class="fct-product-table-mobile-footer"
           @click="$router.push({ name: 'product_edit', params: { product_id: row.ID } })">
        <div class="fct-product-table-product-type" v-if="productTable.isColumnVisible('product_type')">
          <div class="title">{{ translate('Type') }}</div>
          <span v-if="row.detail" class="value">
            {{ getProductTypeText(row.detail.fulfillment_type) }}
          </span>
          <span v-else class="value">{{ 'N/A' }}</span>
        </div>

        <div v-if="productTable.isColumnVisible('variation_type')" class="fct-product-table-variation-col">
          <div class="title">{{ translate('Variation') }}</div>
          <span v-if="row.detail" class="value">
            {{ getVariationTypeText(row.detail.variation_type) }}
          </span>
          <span v-else class="value">
            {{ 'N/A' }}
          </span>
        </div>

        <div v-if="productTable.isColumnVisible('stock_availability')" class="fct-product-table-stock-col">
          <div class="title">{{ translate('Stock') }}</div>
          <span v-if="row.detail" class="value">
            <template v-if="row.detail.manage_stock.toString() === '0'">
              {{ translate('Unlimited') }}
            </template>
            <template v-else>
              {{ translateNumber(Arr.sum(row.variants, 'available')) }}
            </template>
          </span>
          <span v-else>{{ '' }}</span>
        </div>

      </div><!-- fct-product-table-mobile-footer -->

      <div v-if="productTable.getCustomColumns()" class="fct-product-table-mobile-footer">
        <div v-for="column of productTable.getCustomColumns()" class="fct-product-table-stock-col">
          <div class="title" v-if="column.label">{{ column.label }}</div>
          <template v-if="column.render_template">
            <component
                :is="CustomColumnRenderer"
                :templateContent="column.template"
                :data="column"
                :column="column"
                :row="row"
            />
          </template>
          <template v-else>
            <RouteCell v-if="column.as_link !== false" class="hover:no-underline"
                       :to="{ name: 'product_edit', params: { product_id: row.ID } }">
              <div v-html="Arr.get(row, column.accessor)"/>
            </RouteCell>

            <div v-else v-html="Arr.get(row, column.accessor)"/>
          </template>
        </div>
      </div>


    </div>
  </div>
</template>

