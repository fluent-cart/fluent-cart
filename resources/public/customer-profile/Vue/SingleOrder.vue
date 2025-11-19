<template>
    <div 
        class="fct-customer-dashboard-single-order-wrap"
        role="main"
        :aria-labelledby="orderTitleId"
        :aria-busy="loading"
    >
        <div 
            class="fct-customer-dashboard-breadcrumb mb-4"
            role="navigation"
            :aria-label="$t('Breadcrumb navigation')"
        >
            <el-breadcrumb :separator-icon="ArrowRight">
                <el-breadcrumb-item :to="{ name: 'purchase-history' }">
                    {{ $t('Purchase History') }}
                </el-breadcrumb-item>
                <el-breadcrumb-item aria-current="page">#{{ order?.invoice_no }}</el-breadcrumb-item>
            </el-breadcrumb>
        </div>

        <template v-if="order">
            <h1 :id="orderTitleId" class="sr-only">{{ $t('Order Details') }} <span v-if="order.invoice_no">#{{ order.invoice_no }}</span></h1>

            <article class="fct-single-order-box" role="region" aria-labelledby="order-summary-title">
                <div v-if="sectionParts.before_summary" v-html="sectionParts.before_summary"></div>
                <div class="fct-single-order-body">
                    <section class="fct-customer-dashboard-order-items" aria-labelledby="order-summary-title">
                        <div class="fct-order-summary-wrap">
                            <header class="fct-order-summary-header">
                                <h2 id="order-summary-title" class="fct-order-summary-header-title">
                                    {{ $t('Summary') }}
                                </h2>

                                <div v-if="order" :aria-label="$t('Order status information')" class="fct-order-status-badges">
                                    <Badge
                                        :type="order.payment_status"
                                    >
                                      {{ getStatusText(order.payment_status) }}
                                    </Badge>
                                  <template v-if="order.fulfillment_type == 'physical'">
                                    <Badge
                                        class="ml-2"
                                        :type="order.status"
                                    >
                                      {{ getStatusText(order.status) }}
                                    </Badge>
                                    <Badge
                                        class="ml-2"
                                        :type="order.shipping_status"
                                    >
                                      {{ getStatusText(order.shipping_status) }}
                                    </Badge>
                                  </template>
                                </div>
                            </header>

                            <div class="fct-order-summary-body">
                                <ul class="fct-order-summary-items" role="list">
                                    <li v-for="(filteredOrderItem, i) in order.order_items" :key="i"
                                     class="fct-order-summary-item" role="listitem">
                                        <div class="fct-product-info-card">
                                            <div class="fct-product-info-card-inner">
                                                <div class="media">
                                                    <a 
                                                        target="_blank" 
                                                        :href="filteredOrderItem.url"
                                                        rel="noopener noreferrer"
                                                       :aria-label="$t('View product') + ' ' + filteredOrderItem.title"
                                                    >
                                                        <img
                                                            :src="getImage(filteredOrderItem)" 
                                                            :alt="$t('Product image for') + ' ' + filteredOrderItem.title"
                                                            loading="lazy"
                                                        />
                                                    </a>
                                                </div>

                                                <div class="product-info-content">
                                                    <div class="title">
                                                        <a 
                                                            target="_blank" 
                                                            :href="filteredOrderItem.url"
                                                            rel="noopener noreferrer"
                                                           :aria-label="$t('View product') + ' ' + filteredOrderItem.title"
                                                        >
                                                            <span class="text-gray-600"
                                                                v-if="filteredOrderItem.quantity > 1">{{
                                                                    filteredOrderItem.quantity
                                                                }} x</span>
                                                            {{ filteredOrderItem.post_title }}
                                                        </a>
                                                    </div>
                                                    <div class="variation-title">
                                                        {{ filteredOrderItem.title }}
                                                    </div>

                                                    <div
                                                        class="product-subscription-info flex flex-col gap-1 mt-1"
                                                        v-if="filteredOrderItem.meta_lines && filteredOrderItem.meta_lines.length > 0"
                                                    >
                                                        <span v-for="line in filteredOrderItem.meta_lines"
                                                            class="block leading-none text-xs"
                                                            v-html="line.label + ': ' + line.value"
                                                            :aria-label="line.label + ': ' + line.value"
                                                        ></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="fct-order-total text-right" :aria-label="$t('Subtotal for this item')">
                                            {{ formatNumber(filteredOrderItem.subtotal) }}
                                            <span class="text-sm" v-if="filteredOrderItem.extra_amount"><br/> + {{
                                                formatNumber(filteredOrderItem.extra_amount)
                                            }}</span>
                                        </div>
                                    </li>
                                </ul>
                                <div
                                    v-if="(order.manual_discount_total + order.coupon_discount_total) > 0 || order.shipping_total > 0 || (order.manual_discount_total + order.coupon_discount_total) > 0 || order.tax_total > 0"
                                    class="fct-order-summary-order-calculation">
                                    <div v-if="(order.manual_discount_total + order.coupon_discount_total) || order.subtotal > 0"
                                         class="tr">
                                        {{ $t('Subtotal') }} 
                                        <span>{{ formatNumber(order.subtotal) }} {{ parseInt(order.tax_behavior) == 2 ?  $t('(Inc. Tax)') : ''}}</span>
                                    </div>
                                    <div v-if="order.shipping_total > 0" class="tr">
                                        {{ $t('Shipping') }} <span>{{
                                            formatNumber(order.shipping_total)
                                        }}</span>
                                    </div>

                                    <div v-if="order.tax_total > 0" class="tr">
                                        {{ $t('Tax') }} {{order.tax_behavior == 2 ? $t('(Included)') : $t('(Excluded)')}} <span>{{
                                            formatNumber(order.tax_total)
                                        }}</span>
                                    </div>

                                    <div v-if="order.shipping_tax > 0" class="tr">
                                      {{ $t('Shipping Tax') }} {{order.tax_behavior == 2 ? $t('(Included)') : $t('(Excluded)')}}
                                      <span>
                                        {{ formatNumber(order.shipping_tax) }}
                                      </span>
                                    </div>

                                    <div v-if="order.coupon_discount_total > 0"
                                         class="tr">
                                        {{ $t('Coupon Discount') }}
                                        <span>-{{
                                                formatNumber(order.coupon_discount_total)
                                            }}</span>
                                    </div>

                                    <div v-if="order.manual_discount_total > 0"
                                         class="tr">
                                        {{ $t('Upgrade Discount') }}
                                        <span>-{{
                                                formatNumber(order.manual_discount_total)
                                            }}</span>
                                    </div>
                                </div>
                            </div>
                            <footer class="fct-order-summary-footer">
                                <div class="fct-order-summary-footer-inner">
                                    <div class="tr" :aria-level="$t('Total amount for the order')">
                                        {{ $t('Total') }} <span>{{ formatNumber(order.total_amount) }}</span>
                                    </div>
                                </div>
                            </footer>
                        </div>
                    </section>
                </div>
                <div v-if="sectionParts.after_summary" v-html="sectionParts.after_summary"></div>
            </article>

            <article v-if="order.subscriptions && order.subscriptions.length && order.payment_status !=='pending'" class="fct-single-order-box" role="region" aria-labelledby="subscription-title">
                <header class="fct-single-order-header">
                    <h2 id="subscription-title" class="title">{{ $t('Subscription Plan') }}</h2>
                </header>
                <SubscriptionTable :hideHeader="true" :subscriptions="order.subscriptions"/>

                <div v-if="sectionParts.after_subscriptions" v-html="sectionParts.after_subscriptions"></div>
            </article>

            <article v-if="order.downloads && order.downloads.length && order.payment_status !=='pending'" class="fct-single-order-box" role="region" aria-labelledby="downloads-title">
                <header class="fct-single-order-header">
                    <h2 id="downloads-title" class="title">
                        {{ pluralizeTranslate('Download', 'Downloads', order.downloads.length) }}
                    </h2>
                </header>
                <DownloadsTable :show-table-header="false" :downloads="order.downloads"/>

                <div v-if="sectionParts.after_downloads" v-html="sectionParts.after_downloads"></div>
            </article>

            <article v-if="order.licenses && order.licenses.length" class="fct-single-order-box" role="region" aria-labelledby="licenses-title">
                <header class="fct-single-order-header">
                    <h2 id="licenses-title" class="title">
                        {{ pluralizeTranslate('License', 'Licenses', order.licenses.length) }}
                    </h2>
                </header>
                <LicenseTable :licenses="order.licenses" :is_simple="true" :showTableHeader="false"/>

                <div v-if="sectionParts.after_licenses" v-html="sectionParts.after_licenses"></div>

            </article>

            <article v-if="order.transactions && order.transactions.length" class="fct-single-order-box" role="region" aria-labelledby="transactions-title">
                <div v-if="sectionParts.before_transactions" v-html="sectionParts.before_transactions"></div>

                <header class="fct-single-order-header">
                    <h2 id="transactions-title" class="title">{{ $t('Related Transactions') }}</h2>
                </header>
                <div class="fct-customer-dashboard-table">
                    <TransactionsTable :transactions="order.transactions" :show-table-header="true" @billing-address-updated="orderBillingAddressUpdated"/>
                </div>

                <div v-if="sectionParts.after_transactions" v-html="sectionParts.after_transactions"></div>

            </article>

            <article class="fct-single-order-box pb-5 lg:pb-0" v-if="order.billing_address_text || order.shipping_address_text" role="region" aria-labelledby="addresses-title">
                <h2 id="addresses-title" class="sr-only">{{ $t('Order addresses') }}</h2>

                <el-row :gutter="30">
                    <el-col :md="12" :sm="24">
                        <section class="fct-customer-dashboard-address mb-5 lg:mb-0" aria-labelledby="billing-title">
                          <h3 id="billing-title" class="title">{{ $t('Billing Address') }}</h3>
                          <div class="text" v-html="order.billing_address_text"></div>
                        </section>
                    </el-col>
                    <el-col :md="12" :sm="24" v-if="order.fulfillment_type == 'physical'">
                        <section class="fct-customer-dashboard-address" aria-labelledby="shipping-title">
                          <h3 id="shipping-title" class="title">{{ $t('Shipping Address') }}</h3>
                          <div class="text" v-html="order.shipping_address_text"></div>
                        </section>
                    </el-col>
                </el-row>
            </article>

            <div v-if="sectionParts.end_of_order" v-html="sectionParts.end_of_order"></div>

        </template>
        <template v-else-if="loading">
            <div aria-live="polite">
                <OrderTableLoader class="mb-4" :rows-range="[1, 2, 3]"/>
                <OrderTableLoader class="mb-4" :rows-range="[1, 2]"/>
                <OrderTableLoader :rows-range="[1, 2, 3]"/>
            </div>
        </template>
    </div>
