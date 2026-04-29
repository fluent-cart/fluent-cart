<template>
    <div 
        class="fct-customer-purchase-history"
        role="region"
        :aria-labelledby="purchaseHistoryTitleId"
        :aria-busy="loading"
    >
        <div class="fct-customer-purchase-history-inner">
            <div>
                <div class="fct-customer-dashboard-header">
                    <h4 :id="purchaseHistoryTitleId" class="fct-customer-dashboard-title">
                        {{ $t('Purchase History') }}
                    </h4>
                    <div class="actions">
                        <div class="fct-collapsible-search-wrap">
                            <label for="purchase-search-input" class="sr-only">
                              {{ $t('Search orders') }}
                            </label>

                            <transition name="fade-slide">
                              <el-input
                                  id="purchase-search-input"
                                  v-if="showSearch"
                                  v-model="search"
                                  @keyup.enter="fetchOrders"
                                  @clear="fetchOrders"
                                  :placeholder="$t('Search and hit enter...')"
                                  clearable
                                  size="small"
                                  aria-describedby="search-help"
                              />
                            </transition>

                            <small id="search-help" class="sr-only">
                              {{ $t('Press Enter to search') }}
                            </small>

                            <IconButton
                                tag="button"
                                size="small"
                                @click="showSearch = !showSearch"
                                :aria-expanded="showSearch"
                                :aria-label="showSearch ? $t('Close search') : $t('Open search')"
                                aria-controls="purchase-search-input"
                            >
                                <DynamicIcon v-if="showSearch" name="Cross" aria-hidden="true"/>
                                <DynamicIcon v-else name="Search" aria-hidden="true"/>
                            </IconButton>
                        </div>
                    </div>
                </div>
                
                <div v-if="loading && !app_loaded" aria-live="polite">
                    <OrderTableLoader :rowsRange="[1, 2, 3, 4, 5]"/>
                </div>


                <div v-else-if="app_loaded" v-loading="loading" aria-live="polite">
                    <OrderTable v-if="orders.length > 0" :orders="orders" :showTableHeader="true"/>

                    <EmptyState v-else :title="$t('No purchases yet')" :text="$t('Your purchases will be shown here!')">
                        <template #icon>
                            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                            <path opacity="0.4" d="M35 28.6021C35 30.9063 31.7573 32.2761 25.2718 35.0158C22.6663 36.1163 21.3637 36.6666 20 36.6666C18.6363 36.6666 17.3337 36.1163 14.7282 35.0158C8.24273 32.2761 5 30.9063 5 28.6021V11.6666L20 18.9246L35 11.6666V28.6021Z" fill="#758195"/>
                            <path d="M20 36.6666C18.6363 36.6666 17.3337 36.1163 14.7282 35.0158C8.24273 32.2761 5 30.9063 5 28.6021C5 27.957 5 16.7741 5 11.6666M20 36.6666C21.3637 36.6666 22.6663 36.1163 25.2718 35.0158C31.7573 32.2761 35 30.9063 35 28.6021V11.6666M20 36.6666V18.9246" stroke="#758195" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M13.8765 16.1523L9.00787 13.7965C6.33595 12.5035 5 11.8571 5 10.8334C5 9.80966 6.33595 9.16321 9.00787 7.87029L13.8765 5.51441C16.8813 4.06039 18.3838 3.33337 20 3.33337C21.6162 3.33337 23.1187 4.06037 26.1235 5.51441L30.9922 7.87029C33.664 9.16321 35 9.80966 35 10.8334C35 11.8571 33.664 12.5035 30.9922 13.7965L26.1235 16.1523C23.1187 17.6064 21.6162 18.3334 20 18.3334C18.3838 18.3334 16.8813 17.6064 13.8765 16.1523Z" stroke="#758195" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M10 20L13.3333 21.6667" stroke="#758195" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M28.3327 6.66663L11.666 15" stroke="#758195" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </template>
                    </EmptyState>
                </div>
                
            </div>

            <pagination
                v-if="paginate && paginate.total !== 0"
                :hide_on_single="true"
                :pagination="paginate"
                @fetch="fetchOrders"
            />
        </div>
    </div>
</template>

<script type="text/babel">
import OrderTable from "./parts/OrderTable.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import OrderTableLoader from "./parts/OrderTableLoader.vue";
import EmptyState from './EmptyState.vue';
import Pagination from "./parts/Pagination.vue";
import { formatOrderItems } from "@/Bits/common";

export default {
    name: 'PurchaseHistory',
    components: {
        OrderTable,
        Pagination,
        IconButton,
        DynamicIcon,
        OrderTableLoader,
        EmptyState
    },
    data() {
        return {
            purchaseHistoryTitleId: 'purchase-history-title',
            search: '',
            showSearch: false,
            orders: [],
            paginate: {
                current_page: 1,
                per_page: 10,
                total: 0
            },
            loading: true,
            app_loaded: false,
        }
    },
    methods: {
      formatOrderItems,
        fetchOrders() {
            this.loading = true;
            this.$get("customer-profile/orders", {
                per_page: this.paginate.per_page,
                page: this.paginate.current_page,
                search: this.search
            })
                .then((response) => {
                    this.orders = response.orders.data;
                    this.paginate.total = response.orders.total;
                    this.orders.forEach((order) => {
                      order.order_items = this.formatOrderItems(order.order_items);
                    });
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                    this.app_loaded = true;
                });
        }
    },
    mounted() {
        this.fetchOrders();
    }
}
</script>


