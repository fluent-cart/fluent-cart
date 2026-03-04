<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="currentRouteTitle"
        :loading="saving"
        @onSave="saveSettings"
    />


    <div class="setting-wrap-inner">
      <template v-if="!hasFormFieldsError">
        <div class="bg-white rounded p-6 dark:bg-dark-700" v-if="formLoading">
          <el-skeleton :loading="formLoading" animated>
            <template #template>
              <div class="grid gap-3 mb-6">
                <el-skeleton-item variant="p" class="w-[20%]"/>
                <el-skeleton-item variant="p"/>
              </div>
              <div class="grid gap-3 mb-6">
                <el-skeleton-item variant="p" class="w-[20%]"/>
                <el-skeleton-item variant="p"/>
              </div>
              <div class="grid gap-3 mb-6">
                <el-skeleton-item variant="p" class="w-[20%]"/>
                <el-skeleton-item variant="p"/>
              </div>
              <div class="grid gap-3">
                <el-skeleton-item variant="p" class="w-[20%]"/>
                <el-skeleton-item variant="p"/>
              </div>
            </template>
          </el-skeleton>
        </div>
        <template v-if="!formLoading">
          <el-form v-if="form.isReady" class="relative">


            <Card v-if="loading">
              <CardBody>
                <el-skeleton
                    :rows="5"
                    animated
                >
                  <el-skeleton-item />
                  <el-skeleton-item variant="text" />
                  <el-skeleton-item variant="text" />
                </el-skeleton>
              </CardBody>
            </Card>

            <VueForm
                v-if="!loading"
                :form="form"
                :showSubmitButton="true"

                @onSubmitButtonClick="saveSettings"
                :submitButtonText="translate('Save')"
                :loading="saving"
                @on-change="(value) => {}"
                :validation-errors="validationErrors"
            />
          </el-form>
        </template>
      </template>
      <template v-else>
        <Card>
          <CardBody>
            <p>{{ translate('There is an error in the form fields. Please try reloading the page.') }}</p>
          </CardBody>
        </Card>
      </template>
    </div>
  </div>
  <!-- .setting-wrap -->
</template>

<script setup>

import {
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
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import Card from "@/Bits/Components/Card/Card.vue";
import SettingsHeader from "./Parts/SettingsHeader.vue";


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


const saveSettings = () => {
  saving.value = true;


  // let value = JSON.parse(JSON.stringify(form.values));
  let value = form.values;
  // value['store_logo'] = value['store_logo']?.[0] ?? '';
  if (Array.isArray(value['store_logo']) && value['store_logo'].length > 0) {
    value['store_logo'] = value['store_logo']?.[0];
  }

  value['settings_name'] = currentRouteName.value;

  Rest.post("settings/store", {
    ...value,
  })
      .then((response) => {
        Notify.success(
            translate('Settings saved successfully')
        );

        if (currentRouteName.value === 'store_setup') {
          setTimeout(() => {
            window.location.reload();
          }, 300)
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
  Rest.get("settings/store", {
    settings_name: currentRouteName.value
  }).then((response) => {
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
