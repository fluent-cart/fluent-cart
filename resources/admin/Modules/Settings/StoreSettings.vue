<template>
  <SettingsPage
      :heading="currentRouteTitle"
      :loading="saving"
      @on-save="saveSettings"
  >
    <AdminNotice/>

    <template v-if="!hasFormFieldsError">
      <div class="bg-white rounded p-6 dark:bg-dark-700" v-if="formLoading">
        <fct-skeleton animated>
          <div class="grid gap-3 mb-6">
            <fct-skeleton variant="p" class="w-[20%]"/>
            <fct-skeleton variant="p"/>
          </div>
          <div class="grid gap-3 mb-6">
            <fct-skeleton variant="p" class="w-[20%]"/>
            <fct-skeleton variant="p"/>
          </div>
          <div class="grid gap-3 mb-6">
            <fct-skeleton variant="p" class="w-[20%]"/>
            <fct-skeleton variant="p"/>
          </div>
          <div class="grid gap-3">
            <fct-skeleton variant="p" class="w-[20%]"/>
            <fct-skeleton variant="p"/>
          </div>
        </fct-skeleton>
      </div>
      <template v-if="!formLoading">
        <div v-if="form.isReady" class="relative">


          <Section v-if="loading">
            <fct-skeleton :rows="5" animated/>
          </Section>

          <VueForm
              v-if="!loading"
              :form="form"
              :showSubmitButton="!isAppearanceRoute"

              @on-submit-button-click="saveSettings"
              :submitButtonText="translate('Save')"
              :loading="saving"
              @on-change="(value) => {}"
              :validation-errors="validationErrors"
          />
        </div>
      </template>
    </template>
    <template v-else>
      <Section>
        <p>{{ translate('There is an error in the form fields. Please try reloading the page.') }}</p>
      </Section>
    </template>
  </SettingsPage>
</template>

<script setup>

import {
  computed,
  onMounted,
  ref, watch,
} from "vue";
import VueForm from "@/Bits/Components/Form/VueForm.vue";
import {useSettingsModel} from "@/Models/SettingsModel";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import {onBeforeRouteLeave, useRoute} from 'vue-router';
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import SettingsPage from "./Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {radiusBlockingSave, radiusErrorKey, radiusFieldError} from "@/Bits/Components/Form/Components/StoreSettings/radiusLength";

const settingsModel = useSettingsModel();
const {form} = settingsModel.data;

const keyboardShortcuts = useKeyboardShortcuts();

defineOptions({
  name: "StoreSettings",
});

const settings = ref([]);
const fields = ref([]);
const loading = ref(true);
const saving = ref(false);
const formLoading = ref(true);
const validationErrors = ref({});
const route = useRoute();
const currentRouteName = ref(route.name);
const currentRouteTitle = ref(route.meta.title || 'Store Settings');
const isRemindersSettingsRoute = () => currentRouteName.value === 'reminders';
// The appearance tab carries its own live preview, so the form's trailing Save
// button would sit under it far from the controls. The header Save still saves.
const isAppearanceRoute = computed(() => currentRouteName.value === 'appearance');
const getSettingsEndpoint = () => isRemindersSettingsRoute() ? 'email-notification/reminders' : 'settings/store';


