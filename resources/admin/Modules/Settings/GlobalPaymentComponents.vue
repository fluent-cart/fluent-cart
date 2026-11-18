<template>
  <div class="setting-wrap">
    <div class="setting-wrap-inner">
      <div class="mb-5 flex items-center justify-between">
        <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ path: '/settings/payments' }">
            {{ $t("Payment Settings") }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            <span>{{ methodTitle }}</span>
          </el-breadcrumb-item>
        </el-breadcrumb>

        <!-- A placeholder (add-on or Pro missing) has nothing to switch on or save yet. -->
        <div v-if="!fetching && !isPlaceholder" class="setting-switcher flex items-center gap-3">
          <fct-switch
              v-model="settings.is_active"
              active-value="yes"
              inactive-value="no"
              :inactive-text="$t('Payment Activation')"
          />
        </div>
      </div>

      <Card.Container>
        <Card.Header :title="methodTitle" border_bottom>
          <template v-if="!isPlaceholder" #action>
            <el-icon class="text-gray-500 cursor-pointer" @click="editDesign">
              <Edit />
            </el-icon>
          </template>
        </Card.Header>
        <Card.Body class="pt-0">
          <fct-skeleton v-if="fetching" animated :rows="6" class="pt-5"/>
          <template v-if="!fetching">
            <Renderer
                @on-settings-change="updateSettings"
                :routeName="route_name"
                :methodName="methodName"
                :methodTitle="methodTitle"
                :methodLabel="methodLabel"
                :brandColor="brandColor"
                :fields="fields"
                :settings="settings"/>
            <TestConnection v-if="!hasModeLayout" :mode="storeOrderMode"/>
          </template>
        </Card.Body>
      </Card.Container>
      <div v-if="!fetching && !isPlaceholder" class="setting-save-action">
        <fct-button @click="saveSettings()" type="primary" :loading="saving">
          {{ $t('Save Settings')}}
        </fct-button>
      </div>
    </div>
  </div><!-- .setting-wrap -->
</template>

<script setup>
import Renderer from "@/Modules/Settings/PaymentComponet/Renderer.vue";
import * as Card from '@/Bits/Components/Card/Card.js';
import {getCurrentInstance} from "vue";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import {ArrowRight} from "@element-plus/icons-vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import Str from "@/utils/support/Str";
import TestConnection from "@/Modules/Settings/PaymentComponet/TestConnection.vue";
import AppConfig from "@/utils/Config/AppConfig";
const storeOrderMode = AppConfig.get('shop.order_mode');
const selfRef = getCurrentInstance().ctx;
const saveShortcut = useSaveShortcut();
saveShortcut.onSave(()=>{
  selfRef.saveSettings()
});
</script>

<script type="text/babel">

import {computed} from "vue";
import Asset from "@/utils/support/Asset";
import {handleSuccess, handleError} from "@/Bits/common";

export default {
  name: 'GlobalPaymentComponents',
  provide() {
    return {
      paymentConnectionTest: computed(() => ({
        canTest: this.canTestConnection,
        routeName: this.route_name,
        saved: this.savedSnapshot,
        dirty: this.settingsSnapshot(this.settings) !== this.savedSnapshot
      }))
    };
  },
  data() {
    return {
      fields: {},
      settings: {},
      saving: false,
      fetching: true,
      is_key_defined: false,
      labelPosition: 'top',
      webhook_url: '',
      fetchRoute: '',
      pages: [],
      route_name: '',
      ipn_url: 'Blank',
      verifiedMessage: false,
      verifiedStatus: false,
      verifying: false,
      methodName: '',
      methodTitle: '',
      methodLabel: '',
      brandColor: '#253241',
      editDesignModal: false,
      mediaSelection: [],
      checkout_label: '',
      checkout_logo: '',
      checkout_instructions: '',
      addonInfo: null,
      isPlaceholder: false,
      canTestConnection: false,
      savedSnapshot: '',
      thank_you_page_instructions: ''
    }
  },
  computed: {
    // Gateways without mode tabs or a connect provider follow the store's order mode.
    hasModeLayout() {
      return Object.values(this.fields || {}).some(field => ['tabs', 'provider'].includes(field?.type));
    }
  },
  watch: {
    $route(to, from) {
      this.getRoute();
      this.getSettings();
      this.getPageName();
    }
  },
  mounted() {
    this.getRoute();
    this.getSettings();
    if (window.outerWidth < 500) {
      this.labelPosition = "top";
    }
  },
  methods: {
    editDesign() {
      this.$router.push({
        name: 'payment-design',
        params: {
          method: this.route_name
        }
      });
    },
    savePaymentDesign() {
      this.editDesignModal = false;
      if (this.mediaSelection.length > 0) {
        this.checkout_logo = this.mediaSelection[0].url;
      } else {
        this.checkout_logo = '';
      }
      this.$post('settings/payment-methods/design', {
        checkout_label: this.checkout_label,
        checkout_logo: this.checkout_logo,
        checkout_instructions: this.checkout_instructions,
        thank_you_page_instructions: this.thank_you_page_instructions,
        method: this.route_name
      })
          .then(response => {
            this.saving = false;
            handleSuccess('Settings updated!')
          })
    },
    getLogo(name){
      //replace underscores with hyphens
      const $name = name.replace(/_/g, '-');
      return Asset.getUrl('images/payment-methods/' + $name + '-icon.svg');
    },
    updateSettings(settings) {
      this.settings = settings;
      this.savedSnapshot = this.settingsSnapshot(settings);
    },
    // The mode tab, the activation switch and the design fields do not change which credentials a test reads.
    // checkout_label is also filled in by the settings GET but not by connect/info.
    settingsSnapshot(settings) {
      const {
        payment_mode, is_active,
        checkout_label, checkout_logo, checkout_instructions, thank_you_page_instructions,
        ...rest
      } = settings || {};
      return JSON.stringify(rest);
    },
    getSettings() {
      this.fetching = true;

      this.$get('settings/payment-methods/all')
        .then(allGatewaysResponse => {
          const gateway = allGatewaysResponse.gateways.find(g => g.route === this.route_name);
          if (gateway) {
            this.brandColor = gateway.brand_color || '#000000';
          }
        })
        .catch(() => {});

      this.$get('settings/payment-methods', { method: this.route_name })
          .then((response) => {
            this.fetching = false;
            this.fields = response.fields;
            this.settings = response.settings;
            this.addonInfo = response.addon_info || null;
            this.isPlaceholder = !!response.is_placeholder;
            this.canTestConnection = !!response.can_test_connection;
            this.savedSnapshot = this.settingsSnapshot(this.settings);

            this.registerCopyAction()
            //set checkout label and logo from settings
            this.checkout_label = this.settings?.checkout_label ? this.settings?.checkout_label : this.methodTitle;
            this.checkout_logo = this.settings?.checkout_logo;
            this.checkout_instructions = this.settings?.checkout_instructions || '';
            this.thank_you_page_instructions = this.settings?.thank_you_page_instructions || '';
            this.mediaSelection = this.settings?.checkout_logo ? {url: this.settings?.checkout_logo, id: 0, title: 'Checkout Logo'} : '';
          })
          .catch(error => {
            this.fetching = false;
            handleError(error?.data?.message || 'Failed to load settings');
          });
    },
    saveSettings() {
      if (this.isPlaceholder) {
        return;
      }
      this.saving = true;
      const posted = this.settingsSnapshot(this.settings);
      this.$post('settings/payment-methods', {
        settings: this.settings,
        method: this.route_name
      })
          .then(response => {
            this.saving = false;
            this.savedSnapshot = posted;
            handleSuccess('Settings updated!')
          })
          .catch(error => {
            this.saving = false;
            handleError(error?.data?.message || 'Something went wrong!')
          })
    },
    verifyKeys(req, method) {
      this.verifying = true;
    },
    getRoute() {
      this.route_name = this.$route.name;
      this.methodName = this.$route?.name;
      if (this.$route?.meta?.admin_title) {
        this.methodTitle = this.$route?.meta?.admin_title;
      } else {
        this.methodTitle = this.$route?.meta?.title;
      }
      this.methodLabel = this.$route?.meta?.label;
      this.fetchRoute = `settings/payments/${this.$route.name}`;
    },
    getPageName() {
      let pageName = ''
      if (this.$route.meta.title) {
        pageName = this.$route.meta.title + ' Settings';
      } else {
        pageName = this.$route.name.charAt(0).toUpperCase() + this.$route.name.slice(1).toLowerCase() + ' Settings';
      }
      return pageName;
    },
  }
}
</script>
