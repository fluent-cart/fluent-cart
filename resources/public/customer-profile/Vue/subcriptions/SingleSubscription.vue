<template>
    <div class="fct-customer-dashboard-single-subcription-wrap fct-customer-dashboard-layout-width" role="main" aria-labelledby="subscription-overview-title">
        <div class="fct-customer-dashboard-single-subscription">
            <div class="fct-customer-dashboard-breadcrumb mb-8">
                <el-breadcrumb :separator-icon="ArrowRight" role="navigation" :aria-label="$t('Breadcrumb') ">
                    <el-breadcrumb-item class="cursor-pointer" :to="{ path: '/subscriptions' }">
                        {{ $t('Subscription Plans') }}
                    </el-breadcrumb-item>
                    <el-breadcrumb-item aria-current="page">{{ $t('Overview') }}</el-breadcrumb-item>
                </el-breadcrumb>
            </div>
            <template v-if="subscription">
                <div v-if="subscription.reactivate_url" class="fct-renew-box mb-4">
                    <el-alert type="error" :closable="false" role="alert">
                        <div class="text-center p-4">
                            <template v-if="subscription.status === 'expired'">
                                <p class="p-0 m-0 mb-3">
                                  <!-- translators: %s is the expiration date -->
                                    {{ $t('Your subscription has expired at %s. Please renew to keep things running smoothly.', formatDate(subscription.expire_at)) }}
                                </p>
                                <a :href="subscription.reactivate_url" class="el-button el-button--primary" :aria-label="$t('Renew Subscription Plan')">
                                    {{ $t('Renew Subscription Plan') }}
                                </a>
                            </template>
                            <template v-else>
                                <p class="p-0 m-0 mb-3">
                                  <!-- translators: %s is the subscription status -->
                                    {{ $t('Your subscription status is now: %s. Please reactivate to keep things running smoothly.', subscription.status) }}
                                </p>
                                <a :href="subscription.reactivate_url" class="el-button el-button--primary" :aria-label="$t('Reactivate Subscription Plan')">
                                    {{ $t('Reactivate Subscription Plan') }}
                                </a>
                            </template>
                        </div>
                    </el-alert>
                </div>

                <article class="fct-single-order-box mb-11 lg:mb-6" role="region" aria-labelledby="subscription-details-title">
                    <header class="fct-single-order-header">
                        <h1 id="subscription-details-title" class="title">
                            {{ $t('Subscription Plan Details') }}
                        </h1>
                    </header>
                    <div class="fct-single-order-body">
                        <div class="fct-single-order-box-inner">
                            <div class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="fct-product-info-card">
                                        <div class="fct-product-info-card-inner">
                                            <div class="product-info-content">
                                                <div class="title truncate-none">
                                                    {{ subscription.title }}
                                                    <Badge :status="subscriptionStatus()" size="small"/>
                                                </div>
                                                <div class="variation-title">
                                                  {{ subscription.subtitle }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="right-content !w-auto">
                                    <el-dropdown v-if="isCancelable" trigger="click" :hide-on-click="false" popper-class="fct-dropdown" role="menu" aria-haspopup="true" aria-expanded="false">
                                        <IconButton tag="button" size="x-small" class="simple-icon-btn" :aria-label="$t('Subscription options')">
                                            <DynamicIcon name="More" class="w-[5px]" aria-hidden="true"/>
                                        </IconButton>
                                        <template #dropdown>
                                            <el-dropdown-menu role="menu">
                                                <el-dropdown-item role="menuitem">
                                                    <el-popover
                                                        popper-class="fluent-cart-customer-profile-app"
                                                        trigger="click"
                                                        placement="bottom"
                                                        :width="260"
                                                        ref="popoverRef"
                                                        role="dialog"
                                                        aria-modal="true"
                                                        aria-labelledby="cancel-popover-title"
                                                    >

                                                    <h3 id="cancel-popover-title" class="sr-only">
                                                        {{ $t('Cancel Subscription') }}
                                                    </h3>

                                                        <div>
                                                            {{
                                                                $t('Confirm cancellation? Your access stays active until current billing cycle ends.')
                                                            }}
                                                        </div>

                                                        <div class="flex justify-end gap-2">
                                                            <el-button 
                                                                class="el-button--x-small" 
                                                                text
                                                                @click="$refs.popoverRef.hide()"
                                                                :aria-label="$t('Cancel')"
                                                            >
                                                                {{ $t('Cancel') }}
                                                            </el-button>
                                                            <el-button 
                                                                class="el-button--x-small" 
                                                                type="primary"
                                                                :loading="cancelling"
                                                                @click="cancelSubscription"
                                                                :aria-label="$t('Yes, Proceed')"
                                                            >
                                                                {{ $t('Yes, Proceed') }}
                                                            </el-button>
                                                        </div>

                                                        <template #reference>
                                                            <span :aria-label="$t('Cancel Subscription')">
                                                                {{ $t('Cancel Subscription') }}
                                                            </span>
                                                        </template>
                                                    </el-popover>
                                                </el-dropdown-item>
                                            </el-dropdown-menu>
                                        </template>
                                    </el-dropdown>
                                </div>
                            </div>
                            <div class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="title">{{ $t('Billing Terms') }}</div>
                                    <span class="text" :aria-label="$t('Billing Terms')" v-html="subscription.payment_info"></span>
                                </div>
                            </div>

                            <div v-if="subscription.status == 'active' || subscription.status == 'trialing'"
                                 class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="title">{{ $t('Next Charge') }}</div>
                                    <span class="text" :aria-label="$t('Next Charge')">{{ formatDate(subscription.next_billing_date) }}</span>
                                </div>
                            </div>

                            <div class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="title">{{ $t('Payment Method') }}</div>
                                    <span class="text" :aria-label="$t('Payment Method')">
                                        
                                     <span 
                                        v-if="subscription.billing_info.details?.last_4" 
                                        :aria-label="$t('Credit Card ending in') + ' ' + subscription.billing_info.details?.last_4"
                                    >
                                        **** {{ subscription.billing_info.details?.last_4 }}
                                    </span>

                                     <span v-else>{{ subscription.current_payment_method }}</span>
                                </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="fct-single-order-footer mt-3">
                        <div class="flex items-center gap-3">
                            <UpgradePlan v-if="subscription?.can_upgrade" button-type="primary"
                                         :button-text="$t('Upgrade Plan')" :variation_id="subscription.variation_id"
                                         :order_hash="subscription.order.uuid"/>

                            <UpdatePaymentInfos
                                v-if="subscription.can_update_payment_method"
                                :subscription="subscription" :buttonText="$t('Update Payment Method')"
                                @fetch="fetchSubscription" :updateMethod="true"/>
                                
                            <router-link 
                                class="underline-link-button" 
                                :to="{ name: 'view_order', params: { order_id: subscription.order.uuid } }"
                                :aria-label="$t('View Order')"
                            >
                                {{ $t('View Order') }}
                            </router-link>
                        </div>
                    </footer>
                </article>

                <article v-if="subscription.licenses && subscription.licenses.length" class="fct-single-order-box" role="region" aria-labelledby="licenses-title">
                    <header class="fct-single-order-header">
                        <h2 id="licenses-title" class="title">
                            {{ pluralizeTranslate('Connected License', 'Connected Licenses', subscription.licenses.length) }}
                        </h2>
                    </header>
                    <LicenseTable :is_simple="true" :licenses="subscription.licenses" :showTableHeader="false"/>
                </article>

                <article class="fct-single-order-box" role="region" aria-labelledby="transactions-title">
                    <header class="fct-single-order-header">
                        <h2 id="transactions-title" class="title">
                            {{ $t('Related Transactions') }}
                        </h2>
                    </header>

                    <div class="fct-customer-dashboard-table">
                      <TransactionsTable :transactions="subscription.transactions" :show-table-header="true"/>
                    </div>

                </article>

            </template>
            <div v-else-if="loading" aria-live="polite">
                <el-skeleton :rows="5" :animated="true"></el-skeleton>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import {ArrowRight} from "@element-plus/icons-vue";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import UpgradePlan from "./UpdatePaymentInfos/UpgradePlan.vue";
import UpdatePaymentInfos from "./UpdatePaymentInfos/index.vue";
import LicenseTable from "../parts/LicenseTable.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import TransactionsTable from "../parts/TransactionTable.vue";
import {pluralizeTranslate} from "../../translator/Translator";

export default {
    name: "SingleSubscription",
    props: {
        subscription_uuid: String
    },
    components: {
        TransactionsTable,
        IconButton,
        UpdatePaymentInfos,
        UpgradePlan,
        DynamicIcon,
        Badge,
        LicenseTable
    },
    data() {
        return {
            subscription: null,
            loading: true,
            cancelling: false
        };
    },
  computed: {
    isCancelable() {
      return !(this.subscription?.bill_times > 1 || this.subscription?.status === 'canceled');
    },
    ArrowRight () {
      return ArrowRight;
    }
  },
    methods: {
        fetchSubscription() {
            this.loading = true;
            this.$get(`customer-profile/subscriptions/${this.subscription_uuid}`)
                .then((response) => {
                    this.subscription = response.subscription;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },

        pluralizeTranslate,

        cancelSubscription() {
            this.cancelling = true;
            this.$post(`customer-profile/subscriptions/${this.subscription.uuid}/cancel-auto-renew`, {
                method: this.subscription.current_payment_method
            })
                .then((response) => {
                    this.$notify.success(response.message);
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.cancelling = false;
                    this.fetchSubscription();
                });
        },
        subscriptionStatus() {
          return this.subscription?.overridden_status ? this.subscription?.overridden_status : this.subscription?.status;
        }
    },
    mounted() {
        this.fetchSubscription();
    }
};
</script>