const saveSettings = () => {
  // The server refuses a radius outside the grammar; stop here and point at
  // the field rather than posting a save that can only fail.
  if (isAppearanceRoute.value) {
    const appearance = fields.value?.appearance?.schema?.appearance_component;
    const customSource = appearance?.custom_source;
    const invalidRole = radiusBlockingSave(form.values, customSource, form.data.validationErrors);

    if (invalidRole) {
      // Held in the form's validation state, so the field shows it too.
      if (!radiusFieldError(form.data.validationErrors, invalidRole)) {
        form.setValidationErrors({
          ...form.data.validationErrors,
          [radiusErrorKey(invalidRole)]: [appearance?.radius_section?.invalid || translate('Fix the highlighted border radius before saving.')]
        });
      }

      Notify.error(translate('Fix the highlighted border radius before saving.'));
      const field = document.getElementById(`fct-appearance-radius-${invalidRole}`);
      field?.scrollIntoView({block: 'center', behavior: 'smooth'});
      field?.focus();
      return;
    }
  }

  saving.value = true;


  // let value = JSON.parse(JSON.stringify(form.values));
  let value = form.values;
  // value['store_logo'] = value['store_logo']?.[0] ?? '';
  if (Array.isArray(value['store_logo']) && value['store_logo'].length > 0) {
    value['store_logo'] = value['store_logo']?.[0];
  }

  if (!isRemindersSettingsRoute()) {
    value['settings_name'] = currentRouteName.value;
  }

  Rest.post(getSettingsEndpoint(), {
    ...value,
  })
      .then((response) => {
        Notify.success(
            translate('Settings saved successfully')
        );

        if (currentRouteName.value === 'store_setup') {
          const shopKeys = ['currency', 'currency_position', 'currency_separator',
            'decimal_separator', 'decimal_points', 'order_mode',
            'store_name', 'store_logo', 'weight_unit', 'dimension_unit'];
          const shopPatch = {};
          shopKeys.forEach(function(k) {
            if (form.values[k] !== undefined) {
              shopPatch[k] = form.values[k];
            }
          });
          if (shopPatch.currency) {
            const signs = AppConfig.get('currency_signs', {});
            if (signs[shopPatch.currency] !== undefined) {
              shopPatch.currency_sign = signs[shopPatch.currency];
            }
          }
          if (shopPatch.decimal_points !== undefined) {
            shopPatch.is_zero_decimal = parseInt(shopPatch.decimal_points, 10) === 0;
          }
          const prevShop = AppConfig.get('shop', {});
          const chartFields = ['currency', 'currency_position', 'decimal_separator', 'decimal_points'];
          const currencyChanged = chartFields.some(function(k) {
            return shopPatch[k] !== undefined && shopPatch[k] !== prevShop[k];
          });
          AppConfig.mergeConfig({ shop: Object.assign({}, prevShop, shopPatch) });
          CurrencyFormatter.setLocale();
          if (currencyChanged) {
            window.dispatchEvent(new CustomEvent('fluentCartCurrencyChange'));
          }
        }
        //getSettings();
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }



        form.setValidationErrors(errors);
        validationErrors.value = errors;
      })
      .finally(() => {
        saving.value = false;
        formLoading.value = false;
        loading.value = false;
      });
};


const hasFormFieldsError = ref(false);
const getSettings = () => {
  loading.value = true;
  hasFormFieldsError.value = false;
  const payload = isRemindersSettingsRoute() ? {} : {settings_name: currentRouteName.value};

  Rest.get(getSettingsEndpoint(), payload).then((response) => {
    form.setSchema(response.fields).setDefaults(response.settings).initForm();
    settings.value = response.settings;
    fields.value = response.fields;
    loading.value = false;
    formLoading.value = false;
    saving.value = false;
  }).catch((error) => {
    hasFormFieldsError.value = true;
    loading.value = false;
    formLoading.value = false;
    saving.value = false;
  });
};

const bindSaveShortcut = () => {
  keyboardShortcuts.bind(['mod+s'], (event) => {
    event.preventDefault();
    saveSettings();
  });
};

// Watch for route changes
watch(() => route.name, (newVal, oldVal) => {
  currentRouteName.value = newVal;
  currentRouteTitle.value = route.meta?.title || 'Store Settings';

  // Unbind first
  keyboardShortcuts.unbind('mod+s');
  // Then bind again
  bindSaveShortcut();

  getSettings();
});


onMounted(() => {
  bindSaveShortcut();
  getSettings();
});

onBeforeRouteLeave(() => {
  keyboardShortcuts.unbind('mod+s');
});
</script>
