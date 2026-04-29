<script setup>
import {getCurrentInstance, onMounted, onUnmounted, ref} from 'vue'
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";
import Theme from "@/utils/Theme";
import Url from "@/utils/support/Url";
import { CircleCheckFilled } from '@element-plus/icons-vue';
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Asset from "@/utils/support/Asset";
import PageSelector from "@/Modules/Onboarding/PageSelector.vue";
import AppConfig from "@/utils/Config/AppConfig";


const maxStep = 4;
const selfRef = getCurrentInstance().ctx;
const activeStep = ref(1)
const pages = ref([]);
const currencies = ref({});
const allPageCreated = ref(false)
const loading = ref(false);
const dummyProductInfo = ref([]);
const selectedCategoryIndex = ref('start-from-scratch');
const saving = ref(false);
const generatePageType = ref('auto-generate-pages');
const activeCollapse = ref([1])
const headerTitle = ref('Welcome aboard! Let’s create your online store.');
const darkLogo = Asset.getUrl('images/logo/logo-full-dark.svg');
const lightLogo = Asset.getUrl('images/logo/logo-full.svg');
const logo = ref(AppConfig.get('asset_url') + '/images/logo/logo-full-dark.svg');
const savingSettings = ref(false);
const pageSetupTitle = ref('Select Pages');
const pageSetupDescription = ref('Select the pages you\'d like from your website');
const pageGenerated = ref(false);

const saveShortcut = useSaveShortcut();
const $this = getCurrentInstance().ctx;

const gotToPrevStep = () => {
  if (activeStep.value === 1) {
    return;
  }
  activeStep.value--
  handleHeaderTitleChange();
}
const gotToNextStep = () => {

  if (activeStep.value === maxStep) {
    return;
  }
  activeStep.value++;

  handleHeaderTitleChange();
}

saveShortcut.onSave(() => {
  if (activeStep.value === 3) {
    saveSettings();
  }
});

const handleHeaderTitleChange = () => {
  if (activeStep.value === 2) {
    headerTitle.value = translate('Setup Your Pages');
  } else if (activeStep.value === 3) {
    headerTitle.value = translate('Setup Your Products');
  } else if (activeStep.value === 4) {
    headerTitle.value = translate('Setup Your Payments');
  } else {
    headerTitle.value = translate('Welcome aboard! Let’s create your online store.');
  }
}

const getSettings = () => {
  loading.value = true;
  saving.value = true;
  selfRef.$get('onboarding/', {})
      .then(response => {
        pages.value = response.pages;
        currencies.value = response.currencies;
        formData.value = response.default_settings;
        loading.value = false;
        saving.value = false;
      })
}


const createAllPage = () => {
  loading.value = true;
  saving.value = true;
  Rest.post('onboarding/create-pages', {})
      .then(response => {
        //allPageCreated.value = true;
        pages.value = response.pages;
        currencies.value = response.currencies;


        Object.keys(pagesToCreate).forEach(function (key) {
          formData.value[key] = response.default_settings[key] + '';
        })

        loading.value = false;
        saving.value = false;
        pageGenerated.value = true;

        pageSetupTitle.value = 'Existing Pages'
        pageSetupDescription.value = 'Select the pages you\'d like from the list provided.'
      })
}
const pagesToCreate = {
  checkout_page_id: '',
  cart_page_id: '',
  receipt_page_id: '',
  shop_page_id: '',
  customer_profile_page_id: '',
};


const pageTitles = (key) => {
  switch (key) {
    case 'checkout_page_id':
      return translate('Checkout');
    case 'cart_page_id':
      return translate('Cart');
    case 'receipt_page_id':
      return translate('Receipt');
    case 'shop_page_id':
      return translate('Shop');
    case 'customer_profile_page_id':
      return translate('Customer Profile');
  }
};


const formData = ref({
  ...{
    store_name: '',
    store_logo: '',
    store_country: '',
    currency: '',
    category: ''
  },
  ...pagesToCreate
});

const countries = ref([]);

const onPageCreated = (page, modelId) => {
  pages.value.push(page)
  formData.value[modelId] = page.value;
}

const getPageLabel = function (customerProfilePageId) {
  return Str.headline(customerProfilePageId);
}

