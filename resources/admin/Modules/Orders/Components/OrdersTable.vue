<script setup>
import Empty from '@/Bits/Components/Table/Empty.vue';
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";
import Str from "../../../utils/support/Str";
import Url from "@/utils/support/Url";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import CustomerInfoPopover from "@/Modules/Customers/parts/CustomerInfoPopover.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import OrderUpDownIndicator from "@/Bits/Components/OrderUpDownIndicator.vue";
import {ref} from "vue";
import BulkDeleteAction from "@/Modules/Orders/Components/BulkDeleteAction.vue";
import {translateNumber} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Arr from "@/utils/support/Arr";
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
});
const selectedOrders = ref([]);
const siteUrl = AppConfig.get('site_url');
const receiptPageUrl = AppConfig.get('receipt_page_url');


const isColumnVisible = (column) => {
    return props.columns.includes(column)
}


const getPaymentProviderLogo = (payment_method) => {
    return AppConfig.get('payment_logos' + '.' + payment_method);
}


const hasInvoiceUrl = receiptPageUrl || false;

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

const handleSelectionChange = (selection) => {
  // selectedOrders.value = selection;

  // push only id, parent_id to selectedOrders
  selectedOrders.value = selection.map(item => {
    return {
      id: item.id,
      parent_id: item.parent_id,
      invoice_no: item.invoice_no,
      status: item.status
    };
  });

}

const handleDeleteComplete = () => {
  props.table.fetch();
}

const getOrderTypeText = (type) => {
  switch (type) {
    case 'payment':
      return translate('Payment');
    case 'subscription':
      return translate('Subscription');
    case 'renewal':
      return translate('Renewal');
    default:
      Str.headline(type)
  }
}

// Expose selected rows to parent component if needed
defineExpose({
  selectedOrders,
});

