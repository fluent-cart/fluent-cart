<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted, ref} from "vue";
import StockAdjuster from '@/Modules/Products/parts/StockAdjuster.vue';
import SkuInput from '@/Modules/Products/parts/SkuInput.vue';
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
const adjusters = ref([]);
const truncatedMap = ref({});

const trackTruncation = (el, key) => {
  if (el) truncatedMap.value[key] = el.scrollWidth > el.clientWidth;
};

onMounted(() => {
  props.product.variants.forEach(variant => {
    variant.new_stock = variant.total_stock;
    variant.adjusted_quantity = 0;
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
        props.product.variants.forEach(variant => {
          variant.manage_stock = value;
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
              <el-table-column :label="translate('Title')" width="180">
                <template #default="scope">
                  <div class="relative" :class="{ 'fct-inventory-title-cell--has-badge': scope.row.other_info?.payment_type === 'subscription' }">
                    <el-tooltip
                        :content="scope.row.variation_title"
                        placement="top"
                        :show-after="300"
                        :disabled="!truncatedMap[`title_${scope.$index}`]"
                    >
                      <span
                          class="fct-inventory-cell-text truncate block"
                          :ref="el => trackTruncation(el, `title_${scope.$index}`)"
                      >{{ scope.row.variation_title || '--' }}</span>
                    </el-tooltip>

                    <span v-if="scope.row.other_info?.payment_type === 'subscription'" class="fct-variant-badge fct-variant-badge--light">
                    {{scope.row.other_info.repeat_interval}}
                  </span>
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('SKU')" width="220">
                <template #default="scope">
                  <SkuInput :variant="scope.row" :product-title="product.post_title" @saved="sku => scope.row.sku = sku" />
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

              <el-table-column :label="translate('Total Stock')" width="120">
                <template #default="scope">
                  <div>
                    <el-input
                        v-model="scope.row.total_stock"
                        class="input-with-total-stock fct-input-group"
                        readonly
                        size="small"
                        @click="adjusters[scope.$index]?.toggle()"
                    >
                      <template #append>
                        <StockAdjuster
                          :ref="el => adjusters[scope.$index] = el"
                          :variant="scope.row"
                          :field-key="scope.$index"
                          :product-edit-model="productEditModel"
                          @save="saveStock(scope.$index)"
                          @click.stop
                        />
                      </template>
                    </el-input>
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Available')" width="100">
                <template #default="scope">
                  <span class="fct-inventory-cell-text pl-5">{{ translateNumber(scope.row.available) }}</span>
                </template>
              </el-table-column>

              <el-table-column :label="translate('On hold')" width="100">
                <template #default="scope">
                  <span class="fct-inventory-cell-text pl-5">{{ translateNumber(scope.row.on_hold) }}</span>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Delivered')" width="100">
                <template #default="scope">
                  <span class="fct-inventory-cell-text pl-5">{{ translateNumber(scope.row.committed) }}</span>
                </template>
              </el-table-column>
            </el-table>
          </div>



          <!-- mobile view -->
          <div class="fct-product-inventory-inner-wrap-mobile">
            <div v-for="(row, rowIndex) in product.variants" :key="rowIndex" class="fct-product-inventory-mobile-row" :class="applyInventoryRowClass(row)">
              <div class="fct-product-inventory-mobile-col">
                <div class="title">{{ row.variation_title || '--' }}</div>
                <SkuInput :variant="row" :product-title="product.post_title" class="mt-1 mb-2" @saved="sku => row.sku = sku" />
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


