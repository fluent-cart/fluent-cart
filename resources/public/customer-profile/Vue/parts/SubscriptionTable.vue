<template>
    <div class="fct-customer-dashboard-table">
      <!-- mobile view -->
      <div class="subscription-only-mobile">
        <div v-if="subscriptions.length > 0" class="subscription-only-mobile-item" v-for="subscription in subscriptions" :key="subscription.id">
          <div class="item-header">
            <CardInfo :data="subscription" :show-payment-info="showPaymentInfo"/>
            <div
                class="text"
                v-if="subscription.status == 'active' || subscription.status == 'trialing'"
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
                            <Badge :hide-icon="true" :type="scope.row?.overridden_status" size="small" aria-hidden="true">
                              {{ getStatusText(scope.row?.overridden_status) }}
                            </Badge>

                            <div class="text-meta"
                                 v-if="scope.row.status == 'active' || scope.row.status == 'trialing'" aria-live="polite">

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
import Badge from "./Badge.vue";
import PaymentInfo from "./PaymentInfo.vue";
import translate, {dateTimeI18} from "../../translator/Translator";
import statusLabel from "../../utils/statusLabels";

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

      getStatusText: statusLabel
    }
}
</script>
