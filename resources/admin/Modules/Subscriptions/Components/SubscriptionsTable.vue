<template>
  <div class="fct-subscriptions-table-wrap">
    <!--          use full-compact class for compact mode-->
    <el-table :data="subscriptions" class="w-full compact-table"
              :tooltip-options="{ popperClass: 'fct-tooltip-long' }">
      <el-table-column fixed :label="translate('ID')" width="100">
        <template #default="scope">

          <RouteCell class="link block"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">
            #{{ translateNumber(scope.row.id) }}
          </RouteCell>

        </template>
      </el-table-column>

      <el-table-column v-if="columns.indexOf('customer') !== -1" :label="translate('Customer')" :width="150">
        <template #default="scope">

          <CustomerInfoPopover
              v-if="scope.row.customer"
              :customer="scope.row.customer"
              :to-route="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }"
          />
          <span v-else>{{ translate('Customer Not Found') }}</span>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Product')" width="360" show-overflow-tooltip>
        <template #default="scope">
          <RouteCell class="link block hover:no-underline"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">

            <div class="truncate"> {{ scope.row.item_name }}</div>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Status')" width="120">
        <template #default="scope">
          <RouteCell class="link block hover:no-underline"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">
            <Badge :status="subscriptionStatus(scope.row)" :key="scope.row.id" size="small" :hide-icon="true"/>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Billing')" width="230">
        <template #default="scope">
          <RouteCell class="link block hover:no-underline"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">
            <span v-html="scope.row.payment_info"></span>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Created At')" width="130">
        <template #default="scope">
          <RouteCell class="link block hover:no-underline"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">
            <ConvertedTime :date-time="scope.row.created_at" :with-time="false"/>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column v-if="columns.indexOf('next_billing_date') !== -1" :label="translate('Next Billing Date')" width="160">
        <template #default="scope">
          <RouteCell class="link block hover:no-underline"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">
            <ConvertedTime :date-time="scope.row.next_billing_date" :with-time="false"/>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column v-if="columns.indexOf('collection_method') !== -1" :label="translate('Collection Method')" width="160">
        <template #default="scope">
          <RouteCell class="link block hover:no-underline"
                     :to="{ name: 'view_subscription', params: { subscription_id: scope.row?.id } }">
            {{ scope.row.collection_method }}
          </RouteCell>
        </template>
      </el-table-column>


      <el-table-column v-if="columns.indexOf('bills_count') !== -1" :label="translate('Bills Count')" width="100">
        <template #default="scope">
          {{ translateNumber(scope.row.bill_count) }}
        </template>
      </el-table-column>

      <el-table-column v-if="columns.indexOf('payment_method') !== -1" :label="translate('Payment Method')" width="150">
        <template #default="scope">
          {{ scope.row.current_payment_method ? Str.headline(scope.row.current_payment_method.trim().replace(/_/g, '-')) : '-' }}
        </template>
      </el-table-column>

      <el-table-column v-if="columns.indexOf('order_id') !== -1" :label="translate('Order ID')" width="100">
        <template #default="scope">
          <router-link
              class="link"
              :to="{ name: 'view_order',  params: { order_id: scope.row.parent_order_id } }"
          >
            #{{ translateNumber(scope.row.parent_order_id) }}
          </router-link>
        </template>
      </el-table-column>

      <template #empty>
        <Empty icon="Empty/ListView" :has-dark="true" :text="translate('No subscriptions found.')"/>
      </template>
    </el-table>

  </div>
</template>

<script type="text/babel">
import Str from "@/utils/support/Str";

import Empty from '@/Bits/Components/Table/Empty.vue';
import {ArrowDown} from "@element-plus/icons-vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Badge from "@/Bits/Components/Badge.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import CustomerInfoPopover from "@/Modules/Customers/parts/CustomerInfoPopover.vue";
import {translateNumber} from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";

export default {
  name: 'SubscriptionsTable',
  emits: ['fetch'],
  props: {
    subscriptions: {
      type: Array,
      required: true,
    },
    columns: {
      type: Array,
      required: false,
      default() {
        return ['customer', 'next_billing_date', 'collection_method', 'bills_count', 'payment_method', 'valid_till'];
      }
    }
  },
  components: {
    ConvertedTime,
    RouteCell,
    Badge,
    DynamicIcon,
    ArrowDown,
    Empty,
    CustomerInfoPopover,
  },
  computed: {
    Str() {
      return Str
    },
  },
  methods: {
    translate,
    translateNumber,
    subscriptionStatus(subscription) {
      // overridden_status is only for customer view, admin should see the actual status
      // if (subscription?.overridden_status) {
      //   return subscription.overridden_status;
      // }
      return subscription?.status;
    }
  },
}
</script>