const createDummyProduct = (index = 0) => {
  if (selectedCategoryIndex.value === null || selectedCategoryIndex.value === 'start-from-scratch') {
    return;
  }

  const info = dummyProductInfo.value[selectedCategoryIndex.value];

  if (index > info.count - 1) {
    return;
  }

  Rest.post('products/create-dummy', {
    'category': info.category,
    'index': index
  }).then(response => {
    createDummyProduct(index + 1)
  }).catch(errors => {
    if (errors.code === 400) {
      console.log(errors);
    } else {
      createDummyProduct(index + 1)
    }
    Notify.error(errors.message);
  });
}

const saveSettings = () => {
  savingSettings.value = true;
  createDummyProduct();

  if (saving.value === true) {
    return;
  }
  saving.value = true;
  let value = JSON.parse(JSON.stringify(formData.value));
  // value['store_logo'] = value['store_logo']?.[0] ?? '';
  if (Array.isArray(value['store_logo']) && value['store_logo'].length > 0) {
    value['store_logo'] = value['store_logo']?.[0];
  }

  Rest.post('onboarding/', value)
      .then(response => {
        saving.value = false;
        Notify.success(response.message);
        setTimeout(() => {
          savingSettings.value = false;

          redirectToDashboard();

        }, 300)
      })
      .catch(errors => {
        saving.value = false;
        Notify.error(errors);
      })
}

const redirectToDashboard = () => {
  window.location.href = AppConfig.get('dashboard_url');

  if (selectedCategoryIndex.value !== null && selectedCategoryIndex.value !== 'start-from-scratch') {
    const params = {}
    params['import_product'] = selectedCategoryIndex.value;
    const newUrl = Url.appendToVueUrl(null, params);
    window.location.href = Url.appendQueryParams(newUrl);
  }
}

const skipAll = () => {
  redirectToDashboard();
}

const onThemeChanged = (event) => {
  if (Theme.isDark()) {
    logo.value = lightLogo;
  } else {
    logo.value = darkLogo;
  }
};


const fetchCountries = () => {
  if (countries.value.length > 0) {
    return;
  }
  Rest.get("address-info/countries")
      .then((response) => {
        countries.value = response.data;
      })
      .catch((errors) => {
        console.log(errors, 'errors');
      })
      .finally(() => {
      });
}

const saveStoreCountry = (value) => {
  if (value === '') {
    return;
  }

  Rest.post('settings/store', {
    store_country: value,
    store_name: formData.value.store_name,
    settings_name: 'store_setup'
  })
      .then((response) => {

      })
      .catch((errors) => {
        console.log(errors, 'errors');
      })
      .finally(() => {
      });
}

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", onThemeChanged);
  dummyProductInfo.value = AppConfig.get('dummy_product_info');
  jQuery('.fca_admin_menu').hide();
  jQuery('body').addClass('fct-onboarding-body');
  getSettings();
  logo.value = Theme.isDark()? lightLogo : darkLogo;

  fetchCountries();
})

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", onThemeChanged);
  jQuery('.fca_admin_menu').show();
  jQuery('body').removeClass('fct-onboarding-body');
})

// This function capitalizes the first letter of each word in a string.
// It first splits the string by underscores ('_') into an array of words,
// then capitalizes the first letter of each word, and finally joins them back
// into a single string with spaces between words.
// Example: 'example_store' becomes 'Example Store'.


// This function updates the current step when a collapse item is opened.
// It takes the step number as an argument and assigns it to `activeStep.value`,
// which controls the step indicator in the UI (e.g., "1/4 completed").
// it defaults to 0, indicating no active step.
const setStep = (step) => {
  activeStep.value = step || 0;
};

</script>

