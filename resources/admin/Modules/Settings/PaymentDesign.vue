<template>
  <SettingsPage
      :heading="translate('Checkout Customization')"
      :breadcrumbs="[
        {label: translate('Payment Settings'), to: {path: '/settings/payments'}},
        {label: $route.params.method, to: {path: `/settings/payments/${route_name}`}}
      ]"
      @on-save="savePaymentDesign"
      :loading="saving"
      :save-button-text="translate('Update')"
  >

    <Section :title="translate('Customization')" divided>
      <template #actions>
        <fct-button @click="goBack" link class="cursor-pointer border-none p-0">
          <el-icon class="text-gray-500 cursor-pointer" @click="goBack"> <ArrowLeft />
          </el-icon> {{ translate('Back to Gateway Settings') }} </fct-button>
      </template>
      <fct-skeleton v-if="fetching" animated :rows="4" class="pt-2" />
      <el-form v-if="!fetching" label-position="left">
        <el-form-item :label="translate('Checkout Label')">
          <fct-input type="text" v-model="checkout_label"/>
        </el-form-item>
        <el-form-item :label="translate('Checkout Logo')">
          <MediaInput v-model="mediaSelection" icon="Upload" :title="translate('Upload Logo')"/>
        </el-form-item>
        <el-form-item label-position="top">
          <LabelHint :title="translate('Checkout Instructions')" :content="translate('Checkout Instructions will be displayed on the checkout page gateway section.')"/>
          <WpEditor
              v-model="checkout_instructions"
              @update="(val) => { checkout_instructions = val; }"
          ></WpEditor>
        </el-form-item>
        <!-- gap 10px -->
        <div class="h-10"></div>
        <el-form-item label-position="top">
          <LabelHint :title="translate('Thank you page Instruction')" :content="translate('Settings_Thank_Page')"/>
          <WpEditor
              v-model="thank_you_page_instructions"
              @update="(val) => { thank_you_page_instructions = val; }"
          ></WpEditor>
        </el-form-item>
      </el-form>
    </Section>

    <div class="setting-save-action">
      <fct-button type="primary" :loading="saving" @click="savePaymentDesign">
        {{ translate('Update') }}
      </fct-button>
    </div>
  </SettingsPage>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import Section from "@/Bits/Components/Layout/Section.vue";
import {ArrowLeft} from "@element-plus/icons-vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
</script>

<script type="text/babel">
import {handleSuccess, handleError} from "@/Bits/common";
import SettingsPage from "./Parts/SettingsPage.vue";

export default {
  name: 'PaymentDesign',
  components: {
    SettingsPage,
  },
  data() {
    return {
      settings: {},
      fetching: false,
      saving: false,
      mediaSelection: [],
      checkout_label: '',
      checkout_logo: '',
      checkout_instructions: '',
      thank_you_page_instructions: '',
      route_name: '',
      methodTitle: '',
      routeLabel: '',
    }
  },
  mounted() {
    this.initRouteData();
    this.getSettings();
  },
  methods: {
    goBack() {
      this.$router.push({
        path: `/settings/payments/${this.route_name}`
      });
    },
    savePaymentDesign() {
      this.saving = true;

      let logoUrl = '';
      if (Array.isArray(this.mediaSelection) && this.mediaSelection.length > 0) {
        logoUrl = this.mediaSelection[0]?.url || '';
      } else if (this.mediaSelection && typeof this.mediaSelection === 'object' && this.mediaSelection.url) {
        logoUrl = this.mediaSelection.url;
      } else if (typeof this.mediaSelection === 'string') {
        logoUrl = this.mediaSelection;
      }

      this.checkout_logo = logoUrl || '';

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
            this.getSettings();
          })
          .catch(() => {
            this.saving = false;
            handleError('Something went wrong!')
          })
    },
    getSettings() {
      this.fetching = true;
      this.$get('settings/payment-methods', {
        method: this.route_name
      })
          .then((response) => {
            this.fetching = false;
            this.settings = response.settings;
            this.checkout_label = this.settings?.checkout_label ? this.settings?.checkout_label : this.methodTitle;
            this.checkout_logo = this.settings?.checkout_logo;
            this.checkout_instructions = this.settings?.checkout_instructions || '';
            this.thank_you_page_instructions = this.settings?.thank_you_page_instructions || '';
            this.mediaSelection = this.settings?.checkout_logo
                ? [{url: this.settings?.checkout_logo, id: 0, title: 'Checkout Logo'}]
                : '';
          })
          .catch(() => {
            this.fetching = false;
          })
    },
    initRouteData() {
      this.route_name = this.$route.params.method || this.$route.name;
      if (this.$route?.meta?.admin_title) {
        this.methodTitle = this.$route?.meta?.admin_title;
      } else {
        this.methodTitle = this.$route?.meta?.title || '';
      }

      this.routeLabel = this.methodTitle || this.route_name;

      const suffix = this.$t('Gateway & checkout instructions');
      const dynamicTitle = this.methodTitle ? `${this.methodTitle} - ${suffix}` : suffix;
      if (this.$route && this.$route.meta) {
        this.$route.meta.title = dynamicTitle;
      }
    }
  }
}
</script>


