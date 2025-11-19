<template>
    <div class="fct-customer-dashboard-table">
      <!-- mobile view -->
      <div class="subscription-only-mobile">
        <div v-if="subscriptions.length > 0" class="subscription-only-mobile-item" v-for="subscription in subscriptions" :key="subscription.id">
          <div class="item-header">
            <CardInfo :data="subscription" :show-payment-info="showPaymentInfo"/>
            <div
                class="text"
                v-if="subscription.status !== 'expired' && subscription.status !== 'canceled' && subscription.status !== 'completed'"
                aria-live="polite"
            >
              <!-- translators: %s is the next billing date -->
              {{ $t('Auto renews on %s', dateTimeI18(subscription.next_billing_date)) }}
            </div>
          </div>

          <div class="item-body">
            <div class="fct-customer-orders-items fct-customer-sub-orders-items cursor-pointer"
                 @click="$router.push({
                  name: 'view_subscription',
                  params: { subscription_uuid: subscription.uuid }
                 })"
                 :aria-label="$t('View subscription details for') + ' ' + subscription.item_name"
                 >

              <div class="fct-customer-orders-items-title">
                {{ subscription.item_name }}
              </div>
            </div>
          </div>

          <div class="item-footer">
            <div class="item-footer-content">
              <Badge :status="subscriptionStatus(subscription)" :hide-icon="true" size="small"/>
              <PaymentInfo :data="subscription"/>
            </div>
          </div>
        </div>

        <div v-else class="text-center p-5" role="alert" aria-live="polite">
          {{translate('No subscription plans found!')}}
        </div>
      </div>

      <!-- desktop view -->
        <el-table class="subscription-only-desktop" :data="subscriptions" :show-header="!hideHeader" role="table">
            <el-table-column :min-width="200" :label="$t('Item')">
                <template #default="scope">
                    <div class="fct-customer-orders-items fct-customer-sub-orders-items cursor-pointer"
                         @click="$router.push({
                            name: 'view_subscription',
                            params: { subscription_uuid: scope.row.uuid }
                          })"
                          :aria-label="$t('View subscription details for') + ' ' + scope.row.item_name"
                        >

                        <div class="fct-customer-orders-items-title">
                            {{ scope.row.item_name }}
                        </div>

                        <div class="fct-customer-orders-items-meta-wrap">
                            <Badge :hide-icon="true" :type="scope.row.status" size="small" aria-hidden="true">
                              {{ getStatusText(scope.row.status) }}
                            </Badge>

                            <div class="text-meta"
                                 v-if="scope.row.status !== 'expired' && scope.row.status !== 'canceled' && scope.row.status !== 'completed'" aria-live="polite">

                                <!-- translators: %s is the next billing date -->
                                {{
                                  $t('Auto renews on %s', dateTimeI18(scope.row.next_billing_date, 'DD MMM, YYYY'))
                                }}
                            </div>

                        </div>
                    </div>
                </template>
            </el-table-column>

            <el-table-column min-width="200" :label="$t('Plan')" align="right">
                <template #default="scope">
                    <div class="fct-customer-payment-meta-info">
                      <PaymentInfo :data="scope.row"/>
                      <CardInfo :data="scope.row"/>
                    </div>
                </template>
            </el-table-column>
        </el-table>
    </div>
</template>

<script type="text/babel">
import CardInfo from "./CardInfo.vue";
import Badge from "@/Bits/Components/Badge.vue";
import PaymentInfo from "./PaymentInfo.vue";
import translate, {dateTimeI18} from "../../translator/Translator";
import Str from "@/utils/support/Str";

export default {
    name: 'SubscriptionTable',
    components: {
      PaymentInfo,
        CardInfo,
        Badge
    },
    props: {
        subscriptions: {
            type: Array,
            required: true
        },
        hideHeader: {
            type: Boolean,
            default: false
        }
    },
    methods: {
      dateTimeI18,
      translate: translate,
        subscriptionStatus(subscription) {
            return subscription?.overridden_status ? subscription?.overridden_status : subscription?.status;
        },

      getStatusText(status) {
        switch (status) {
          case 'completed':
            return translate('Completed');
          case 'paid':
            return translate('Paid');
          case 'active':
            return translate('Active');
          case 'publish':
            return translate('Published');
          case 'draft':
            return translate('Draft');
          case 'shipped':
            return translate('Shipped');
          case 'success':
            return translate('Success');
          case 'licensed':
            return translate('Licensed');
          case 'succeeded':
            return translate('Succeeded');
          case 'failed':
            return translate('Failed');
          case 'error':
            return translate('Error');
          case 'canceled':
            return translate('Canceled');
          case 'expired':
            return translate('Expired');
          case 'partially_paid':
            return translate('Partially Paid');
          case 'intended':
            return translate('Intended');
          case 'scheduled':
            return translate('Scheduled');
          case 'on-hold':
            return translate('On Hold');
          case 'pending':
            return translate('Pending');
          case 'unpaid':
            return translate('Unpaid');
          case 'warning':
            return translate('Warning');
          case 'processing':
            return translate('Processing');
          case 'future':
            return translate('Future');
          case 'inactive':
            return translate('Inactive');
          case 'dispute':
            return translate('Dispute');
          case 'disabled':
            return translate('Disabled');
          case 'beta':
            return translate('Beta');
          default:
            return Str.headline(status);
        }
      }
    }
}
</script>