</template>

<script type="text/babel">
import {ArrowRight} from '@element-plus/icons-vue';
import Badge from "@/Bits/Components/Badge.vue";
import SubscriptionTable from "./parts/SubscriptionTable.vue";
import DownloadsTable from "./parts/DownloadsTable.vue";
import LicenseTable from "./parts/LicenseTable.vue";
import TransactionsTable from "./parts/TransactionTable.vue";
import OrderTableLoader from "./parts/OrderTableLoader.vue";
import translate, {pluralizeTranslate} from '../translator/Translator'
import Str from "@/utils/support/Str";

export default {
    name: 'SingleOrderDetails',
    props: {
        order_id: {
            type: [String, Number],
            required: true
        }
    },
    watch: {
        order_id() {
            this.fetchOrder();
        }
    },
    components: {
        OrderTableLoader,
        TransactionsTable,
        LicenseTable,
        SubscriptionTable,
        Badge,
        DownloadsTable
    },
    data() {
        return {
            orderTitleId: 'order-title',
            order: null,
            loading: true,
            placeholderImage: window.fluentcart_customer_profile_vars.placeholder_image,
            sectionParts: {}
        };
    },
    computed: {
        ArrowRight() {
            return ArrowRight;
        }
    },
    methods: {
      orderBillingAddressUpdated(address) {
        this.order.billing_address_text = address;
      },
        getImage(item) {
            if(item.variant_image) {
                return item.variant_image;
            } else if(item.image) {
                return item.image;
            } else {
                return this.placeholderImage;
            }
        },
        fetchOrder() {
            this.$get("customer-profile/orders/" + this.order_id)
                .then((response) => {
                    this.order = response.order;
                    if(response.section_parts) {
                        this.sectionParts = response.section_parts;
                    }
                })
                .catch((error) => {
                    if (error?.data?.parent_order && error?.data?.parent_order?.uuid) {
                        this.$router.push({
                            name: 'view_order',
                            params: {
                                order_id: error.data.parent_order.uuid
                            }
                        });
                        return;
                    }

                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        pluralizeTranslate,
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
          case 'subscription':
            return translate('Subscription');
          case 'renewal':
            return translate('Renewal');
          case 'payment':
            return translate('Payment');
          case 'unshipped':
            return translate('Unshipped');
          default:
            return Str.headline(status);
        }
      }
    },
    mounted() {
        this.fetchOrder();
    }
};
</script>
