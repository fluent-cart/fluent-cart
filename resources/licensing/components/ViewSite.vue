<template>
    <div>
        <NotFound v-if="notFound.show" :button-text="notFound.buttonText" :message="notFound.message"
                  :route="notFound.route"/>
        <div v-if="notFound.show === false" class="fct-view-site-wrap fct-layout-width">
            <template v-if="!loading">
                <template v-if="site">
                    <div class="single-page-header">
                        <el-breadcrumb class="hide-license-breadcrumb-on-mobile" :separator-icon="ArrowRight">
                            <el-breadcrumb-item :to="{ name: 'licenses' }">
                                {{ translate('Licenses') }}
                            </el-breadcrumb-item>
                            <el-breadcrumb-item :to="{ name: 'activated_sites' }">
                                {{ translate('Sites') }}
                            </el-breadcrumb-item>
                            <el-breadcrumb-item>
                                <div class="flex items-center gap-3 flex-wrap whitespace-pre-wrap" style="overflow-wrap: anywhere;">
                                    {{ site.site_url }}
                                </div>
                            </el-breadcrumb-item>
                        </el-breadcrumb>

                        <!-- mobile view -->
                        <ul class="license-breadcrumb-only-mobile">
                            <li>
                                <router-link :to="{ name: 'activated_sites' }">
                                    <DynamicIcon name="ArrowLeft" class="cursor-pointer"/>
                                </router-link>
                            </li>
                            <li>{{ site.site_url }}</li>
                        </ul>
                        <!-- mobile view -->
                    </div><!-- .single-page-header -->

                    <div class="single-page-body">
                        <el-row :gutter="30">
                            <el-col :lg="17">
                                <div class="fct-view-site-content-wrap">

                                    <!-- Activated Licenses -->
                                    <Card.Container class="overflow-hidden">
                                        <Card.Header :title="translate('Activated Licenses')" title_size="small"/>
                                        <Card.Body class="px-0 pb-0">
                                            <div class="fct-related-license-content">
                                                <el-table :data="activations" class="w-full">
                                                    <el-table-column :min-width="160" :label="translate('Product')">
                                                        <template #default="scope">
                                                            <div>
                                                                <router-link v-if="scope.row.product_id" class="link"
                                                                             :to="{ name: 'product_edit', params: { product_id: scope.row.product_id } }">
                                                                    {{ scope.row.product_name }}
                                                                </router-link>
                                                                <span v-else>{{ scope.row.product_name }}</span>
                                                                <span v-if="scope.row.variation_title"
                                                                      class="text-gray-400 block text-xs">
                                                                    – {{ scope.row.variation_title }}
                                                                </span>
                                                            </div>
                                                        </template>
                                                    </el-table-column>

                                                    <el-table-column :width="110" :label="translate('Status')">
                                                        <template #default="scope">
                                                            <Badge :status="scope.row.license_status" size="small"/>
                                                        </template>
                                                    </el-table-column>

                                                    <el-table-column :width="180" :label="translate('License Key')">
                                                        <template #default="scope">
                                                            <span class="fct-license-key">
                                                                <span class="fct-license-key-inner pr-2">
                                                                    <router-link class="link text-xs"
                                                                                 :to="{ name: 'view_license', params: { license_id: scope.row.license_id } }">
                                                                        {{ scope.row.license_key.substring(0, 12) }}...
                                                                    </router-link>
                                                                </span>
                                                                <el-tooltip :content="scope.row.license_key" placement="top" popper-class="fct-tooltip">
                                                                    <DynamicIcon class="cursor-pointer p-1 w-6 h-6 flex-none" name="Copy" @click="()=>{
                                                                        Clipboard.copy(scope.row.license_key, {
                                                                            'successMessage': translate('License Key copied to clipboard'),
                                                                            'errorMessage': translate('Failed to copy License Key')
                                                                        });
                                                                    }"/>
                                                                </el-tooltip>
                                                            </span>
                                                        </template>
                                                    </el-table-column>

                                                    <el-table-column :width="100" :label="translate('Activations')">
                                                        <template #default="scope">
                                                            <span v-if="scope.row.activation_limit !== null">
                                                                {{ scope.row.activation_count }} / {{ scope.row.activation_limit == 0 ? translate('Unlimited') : scope.row.activation_limit }}
                                                            </span>
                                                            <span v-else>—</span>
                                                        </template>
                                                    </el-table-column>

                                                    <el-table-column :width="130" :label="translate('Expiration')">
                                                        <template #default="scope">
                                                            <span v-if="scope.row.expiration_date">{{ formatDate(scope.row.expiration_date) }}</span>
                                                            <span v-else>{{ translate('Lifetime') }}</span>
                                                        </template>
                                                    </el-table-column>

                                                    <el-table-column :width="130" :label="translate('Last Update')">
                                                        <template #default="scope">
                                                            <div v-if="scope.row.last_update_date">
                                                                <span v-if="scope.row.last_update_version" class="block text-xs">{{ scope.row.last_update_version }}</span>
                                                                <span class="text-gray-400 text-xs">{{ formatDate(scope.row.last_update_date) }}</span>
                                                            </div>
                                                            <span v-else class="text-gray-300">—</span>
                                                        </template>
                                                    </el-table-column>

                                                    <template #empty>
                                                        <Empty icon="Empty/ListView" :has-dark="true"
                                                               :text="translate('No activations found')"/>
                                                    </template>
                                                </el-table>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>

                                    <!-- Related Orders -->
                                    <Card.Container v-if="orders.length" class="overflow-hidden">
                                        <Card.Header :title="translate('Related Orders')" title_size="small"/>
                                        <Card.Body class="px-0 pb-0">
                                            <div class="fct-related-license-content">
                                                <el-table :data="orders" class="w-full">
                                                    <el-table-column :width="120" :label="translate('ID')">
                                                        <template #default="scope">
                                                            <router-link class="link"
                                                                         :to="{ name: 'view_order', params: { order_id: scope.row.id } }">
                                                                #{{ scope.row.id }}
                                                            </router-link>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :width="120" :label="translate('Amount')">
                                                        <template #default="scope">
                                                            <span>{{ formatNumber(scope.row.total_paid) }}</span>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :width="150" :label="translate('Date')">
                                                        <template #default="scope">
                                                            <span>{{ formatDate(scope.row.created_at) }}</span>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :label="translate('Payment Method')">
                                                        <template #default="scope">
                                                            {{ scope.row.payment_method }}
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :label="translate('Status')">
                                                        <template #default="scope">
                                                            <Badge :status="scope.row.payment_status" size="small"/>
                                                        </template>
                                                    </el-table-column>
                                                </el-table>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>
                                </div>
                            </el-col>

                            <el-col :lg="7">
                                <div class="fct-admin-sidebar">
                                    <!-- Site Details -->
                                    <Card.Container>
                                        <Card.Header :title="translate('Site Details')" title_size="small" border_bottom/>
                                        <Card.Body>
                                            <div class="fct-site-details-list grid gap-4">
                                                <div class="fct-site-detail-item">
                                                    <span class="text-gray-400 text-xs block mb-1">{{ translate('Site URL') }}</span>
                                                    <a :href="siteExternalUrl" target="_blank" rel="noopener noreferrer" class="link font-medium inline-flex items-center gap-1.5" style="outline: none; box-shadow: none;">
                                                        {{ site.site_url }}
                                                        <DynamicIcon name="External" class="w-3.5 h-3.5 text-gray-400"/>
                                                    </a>
                                                </div>

                                                <div v-if="hasLocalActivation" class="fct-site-detail-item">
                                                    <span class="text-gray-400 text-xs block mb-1">{{ translate('Environment') }}</span>
                                                    <Badge status="warning" size="small" :hide-icon="true" :text="translate('Local / Staging')"/>
                                                </div>

                                                <div v-if="site.platform_version" class="fct-site-detail-item">
                                                    <span class="text-gray-400 text-xs block mb-1">{{ translate('Platform Version') }}</span>
                                                    <span>{{ site.platform_version }}</span>
                                                </div>

                                                <div v-if="site.server_version" class="fct-site-detail-item">
                                                    <span class="text-gray-400 text-xs block mb-1">{{ translate('Server Version') }}</span>
                                                    <span>{{ site.server_version }}</span>
                                                </div>

                                                <div v-if="site.created_at" class="fct-site-detail-item">
                                                    <span class="text-gray-400 text-xs block mb-1">{{ translate('Registered At') }}</span>
                                                    <span>{{ formatFullDate(site.created_at) }}</span>
                                                </div>

                                                <div v-if="lastUpdateInfo" class="fct-site-detail-item">
                                                    <span class="text-gray-400 text-xs block mb-1">{{ translate('Last Update Check') }}</span>
                                                    <span v-if="lastUpdateInfo.version">{{ lastUpdateInfo.version }} &mdash; {{ formatFullDate(lastUpdateInfo.date) }}</span>
                                                    <span v-else>{{ formatFullDate(lastUpdateInfo.date) }}</span>
                                                </div>

                                                <template v-if="site.other && Object.keys(site.other).length">
                                                    <div v-for="(val, key) in site.other" :key="key" class="fct-site-detail-item">
                                                        <span class="text-gray-400 text-xs block mb-1">{{ formatMetaKey(key) }}</span>
                                                        <span>{{ val }}</span>
                                                    </div>
                                                </template>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>

                                    <!-- Summary -->
                                    <Card.Container>
                                        <Card.Header :title="translate('Summary')" title_size="small" border_bottom/>
                                        <Card.Body>
                                            <div class="grid gap-3">
                                                <div class="flex justify-between">
                                                    <span class="text-gray-400">{{ translate('Total Activations') }}</span>
                                                    <span class="font-medium">{{ activations.length }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-400">{{ translate('Active') }}</span>
                                                    <span class="font-medium">{{ activeCount }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-400">{{ translate('Products') }}</span>
                                                    <span class="font-medium">{{ uniqueProductCount }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-400">{{ translate('Orders') }}</span>
                                                    <span class="font-medium">{{ orders.length }}</span>
                                                </div>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>

                                    <!-- Customers -->
                                    <Card.Container v-if="customers.length">
                                        <Card.Header :title="translate('Customers')" title_size="small" border_bottom/>
                                        <Card.Body>
                                            <div class="grid gap-4">
                                                <div v-for="customer in customers" :key="customer.id" class="flex items-center gap-3">
                                                    <router-link :to="{ name: 'view_customer', params: { customer_id: customer.id } }">
                                                        <img v-if="customer.photo" :src="customer.photo" :alt="customer.full_name"
                                                             class="w-10 h-10 rounded-full object-cover flex-shrink-0"/>
                                                    </router-link>
                                                    <div>
                                                        <router-link class="link font-medium block"
                                                                     :to="{ name: 'view_customer', params: { customer_id: customer.id } }">
                                                            {{ customer.full_name }}
                                                        </router-link>
                                                        <span class="text-gray-400 text-xs">{{ customer.email }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>
                                </div>
                            </el-col>
                        </el-row>
                    </div><!-- .single-page-body -->
                </template>

                <div v-if="!site && !loading" class="fct-error-state p-8 text-center">
                    <Empty icon="Empty/ListView" :has-dark="true" :text="translate('Failed to load site details')"/>
                    <div class="mt-4 flex justify-center gap-3">
                        <el-button @click="fetchSite">{{ translate('Retry') }}</el-button>
                        <el-button @click="$router.push({ name: 'activated_sites' })">{{ translate('Back to Sites') }}</el-button>
                    </div>
                </div>
            </template>

            <div v-if="loading" class="fct-loading-wrap p-8 text-center">
                <el-skeleton :rows="8" animated/>
            </div>
        </div>
    </div>
</template>

<script setup>
import {ArrowRight} from "@element-plus/icons-vue";
import * as Card from '@/Bits/Components/Card/Card.js';
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {formatDate, handleError} from "@/Bits/common";
import dayjs from "dayjs";
import NotFound from "@/Pages/NotFound.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Clipboard from "@/utils/Clipboard";
</script>

<script>
import Arr from "../../admin/utils/support/Arr";
import translate from "../../admin/utils/translator/Translator";

export default {
    name: "ViewSite",
    props: ["site_id"],
    data() {
        return {
            site: null,
            activations: [],
            customers: [],
            orders: [],
            loading: true,
            notFound: {
                show: false,
                message: '',
                buttonText: '',
                route: ''
            },
        };
    },
    computed: {
        siteExternalUrl() {
            var url = this.site ? this.site.site_url : '';
            if (/^https?:\/\//.test(url)) {
                return url;
            }
            return 'https://' + url;
        },
        hasLocalActivation() {
            return this.activations.some(function (a) {
                return a.is_local && a.is_local !== '0';
            });
        },
        activeCount() {
            return this.activations.filter(function (a) {
                return a.activation_status === 'active';
            }).length;
        },
        uniqueProductCount() {
            var products = {};
            this.activations.forEach(function (a) {
                if (a.product_name) {
                    products[a.product_name] = true;
                }
            });
            return Object.keys(products).length;
        },
        lastUpdateInfo() {
            var latest = null;
            this.activations.forEach(function (a) {
                if (a.last_update_date && (!latest || a.last_update_date > latest.last_update_date)) {
                    latest = a;
                }
            });
            if (latest && latest.last_update_date) {
                return {
                    version: latest.last_update_version || null,
                    date: latest.last_update_date
                };
            }
            return null;
        }
    },
    methods: {
        translate,
        formatDate,
        handleError,
        formatFullDate(value) {
            if (!value) return '';
            return dayjs(value).format('MMM DD, YYYY');
        },
        formatMetaKey(key) {
            return key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        },
        fetchSite() {
            this.loading = true;
            this.notFound.show = false;
            this.$get("licensing/sites/" + this.site_id)
                .then(function (response) {
                    this.site = response.site;
                    this.activations = response.activations.data || response.activations;
                    this.customers = response.customers || [];
                    this.orders = response.orders || [];
                }.bind(this))
                .catch(function (errors) {
                    if (errors) {
                        if (errors.code === 'fluent_cart_entity_not_found') {
                            this.notFound.show = true;
                            this.notFound.buttonText = Arr.get(errors, 'data.buttonText', translate('Back to Sites'));
                            this.notFound.message = Arr.get(errors, 'data.message', translate('Site not found'));
                            this.notFound.route = Arr.get(errors, 'data.route', '/licenses/sites');
                        } else {
                            this.handleError(errors);
                        }
                    }
                }.bind(this))
                .finally(function () {
                    this.loading = false;
                }.bind(this));
        }
    },
    mounted() {
        this.fetchSite();
    }
};
</script>

