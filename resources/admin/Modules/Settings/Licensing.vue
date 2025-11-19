<template>

  <div class="setting-wrap">
    <Card.Container>
      <Card.Header :title="translate('Licensing')" border_bottom/>
      <Card.Body>
        <el-skeleton :loading="loading" animated :rows="6"/>

        <div>
          <!--          <div class="fluent_cart_settings_header fluent_cart_configure_integration_card_header">-->
          <!--            <div v-if="!disabled" class="fluent_cart_settings_actions">-->
          <!--              <el-button-->
          <!--                  class="refresh-setting-icon"-->
          <!--                  @click="getSettings">-->
          <!--                <el-icon><Refresh /></el-icon>-->
          <!--              </el-button>-->
          <!--            </div>-->
          <!--          </div>-->

          <div v-if="!disabled && !loading" v-loading="verifying" class="fluent_cart_configure_integration_body">
            <div class="fluent_cart_license_box" :class="'fct_license_'+licenseData.status">
              <div v-if="licenseData.status == 'expired'" class="fct-license-box-content">
                <template v-if="!showNewLicenseInput">
                  <DynamicIcon name="GradientWarningCircle"/>
                  <h3>{{ translate("Looks like your license key has been Expired!") }}</h3>
                  <p>{{ translate('Have a new license Key?') }} <a
                      @click.prevent="showNewLicenseInput = !showNewLicenseInput" href="#">{{
                      translate('Click here')
                    }}</a> <template v-if="licenseData.renew_url">or <a :href="licenseData.renew_url" target="_blank">{{
                      translate('Purchase a new license')
                    }}</a></template></p>

                  <div class="fct-license-box-content-details">
                    <p>
                      <span class="label">{{ translate('License Key') }}</span>
                      <span class="license-key">{{ maskLicense(licenseData.license_key) }}
                      <CopyToClipboard
                          :text="licenseData.license_key"
                          showMode="basic_copy_btn"
                          :tooltipText="translate('Copy License')"
                      />
                    </span>
                    </p>
                    <p><span class="label">{{ translate('Expired At') }}</span> {{
                        formatDate(licenseData.expires, true)
                      }}</p>
                  </div>
                </template>
                <div v-else class="w-full items-start">
                    <h3>{{ translate('Your License Key') }}</h3>
                    <el-input v-model="licenseKey" :placeholder="translate('License Key')"/>
                </div>
              </div>

              <div v-else-if="licenseData.status == 'valid'" class="fct-license-box-content">
                <DynamicIcon name="GradientCheckCircle"/>
                <!-- <span style="font-size: 50px;" class="el-icon el-icon-circle-check"></span> -->
                <h3>{{ translate('Congratulations! Your license key is valid and activated') }}</h3>
                <p>{{ translate('Want to deactivate this license?') }} <a @click.prevent="deactivateLicense()" href="#">{{
                    translate('Click here')
                  }}</a></p>
                <div class="fct-license-box-content-details">
                  <p v-if="licenseData.license_key">
                    <span class="label">{{ translate('License Key') }}</span>
                    <span class="license-key">{{ maskLicense(licenseData.license_key) }}
                    </span>
                  </p>
                  <p><span class="label">{{ translate('Expires') }}</span> {{
                      licenseData.expires !== 'lifetime' ? formatDate(licenseData.expires, true) : licenseData.expires
                    }}</p>
                </div>
              </div>

              <div v-else class="fct-license-box-content">
                <h3>
                  {{ translate('Please Provide a license key of') }} FluentCart
                </h3>
                <el-input v-model="licenseKey" :placeholder="translate('License Key')"/>
                <p v-if="!showNewLicenseInput">{{ translate("Don't have a license key?") }} <a target="_blank"
                                                                                               :href="purchaseLink">{{
                    translate('Purchase one here')
                  }}
                  <DynamicIcon name="External" class="w-4 h-4"/>
                </a></p>
              </div>
              <p class="fluent_cart_warning" v-html="errorMessage"></p>
<!--              <a :href="licenseData.renew_url" target="_blank"-->
<!--                 class="el-button el-button&#45;&#45;danger el-button&#45;&#45;small">{{-->
<!--                  translate("Click Here to Renew your License")-->
<!--                }}</a>-->
              <el-button v-if="licenseData.status === 'expired' && showNewLicenseInput"
                         type="primary"
                         @click="verifyLicense()">
                <DynamicIcon name="Lock" class="w-5 h-5"/>
                {{ translate('Click Here to Renew your License') }}
              </el-button>
              <el-button v-else-if="!['valid', 'expired'].includes(licenseData.status)"
                         type="primary"
                         @click="verifyLicense()">
                <DynamicIcon name="Lock" class="w-5 h-5"/>
                {{ translate('Verify License') }}
              </el-button>
            </div>
          </div>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {formatDate} from "@/Bits/common";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
</script>

<script>
import {Refresh, Lock, CircleCheck} from '@element-plus/icons-vue';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

export default {
  name: 'licensing',
  props: ['disabled'],
  components: {
    Lock,
    Refresh,
    CircleCheck,
  },
  data() {
    return {
      loading: true,
      verifying: false,
      licenseData: {},
      licenseKey: '',
      showNewLicenseInput: false,
      errorMessage: ''
    };
  },
  computed: {
    purchaseLink() {
      return AppConfig.get('purchase_fluent_cart_link');
    }
  },
  methods: {
    getSettings() {
      this.errorMessage = '';
      this.loading = true;

      Rest.get('settings/license', {verify: true})
          .then(response => {
            this.licenseData = response;
            // this.licenseData = {
            //   expires: '',
            //   license_key: '',
            //   price_id: '',
            //   status: 'none'
            // }
          })
          .catch(errors => {
            Notify.error(errors);
          })
          .finally(() => {
            this.loading = false;
          });
    },
    verifyLicense() {
      if (!this.licenseKey) {
        Notify.error(translate('Please provide a license key'));
        this.errorMessage = translate('Please provide a license key');
        return;
      }

      this.verifying = true;

      this.errorMessage = '';

      Rest.post('settings/license', {
        license_key: this.licenseKey,
      })
          .then(response => {
            this.licenseData = response;
          })
          .catch(errors => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.data);
            }

          })
          .finally(() => {
            this.verifying = false;
          });
    },
    deactivateLicense() {
      this.verifying = true;

      Rest.delete('settings/license')
          .then(response => {
            this.licenseData = response;
          })
          .catch(errors => {
            Notify.error(errors);
          })
          .finally(() => {
            this.verifying = false;
          });
    },
    maskLicense(key) {
      if (!key) {
        return '';
      }
      const prefix = key.slice(0, 4);
      const last4 = key.slice(-4);
      const masked = '*'.repeat(key.length - 8);
      return `${prefix}${masked}${last4}`;
    }
  },
  mounted() {
    if (!this.disabled) {
      this.getSettings();
    }
  }
};
</script>