</script>
<template>

    <div class="fct-orders-table-wrap">
        <!--          use full-compact class for compact mode-->

      <BulkDeleteAction
          v-if="selectedOrders.length"
          :selected-orders="selectedOrders"
          :on-complete="handleDeleteComplete"
      />


      <el-table class="w-full compact-table"
                  :data="orders"
                  :tooltip-options="{ popperClass: 'fct-tooltip-long' }"
                  @selection-change="handleSelectionChange"
        >
            <el-table-column v-if="table && table.data.showDeleteBulkAction" type="selection" width="45"/>

            <el-table-column :width="140" :label="translate('Date')">
                <template #default="scope">
                    <RouteCell class="hover:no-underline"
                               :to="{ name: 'view_order',  params: { order_id: scope.row.id } }">
                      <div>
                        <div :title="
                            /* translators: %s is the order number */
                            translate('Order ID: %s', scope.row.id)
                            "
                             class="text-xs text-system-mid dark:text-gray-300 mb-0.5">{{ scope.row.invoice_no }}</div>
                        <ConvertedTime :date-time="scope.row.created_at" :with-time="false"/>
                      </div>
                    </RouteCell>
                </template>
            </el-table-column>

            <el-table-column v-if="isColumnVisible('customer')" :width="130" :label="translate('Customer')">
                <template #default="scope">
                    <CustomerInfoPopover
                        v-if="scope.row.customer"
                        :customer="scope.row.customer"
                        :to-route="{ name: 'view_order',  params: { order_id: scope.row.id } }"
                    />
                    <span v-else>{{ translate('Customer Not Found') }}</span>
                </template>
            </el-table-column>

            <el-table-column v-if="isColumnVisible('order_items')" :min-width="200" :label="translate('Items')">
                <template #default="scope">
                    <div class="fct-popover-box">
                        <el-popover
                            placement="bottom-start"
                            :width="360"
                            trigger="click"
                        >
                          <div class="fct-popover-content">
                            <div class="fct-product-orders-items is-scroll">
                              <p v-for="item in getFilteredItems(scope.row.order_items)" :key="item.id">
                                <router-link class="title"
                                             :to="{ name: 'product_edit', params: { product_id: item?.post_id } }">
                                  {{ item.post_title }}
                                </router-link>
                                <span class="variation-title"><b>{{ item.quantity }} </b> x {{ item.title }}</span>
                              </p>

                              <p v-if="getFilteredItems(scope.row.order_items).length === 0">
                                {{ translate('No products found!') }}
                              </p>
                            </div>
                          </div>

                            <template #reference>
                                <div class="fct-popover-box-action">
                                    <template v-if="getFilteredItems(scope.row.order_items).length === 1">
                                        <span class="block w-full truncate mr-4">
                                           {{ getFilteredItems(scope.row.order_items)[0]?.post_title }}
                                        </span>
                                    </template>
                                    <template v-else>
                                      <span class="block w-full truncate mr-4">
                                         {{ translateNumber(getFilteredItems(scope.row.order_items).length) }} {{ $t("items") }}
                                      </span>
                                    </template>
                                    <div class="fct-popover-box-action-icon">
                                        <DynamicIcon name="ChevronDown"/>
                                    </div>
                                </div>
                            </template>
                        </el-popover>

                        <RouteCell class="hover:no-underline"
                                   :to="{ name: 'view_order',  params: { order_id: scope.row.id } }">
                            <span>&nbsp;</span>
                        </RouteCell>
                    </div>
                </template>
            </el-table-column>

            <el-table-column :width="150" :label="translate('Total')">
                <template #default="scope">
                    <RouteCell class="hover:no-underline"
                               :to="{ name: 'view_order',  params: { order_id: scope.row.id } }">
                        <span v-if="!parseInt(scope.row.total_refund)" v-html="CurrencyFormatter.formatNumber(scope.row.total_amount, true, false, scope.row.currency)"></span>
                        <template v-else>
                            <span class="text-gray-700 line-through" v-html="CurrencyFormatter.formatNumber(scope.row.total_amount, true, false, scope.row.currency)"></span>
                            <span style="margin-left: 5px;" v-if="scope.row.payment_status != 'refunded'" v-html="CurrencyFormatter.formatNumber((parseInt(scope.row.total_amount) - parseInt(scope.row.total_refund)), true, false, scope.row.currency)"></span>
                        </template>
                    </RouteCell>
                </template>
            </el-table-column>

            <el-table-column :width="150" :label="translate('Payment Status')">
                <template #default="scope">
                    <RouteCell class="hover:no-underline"
                               :to="{ name: 'view_order',  params: { order_id: scope.row.id } }">
                        <div class="fct-status-group">
                            <Badge :status="scope.row.payment_status" :hide-icon="true"/>

                            <span class="img" v-if="getPaymentProviderLogo(scope.row.payment_method)"
                                  :class="scope.row.payment_method">
                 <img
                     :src="getPaymentProviderLogo(scope.row.payment_method)"
                     :alt="Str.headline(scope.row.payment_method)">
              </span>
                            <el-tag v-else type="info" round size="small" class="capitalize">
                                {{ Str.headline(scope.row.payment_method) }}
                            </el-tag>
                        </div>
                    </RouteCell>
                </template>
            </el-table-column>

            <el-table-column v-if="isColumnVisible('status')" :width="120" :label="translate('Status')">
                <template #default="scope">

                    <RouteCell class="hover:no-underline"
                               :to="{ name: 'view_order',  params: { order_id: scope.row.id } }">
                        <Badge :status="scope.row.status" :hide-icon="true" :key="scope.row.id"/>
                    </RouteCell>

                </template>
            </el-table-column>

            <el-table-column v-if="isColumnVisible('type')" :width="140" :label="translate('Order Type')">
                <template #default="scope">
                    <span>{{ getOrderTypeText(scope.row.type) }}</span>
                    <OrderUpDownIndicator :order="scope.row"/>
                </template>
            </el-table-column>


            <el-table-column v-if="table" v-for="column of table.getCustomColumns()" :label="column.label">
              <template #default="scope">
                <div v-if="column.render_template">
                  <component
                      :is="CustomColumnRenderer"
                      :templateContent="column.template"
                      :data="{
                        column,
                        order: scope.row,
                        index: scope.$index,
                      }"
                  />
                </div>
                <div v-else>
                  <RouteCell v-if="column.as_link !== false" class="hover:no-underline"
                             :to="{ name: 'view_order', params: { order_id: scope.row.id } }">
                    <div v-html="Arr.get(scope.row, column.accessor)"/>
                  </RouteCell>

                  <div v-else v-html="Arr.get(scope.row, column.accessor)"/>
                </div>
              </template>

            </el-table-column>

            <el-table-column v-if="isColumnVisible('actions') && hasInvoiceUrl" width="60" label="">
                <template #default="scope">
                    <div class="fct-btn-group sm justify-end">
                        <div class="table-cell">
                            <el-tooltip :content="translate('View Invoice')" placement="top"
                                        popper-class="fct-tooltip">
                                <IconButton
                                    target="_blank"
                                    :href="getInvoiceUrl(scope.row.uuid)"
                                    size="small"
                                    tag="a"
                                    bg="ghost"
                                >
                                    <DynamicIcon name="Print"/>
                                </IconButton>
                            </el-tooltip>
                        </div>


                        <!--            <a-->
                        <!--                class="link block no-underline"-->
                        <!--                target="_blank"-->
                        <!--                :href="getInvoiceUrl(scope.row.uuid)"-->
                        <!--            >-->
                        <!--              <div class="table-cell">-->
                        <!--                <DynamicIcon class="w-4 h-4" name="Print"/>-->
                        <!--              </div>-->
                        <!--            </a>-->

                    </div>
                </template>
            </el-table-column>

            <template #empty>
                <Empty icon="Empty/Order" has-dark :text="emptyText"/>
            </template>
        </el-table>
    </div>
</template>


