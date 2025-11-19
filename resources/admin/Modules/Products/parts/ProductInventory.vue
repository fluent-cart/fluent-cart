<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted, ref, nextTick} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { useFilterPopoverOutsideClickMixin } from '@/mixin/filterPopoverOutsideClickMixin';
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
const visibleDesktop = ref([]);
const visibleMobile = ref([]);
const filterPopoverOutsideClickMixin = useFilterPopoverOutsideClickMixin();
const filter_popover_wrapper = ref([]);
const hasPro = AppConfig.get('app_config.isProActive');
const showStockManagement = AppConfig.get('modules_settings.stock_management.active');
const proInventory = false;
onMounted(() => {
  const len = Object.keys(props.product.variants).length;
  visibleDesktop.value = Array.from({ length: len }, () => false);
  visibleMobile.value = Array.from({ length: len }, () => false);

  props.product.variants.filter(variant => {
    variant.new_stock = variant.total_stock
    variant.adjusted_quantity = 0
    return variant;
  });

  // filterPopoverOutsideClickMixin.handle(filter_popover_wrapper.value, ()=>{
  //   visibleDesktop.value.forEach((_, i) => {
  //     visibleDesktop.value[i] = false;
  //   });
  // })
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

  nextTick(() => {
    visibleDesktop.value[index] = false;
    visibleMobile.value[index] = false;
  });
  
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
      })
      .finally(() => {

      });

  // props.productEditModel.setHasChange(true)
}

const handleManageStockChange = (value) => {
  if (!hasPro) {
    return;
  }
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
      })
      .finally(() => {

      });
}

const toggleDropdown = (index, type) => {
  if (type === 'desktop') {
    visibleDesktop.value = visibleDesktop.value.map((_, i) => i === index);
  } else {
    visibleMobile.value = visibleMobile.value.map((_, i) => i === index);
  }
}

</script>

<template>
  <div v-if="hasPro && showStockManagement === 'yes'" class="fct-product-inventory-wrap">
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
                        <div ref="filter_popover_wrapper">
                          <el-popover :visible="visibleDesktop[scope.$index]" popper-class="fct-stock-dropdown" trigger="click" placement="bottom-end">
                            <div class="fct-adjust-by-wrap">
                              <div class="fct-adjust-by-row">
                                <div class="fct-adjust-by-col">
                                  <span class="title">{{translate('Adjust by')}}</span>
                                  <el-input size="small" :class="productEditModel.hasValidationError(`${scope.$index}.adjusted_quantity`) ? 'is-error' : ''" :placeholder="translate('Quantity')" type="number" v-model.number="scope.row.adjusted_quantity" @keyup="event => {productEditModel.onChangeAdjustedQuantity('adjusted_quantity', event.target.value, scope.$index)}" @change="value => {productEditModel.onChangeAdjustedQuantity('adjusted_quantity', value, scope.$index)}">
                                  </el-input>
                                </div><!-- .fct-adjust-by-col -->

                                <div class="fct-adjust-by-col">
                                  <span class="title">{{translate('New Stock')}}</span>
                                  <el-input size="small" :class="productEditModel.hasValidationError(`${scope.$index}.new_stock`) ? 'is-error' : ''" :placeholder="translate('New Stock')" type="number" :min="1" v-model.number="scope.row.new_stock"  @keyup="event => {productEditModel.onChangeNewStock('new_stock', event.target.value, scope.$index)}">
                                  </el-input>
                                </div><!-- .fct-adjust-by-col -->
                              </div>

                              <div class="fct-adjust-by-action">
                                <el-button size="small" type="info" soft @click="visibleDesktop[scope.$index]=false">
                                  {{ translate('Cancel') }}
                                </el-button>
                                <el-button size="small" type="primary" @click="saveStock(scope.$index)" :disabled="scope.row.new_stock === ''">
                                  {{ translate('Apply') }}
                                </el-button>
                              </div>
                            </div><!-- .fct-adjust-by-wrap -->

                            <template #reference>
                              <div @click="toggleDropdown(scope.$index, 'desktop')">
                                <DynamicIcon name="Configuration" class="w-3.5 h-3.5" />
                              </div>
                            </template>
                          </el-popover>
                        </div>
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
                    <div ref="filter_popover_wrapper">
                      <el-popover :visible="visibleMobile[rowIndex]" popper-class="fct-stock-dropdown" trigger="click" placement="bottom-end">
                        <div class="fct-adjust-by-wrap">
                          <div class="fct-adjust-by-row">
                            <div class="fct-adjust-by-col">
                              <span class="title">{{translate('Adjust by')}}</span>
                              <el-input size="small" :class="productEditModel.hasValidationError(`${rowIndex}.adjusted_quantity`) ? 'is-error' : ''" :placeholder="translate('Quantity')" type="number" v-model.number="row.adjusted_quantity" @keyup="event => {productEditModel.onChangeAdjustedQuantity('adjusted_quantity', event.target.value, rowIndex)}" @change="value => {productEditModel.onChangeAdjustedQuantity('adjusted_quantity', value, rowIndex)}">
                              </el-input>
                            </div><!-- .fct-adjust-by-col -->

                            <div class="fct-adjust-by-col">
                              <span class="title">{{translate('New Stock')}}</span>
                              <el-input size="small" :class="productEditModel.hasValidationError(`${rowIndex}.new_stock`) ? 'is-error' : ''" :placeholder="translate('New Stock')" type="number" :min="1" v-model.number="row.new_stock"  @keyup="event => {productEditModel.onChangeNewStock('new_stock', event.target.value, rowIndex)}">
                              </el-input>
                            </div><!-- .fct-adjust-by-col -->
                          </div>

                          <div class="fct-adjust-by-action">
                            <el-button size="small" type="info" soft @click="visibleMobile[rowIndex]=false">
                              {{ translate('Cancel') }}
                            </el-button>
                            <el-button size="small" type="primary" @click="saveStock(rowIndex)" :disabled="row.new_stock === ''">
                              {{ translate('Apply') }}
                            </el-button>
                          </div>
                        </div><!-- .fct-adjust-by-wrap -->

                        <template #reference>
                          <div @click="toggleDropdown(rowIndex, 'mobile')">
                            <DynamicIcon name="Configuration" class="w-3.5 h-3.5" />
                          </div>
                        </template>
                      </el-popover>
                    </div>
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
  <div v-else-if="!hasPro">
    <Card.Container class="overflow-hidden">
      <Card.Header class="pb-0">
        <template #action>
          <div class="flex items-center gap-2">
            <el-switch v-model="proInventory" active-value="1" inactive-value="0" :active-text="translate('Inventory Management')">
            </el-switch>
            <DynamicIcon name="Crown" class="w-4 h-4 text-warning-500" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-5 mt-5 border-[1px] border-r-0 border-l-0 border-b-0 border-solid border-gray-divider dark:border-dark-400">
        <div class="fct-product-inventory-pro-text">
          <p class="m-0">{{ translate('This feature is only available for pro version.') }}</p>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
