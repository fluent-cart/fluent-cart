<script setup>
import Empty from '@/Bits/Components/Table/Empty.vue';
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";
import Str from "../../../utils/support/Str";
import Arr from "@/utils/support/Arr";
import Url from "@/utils/support/Url";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import CustomerInfoPopover from "@/Modules/Customers/parts/CustomerInfoPopover.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import OrderUpDownIndicator from "@/Bits/Components/OrderUpDownIndicator.vue";
import AppConfig from "@/utils/Config/AppConfig";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import CustomColumnRenderer from "@/Bits/Components/CustomColumnRenderer.vue";


const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },

    emptyText: {
        type: String,
        required: false,
        default: translate('No orders found.')
    },

    columns: {
        type: Array,
        required: false,
        default() {
            return ['selection', 'customer', 'actions', 'status', 'order_items'];
        }
    },
  table: Object
})
const siteUrl = AppConfig.get('site_url');

const isColumnVisible = (column) => {
    return props.columns.includes(column)
}


const getPaymentProviderLogo = (payment_method) => {
  return AppConfig.get('payment_logos' + '.' + payment_method);
}

const getInvoiceUrl = (uuid) => {

    if (uuid) {
        return Url.appendQueryParams(
            siteUrl,
            {
              'fluent-cart': 'receipt',
              'order_hash': uuid,
              'download': 1
            }
        );
    }
    return '';
}

const getFilteredItems = (orderItems) => {
  return orderItems.filter(item => item.payment_type !== 'signup_fee');
};
const getOrderTypeText = (type) => {
  switch (type) {
    case 'payment':
      return translate('Payment');
    case 'subscription':
      return translate('Subscription');
    case 'renewal':
      return translate('Renewal');
    default:
      Str.headline(type);
  }
}

