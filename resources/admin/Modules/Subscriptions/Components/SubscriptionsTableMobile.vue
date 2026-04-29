<template>
  <div class="fct-table-mobile-wrap fct-subscriptions-table-mobile-wrap">
    <div v-for="(row, rowIndex) in subscriptions" :key="row.id" class="fct-table-mobile-row">
      <div class="fct-table-mobile-header" @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })">
        <div class="fct-table-mobile-header-left">
          <div class="fct-table-status-col">
            <Badge :status="subscriptionStatus(row)" :key="row.id"/>
          </div>
          <div class="fct-table-id-col">
            <div class="id">#{{ row.id }}</div>
          </div>
        </div>
      </div><!-- fct-table-mobile-header -->

      <div class="fct-table-mobile-body">
        <div v-if="columns.indexOf('customer') !== -1" class="fct-table-customer-col">
          <CustomerInfoPopover
              :customer="row.customer"
              :to-route="{ name: 'view_subscription', params: { subscription_id: row?.id } }"
          />
        </div>

        <div @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })">
          <div class="fct-table-product-col">
            <div class="product-name">{{ row.item_name }}</div>
          </div>

          <div class="fct-table-billing-col">
            <div class="title">{{ translate('Billing:') }}</div>
            <span v-html="row.payment_info"></span>
          </div>
        </div>
      </div><!-- fct-table-mobile-body -->

      <div class="fct-table-mobile-footer">
        <div class="fct-table-mobile-footer-row cols-3">
          <div
              class="fct-table-created-at-col"
              @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })"
          >
            <div class="title">{{ translate('Created At') }}</div>
            <ConvertedTime class="value" :date-time="row.created_at" :with-time="false"/>
          </div>

          <div
              v-if="columns.indexOf('next_billing_date') !== -1"
              class="fct-table-next-bill-date-col"
              @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })"
          >
            <div class="title">{{ translate('Next Billing') }}</div>
            <ConvertedTime class="value" :date-time="row.next_billing_date" :with-time="false"/>
          </div>

          <div
              v-if="columns.indexOf('bills_count') !== -1"
              class="fct-table-bill-count-col"
              @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })"
          >
            <div class="title">{{ translate('Bills Count') }}</div>
            <div class="value">{{ row.bill_count }}</div>
          </div>

          <div
              v-if="columns.indexOf('payment_method') !== -1"
              class="fct-table-payment-method-col"
              @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })"
          >
            <div class="title">{{ translate('Payment Method') }}</div>
            <div class="value">
              {{ row.current_payment_method ?
                Str.headline(row.current_payment_method.trim().replace(/_/g, '-'))
                : '-'
              }}
            </div>
          </div>

          <div
              v-if="columns.indexOf('order_id') !== -1"
              class="fct-table-order-id-col"
              @click="$router.push({ name: 'view_order', params: { order_id: row.parent_order_id } })"
          >
            <div class="title">{{ translate('Order ID') }}</div>
            <div class="value">#{{ row.parent_order_id }}</div>
          </div>

          <div
              v-if="columns.indexOf('collection_method') !== -1"
              class="fct-table-collection-method-col"
              @click="$router.push({ name: 'view_subscription', params: { subscription_id: row.id } })"
          >
            <div class="title">{{ translate('Collection') }}</div>
            <div class="value">
               {{ row.collection_method }}
            </div>
          </div>
        </div>
      </div><!-- fct-table-mobile-footer -->
    </div>

    <!-- Empty State -->
    <div v-if="subscriptions.length === 0" class="py-6 text-center">
      <Empty icon="Empty/ListView" has-dark :text="translate('No subscriptions found.')"/>
    </div>
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
import translate from "@/utils/translator/Translator";

export default {
  name: 'SubscriptionsTableMobile',
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