<template>
  <div class="fct-onboarding-wrap">
    <div v-if="activeStep < 4" class="fct-onboarding-card">
      <div class="fct-onboarding-card-body" v-loading="loading">

        <div class="fct-onboarding-content-box">
          <div class="fct-onboarding-card-head">
            <div class="fct-onboarding-card-head-top">
              <img :src="logo" :alt="translate('Fluent Cart')">
              <div class="fct-onboarding-steps-count">
                {{
                  /* translators: %s - current step */
                  translate('Step %s of 3', activeStep)
                }}
              </div>
              <el-progress
                  :percentage="(activeStep / 3) * 100"
                  :stroke-width="8"
                  :show-text="false"
                  class="fct-onboarding-progress" />
            </div>
            <h2 class="fct-onboarding-card-title">
              {{ headerTitle }}
            </h2>
            <p class="fct-onboarding-card-text">
              {{ translate('You\'ve successfully Installed The Plugin.') }}
            </p>
          </div><!-- .fct-onboarding-card-head -->

          <div class="fct-onboarding-content-box-inner">
            <div :class="`fct-onboarding-form-wrap ${activeStep === 1 ? 'active' : ''}`">
              <div class="fct-onboarding-form-body">
                <div class="fct-form-group">
                  <label for="store-country">{{ translate('Store Country') }}</label>
                  <el-select
                      v-model="formData.store_country"
                      :placeholder="translate('Store Country')"
                      @change="saveStoreCountry"
                      filterable
                  >
                    <el-option
                        v-for="(country, index) in countries"
                        :key="index"
                        :label="country.name"
                        :value="country.value"
                    ></el-option>
                  </el-select>
                </div>

                <div class="fct-form-group">
                  <label>{{ translate('Shop Name') }}</label>
                  <el-input v-model="formData.store_name"></el-input>
                </div>

                <div class="fct-form-group">
                  <label>{{ translate('Store Currency') }}</label>
                  <el-select v-model="formData.currency" filterable :placeholder="translate('Select')">
                    <el-option
                        v-for="(currency, index) in currencies"
                        :key="index"
                        :label="currency.label"
                        :value="currency.value" />
                  </el-select>
                </div>

                <div class="fct-form-group">
                  <label>{{ translate('Shop Image/Logo') }}</label>
                  <MediaInput v-model="formData.store_logo" showSupported icon="Upload" :title="translate('Upload')"/>
                </div>
              </div>
            </div>

            <div :class="`fct-onboarding-form-page-setup fct-onboarding-form-wrap ${activeStep === 2 ? 'active' : ''}`">
              <div class="fct-onboarding-form-body">
                <div class="fct-onboarding-form-body-header">
                  <div class="left">
                    <div class="fct-onboarding-form-body-header__title">
                      {{ pageSetupTitle }}
                    </div>
                    <div class="fct-onboarding-form-body-header__desc">
                      {{ pageSetupDescription }}
                    </div>
                  </div>
                  <div v-if="!pageGenerated" class="fct-onboarding-form-body-header__action">
                    <el-button @click="createAllPage">
                      <DynamicIcon name="MagicPen" />
                      {{ translate('Generate All Pages') }}
                    </el-button>
                  </div>
                </div>

                <div class="fct-form-group" v-for="(index, generatablePage) of pagesToCreate">
                  <PageSelector
                      :title="pageTitles(generatablePage)"
                      :pages="pages"
                      :modelId="generatablePage"
                      v-model="formData[generatablePage]"
                      @on-page-created="onPageCreated"
                  />
                </div>

              </div>
            </div><!-- .fct-onboarding-form-wrap -->

            <div :class="`fct-onboarding-form-wrap ${activeStep === 3 ? 'active' : ''}`">
              <div class="fct-onboarding-form-body">
                <div class="fct-form-group">
                  <el-radio-group class="fct-import-onboarding-products" v-model="selectedCategoryIndex" size="large">
                    <el-radio-button value="start-from-scratch">
                      <DynamicIcon name="Scratch" />
                      <span class="label">Start <br>From scratch</span>
                      <span class="marker">
                        <el-icon><CircleCheckFilled /></el-icon>
                      </span>
                    </el-radio-button>
                    <el-radio-button v-for="(info, key) in dummyProductInfo" :key="key" :value="key">
                      <DynamicIcon :name="info.icon" />
                      <span class="label">{{ info.title }}</span>
                      <span class="marker">
                        <el-icon><CircleCheckFilled /></el-icon>
                      </span>
                    </el-radio-button>
                  </el-radio-group>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
  <div v-if="activeStep < 4" class="fct-onboarding-bottom">
    <div class="fct-onboarding-bottom-left">
      <el-button v-if="activeStep > 1" text @click="skipAll">
        {{ translate('Skip All') }}
      </el-button>
    </div>

    <div class="fct-onboarding-bottom-right">
      <el-button @click="gotToPrevStep" type="info" plain :disabled="activeStep === 1">
        {{ translate('Go Back') }}
      </el-button>

      <el-button @click="gotToNextStep" type="primary" v-if="activeStep < 3">
        {{ translate('Continue') }}
      </el-button>
      <el-button @click="saveSettings" v-if="activeStep === 3" type="primary" :loading="savingSettings">
        <span v-if="!savingSettings" class="cmd">⌘s</span>
        {{ savingSettings ? translate('Saving') : translate('Save') }}
      </el-button>
    </div>
  </div>
</template>
