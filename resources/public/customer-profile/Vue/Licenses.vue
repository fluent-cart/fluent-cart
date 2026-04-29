<template>
    <div 
        class="fct-customer-dashboard-license"
        role="region"
        :aria-labelledby="licensesTitleId"
        :aria-busy="loading"
    >
        <div class="fct-customer-dashboard-license-inner">
            <div>
                <div class="fct-customer-dashboard-header">
                    <h4 :id="licensesTitleId" class="fct-customer-dashboard-title">{{ $t('Licenses') }}</h4>
                </div>

                <div v-if="loading && !appLoaded" aria-live="polite">
                    <OrderTableLoader :rows-range="[1,2,3,4]" />
                </div>
                
                <LicenseTable v-loading="loading" v-else-if="appLoaded && paginate.total" :licenses="licenses" :showTableHeader="true"/>

                <EmptyState v-else :title="$t('No licenses yet')" :text="$t('Looks like no licenses yet. Purchase something to view data.')">
                    <template #icon>
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                            <path opacity="0.4" d="M20.928 2.08337C23.9908 2.08334 26.4168 2.08332 28.3157 2.33861C30.2697 2.60131 31.8512 3.15484 33.0985 4.40211C34.3458 5.64939 34.8993 7.23097 35.162 9.18501C35.4173 11.0837 35.4172 13.5097 35.4172 16.5726V23.4274C35.4172 26.4902 35.4173 28.9164 35.162 30.815C34.8993 32.769 34.3458 34.3507 33.0985 35.598C31.8512 36.8452 30.2697 37.3987 28.3157 37.6615C26.4168 37.9167 23.9908 37.9167 20.928 37.9167H19.0733C16.0105 37.9167 13.5843 37.9167 11.6856 37.6615C9.7315 37.3987 8.14992 36.8452 6.90264 35.5979C5.65537 34.3507 5.10185 32.769 4.83915 30.815C4.58389 28.9164 4.58394 26.4902 4.58399 23.4274L4.58405 16.5726C4.58405 13.5096 4.58405 11.0836 4.83934 9.18494C5.10207 7.23091 5.6556 5.64934 6.90287 4.40209C8.15014 3.15482 9.7317 2.60131 11.6857 2.33859C13.5844 2.08332 16.0104 2.08334 19.0733 2.08337H20.928Z" fill="#758195"/>
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M11.666 11.6667C11.666 10.7462 12.4122 10 13.3327 10H26.666C27.5865 10 28.3327 10.7462 28.3327 11.6667C28.3327 12.5871 27.5865 13.3333 26.666 13.3333H13.3327C12.4122 13.3333 11.666 12.5871 11.666 11.6667ZM11.666 20C11.666 19.0795 12.4122 18.3333 13.3327 18.3333H26.666C27.5865 18.3333 28.3327 19.0795 28.3327 20C28.3327 20.9205 27.5865 21.6667 26.666 21.6667H13.3327C12.4122 21.6667 11.666 20.9205 11.666 20ZM11.666 28.3333C11.666 27.4128 12.4122 26.6667 13.3327 26.6667H19.9993C20.9198 26.6667 21.666 27.4128 21.666 28.3333C21.666 29.2538 20.9198 30 19.9993 30H13.3327C12.4122 30 11.666 29.2538 11.666 28.3333Z" fill="#758195"/>
                        </svg>
                    </template>
                </EmptyState>
            </div>

            <pagination
                v-if="paginate && paginate.total !== 0"
                :hide_on_single="true"
                :pagination="paginate"
                @fetch="fetchLicenses"
            />
        </div>
    </div>
</template>

<script type="text/babel">
import LicenseTable from "./parts/LicenseTable.vue";
import OrderTableLoader from "./parts/OrderTableLoader.vue";
import EmptyState from './EmptyState.vue';
import Pagination from "./parts/Pagination.vue";

export default {
    name: 'Licenses',
    components: {
        LicenseTable,
        Pagination,
        OrderTableLoader,
        EmptyState
    },
    data() {
        return {
            licensesTitleId: 'licenses-title',
            licenses: [],
            loading: false,
            skeletonRowCount: 10,
            paginate: {
                current_page: 1,
                total: 0,
                per_page: 10
            },
            search: '',
            appLoaded: false,
        };
    },
    methods: {
        fetchLicenses() {
            this.loading = true;
            let queryParams = {
                "per_page": this.paginate.per_page,
                "page": this.paginate.current_page
            };
            this.$get("customer-profile/licenses", {
                per_page: this.paginate.per_page,
                page: this.paginate.current_page,
                search: this.search
            })
                .then((response) => {
                    this.licenses = response.licenses.data;
                    this.paginate.total = response.licenses.total;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                    this.appLoaded = true;
                });
        }
    },
    mounted() {
        this.fetchLicenses();
    }
};
</script>
