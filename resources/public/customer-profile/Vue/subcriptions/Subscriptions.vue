<template>
    <div class="fct-customer-dashboard-subscriptions" role="main" aria-labelledby="subscriptions-title">
        <div class="fct-customer-dashboard-subscriptions-inner">
            <header class="fct-customer-dashboard-header">
                <h4 id="subscriptions-title" class="fct-customer-dashboard-title">
                    {{ $t('Subscription Plans') }}
                </h4>
            </header>

            <SubscriptionTable v-if="subscriptions.length" :subscriptions="subscriptions" :aria-label="$t('Subscription plans table')"/>

            <div v-else-if="loading" class="fct-customer-dashboard-loading" aria-live="polite" aria-busy="true">
                <OrderTableLoader :rows-range="[1, 2, 3, 4, 5]"/>
            </div>

            <EmptyState 
                v-else 
                :title="$t('No subscriptions yet')" 
                :text="$t('Once you subscribe to a plan, it will appear here.')"
                role="status"
            >
                <template #icon>
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                        <path d="M33.5429 11.9789C34.291 11.4426 35.3322 11.6143 35.8685 12.3624C37.4175 14.5232 38.3297 17.1585 38.3297 20.0001C38.3297 27.4066 32.172 33.3335 24.6727 33.3335H14.349V35C14.3491 35.419 14.1921 35.8383 13.8769 36.1623C13.235 36.8221 12.1798 36.8365 11.5201 36.1946L9.84873 34.5685C9.53043 34.259 9.11337 33.8536 8.86883 33.5373C8.65405 33.2595 8.03432 32.3905 8.48888 31.3226C8.93338 30.2785 9.96332 30.1015 10.3062 30.0543C10.7031 29.9996 11.2015 29.9998 11.6536 30.0001H11.7377H24.6727C30.4177 30.0001 34.9963 25.4803 34.9963 20.0001C34.9963 17.8841 34.3197 15.9231 33.1593 14.3045C32.623 13.5564 32.7949 12.5152 33.5429 11.9789Z" fill="black"/>
                        <path opacity="0.4" d="M6.45682 28.0212C5.7087 28.5575 4.66751 28.3857 4.13121 27.6377C2.58217 25.4768 1.66992 22.8415 1.66992 20C1.66992 12.5934 7.82769 6.66658 15.327 6.66658H25.6507V5.00005C25.6507 4.58106 25.8075 4.16166 26.1228 3.83765C26.7647 3.17793 27.8198 3.16348 28.4797 3.8054L30.151 5.43163C30.4692 5.741 30.8863 6.1464 31.1308 6.46268C31.3457 6.74053 31.9653 7.60961 31.5108 8.67741C31.0663 9.72158 30.0363 9.89861 29.6935 9.94578C29.2965 10.0004 28.7982 10.0002 28.3462 9.99995L28.262 9.99991H15.327C9.58204 9.99991 5.00326 14.5198 5.00326 20C5.00326 22.1158 5.67996 24.0768 6.84032 25.6955C7.37662 26.4437 7.20492 27.4848 6.45682 28.0212Z" fill="black"/>
                    </svg>
                </template>
            </EmptyState>

            <pagination
                v-if="paginate.total"
                :hide_on_single="true"
                :pagination="paginate"
                @fetch="fetchSubscriptions"
                :aria-label="$t('Subscription pagination')"
            />
        </div>

    </div>

</template>

<script type="text/babel">
import Badge from "@/Bits/Components/Badge.vue";
import SubscriptionTable from "../parts/SubscriptionTable.vue";
import OrderTableLoader from "../parts/OrderTableLoader.vue";
import translate from "../../translator/Translator";
import EmptyState from "../EmptyState.vue";
import Pagination from "../parts/Pagination.vue";

export default {
    name: 'SubscriptionPlans',
    components: {
        OrderTableLoader,
        Badge,
        Pagination,
        SubscriptionTable,
        EmptyState
    },
    props: {
        showLimit: {
            type: Number,
            default: 10
        },
        showViewAllLink: {
            type: Boolean,
            default: false
        }
    },
    data() {
        return {
            loading: false,
            appLoaded: false,
            subscriptions: [],
            canceling_subscription: false,
            paginate: {
                current_page: 1,
                per_page: 10,
                total: 0
            }
        };
    },
    methods: {
        subscriptionStatus(subscription) {
            return subscription?.overridden_status ? subscription?.overridden_status : subscription?.status;
        },
        modifySubscriptionResponse(subscriptions) {
            return subscriptions.map((subscription) => {
                return {
                    ...subscription,
                    card_last_4: subscription?.billingInfo?.card?.last_4
                }
            });
        },
        fetchSubscriptions() {
            this.loading = true;
            this.$get("customer-profile/subscriptions", {
                per_page: this.paginate.per_page,
                page: this.paginate.current_page,
            })
                .then((response) => {
                    this.subscriptions = response.subscriptions.data;
                    this.paginate.total = response.subscriptions.total;
                })
                .finally(() => {
                    this.appLoaded = true;
                    this.loading = false;
                });
        },
        cancelSubscription(subscriptionId, vendorSubscriptionId, orderId) {
            ElMessageBox.confirm(translate('It will permanently cancel the active subscription from your payment provider. Are you sure to do that?'), translate('Warning'), {
                    type: "warning",
                    cancelButtonText: translate("No, keep this!"),
                    confirmButtonText: translate("Yes cancel this subscription!"),
                }
            ).then(() => {
                this.canceling_subscription = true;

                this.$put(`orders/${orderId}/subscriptions/${subscriptionId}/cancel`, {
                    data: {
                        vendor_charge_id: vendorSubscriptionId,
                    },
                }).then(() => {
                    this.canceling_subscription = false;
                    this.fetchSubscriptions();
                })
                    .catch((errors) => {
                        this.canceling_subscription = false;
                        console.log(errors)
                    });
            });
        },
    },
    mounted() {
        this.fetchSubscriptions();
    }
}
</script>
