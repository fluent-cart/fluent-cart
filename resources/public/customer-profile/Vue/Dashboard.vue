<template>
    <div 
         class="fct-customer-dashboard fct-customer-dashboard-layout-width"
         role="region"
         :aria-labelledby="recentPurchasesTitleId"
         :aria-busy="loading"
     >
        <div>
            <div class="fct-customer-dashboard-header">
                <h4 :id="recentPurchasesTitleId" class="fct-customer-dashboard-title">
                    {{ $t('Your Recent Purchases') }}
                </h4>
                <div class="actions">
                    <router-link
                        v-if="dashboardData.orders.length"
                        class="is-link"
                        :to="{ name: 'purchase-history'}"
                        :aria-label="$t('View all purchases')"
                    >
                        {{ $t('View all') }}
                    </router-link>
                </div>
            </div>

            <div v-if="loading" aria-live="polite">
                <OrderTableLoader :rows-range="[1,2]"/>
            </div>

            <div v-if="sectionParts.before_orders_table" v-html="sectionParts.before_orders_table"></div>

            <OrderTable v-else :orders="dashboardData.orders"/>

            <div v-if="sectionParts.after_orders_table" v-html="sectionParts.after_orders_table"></div>

        </div>
    </div>
</template>

<script type="text/babel">
import OrderTable from "./parts/OrderTable.vue";
import OrderTableLoader from "./parts/OrderTableLoader.vue";
import { formatOrderItems } from "@/Bits/common";

export default {
    name: "Dashboard",
    components: {
        OrderTable,
        OrderTableLoader
    },
    data() {
        return {
            recentPurchasesTitleId: 'recent-purchases-title',
            dashboardData: {
                orders: [],
                subscriptions: [],
                licenses: []
            },
            sectionParts: {},
            loading: false
        };
    },
    methods: {
      formatOrderItems,
        fetchDashboardData() {
            this.loading = true;
            this.$get("customer-profile")
                .then((response) => {
                    this.dashboardData = response.dashboard_data;

                    this.dashboardData.orders.forEach((order) => {
                      order.order_items = this.formatOrderItems(order.order_items);
                    });

                    if(response.section_parts) {
                        this.sectionParts = response.section_parts;
                    }
                })
                .finally(() => {
                    this.loading = false;
                });
        }
    },
    mounted() {
        this.fetchDashboardData();
    }
}
</script>


