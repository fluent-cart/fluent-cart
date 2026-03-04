<template>
  <div class="setting-wrap">
    <SettingsHeader
        @onSave="savePaymentDesign"
        :loading="saving"
        :save-button-text="$t('Update')"
        :loading-text="$t('Updating')"
    >
      <template #heading>
        <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ path: '/settings/payments' }">
            {{ $t("Payment Settings") }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            <span class="cursor-pointer" @click="goBack">{{ $route.params.method }}</span>
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            <span>{{ $t('Checkout Customization') }}</span>
          </el-breadcrumb-item>
        </el-breadcrumb>
      </template>
    </SettingsHeader>

    <div class="setting-wrap-inner">
      <Card.Container>
        <Card.Header :title="$t('Customization')" border_bottom>
          <template #action>
            <el-button @click="goBack" type="text" class="cursor-pointer border-none p-0">
              <el-icon class="text-gray-500 cursor-pointer" @click="goBack"> <ArrowLeft />
              </el-icon> {{ $t('Back to Gateway Settings') }} </el-button>
          </template>
        </Card.Header>
        <Card.Body>
          <el-skeleton :loading="fetching" animated :rows="4" class="pt-2" />
          <el-form v-if="!fetching" label-position="left">
            <el-form-item :label="$t('Checkout Label')">
              <el-input type="text" v-model="checkout_label"/>
            </el-form-item>
            <el-form-item :label="$t('Checkout Logo')">
              <MediaInput v-model="mediaSelection" icon="Upload" :title="$t('Upload Logo')"/>
            </el-form-item>
            <el-form-item label-position="top">
              <LabelHint :title="$t('Checkout Instructions')" :content="$t('Checkout Instructions will be displayed on the checkout page gateway section.')"/>
              <wp-editor
                  v-model="checkout_instructions"
                  @update="(val) => { checkout_instructions = val; }"
              ></wp-editor>
            </el-form-item>
            <!-- gap 10px -->
            <div class="h-10"></div>
            <el-form-item label-position="top">
              <LabelHint :title="$t('Thank you page Instruction')" :content="$t('Thank you page Instruction will be displayed on the thank you page receipt section.')"/>
              <wp-editor
                  v-model="thank_you_page_instructions"
                  @update="(val) => { thank_you_page_instructions = val; }"
              ></wp-editor>
            </el-form-item>
          </el-form>
        </Card.Body>
      </Card.Container>

      <div class="setting-save-action">
        <el-button type="primary" :loading="saving" @click="savePaymentDesign">
          {{ saving ? $t('Saving') : $t('Update') }}
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {ArrowRight, ArrowLeft} from "@element-plus/icons-vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
</script>

<script type="text/babel">
import {handleSuccess, handleError} from "@/Bits/common";
import SettingsHeader from "./Parts/SettingsHeader.vue";

export default {
  name: 'PaymentDesign',
  components: {
    SettingsHeader,
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
  },
  mounted() {
    this.initRouteData();
    this.getSettings();
  }
}
</script>


