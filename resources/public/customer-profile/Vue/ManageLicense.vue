<template>
    <div 
        class="fct-customer-dashboard-manage-license-wrap fct-customer-dashboard-layout-width"
        role="main"
        :aria-labelledby="licenseDetailsTitleId"
        :aria-busy="loading"
    >
        <div class="fct-customer-dashboard-single-subscription">
            <div class="fct-customer-dashboard-breadcrumb mb-8" role="navigation" :aria-label="$t('Breadcrumb')">
                <el-breadcrumb>
                    <el-breadcrumb-item class="cursor-pointer" :to="{ path: '/licenses' }">
                        {{ $t('Licenses') }}
                    </el-breadcrumb-item>
                    <el-breadcrumb-item>{{ license_key }}</el-breadcrumb-item>
                </el-breadcrumb>
            </div>

            <div v-if="license" :aria-labelledby="licenseDetailsTitleId">

                <!--
                    The server decides whether this box appears: renewal_url is empty
                    unless the underlying subscription can be reactivated, and the link
                    it holds is the subscription's own reactivate URL.

                    Status must not re-gate that decision, only word it. Canceling a
                    subscription does not revoke the license the customer already paid
                    for, so the common case here is a live license with months left and
                    no subscription behind it — for which the expiry sentence would be
                    false and "renew the license" would name the wrong action.
                -->
                <div v-if="license.renewal_url" class="fct-renew-box mb-4">
                    <el-alert :type="license.status === 'expired' ? 'error' : 'warning'" :closable="false" role="alert" aria-live="assertive">
                        <div class="text-center p-4">
                            <p v-if="license.status === 'expired'" class="p-0 m-0 mb-3">
                                <!-- translators: %s is the expiration date -->
                                {{ $t('Your license has been expired at %s. Please renew the license for getting updates and support.', dateTimeI18(license.expiration_date)) }}
                            </p>
                            <p v-else class="p-0 m-0 mb-3">
                                <!-- translators: %1$s is the date the license stays active until -->
                                {{ $t('Your license is active until %1$s. Reactivate the subscription to keep getting updates and support after that date.', dateTimeI18(license.expiration_date)) }}
                            </p>
                            <a :href="license.renewal_url" class="el-button el-button--primary"
                               :aria-label="license.status === 'expired' ? $t('Renew License') : $t('Reactivate Subscription Plan')">
                                {{ license.status === 'expired' ? $t('Renew License') : $t('Reactivate Subscription Plan') }}
                            </a>
                        </div>
                    </el-alert>
                </div>

                <div v-if="sectionParts.before_summary" v-html="sectionParts.before_summary"></div>

                <article class="fct-single-order-box">
                    <header class="fct-single-order-header">
                        <h1 :id="licenseDetailsTitleId" class="title">
                            {{ $t('License Details') }}
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
                                                    {{ license.title }}
                                                </div>
                                                <div class="variation-title">
                                                    {{ license.subtitle }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="right-content">
                                    <Badge :status="license.status"/>
                                </div>
                            </div>

                            <div class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="title">{{ $t('License Key') }}</div>
                                    <LicenseKey :license_key="license_key"/>
                                </div>
                            </div>

                            <div class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="title">{{ $t('Activations') }}</div>
                                    <div class="flex items-center gap-2">
                                    <span class="text" v-if="!license?.limit || Number(license?.limit) === 0">
                                      <!-- translators: %s - number of activations -->
                                        {{ $t('%s / Unlimited', license?.activation_count) }}
                                    </span>
                                        <span class="text" v-else>
                                          <!-- translators: %1$s - number of activations, %2$s - number of limit -->
                                        {{ $t('%1$s / %2$s', license?.activation_count, license?.limit) }}
                                    </span>
                                        <ActivatedSites v-if="license.license_key" :license_key="license?.license_key"/>
                                    </div>
                                </div>
                                <div class="right-content">
                                </div>
                            </div>

                            <div class="fct-customer-dashboard-content-table-item border-0 pb-0">
                                <div class="left-content">
                                    <div class="title">{{ $t('Expiration Date') }}</div>
                                    <div class="text">
                                        {{
                                            license.expiration_date ? dateTimeI18(license.expiration_date) : $t('Never Expires')
                                        }}
                                    </div>
                                </div>
                            </div>

                            <div v-if="sectionParts.end_of_details" v-html="sectionParts.end_of_details"></div>

                        </div>
                    </div>

                    <footer class="fct-single-order-footer mt-3">
                        <div class="flex items-center gap-3">
                            <UpgradePlan 
                                v-if="license.has_upgrades" 
                                button-type="primary" 
                                :button-text="$t('Upgrade Plan')" 
                                :variation_id="license.variation_id" 
                                :order_hash="license.order.uuid"
                                :aria-label="$t('Upgrade Plan')"
                            />
                            <router-link 
                                class="underline-link-button" 
                                :to="{ name: 'view_order', params: {order_id: license.order.uuid} }"
                                :aria-label="$t('View Order')"
                            >
                                {{ $t('View Order') }}
                            </router-link>
                            <span v-if="sectionParts.additional_actions" v-html="sectionParts.additional_actions"></span>
                        </div>
                    </footer>
                </article>

                <div v-if="sectionParts.after_summary" v-html="sectionParts.after_summary"></div>
            </div>

            <div v-else-if="loading" class="fct-customer-dashboard-loader" aria-live="polite">
                <el-skeleton :rows="5" animated/>
            </div>
            <div v-else class="fct-customer-dashboard-no-data" role="alert">
                <p>{{ $t('License could not be loaded. Please try again.') }}</p>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import Badge from "./parts/Badge.vue";
import LicenseKey from "./parts/LicenseKey.vue";
import ActivatedSites from "./parts/ActivatedSites.vue";
import UpgradePlan from "./subcriptions/UpdatePaymentInfos/UpgradePlan.vue";
import {dateTimeI18} from "../translator/Translator";

export default {
    name: 'LicenseDetails',
    components: {
        UpgradePlan,
        Badge,
        LicenseKey,
        ActivatedSites
    },
    props: {
        license_key: {
            type: String,
            required: true
        }
    },
    data() {
        return {
            licenseDetailsTitleId: 'license-details-title',
            license: null,
            loading: false,
            sectionParts: {}
        }
    },
    mounted() {
        this.fetchLicense();
    },
    methods: {
        dateTimeI18,
        fetchLicense() {
            this.loading = true;
            this.$get('customer-profile/licenses/' + this.license_key)
                .then(response => {
                    this.license = response.license;
                    if(response.section_parts) {
                        this.sectionParts = response.section_parts;
                    }
                })
                .catch(error => {
                    this.$notify.error(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        }
    }
}
</script>
