<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted} from "vue";
import StockAdjuster from '@/Modules/Products/parts/StockAdjuster.vue';
import Animation from "@/Bits/Components/Animation.vue";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";


const props = defineProps({
  product: Object,
  productEditModel: Object,
})
const emit = defineEmits(['update:modelValue'])
const showStockManagement = AppConfig.get('modules_settings.stock_management.active');
onMounted(() => {
  props.product.variants.filter(variant => {
    variant.new_stock = variant.total_stock
    variant.adjusted_quantity = 0
    return variant;
  });
})

const inventoryTableRowClass = (row) => {
  return (props.product.detail.variation_type === 'simple_variations' && row.row.manage_stock == 0) ? 'disable_inventory_row' : '';
}

const applyInventoryRowClass = (row) => {
  return (props.product.detail.variation_type === 'simple_variations' && row.manage_stock == 0) ? 'disable_inventory_row' : '';
}

const saveStock = (index) => {
  let newStock = parseInt(props.product.variants[index]['new_stock']);
  props.product.variants[index]['total_stock'] = (newStock < 0) ? 0 : newStock;
  let available = parseInt(props.product.variants[index]['total_stock']) - parseInt(props.product.variants[index]['committed']) - parseInt(props.product.variants[index]['on_hold']);
  props.product.variants[index]['available'] = available < 0 ? 0 : available;

  props.product.variants[index]['adjusted_quantity'] = 0;
  props.product.variants[index]['new_stock'] = props.product.variants[index]['total_stock'];

  Rest.put(`products/${props.product.ID}/update-inventory/${props.product.variants[index].id}`, {
    total_stock: props.product.variants[index]['total_stock'],
    available: props.product.variants[index]['available']
  })
      .then(response => {
        Notify.success(response.message);
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      });
}

const handleManageStockChange = (value) => {
  Rest.put(`products/${props.product.ID}/update-manage-stock`, {
    manage_stock: props.product.detail.manage_stock
  })
      .then(response => {
        Notify.success(response.message);
        // update total_stock and available for all props.product.variants
        props.product.variants.forEach(variant => {
          variant.manage_stock = value;
          variant.total_stock = 1;
          variant.available = 1;
        });
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      });
}

</script>

<template>
  <div v-if="showStockManagement === 'yes'" class="fct-product-inventory-wrap">
    <Card.Container class="overflow-hidden">
      <Card.Header :class="product.detail?.manage_stock.toString() === '0' ? 'pb-5' : ''">
        <template #action>
          <el-switch v-if="product.detail?.manage_stock" v-model="product.detail.manage_stock" @change="handleManageStockChange" active-value="1" inactive-value="0" :active-text="translate('Inventory Management')">
          </el-switch>
        </template>
      </Card.Header>
      <Animation :visible="product.detail?.manage_stock.toString() === '1'" accordion>

        <Card.Body class="px-0 pb-0">
          <div class="fct-product-inventory-inner-wrap hide-on-mobile">
            <el-table :data="product.variants" :row-class-name="inventoryTableRowClass">
              <el-table-column :label="translate('Title')" v-if="product.detail.variation_type === 'simple_variations'" width="140">
                <template #default="scope">
                  <div class="relative">
                    <el-input disabled size="small" v-model="scope.row.variation_title">
                    </el-input>

                    <span v-if="scope.row.other_info?.payment_type === 'subscription'" class="fct-variant-badge absolute -top-2 right-1.5 bg-white border border-solid border-gray-outline text-primary-500 rounded-xs dark:bg-primary-700 dark:text-gray-50 dark:border-primary-500">
                    {{scope.row.other_info.repeat_interval}}
                  </span>
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('SKU')" width="140">
                <template #default="scope">
                  <el-input disabled size="small" v-model="scope.row.sku" :placeholder="translate('SKU')">
                  </el-input>
                </template>
              </el-table-column>

              <!-- <el-table-column :label="translate('Stock Status')" width="140">
                <template #default="scope">
                  <input type="text" v-model="scope.row.manage_stock" hidden/>
                  <el-select size="small" :class="validationErrors?.hasOwnProperty(`${scope.$index}.stock_status`) ? 'is-error' : ''" v-model="scope.row.stock_status" :placeholder="translate('Select')" disabled @change="value => {
                      emit('update:modelValue', product, 3)
                    }">
                    <el-option :label="translate('In stock')" value="in-stock"/>
                    <el-option :label="translate('Out of stock')" value="out-of-stock"/>
                  </el-select>
                  <ValidationError :validation-errors="validationErrors" :field-key="`${scope.$index}.stock_status`"/>
                </template>
              </el-table-column> -->

              <el-table-column :label="translate('Total Stock')" width="160">
                <template #default="scope">
                  <div>
                    <el-input
                        v-model="scope.row.total_stock"
                        class="input-with-total-stock fct-input-group"
                        readonly
                        size="small"
                    >
                      <template #append>
                        <StockAdjuster
                          :variant="scope.row"
                          :field-key="scope.$index"
                          :product-edit-model="productEditModel"
                          @save="saveStock(scope.$index)"
                        />
                      </template>
                    </el-input>
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Available')" width="100">
                <template #default="scope">
                  <el-input disabled size="small" :placeholder="translateNumber(scope.row.available)"/>
                </template>
              </el-table-column>

              <el-table-column :label="translate('On hold')" width="100">
                <template #default="scope">
                  <el-input disabled size="small" :placeholder="translateNumber(scope.row.on_hold)"/>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Delivered')" width="100">
                <template #default="scope">
                  <el-input disabled size="small" :placeholder="translateNumber(scope.row.committed)"/>
                </template>
              </el-table-column>
            </el-table>
          </div>



          <!-- mobile view -->
          <div class="fct-product-inventory-inner-wrap-mobile">
            <div v-for="(row, rowIndex) in product.variants" :key="rowIndex" class="fct-product-inventory-mobile-row" :class="applyInventoryRowClass(row)">
              <div
                  class="fct-product-inventory-mobile-col"
                  v-if="product.detail.variation_type === 'simple_variations'"
              >
                <div class="title">
                  {{row.variation_title}}
                  <div class="text-xs text-gray-500 mt-1" v-if="row.sku">SKU: {{ row.sku }}</div>
                </div>
              </div><!-- fct-product-inventory-mobile-col -->

              <div class="fct-product-inventory-mobile-col">
                <el-input
                    v-model="row.total_stock"
                    class="input-with-total-stock fct-input-group"
                    readonly
                    size="small"
                >
                  <template #append>
                    <StockAdjuster
                      :variant="row"
                      :field-key="rowIndex"
                      :product-edit-model="productEditModel"
                      @save="saveStock(rowIndex)"
                    />
                  </template>
                </el-input>
              </div><!-- fct-product-inventory-mobile-col -->

              <div class="fct-product-inventory-mobile-col">
                <ul>
                  <li>
                    <span>{{ translate('Available') }}:</span> {{row.available.toString()}}
                  </li>
                  <li>
                    <span>{{ translate('On Hold') }}:</span> {{row.on_hold.toString()}}
                  </li>
                  <li>
                    <span>{{ translate('Delivered') }}:</span> {{row.committed.toString()}}
                  </li>
                </ul>
              </div><!-- fct-product-inventory-mobile-col -->



            </div>
          </div>
          <!-- mobile view -->


        </Card.Body>
      </Animation>
    </Card.Container>
  </div>
</template>
