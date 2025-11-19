<template>
  <div class="setting-wrap">
    <div class="mb-5 flex items-center justify-between">
      <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
        <el-breadcrumb-item :to="{ path: '/settings/payments' }">
          {{ $t("Payment Settings") }}
        </el-breadcrumb-item>
        <el-breadcrumb-item>
          <span>{{ methodTitle }}</span>
        </el-breadcrumb-item>
      </el-breadcrumb>

      <div class="setting-switcher">
        <el-switch
            v-model="settings.is_active"
            active-value="yes"
            inactive-value="no"
            :inactive-text="$t('Payment Activation')"
        />
      </div>
    </div>

    <Card.Container>
      <Card.Header :title="methodTitle" border_bottom>
        <template #action>
          <el-icon class="text-gray-500 cursor-pointer" @click="editDesign">
            <Edit />
          </el-icon>
        </template>
      </Card.Header>
      <Card.Body class="pt-0">
        <el-skeleton :loading="fetching" animated :rows="6" class="pt-5"/>
        <template v-if="!fetching">
          <Renderer
          @onSettingsChange="updateSettings" 
          :route_name="route_name"
          :methodName="methodName"
          :methodTitle="methodTitle"
          :methodLabel="methodLabel"
          :fields="fields" 
          :settings="settings"/>
        </template>
      </Card.Body>
    </Card.Container>
    <div class="setting-save-action">
      <el-button @click="saveSettings()" type="primary" :loading="saving">
        {{ saving ? $t('Saving') : $t('Save Settings')}}
      </el-button>
    </div>
  </div><!-- .setting-wrap -->
  <el-dialog v-model="editDesignModal" :title="$t('Edit Gateway Label')" width="50%">
    <div class="edit-design-modal">
       <el-form label-position="left">
        <el-form-item :label="$t('Checkout Label')">
          <el-input type="text" v-model="checkout_label"/>
        </el-form-item>
        <el-form-item :label="$t('Checkout Logo')">
          <MediaInput v-model="mediaSelection" icon="Upload" :title="$t('Upload Logo')"/>
        </el-form-item>
        <el-form-item :label="$t('Checkout Instructions')" label-position="top">
          <wp-editor
              v-model="checkout_instructions"
              @update="(val) => { checkout_instructions = val; }"
          ></wp-editor>
        </el-form-item>
        <el-form-item class="float-right">
          <el-button type="primary" @click="savePaymentDesign">{{ $t('Update') }}</el-button>
        </el-form-item>
       </el-form>
    </div>
  </el-dialog>
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
const selfRef = getCurrentInstance().ctx;
const saveShortcut = useSaveShortcut();
saveShortcut.onSave(()=>{
  selfRef.saveSettings()
});
</script>

<script type="text/babel">

import Asset from "@/utils/support/Asset";
import {handleSuccess, handleError} from "@/Bits/common";

export default {
  name: 'GlobalPaymentComponents',
  data() {
    return {
      fields: {},
      settings: {},
      saving: false,
      fetching: false,
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
      editDesignModal: false,
      mediaSelection: [],
      checkout_label: '',
      checkout_logo: '',
      checkout_instructions: '',
    }
  },
  watch: {
    $route(to, from) {
      this.getRoute();
      this.getSettings();
      this.getPageName();
    }
  },
  methods: {
    editDesign() {
      this.editDesignModal = true;
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
    },
    getSettings() {
      this.fetching = true;
      this.$get('settings/payment-methods', {
        method: this.route_name
      })
          .then((response) => {
            this.fetching = false;
            this.fields = response.fields;
            this.settings = response.settings;
            this.registerCopyAction()
            //set checkout label and logo from settings
            this.checkout_label = this.settings?.checkout_label ? this.settings?.checkout_label : this.methodTitle;
            this.checkout_logo = this.settings?.checkout_logo;
            this.checkout_instructions = this.settings?.checkout_instructions || '';
            this.mediaSelection = this.settings?.checkout_logo ? {url: this.settings?.checkout_logo, id: 0, title: 'Checkout Logo'} : '';
          })
    },
    saveSettings() {
      this.saving = true;
      this.$post('settings/payment-methods', {
        settings: this.settings,
        method: this.route_name
      })
          .then(response => {
            this.saving = false;
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
    }
  },
  mounted() {
    this.getRoute();
    this.getSettings();
    if (window.outerWidth < 500) {
      this.labelPosition = "top";
    }
  }
}
</script>
