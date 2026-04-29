<script setup>
import translate, {dateTimeI18} from "../../translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import LicenseKey from "./LicenseKey.vue";
import {useRouter} from "vue-router";
import AppConfig from "@/utils/Config/AppConfig";
import Str from "@/utils/support/Str";

const props = defineProps({
    licenses: {
        type: Array,
        required: true,
    },
    showTableHeader: {
        type: Boolean,
        default: false
    },
    itemsColumnWidth: {
        type: Number,
        default: 300
    },
    is_simple: {
        type: Boolean,
        default: false
    }
});
const upgradeBasePath = () => {
    if (window.fluentcart_customer_profile_vars?.upgrade_path_base) {
        return window.fluentcart_customer_profile_vars.upgrade_path_base;
    } else if (AppConfig.get('upgrade_path_base')) {
        return AppConfig.get('upgrade_path_base');
    }
    return '';
}

const router = useRouter();

const getStatusText = (status) => {
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
</script>

<template>
    <div class="fct-customer-dashboard-table">
      <!-- Mobile View -->
      <div class="license-only-mobile">
        <div v-if="licenses.length > 0" class="license-only-mobile-item" v-for="license in licenses" :key="license.id">
            <div class="item-header">
              <div class="fct-expiration-info" v-if="license?.status !== 'disabled' && !is_simple">
                <div class="text-meta" v-if="license.expiration_date">
                  <template v-if="license?.status === 'expired'">
                    <!-- translators: %s is the expiration date -->
                    {{ translate('Expired at: %s', dateTimeI18(license.expiration_date, 'MMM DD, YYYY')) }}
                  </template>

                  <template v-else>
                    <!-- translators: %s is the expiration date -->
                    {{ translate('Expires on: %s', dateTimeI18(license.expiration_date, 'MMM DD, YYYY')) }}
                  </template>
                </div>
                <div class="text-meta" v-else>{{ translate('Never Expires') }}</div>
              </div>

              <div class="fct-customer-orders-items fct-customer-license-orders-items cursor-pointer"
                   @click="router.push({
                    name: 'manage_license',
                    params: {
                      license_key: license.license_key
                    }
                  })"
                 :aria-label="
                   /* translators: %s is the license title */
                   $t('View details for license: %s', license.title)"
                 >

                <div class="fct-customer-orders-items-title">
                  {{ license.title }}
                </div>
              </div>
            </div>

          <div class="item-body">
            <div class="fct-customer-payment-meta-info">
              <LicenseKey
                  :license_key="license?.license_key"
                  show_mode="basic_copy_btn"
                  :tooltip_text="translate('Copy Key')"
              />
            </div>
          </div>

            <div class="item-footer">
              <div class="fct-customer-orders-items-meta-wrap">
                <!-- translators: %s is the license status -->
                <Badge :type="license.status" :hide-icon="true" :aria-label="$t('License status: %s', license.status)">
                  {{ getStatusText(license.status) }}
                </Badge>
                <el-button
                    v-if="license.renewal_url"
                    link
                    tag="a"
                    :href="license.renewal_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :aria-label="
                     /* translators: %s is the license title */
                    $t('Renew license: %s', license.title)"
                >
                  {{ translate('Renew License') }}
                </el-button>
                <div class="text-meta" v-if="license.status !== 'expired'">
                  {{ license.subtitle }}
                </div>
              </div>
            </div>

        </div>

        <div v-else role="alert" class="text-center p-5">
          {{translate('No Licenses found')}}
        </div>
      </div>

      <!-- Desktop View -->
        <el-table class="license-only-desktop" :data="licenses" :show-header="showTableHeader" role="grid"
      :aria-label="$t('Licenses table')">
            <el-table-column :label="translate('Items')" :width="itemsColumnWidth">
                <template #default="scope">
                    <div class="fct-customer-orders-items fct-customer-license-orders-items cursor-pointer"
                         @click="router.push({
                          name: 'manage_license',
                          params: {
                            license_key: scope.row.license_key
                          }
                        })"
                        :aria-label="
                           /* translators: %s is the license title */
                          $t('View details for license: %s', scope.row.title)"
                    >

                      <div class="fct-customer-orders-items-title">
                        {{ scope.row.title }}
                      </div>


                        <div class="fct-customer-orders-items-meta-wrap">
                            <div class="text-meta" v-if="scope.row.status !== 'expired'">
                                {{ scope.row.subtitle }}
                            </div>
                          <!-- translators: %s is the license status -->
                            <Badge :type="scope.row.status" :hide-icon="true" :aria-label="$t('License status: %s', scope.row.status)">
                              {{ getStatusText(scope.row.status) }}
                            </Badge>


                            <div class="fct-btn-group pt-1" v-if="scope.row.renewal_url">
                                <el-button
                                    link
                                    tag="a"
                                    :href="scope.row.renewal_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :aria-label="
                                      /* translators: %s is the license title */
                                    $t('Renew license: %s', scope.row.title)"
                                >
                                    {{ translate('Renew License') }}
                                </el-button>
                            </div>
                        </div>

                    </div>
                </template>


            </el-table-column>

            <el-table-column :width="400" :label="translate('License Info')" align="right" class-name="license-info-column">
                <template #default="scope">
                    <div class="fct-customer-payment-meta-info">
                        <template v-if="scope.row.status !== 'disabled' && !is_simple">
                            <div class="text-meta" v-if="scope.row.expiration_date">

                                <template v-if="scope.row.status === 'expired'">
                                    {{ translate('Expired at: %s', dateTimeI18(scope.row.expiration_date, 'MMM DD, YYYY')) }}
                                </template>
                                <template v-else>
                                    {{ translate('Expires on: %s', dateTimeI18(scope.row.expiration_date, 'MMM DD, YYYY')) }}
                                </template>


                            </div>
                            <div class="text-meta" v-else>{{ translate('Never Expires') }}</div>
                        </template>
                        <LicenseKey :license_key="scope.row?.license_key" show_mode="basic_copy_btn"
                                    :tooltip_text="translate('Copy Key')"/>
                    </div>
                </template>
            </el-table-column>
        </el-table>
    </div>
</template>

