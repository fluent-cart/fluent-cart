<template>
    <div 
        class="fct-customer-dashboard-download"
        role="region"
        :aria-labelledby="downloadsTitleId"
        :aria-busy="loading || !appLoaded"
    >
        <div class="fct-customer-dashboard-header">
            <h4 :id="downloadsTitleId" class="fct-customer-dashboard-title">{{ $t('Downloads') }}</h4>
            <div class="actions" v-if="isLimited">
                <router-link class="is-link" :to="{ name: 'downloads' }" :aria-label="$t('View all downloads')">
                    {{ $t('View all') }}
                </router-link>
            </div>
        </div>

      <div v-if="!appLoaded" aria-live="polite">
        <order-table-loader :rows-range="[1, 2, 3, 4]" />
      </div>
      
      <div v-else-if="appLoaded" v-loading="loading" class="fct-customer-dashboard-downloads-table" aria-live="polite">
        <DownloadsTable v-if="downloads?.length > 0" :downloads="downloads"/>

        <EmptyState v-else :title="$t('No downloads yet')" :text="$t('Your valid downloads will be shown here.')">
            <template #icon>
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                <path opacity="0.4" d="M18.3328 5.41667C18.3328 4.49618 19.079 3.75 19.9995 3.75C20.92 3.75 21.6661 4.49618 21.6661 5.41667V22.998C22.0163 22.6323 22.378 22.2345 22.734 21.8245C23.4321 21.0203 24.0816 20.2106 24.5585 19.5996C24.796 19.2953 24.9885 19.0418 25.1216 18.8656C25.1881 18.7775 25.2401 18.7083 25.2746 18.6621C25.2916 18.6393 25.3036 18.6213 25.312 18.61C25.316 18.6046 25.3198 18.6013 25.3218 18.5986L25.3235 18.5953C25.8693 17.8541 26.913 17.6963 27.6541 18.2421C28.395 18.788 28.5541 19.8301 28.009 20.5713H28.0073V20.5745C28.0065 20.5756 28.0038 20.576 28.0025 20.5778C27.9996 20.5816 27.9961 20.5873 27.9911 20.5941C27.9808 20.608 27.9663 20.6285 27.9471 20.6543C27.9088 20.7058 27.8523 20.7798 27.7811 20.874C27.639 21.0623 27.4353 21.3301 27.1855 21.6503C26.6868 22.2893 25.9988 23.1465 25.2501 24.0088C24.5085 24.863 23.6738 25.76 22.8853 26.4566C22.4931 26.8031 22.0738 27.1363 21.6515 27.391C21.2748 27.618 20.6833 27.9166 19.9995 27.9166C19.3158 27.9166 18.7241 27.618 18.3475 27.391C17.9253 27.1363 17.506 26.8031 17.1138 26.4566C16.3253 25.76 15.4905 24.863 14.7489 24.0088C14.0002 23.1465 13.3122 22.2893 12.8137 21.6503C12.5637 21.3301 12.3601 21.0623 12.2179 20.874C12.1468 20.7798 12.0903 20.7058 12.0519 20.6543C12.0327 20.6285 12.0183 20.608 12.008 20.5941C12.0029 20.5871 11.9978 20.5816 11.995 20.5778C11.9939 20.5763 11.9924 20.5755 11.9917 20.5745V20.5713C11.4464 19.8301 11.6039 18.7863 12.3449 18.2406C13.086 17.6953 14.1299 17.8545 14.6756 18.5953L14.6773 18.5986C14.6792 18.6013 14.6831 18.6046 14.687 18.61C14.6954 18.6213 14.7074 18.6393 14.7245 18.6621C14.7589 18.7083 14.8109 18.7775 14.8775 18.8656C15.0106 19.0418 15.2031 19.2953 15.4406 19.5996C15.9174 20.2106 16.5669 21.0203 17.2651 21.8245C17.621 22.2345 17.9828 22.6323 18.3328 22.998V5.41667Z" fill="black"/>
                <path d="M3.75 23.75V20.415L3.7614 19.987C3.98537 15.5864 7.6225 12.086 12.0785 12.0834H12.0833C13.0036 12.0834 13.7496 12.8299 13.75 13.75C13.75 14.6705 13.0038 15.4167 12.0833 15.4167H12.0801C9.32045 15.4185 7.08403 17.657 7.08333 20.4167V23.75C7.08333 25.7243 7.08512 27.1007 7.17612 28.1707C7.26538 29.2198 7.43154 29.8213 7.67417 30.2752C8.14029 31.1468 8.85484 31.8615 9.72657 32.3275C10.1803 32.5698 10.7808 32.7363 11.8294 32.8255C12.8993 32.9165 14.2758 32.9167 16.25 32.9167H23.75C25.7242 32.9167 27.1007 32.9148 28.1705 32.824C29.2195 32.7347 29.8197 32.5683 30.2734 32.3258C31.1455 31.8597 31.8597 31.1455 32.3259 30.2735C32.5684 29.8198 32.7347 29.2195 32.8239 28.1707C32.9149 27.1008 32.9167 25.7242 32.9167 23.75V20.4167C32.9167 17.6553 30.678 15.4167 27.9167 15.4167C26.9962 15.4167 26.25 14.6705 26.25 13.75C26.25 12.8296 26.9962 12.0834 27.9167 12.0834C32.519 12.0834 36.25 15.8143 36.25 20.4167V23.75C36.25 25.6665 36.2515 27.2083 36.1459 28.4522C36.0384 29.7167 35.8099 30.8252 35.2654 31.8442C34.4885 33.2973 33.2989 34.4883 31.8457 35.2653C30.8267 35.81 29.7167 36.0383 28.4522 36.1458C27.8305 36.1987 27.1344 36.2242 26.3542 36.237L23.75 36.25H16.25C14.3335 36.25 12.7917 36.2517 11.5479 36.1458C10.2831 36.0383 9.17335 35.8117 8.1543 35.267C6.70118 34.4902 5.51155 33.2988 4.7347 31.8457C4.19002 30.8268 3.96177 29.7183 3.85417 28.4538C3.74833 27.2097 3.75 25.6672 3.75 23.75Z" fill="black"/>
                </svg>
            </template>
        </EmptyState>
      </div>

    
        <pagination
            v-if="paginate.total > 0"
            :hide_on_single="true"
            :pagination="paginate"
            @fetch="fetchFiles"
        />
    </div>
</template>

<script type="text/babel">
import DownloadsTable from "./parts/DownloadsTable.vue";
import OrderTableLoader from "./parts/OrderTableLoader.vue";
import EmptyState from './EmptyState.vue';
import Pagination from "./parts/Pagination.vue";

export default {
    components: {
        OrderTableLoader,
        Pagination,
        DownloadsTable,
        EmptyState
    },
    props: {
        isLimited: {
            type: Boolean,
            default: false
        },
        per_page: {
            type: Number,
            default: 10
        },
    },
    data() {
        return {
            downloadsTitleId: 'downloads-title',
            downloads: [],
            loading: false,
            appLoaded: false,
            paginate: {
                current_page: 1,
                per_page: this.per_page,
                total: 0
            },
        };
    },
    methods: {
        fetchFiles() {
            this.loading = true;
            this.$get('customer-profile/downloads', {
                per_page: this.paginate.per_page,
                page: this.paginate.current_page,
            })
                .then((response) => {
                    this.downloads = response.downloads?.data;
                    this.paginate.total = response.downloads?.total;
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
        this.fetchFiles();
    }
};
</script>