</script>
<template>
    <div class="fct-orders-table-mobile-wrap">
      <div v-for="(row, rowIndex) in orders" :key="row.id" class="fct-orders-table-mobile-row">
        <div class="fct-orders-table-mobile-header">
          <div class="fct-order-table-date-col" @click="$router.push({ name: 'view_order', params: { order_id: row.id } })">
            <ConvertedTime class="date" :date-time="row.created_at" :with-time="false"/>
            <span class="bullet">•</span>
            <div :title="translate('Order ID: %s', row.id)" class="invoice-no">
              {{ row.invoice_no }}
            </div>
          </div>

          <div class="flex items-center gap-3">
            <div class="fct-order-table-total-col" @click="$router.push({ name: 'view_order', params: { order_id: row.id } })">
              <span v-if="!parseInt(row.total_refund)" v-html="CurrencyFormatter.formatNumber(row.total_amount, true, false, row.currency)"></span>

              <template v-else>
                <span class="text-gray-700 line-through" v-html="CurrencyFormatter.formatNumber(row.total_amount, true, false, row.currency)"></span>
                <span class="ml-1" v-if="row.payment_status != 'refunded'" v-html="CurrencyFormatter.formatNumber((parseInt(row.total_amount) - parseInt(row.total_refund)), true, false, row.currency)"></span>
              </template>
            </div>

            <div v-if="isColumnVisible('actions')" class="fct-order-table-actions-col">
              <el-dropdown trigger="click" popper-class="fct-dropdown">
                <span class="el-dropdown-link">
                   <DynamicIcon name="More" class="w-1"/>
                </span>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item class="item-link">
                      <a target="_blank" :href="getInvoiceUrl(row.uuid)">
                        <DynamicIcon name="Print"/>
                        {{ translate('View Invoice') }}
                      </a>
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>

            </div>
          </div>
        </div><!-- fct-orders-table-mobile-header -->


        <div class="fct-orders-table-mobile-body" v-if="isColumnVisible('customer') || isColumnVisible('order_items')">
          <div class="fct-order-and-customer-info">
            <div v-if="isColumnVisible('customer')" class="fct-order-table-customer-col">
              <CustomerInfoPopover
                  v-if="row.customer"
                  :customer="row.customer"
                  :to-route="{ name: 'view_order',  params: { order_id: row.id } }"
              />
              <span v-else>{{ translate('Customer Not Found') }}</span>
            </div>

            <div v-if="isColumnVisible('order_items')" class="fct-order-table-order-items-col">
              <div class="fct-popover-box">
                <el-popover
                    placement="bottom-start"
                    :width="360"
                    trigger="click"
                >
                  <div class="fct-popover-content">
                    <div class="fct-product-orders-items is-scroll">
                      <p v-for="item in getFilteredItems(row.order_items)" :key="item.id">
                        <router-link class="title"
                                     :to="{ name: 'product_edit', params: { product_id: item?.post_id } }">
                          {{ item.post_title }}
                        </router-link>
                        <span class="variation-title"><b>{{ item.quantity }} </b> x {{ item.title }}</span>
                      </p>

                      <p v-if="getFilteredItems(row.order_items).length === 0">
                        {{ translate('No products found!') }}
                      </p>
                    </div>
                  </div>

                  <template #reference>
                    <div class="fct-popover-box-action">
                      <template v-if="getFilteredItems(row.order_items).length === 1">
                          <span class="fct-order-count-text">
                             {{ getFilteredItems(row.order_items)[0]?.post_title }}
                          </span>
                      </template>
                      <template v-else>
                          <span class="fct-order-count-text">
                             {{ getFilteredItems(row.order_items).length }} {{ $t("items") }}
                          </span>
                      </template>
                      <div class="fct-popover-box-action-icon">
                        <DynamicIcon name="ChevronDown"/>
                      </div>
                    </div>
                  </template>
                </el-popover>
              </div>
            </div>
          </div>
        </div><!-- fct-orders-table-mobile-body -->

        <div class="fct-orders-table-mobile-footer" @click="$router.push({ name: 'view_order', params: { order_id: row.id } })">
          <div class="fct-status-and-order-type-wrap">
            <div class="fct-order-table-payment-status-col">
              <div class="title">{{translate('Payment Status')}}</div>
              <div class="fct-status-group">
                <Badge :status="row.payment_status" :hide-icon="true"/>

                <span class="img" v-if="getPaymentProviderLogo(row.payment_method)"
                      :class="row.payment_method">
                     <img
                         :src="getPaymentProviderLogo(row.payment_method)"
                         :alt="Str.headline(row.payment_method)">
                  </span>
                <el-tag v-else type="info" round size="small" class="capitalize">
                  {{ Str.headline(row.payment_method) }}
                </el-tag>
              </div>
            </div><!-- fct-order-table-payment-status-col -->

            <div v-if="isColumnVisible('status')" class="fct-order-table-status-col">
              <div class="title">{{translate('Status')}}</div>
              <Badge :status="row.status" :hide-icon="true" :key="row.id"/>
            </div><!-- fct-order-table-status-col -->

            <div v-if="isColumnVisible('type')" class="fct-order-table-order-type-col">
              <div class="title">{{translate('Order Type')}}</div>
              <span class="type">{{ getOrderTypeText(row.type) }}</span>
              <OrderUpDownIndicator :order="row"/>
            </div><!-- fct-order-table-order-type-col -->
          </div>
        </div><!-- fct-orders-table-mobile-footer -->

        <div class="fct-orders-table-mobile-footer">
          <div class="fct-status-and-order-type-wrap">
            <div class="fct-order-table-order-items-col" v-if="table" v-for="column of table.getCustomColumns()">
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
                           :to="{ name: 'view_order', params: { order_id: row.id } }">
                  <div v-html="Arr.get(row, column.accessor)"/>
                </RouteCell>
                <div v-else v-html="Arr.get(row, column.accessor)"/>
              </template>
            </div>
          </div>
        </div>

      </div><!-- row -->

      <!-- Empty State -->
      <div v-if="orders.length === 0" class="py-6 text-center">
        <Empty icon="Empty/Order" has-dark :text="emptyText"/>
      </div>
    </div>
</template>


